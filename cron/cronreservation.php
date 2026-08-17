<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../text.php';

global $pdo;

// Prevent overlapping runs: non-blocking file lock ensures only one
// instance processes the reservation queue at a time.
$lockHandle = fopen(__DIR__ . '/.cronreservation.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    error_log('cronreservation: skipped, previous run still in progress');
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

$ManagePanel = new ManagePanel();

/**
 * How many reservations to check per run. This cron runs every minute
 * (see admin.php), so the batch size controls how quickly the queue is
 * drained. 50/min is a reasonable default — raise if needed.
 */
const BATCH_SIZE = 50;

/**
 * Small delay between outgoing Telegram messages so a batch that fires
 * many activations at once doesn't burst past Telegram's ~30 msg/sec limit.
 */
const TELEGRAM_SEND_DELAY_US = 50000; // 50ms => max ~20 msg/sec

// Cancel and refund any pending reservations whose invoice went back to
// 'active' (user manually extended before the cron fired). These would
// otherwise stay pending forever with no activation or refund path.
$strandedStmt = $pdo->prepare(
    "SELECT rp.id, rp.id_user, rp.price_product
     FROM reserved_package rp
     INNER JOIN invoice inv ON rp.invoice_id = inv.id_invoice
     WHERE rp.status = 'pending'
     AND inv.Status = 'active'"
);
$strandedStmt->execute();
$stranded = $strandedStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($stranded as $s) {
    try {
        $pdo->beginTransaction();
        $freshUser = $pdo->prepare("SELECT Balance FROM user WHERE id = :id FOR UPDATE");
        $freshUser->bindValue(':id', $s['id_user']);
        $freshUser->execute();
        $row = $freshUser->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $newBal = intval($row['Balance']) + intval($s['price_product']);
            $pdo->prepare("UPDATE user SET Balance = :b WHERE id = :id")->execute([':b' => $newBal, ':id' => $s['id_user']]);
        }
        $pdo->prepare("UPDATE reserved_package SET status = 'cancelled' WHERE id = :id")->execute([':id' => $s['id']]);
        $pdo->commit();
        error_log("cronreservation: refunded stranded reservation {$s['id']} for user {$s['id_user']}");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("cronreservation: failed to refund stranded reservation {$s['id']}: " . $e->getMessage());
    }
}

// Fetch pending reservations whose parent invoice has reached terminal
// state (end_of_time or end_of_volume). Process oldest first.
$stmt = $pdo->prepare(
    "SELECT rp.*, inv.id_user, inv.username, inv.Service_location
    FROM reserved_package rp
    INNER JOIN invoice inv ON rp.invoice_id = inv.id_invoice
    WHERE rp.status = 'pending'
    AND (inv.Status = 'end_of_time' OR inv.Status = 'end_of_volume')
    ORDER BY rp.created_at ASC
    LIMIT " . BATCH_SIZE
);
$stmt->execute();
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$reservations) {
    error_log('cronreservation: no pending reservations, exiting');
    return;
}

// Preload all panels once instead of re-querying per reservation row.
$panelsByName = [];
foreach (select("marzban_panel", "*", null, null, "fetchAll") as $panel) {
    $panelsByName[$panel['name_panel']] = $panel;
}

foreach ($reservations as $reservation) {
  try {
    $invoiceRow = [
        'id_invoice'       => $reservation['invoice_id'],
        'id_user'          => $reservation['id_user'],
        'username'         => $reservation['username'],
        'Service_location' => $reservation['Service_location'],
    ];

    $location = $invoiceRow['Service_location'];
    if (!isset($panelsByName[$location])) {
        // Panel no longer exists; skip this reservation.
        error_log("cronreservation: panel '{$location}' not found for reservation {$reservation['id']}");
        continue;
    }

    // Attempt activation (already handles panel-specific logic and status updates).
    $success = activateReservedPackage($pdo, $ManagePanel, $invoiceRow, $textbotlang);

    if ($success) {
        error_log("cronreservation: activated reservation {$reservation['id']} for invoice {$reservation['invoice_id']}");
        usleep(TELEGRAM_SEND_DELAY_US);
    } else {
        error_log("cronreservation: failed to activate reservation {$reservation['id']} for invoice {$reservation['invoice_id']}");
    }

  } catch (Throwable $e) {
      // Never let one bad row abort the whole batch.
      error_log('cronreservation: failed processing reservation ' . ($reservation['id'] ?? '?') . ': ' . $e->getMessage());
  }
}
