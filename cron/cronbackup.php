<?php
/**
 * /cron/cronbackup.php
 *
 * Daily database + codebase backup, delivered as a zip document to the
 * private backup channel configured in the admin panel.
 *
 * Suggested crontab (same mechanism as the other crons here):
 *   30 4 * * * curl -s https://DOMAIN/cron/cronbackup.php > /dev/null 2>&1
 */

ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../backup.php';

global $pdo;

// A full dump + zip can easily outlast the cron interval on a big database,
// and two concurrent runs would double the disk and Telegram traffic.
$lockHandle = fopen(__DIR__ . '/.cronbackup.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

// Dumping every row of every table is far slower than the other crons' work,
// so the default 30s limit isn't enough on a grown database.
set_time_limit(600);

$result = runBackup();
if (!$result['ok']) {
    error_log('cronbackup.php failed: ' . $result['error']);
}
