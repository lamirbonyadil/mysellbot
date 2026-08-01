<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../text.php';
require_once __DIR__ . '/../keyboard.php';

global $pdo;

// Prevent overlapping runs: this cron is scheduled every minute, and a
// slow run (panel/API timeouts, Telegram throttling) could otherwise
// exceed that window, letting two invocations process the same
// round-robin batch concurrently and double-send warnings to the same
// customers. A non-blocking file lock makes a second overlapping tick
// bail out immediately instead.
$lockHandle = fopen(__DIR__ . '/.cronvolume.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

addFieldToTable("invoice", "Volume_Warning_Level", "0", "INT(2)");
// Round-robin cursor: lets the batch below pick "whoever hasn't been
// checked in the longest time" instead of a random sample. This is what
// guarantees every invoice gets checked within a bounded number of runs
// instead of a statistically unbounded one (see cursor query below).
addFieldToTable("invoice", "Volume_Last_Checked_At", null, "DATETIME");
// Backs the ORDER BY below so the round-robin cursor scan stays fast as
// the invoice table grows (avoids a full table scan/sort every run).
addIndexToTable("invoice", "idx_volume_check", "Status, name_product, Volume_Last_Checked_At");

$ManagePanel = new ManagePanel();

/**
 * How many invoices to examine per cron run. This cron is registered to
 * run every minute (see admin.php), so this number directly determines
 * how long a full sweep of the customer base takes:
 *   time_to_cover_all ≈ (active invoice count / BATCH_SIZE) minutes
 * 150/min covers 1,000 active invoices in ~7 minutes instead of the
 * multi-hour tail the previous RAND()-based sampling could produce.
 * Raise this if the panel APIs and Telegram rate limit comfortably allow it.
 */
const BATCH_SIZE = 150;

/**
 * Small delay between outgoing Telegram messages so a batch that fires
 * many warnings at once doesn't burst past Telegram's ~30 msg/sec limit.
 */
const TELEGRAM_SEND_DELAY_US = 50000; // 50ms => max ~20 msg/sec

/**
 * How many usernames to request per Marzban bulk-fetch HTTP call (see
 * bulkFetchMarzbanUsers() below). Deliberately independent of how many
 * total users live on the panel - we only ever ask for usernames that are
 * actually part of *this run's* batch, chunked to keep each request's
 * query string/response payload small and predictable even if BATCH_SIZE
 * is raised later. This is what keeps a panel with thousands of users from
 * ever being fetched "all at once".
 */
const MARZBAN_BULK_CHUNK_SIZE = 150;

/**
 * Cron-only bulk fetch for Marzban panels.
 *
 * Marzban's GET /api/users accepts a repeatable `username` query parameter
 * that returns only the requested usernames in one response. That lets us
 * fetch exactly the usernames needed for the current cron batch instead of:
 *   - fetching the panel's ENTIRE user list (a busy panel can have
 *     thousands of users - large payload, slow response, mostly wasted
 *     since only a handful are due for a check this run), or
 *   - the original one-HTTP-call-per-username approach.
 *
 * Requests are chunked by MARZBAN_BULK_CHUNK_SIZE so the amount of work per
 * call tracks the batch size being processed, not panel size.
 *
 * Scoped to this cron file (not panels.php/marzban.php) because we've only
 * confirmed the `username` array filter for Marzban's API; other panel
 * types keep using the existing per-user ManagePanel::DataUser() path
 * further down.
 *
 * @param array $panel     Row from `marzban_panel` (needs id + url_panel).
 * @param array $usernames Usernames to fetch (already de-duplicated).
 * @return array<string, array{status:string, data_limit:?int, used_traffic:?int}>
 *         Keyed by username; missing/unresolved usernames simply won't have
 *         a key, which the caller treats the same as "not found".
 */
function bulkFetchMarzbanUsers(array $panel, array $usernames): array
{
    $result = [];
    if (empty($usernames)) {
        return $result;
    }

    $token = token_panel($panel['id']);
    if (!isset($token['access_token'])) {
        // Auth failure - leave all usernames unresolved for this run rather
        // than falling back to per-user calls that would hit the same auth
        // problem N times. They'll be re-checked next cron run.
        return $result;
    }

    foreach (array_chunk($usernames, MARZBAN_BULK_CHUNK_SIZE) as $chunk) {
        $queryParts = ['limit=' . count($chunk)];
        foreach ($chunk as $username) {
            $queryParts[] = 'username=' . rawurlencode($username);
        }
        $url = rtrim($panel['url_panel'], '/') . '/api/users?' . implode('&', $queryParts);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token['access_token'],
            ],
        ]);
        $output = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || !$output) {
            // Network hiccup for this chunk only; those usernames stay
            // unresolved and get re-checked next run.
            continue;
        }

        $body = json_decode($output, true);
        if (!isset($body['users']) || !is_array($body['users'])) {
            continue;
        }

        foreach ($body['users'] as $u) {
            if (!isset($u['username'])) {
                continue;
            }
            $result[$u['username']] = [
                'status'       => $u['status'] ?? '',
                'data_limit'   => $u['data_limit'] ?? null,
                'used_traffic' => $u['used_traffic'] ?? null,
            ];
        }
    }

    return $result;
}

/**
 * Volume warning tiers, ordered least -> most urgent.
 * `level` is persisted on the invoice row (Volume_Warning_Level) so each
 * tier fires at most once per billing cycle. A tier only fires when the
 * remaining volume's computed level is HIGHER (more urgent) than whatever
 * is already stored — this makes the check monotonic and spam-proof even
 * if remaining volume skips a tier between two cron runs.
 *
 * Requires migration: addFieldToTable("invoice", "Volume_Warning_Level", "0", "INT(2)");
 * Must be reset to 0 wherever an invoice's Status is set back to 'active'
 * after a renew (see functions.php::DirectPayment / index.php confirmserivce-).
 *
 * NOTE: full depletion (0 bytes remaining) is NOT part of this ladder — it's
 * a terminal state handled separately below, and is gated on invoice Status
 * rather than Volume_Warning_Level, since Status already flips back to
 * 'active' on renewal (see index.php confirmserivce-), which naturally
 * re-arms this alert for the next cycle without any extra reset needed.
 */
$volumeThresholds = [
    ['level' => 1, 'bytes' => 2 * pow(1024, 3)],   // 2 GB
    ['level' => 2, 'bytes' => 1 * pow(1024, 3)],   // 1 GB
    ['level' => 3, 'bytes' => 500 * pow(1024, 2)], // 500 MB
];

// Preload all panels once instead of re-querying `marzban_panel` per invoice
// row just to check it still exists. Cuts one DB round trip per invoice.
$panelsByName = [];
foreach (select("marzban_panel", "*", null, null, "fetchAll") as $panel) {
    $panelsByName[$panel['name_panel']] = $panel;
}

/*
 * Round-robin selection: oldest-checked (or never-checked) invoices first.
 * This replaces `ORDER BY RAND() LIMIT 15`, which forces a full sort of
 * every matching row on every run and gives no guarantee that a given
 * invoice will ever be picked in a timely manner. With a deterministic
 * cursor + composite index, every eligible invoice is guaranteed to be
 * re-checked within ceil(active_count / BATCH_SIZE) runs.
 *
 * Backing index is created automatically above via addIndexToTable().
 */
$stmt = $pdo->prepare(
    "SELECT * FROM invoice
    WHERE (Status = 'active' OR Status = 'end_of_volume' OR Status = 'end_of_time' OR Status = 'sendedwarn')
    AND name_product != 'usertest'
    ORDER BY Volume_Last_Checked_At IS NOT NULL, Volume_Last_Checked_At ASC
    LIMIT " . BATCH_SIZE
);
$stmt->execute();
$invoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$invoiceRows) {
    return;
}

// Group this batch's usernames by panel location so Marzban panels can be
// bulk-fetched in one (or a few chunked) call instead of one HTTP request
// per invoice. Only usernames actually due for a check this run are
// requested — never the whole panel.
$usernamesByLocation = [];
foreach ($invoiceRows as $row) {
    $usernamesByLocation[$row['Service_location']][] = trim($row['username']);
}

$bulkDataByLocation = [];
foreach ($usernamesByLocation as $location => $usernames) {
    $panel = $panelsByName[$location] ?? null;
    if (!$panel || $panel['type'] !== 'marzban') {
        // Non-Marzban panel type: fall back to the existing per-user
        // ManagePanel::DataUser() path inside the loop below.
        continue;
    }
    $bulkDataByLocation[$location] = bulkFetchMarzbanUsers($panel, array_values(array_unique($usernames)));
}

// Each of these runs autocommit (no explicit transaction wrapping the
// loop): the loop also makes slow network calls (sendmessage(), and the
// non-Marzban DataUser() fallback), and holding a transaction open across
// those would keep row locks held for however long that I/O takes. A
// single-row UPDATE's lock duration (milliseconds) is a non-issue by
// comparison, so there's nothing worth batching into one transaction here.
$updateStmt = $pdo->prepare(
    "UPDATE invoice SET Volume_Warning_Level = ?, Volume_Last_Checked_At = NOW() WHERE id_invoice = ?"
);
$statusStmt = $pdo->prepare(
    "UPDATE invoice SET Status = 'sendedwarn' WHERE id_invoice = ? AND Status = 'active'"
);
$touchStmt = $pdo->prepare(
    "UPDATE invoice SET Volume_Last_Checked_At = NOW() WHERE id_invoice = ?"
);

foreach ($invoiceRows as $invoiceRow) {
  try {
    $usernameSvc = trim($invoiceRow['username']);
    $location    = $invoiceRow['Service_location'];

    if (!isset($panelsByName[$location])) {
        // Panel no longer exists; still advance the cursor so this row
        // doesn't get re-picked ahead of everything else next run.
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    if (isset($bulkDataByLocation[$location])) {
        // Marzban panel already bulk-fetched above.
        $remoteUser = $bulkDataByLocation[$location][$usernameSvc] ?? null;
    } else {
        // Non-Marzban panel type - existing per-user lookup.
        $remoteUser = $ManagePanel->DataUser($location, $usernameSvc);
    }

    if (!$remoteUser || (isset($remoteUser['status']) && $remoteUser['status'] == "Unsuccessful")) {
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }
    if (!in_array($remoteUser['status'] ?? '', ['active', 'limited'])) {
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    // Unlimited packages (no data_limit) never trigger volume warnings.
    if (empty($remoteUser['data_limit'])) {
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    $remainingBytes = $remoteUser['data_limit'] - $remoteUser['used_traffic'];

    if ($remainingBytes <= 0) {
        // Fire once per cycle: skip if we've already sent the depletion
        // alert (level 4). This script intentionally does not touch
        // invoice Status here — that transition is owned elsewhere in the
        // pipeline. Volume_Warning_Level resets to 0 on renewal, so this
        // re-arms automatically for the next billing cycle.
        $currentLevel = intval($invoiceRow['Volume_Warning_Level'] ?? 0);
        if ($currentLevel >= 4) {
            $touchStmt->execute([$invoiceRow['id_invoice']]);
            continue;
        }

        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['extend']['title'], 'callback_data' => 'extend_' . $usernameSvc],
                ],
            ]
        ]);

        sendmessage(
            $invoiceRow['id_user'],
            sprintf($textbotlang['users']['cron']['cronvolumeend'], $usernameSvc),
            $Response,
            'HTML'
        );
        usleep(TELEGRAM_SEND_DELAY_US);

        $updateStmt->execute([4, $invoiceRow['id_invoice']]);

        continue;
    }

    #-----------[ low-volume warning ladder (2GB / 1GB / 500MB) ]-----------#
    $currentLevel = intval($invoiceRow['Volume_Warning_Level'] ?? 0);
    $targetLevel  = 0;

    foreach ($volumeThresholds as $tier) {
        if ($remainingBytes <= $tier['bytes']) {
            $targetLevel = $tier['level'];
        }
    }

    // No tier crossed, or we already warned at this tier (or a more urgent one).
    if ($targetLevel === 0 || $targetLevel <= $currentLevel) {
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    $RemainingVolume = formatBytes($remainingBytes);

    $Response = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['users']['extend']['title'], 'callback_data' => 'extend_' . $usernameSvc],
            ],
        ]
    ]);

    sendmessage(
        $invoiceRow['id_user'],
        sprintf($textbotlang['users']['cron']['cronvolume'], $usernameSvc, $RemainingVolume),
        $Response,
        'HTML'
    );
    usleep(TELEGRAM_SEND_DELAY_US);

    $updateStmt->execute([$targetLevel, $invoiceRow['id_invoice']]);

    // Preserve the existing status semantics: still mark as 'sendedwarn'
    // unless it's already past that stage (end_of_time takes priority).
    $statusStmt->execute([$invoiceRow['id_invoice']]);
  } catch (Throwable $e) {
      // Never let one bad row abort the whole batch. Still advance its
      // cursor so a persistently-failing row doesn't get stuck at the
      // front of the round-robin queue and starve the rest of the batch.
      error_log('cronvolume: failed processing invoice ' . ($invoiceRow['id_invoice'] ?? '?') . ': ' . $e->getMessage());
      try {
          $touchStmt->execute([$invoiceRow['id_invoice']]);
      } catch (Throwable $e2) {
          error_log('cronvolume: failed to touch cursor for invoice ' . ($invoiceRow['id_invoice'] ?? '?') . ': ' . $e2->getMessage());
      }
  }
}
