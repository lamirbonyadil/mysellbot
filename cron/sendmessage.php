<?php
/**
 * /cron/sendmessage.php
 *
 * Drains the broadcast queue (tables `broadcast` / `broadcast_recipient`),
 * sending BATCH_SIZE messages per tick so bulk sends can't trip Telegram's
 * flood limits.
 *
 * Suggested crontab (same mechanism as the other crons here):
 *   * * * * * curl -s https://DOMAIN/cron/sendmessage.php > /dev/null 2>&1
 */

ini_set('error_log', __DIR__ . '/error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../text.php';

global $pdo;

const BATCH_SIZE = 100;

// Two overlapping ticks would claim the same pending rows and send every
// recipient in the batch twice.
$lockHandle = fopen(__DIR__ . '/.cronsendmessage.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

// Announce renewal campaigns that ran out on their own. Admin-stopped ones are
// already announced (and flagged) by admin.php, so they never reach this.
// The 24h floor keeps a long-idle cron from suddenly announcing the end of
// campaigns that expired weeks ago.
$stmt = $pdo->prepare("SELECT * FROM RenewalCampaign WHERE end_notified = 0 AND expires_at <= :now AND expires_at > :floor ORDER BY expires_at ASC LIMIT 1");
$stmt->bindValue(':now', time(), PDO::PARAM_INT);
$stmt->bindValue(':floor', time() - 86400, PDO::PARAM_INT);
$stmt->execute();
$expiredcampaign = $stmt->fetch(PDO::FETCH_ASSOC);
if ($expiredcampaign !== false) {
    // A newer campaign may already be running on the same panel, in which case
    // the discount never actually stopped and announcing its end would be a lie.
    // Flag the stale row either way so it doesn't get retried every tick.
    $notified = false;
    if (getActiveRenewalCampaign($expiredcampaign['name_panel']) === null) {
        $textcampaignend = sprintf($textbotlang['Admin']['RenewalCampaign']['notifyend'], $expiredcampaign['name_panel'], $expiredcampaign['percent']);
        $notified = queueRenewalCampaignBroadcast($textcampaignend);
    } else {
        $notified = true;
    }
    if ($notified) {
        $stmt = $pdo->prepare("UPDATE RenewalCampaign SET end_notified = 1 WHERE id = :id");
        $stmt->bindValue(':id', intval($expiredcampaign['id']), PDO::PARAM_INT);
        $stmt->execute();
    }
}

// One-time migration of a broadcast that was still in flight when the queue
// moved from these two flat files into the database.
$legacyusersfile = __DIR__ . '/users.json';
$legacyinfofile = __DIR__ . '/info';
if (is_file($legacyusersfile) && is_file($legacyinfofile)) {
    $legacyrecipients = json_decode(file_get_contents($legacyusersfile), true);
    $legacyinfo = json_decode(file_get_contents($legacyinfofile), true);
    if (is_array($legacyrecipients) && count($legacyrecipients) > 0 && isset($legacyinfo['text'])) {
        $stmt = $pdo->prepare("INSERT INTO broadcast (text, id_admin, status, created_at) VALUES (?, ?, 'active', ?)");
        $stmt->execute([$legacyinfo['text'], $legacyinfo['id_admin'] ?? null, time()]);
        $legacybroadcastid = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO broadcast_recipient (broadcast_id, chat_id) VALUES (?, ?)");
        foreach ($legacyrecipients as $legacyrecipient) {
            $chatid = is_array($legacyrecipient) ? ($legacyrecipient['id'] ?? null) : $legacyrecipient;
            if ($chatid !== null) {
                $stmt->execute([$legacybroadcastid, $chatid]);
            }
        }
    }
    unlink($legacyusersfile);
    unlink($legacyinfofile);
}

$broadcast = $pdo->query("SELECT id, text, id_admin FROM broadcast WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($broadcast === false) {
    return;
}

$stmt = $pdo->prepare("SELECT id, chat_id FROM broadcast_recipient WHERE broadcast_id = :bid AND status = 'pending' ORDER BY id ASC LIMIT " . BATCH_SIZE);
$stmt->bindValue(':bid', intval($broadcast['id']), PDO::PARAM_INT);
$stmt->execute();
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($recipients) == 0) {
    if (!empty($broadcast['id_admin'])) {
        sendmessage($broadcast['id_admin'], $textbotlang['users']['cron']['sendedmessage'], null, 'HTML');
    }
    $stmt = $pdo->prepare("UPDATE broadcast SET status = 'done' WHERE id = :bid");
    $stmt->bindValue(':bid', intval($broadcast['id']), PDO::PARAM_INT);
    $stmt->execute();
    return;
}

$markstmt = $pdo->prepare("UPDATE broadcast_recipient SET status = :status, attempts = attempts + 1, sent_at = :sent_at WHERE id = :id");
foreach ($recipients as $recipient) {
    $res = sendmessage($recipient['chat_id'], $broadcast['text'], null, 'HTML');
    $ok = isset($res['ok']) && $res['ok'];
    $markstmt->bindValue(':status', $ok ? 'sent' : 'failed', PDO::PARAM_STR);
    $markstmt->bindValue(':sent_at', $ok ? time() : null, $ok ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $markstmt->bindValue(':id', intval($recipient['id']), PDO::PARAM_INT);
    $markstmt->execute();
}
