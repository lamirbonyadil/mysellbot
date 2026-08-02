<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../text.php';

global $pdo;

// Prevent overlapping runs: this cron is scheduled every minute, and a
// slow run (panel/API timeouts, Telegram throttling) could otherwise
// exceed that window, letting two invocations process the same
// round-robin batch concurrently and double-send warnings to the same
// customers. A non-blocking file lock makes a second overlapping tick
// bail out immediately instead. Uses its own lock file (separate from
// cronvolume.php's) since the two crons run independently.
$lockHandle = fopen(__DIR__ . '/.cronday.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    error_log('cronday: skipped, previous run still in progress');
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

addFieldToTable("invoice", "Day_Warning_Level", "0", "INT(2)");
// Round-robin cursor: lets the batch below pick "whoever hasn't been
// checked in the longest time" instead of a random sample. Kept separate
// from cronvolume.php's Volume_Last_Checked_At column so the two crons'
// schedules don't interfere with each other's coverage.
addFieldToTable("invoice", "Day_Last_Checked_At", null, "DATETIME");
// Backs the ORDER BY below so the round-robin cursor scan stays fast as
// the invoice table grows (avoids a full table scan/sort every run).
addIndexToTable("invoice", "idx_day_check", "Status, name_product, Day_Last_Checked_At");

$ManagePanel = new ManagePanel();

/*
 * How many invoices to examine per cron run. This cron is registered to
 * run every minute (see admin.php), so this number directly determines
 * how long a full sweep of the customer base takes:
 *   time_to_cover_all ≈ (active invoice count / BATCH_SIZE) minutes
 * 150/min covers 1,000 active invoices in ~7 minutes instead of the
 * unbounded-tail RAND()-based sampling the previous version used.
 */
const BATCH_SIZE = 150;

/*
 * Small delay between outgoing Telegram messages so a batch that fires
 * many warnings at once doesn't burst past Telegram's ~30 msg/sec limit.
 */
const TELEGRAM_SEND_DELAY_US = 50000; // 50ms => max ~20 msg/sec

/**
 * Expiry warning tiers, ordered least -> most urgent, expressed in whole
 * days remaining. `level` is persisted on the invoice row
 * (Day_Warning_Level) so each tier fires at most once per billing cycle. A
 * tier only fires when the remaining-time's computed level is HIGHER (more
 * urgent) than whatever is already stored - this makes the check monotonic
 * and spam-proof even if remaining time skips a tier between two cron runs
 * (e.g. the cron didn't run for a while).
 *
 * Requires migration: addFieldToTable("invoice", "Day_Warning_Level", "0", "INT(2)");
 * Reset to 0 wherever an invoice's Status is set back to 'active' after a
 * renew (see functions.php::DirectPayment and index.php's renewal
 * confirmation handler), mirroring Volume_Warning_Level's reset - otherwise
 * a customer who reached e.g. the 1-day tier before renewing would never
 * get warned again next cycle, since the stored level would already be
 * "more urgent" than the next cycle's 3-day tier.
 *
 * NOTE: full expiry (0 seconds remaining) is NOT part of this ladder - it's
 * a terminal state handled separately below, capped at level 4, exactly
 * mirroring cronvolume.php's full-depletion handling.
 */
$dayThresholds = [
    ['level' => 1, 'days' => 3, 'seconds' => 3 * 86400],
    ['level' => 2, 'days' => 2, 'seconds' => 2 * 86400],
    ['level' => 3, 'days' => 1, 'seconds' => 1 * 86400],
];

// Preload all panels once instead of re-querying `marzban_panel` per invoice
// row just to check it still exists. Cuts one DB round trip per invoice.
$panelsByName = [];
foreach (select("marzban_panel", "*", null, null, "fetchAll") as $panel) {
    $panelsByName[$panel['name_panel']] = $panel;
}

/**
 * Round-robin selection: oldest-checked (or never-checked) invoices first.
 * This replaces `ORDER BY RAND() LIMIT 5`, which forces a full sort of
 * every matching row on every run and gives no guarantee that a given
 * invoice will ever be picked in a timely manner. With a deterministic
 * cursor + composite index, every eligible invoice is guaranteed to be
 * re-checked within ceil(active_count / BATCH_SIZE) runs.
 *
 * Backing index is created automatically above via addIndexToTable().
 *
 * Note: 'end_of_time' and 'sendedwarn' are included here (in addition to
 * the original 'active'/'end_of_volume') so this cron keeps tracking an
 * invoice across the whole warned lifecycle, matching the eligibility set
 * used everywhere else in the codebase (cronvolume.php, removeexpire.php,
 * index.php's service-list queries). 'end_of_volume' is kept only for
 * backward compatibility with old rows - nothing in the current codebase
 * writes that status anymore (cronvolume.php now uses 'sendedwarn').
 */
$stmt = $pdo->prepare(
    "SELECT * FROM invoice
    WHERE (Status = 'active' OR Status = 'end_of_volume' OR Status = 'end_of_time' OR Status = 'sendedwarn')
    AND name_product != 'usertest'
    ORDER BY Day_Last_Checked_At IS NOT NULL, Day_Last_Checked_At ASC
    LIMIT " . BATCH_SIZE
);
$stmt->execute();
$invoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$invoiceRows) {
    error_log('cronday: no invoices due, exiting');
    return;
}

// Group this batch's usernames by panel location so Marzban panels can be
// bulk-fetched in one (or a few chunked) call instead of one HTTP request
// per invoice. Only usernames actually due for a check this run are
// requested - never the whole panel.
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
    "UPDATE invoice SET Day_Warning_Level = ?, Day_Last_Checked_At = NOW() WHERE id_invoice = ?"
);
$statusStmt = $pdo->prepare(
    "UPDATE invoice SET Status = 'sendedwarn' WHERE id_invoice = ? AND Status = 'active'"
);
$touchStmt = $pdo->prepare(
    "UPDATE invoice SET Day_Last_Checked_At = NOW() WHERE id_invoice = ?"
);
// Preserves the original "disabled" behaviour: once the panel reports the
// account is no longer active/on_hold (e.g. limited/expired), flag the
// invoice as disabled. Guarded so it's a no-op once already disabled.
$disableStmt = $pdo->prepare(
    "UPDATE invoice SET Status = 'disabled', Day_Last_Checked_At = NOW() WHERE id_invoice = ? AND Status != 'disabled'"
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

    if (!in_array($remoteUser['status'] ?? '', ['active', 'on_hold'])) {
        // Panel no longer considers this service active (e.g. limited,
        // expired) - flag the invoice as disabled, mirroring the original
        // behaviour exactly.
        $disableStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    // Services without an expiry (e.g. unlimited-time plans) never trigger
    // day warnings.
    if (empty($remoteUser['expire'])) {
        $touchStmt->execute([$invoiceRow['id_invoice']]);
        continue;
    }

    $secondsRemaining = $remoteUser['expire'] - time();

    if ($secondsRemaining <= 0) {
        // Fire once per cycle: skip if we've already sent the "day ended"
        // alert (level 4). Status is intentionally left untouched here -
        // that transition (disabling/removing the service) is owned by
        // removeexpire.php's pipeline. Day_Warning_Level resets to 0 on
        // renewal, so this re-arms automatically for the next cycle.
        $currentLevel = intval($invoiceRow['Day_Warning_Level'] ?? 0);
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
            sprintf($textbotlang['users']['cron']['crondayend'], $usernameSvc),
            $Response,
            'HTML'
        );
        usleep(TELEGRAM_SEND_DELAY_US);

        $updateStmt->execute([4, $invoiceRow['id_invoice']]);
        continue;
    }

    #-----------[ expiry warning ladder (3 days / 2 days / 1 day) ]-----------#
    $currentLevel = intval($invoiceRow['Day_Warning_Level'] ?? 0);
    $targetLevel  = 0;
    $targetDays   = 0;

    foreach ($dayThresholds as $tier) {
        if ($secondsRemaining <= $tier['seconds']) {
            $targetLevel = $tier['level'];
            $targetDays  = $tier['days'];
        }
    }

    // No tier crossed, or we already warned at this tier (or a more urgent one).
    if ($targetLevel === 0 || $targetLevel <= $currentLevel) {
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
        sprintf($textbotlang['users']['cron']['cronday'], $usernameSvc, $targetDays),
        $Response,
        'HTML'
    );
    usleep(TELEGRAM_SEND_DELAY_US);

    $updateStmt->execute([$targetLevel, $invoiceRow['id_invoice']]);

    // Preserve the existing status semantics: still mark as 'sendedwarn'
    // unless it's already past that stage (end_of_time/sendedwarn/disabled
    // take priority - the WHERE clause makes this a no-op in that case).
    $statusStmt->execute([$invoiceRow['id_invoice']]);
  } catch (Throwable $e) {
      // Never let one bad row abort the whole batch. Still advance its
      // cursor so a persistently-failing row doesn't get stuck at the
      // front of the round-robin queue and starve the rest of the batch.
      error_log('cronday: failed processing invoice ' . ($invoiceRow['id_invoice'] ?? '?') . ': ' . $e->getMessage());
      try {
          $touchStmt->execute([$invoiceRow['id_invoice']]);
      } catch (Throwable $e2) {
          error_log('cronday: failed to touch cursor for invoice ' . ($invoiceRow['id_invoice'] ?? '?') . ': ' . $e2->getMessage());
      }
  }
}
