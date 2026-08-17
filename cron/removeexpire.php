<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once '../config.php';
require_once '../botapi.php';
require_once '../panels.php';
require_once '../functions.php';
require_once '../text.php';
$ManagePanel = new ManagePanel();


$setting = select("setting", "*");

// Soft delete expired services
// Only process services that are truly expired (end_of_time or end_of_volume)
// and have been in that state for the grace period configured by admin
$stmt = $pdo->prepare("SELECT * FROM invoice
    WHERE (status = 'end_of_time' OR status = 'end_of_volume')
    AND name_product != 'usertest'
    AND expired_at IS NOT NULL
    ORDER BY RAND()
    LIMIT 10");
$stmt->execute();

while ($resultss = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $line = trim($resultss['username']);
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $resultss['Service_location'], "select");

    if ($marzban_list_get == false) continue;

    // Calculate days since service expired
    $days_expired = floor((time() - strtotime($resultss['expired_at'])) / 86400);

    // Check if grace period has passed (admin configurable via removedayc setting)
    if ($days_expired >= intval($setting['removedayc'])) {
        // Soft delete: set status to 'deleted' and remove from panel
        sendmessage($resultss['id_user'], sprintf($textbotlang['users']['cron']['removeexpire'], $resultss['username']), null, 'HTML');

        // Update status to 'deleted' instead of 'removeTime'
        update("invoice", "status", "deleted", "username", $line);

        // Remove from Marzban panel
        $ManagePanel->RemoveUser($resultss['Service_location'], $line);

        // Send report to admin channel if configured
        if (strlen($setting['Channel_Report']) > 0) {
            $status_var = [
                'end_of_time' => $textbotlang['users']['status']['expired'],
                'end_of_volume' => $textbotlang['users']['status']['limited'],
            ][$resultss['status']] ?? $resultss['status'];

            sendmessage($setting['Channel_Report'], sprintf($textbotlang['Admin']['Report']['reportremovecron'], $line, $status_var), null, 'HTML');
        }
    }
}
