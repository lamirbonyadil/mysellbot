<?php
/**
 * /cron/inactivityalert.php
 *
 * Scans genuinely active invoices (status = 'active' only — see the
 * scoping note below for why end_of_time / end_of_volume / sendedwarn
 * are deliberately excluded) and finds services whose customer has not
 * connected to the VPN for >= 24h.
 *
 *   Case A (never connected): inactivity is measured from the invoice's
 *   purchase timestamp (invoice.time_sell).
 *   Case B (previously connected): inactivity is measured from the panel's
 *   reported online_at value.
 *
 * Matched users get a single supportive Persian message with two inline
 * "glass" buttons that route into the existing helpbtn / support flows
 * already wired in index.php — no new conversational state is introduced.
 *
 * Suggested crontab (same mechanism used for cron/sendmessage.php):
 *   0 *\/6 * * * curl -s https://DOMAIN/cron/inactivityalert.php > /dev/null 2>&1
 */

ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../text.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../panels.php';

global $pdo;

#-----------------------------------------------------------------#
# 0) Schema bootstrap — reuse the project's own migration helper.
#    Stores the last time we nagged this invoice so we don't spam
#    the user every single cron tick.
#-----------------------------------------------------------------#
addFieldToTable("invoice", "last_inactivity_notify", "0", "VARCHAR(200)");

#-----------------------------------------------------------------#
# 1) Config
#-----------------------------------------------------------------#
const INACTIVITY_THRESHOLD_SECONDS = 86400;  // 1 full day
const RENOTIFY_COOLDOWN_SECONDS    = 172800; // don't repeat within 2 days
// Panel types that don't expose a meaningful online_at/connection signal.
const SKIP_PANEL_TYPES = ["wgdashboard", "mikrotik"];

$ManagePanel = new ManagePanel();

#-----------------------------------------------------------------#
# 2) Pull only genuinely active invoices.
#
#    Deliberately narrower than the "is this invoice still live"
#    check used elsewhere (which also includes end_of_time,
#    end_of_volume, sendedwarn). Those three statuses mean the
#    service has already run out of time or data — that's a
#    renewal concern owned by cron/cronvolume.php's warning flow,
#    not a "you haven't connected, need help?" concern. Nudging a
#    customer to get connection help when what they actually need
#    is to renew would be a misleading, unhelpful message.
#-----------------------------------------------------------------#
$stmt = $pdo->prepare("SELECT * FROM invoice WHERE status = 'active'");
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$invoices) {
    return;
}

$now = time();

foreach ($invoices as $invoice) {
    try {
        #-----------------------------------------------------#
        # Guard clauses — skip anything that isn't eligible
        #-----------------------------------------------------#
        $recipient = select("user", "*", "id", $invoice['id_user'], "select");
        if ($recipient == false) {
            continue;
        }
        if ($recipient['User_Status'] == "block") {
            continue;
        }

        $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
        if ($panel == false) {
            continue;
        }
        if (in_array($panel['type'], SKIP_PANEL_TYPES)) {
            continue;
        }

        // Respect the renotify cooldown, so we don't flood the user.
        $lastNotify = intval($invoice['last_inactivity_notify'] ?? 0);
        if ($lastNotify != 0 && ($now - $lastNotify) < RENOTIFY_COOLDOWN_SECONDS) {
            continue;
        }

        #-----------------------------------------------------#
        # Pull live panel data through the facade — never call
        # a sub-driver (marzban.php etc.) directly from here.
        #-----------------------------------------------------#
        $DataUserOut = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);

        $isUnusable = !$DataUserOut
            || empty($DataUserOut['status'])
            || $DataUserOut['status'] == "Unsuccessful";
        if ($isUnusable) {
            continue;
        }

        // Belt-and-suspenders: invoice.Status is only updated once per
        // cron cycle elsewhere in the system (see cronvolume.php), so it
        // can briefly lag the panel's live truth. Re-check against the
        // panel directly using the same active/on_hold bucket index.php
        // already treats as "usable" — anything reporting limited,
        // disabled, or expired here has genuinely run out, and must not
        // receive a connection-help nudge instead of a renewal one.
        if (!in_array($DataUserOut['status'], ['active', 'on_hold'])) {
            continue;
        }

        #-----------------------------------------------------#
        # Case A / Case B inactivity resolution
        #-----------------------------------------------------#
        $neverConnected = empty($DataUserOut['online_at']);

        if ($neverConnected) {
            // Case A: fall back to purchase time.
            $lastActivityTs = intval($invoice['time_sell']);
        } else {
            // Case B: last_seen reported by the panel API.
            $parsed = strtotime($DataUserOut['online_at']);
            $lastActivityTs = ($parsed !== false) ? $parsed : intval($invoice['time_sell']);
        }

        if ($lastActivityTs <= 0) {
            continue; // no usable timestamp at all, don't guess
        }

        $inactiveSeconds = $now - $lastActivityTs;
        if ($inactiveSeconds < INACTIVITY_THRESHOLD_SECONDS) {
            continue; // still within the healthy window
        }

        #-----------------------------------------------------#
        # Build the message + glass inline keyboard
        #-----------------------------------------------------#
        $inactiveDays = floor($inactiveSeconds / 86400);

        $text_inactivity = sprintf(
            $textbotlang['users']['inactivity']['message'],
            $invoice['username'],
            $inactiveDays
        );

        $keyboard_inactivity = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['inactivity']['btn_help'],    'callback_data' => 'helpbtn'],
                ],
                [
                    ['text' => $textbotlang['users']['inactivity']['btn_support'], 'callback_data' => 'support'],
                ],
            ]
        ]);

        sendmessage($invoice['id_user'], $text_inactivity, $keyboard_inactivity, 'HTML');

        // Stamp so we don't re-notify before the cooldown elapses.
        update("invoice", "last_inactivity_notify", $now, "id_invoice", $invoice['id_invoice']);

        // Gentle pacing to stay well under Telegram's flood limits.
        usleep(150000);
    } catch (\Throwable $e) {
        error_log("inactivityalert.php error for invoice {$invoice['id_invoice']}: " . $e->getMessage());
        continue;
    }
}