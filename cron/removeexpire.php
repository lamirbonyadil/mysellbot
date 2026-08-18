<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once '../config.php';
require_once '../botapi.php';
require_once '../panels.php';
require_once '../functions.php';
require_once '../text.php';

global $pdo;

// Prevent overlapping runs: this cron is scheduled every minute, and a
// slow run (panel/API timeouts, Telegram throttling) could otherwise
// exceed that window and double-process the same invoices.
$lockHandle = fopen(__DIR__ . '/.removeexpire.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    error_log('removeexpire: skipped, previous run still in progress');
    return;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

addIndexToTable("invoice", "idx_soft_delete", "Status, name_product, expired_at");

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");

/**
 * How many expired invoices to process per run. This cron runs every
 * minute (see admin.php). Each row may make a panel API call, so keep
 * this modest — raise if a large backlog needs to drain faster.
 */
const BATCH_SIZE = 50;

/**
 * RemoveUser() returns a status array; it does not throw on API failure.
 * Treat an already-missing panel user as success so a previous partial
 * run can still finish the database soft-delete.
 */
function panelUserRemoved($result): bool
{
    if (!is_array($result)) {
        return false;
    }
    $status = strtolower((string)($result['status'] ?? ''));
    if ($status === 'successful') {
        return true;
    }
    $msg = strtolower((string)($result['msg'] ?? ''));
    if ($msg === '') {
        return false;
    }
    foreach (['not found', 'does not exist', 'no such'] as $hint) {
        if (str_contains($msg, $hint)) {
            return true;
        }
    }
    return false;
}

// Soft delete expired services that have passed the admin grace period
// and have no pending reserved package. The invoice is claimed as
// pending_delete in a short transaction; panel HTTP runs without a row lock.
$stmt = $pdo->prepare("SELECT * FROM invoice
    WHERE name_product != 'usertest'
    AND (
        status = 'pending_delete'
        OR (
            (status = 'end_of_time' OR status = 'end_of_volume')
            AND expired_at IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM reserved_package
                WHERE reserved_package.invoice_id = invoice.id_invoice
                AND reserved_package.status = 'pending'
            )
        )
    )
    ORDER BY CASE WHEN status = 'pending_delete' THEN 0 ELSE 1 END, expired_at ASC
    LIMIT " . BATCH_SIZE);
$stmt->execute();

$processed = 0;
$deleted = 0;
$failed = 0;

while ($resultss = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $processed++;
    $line = trim($resultss['username']);

    try {
        $rowStatus = $resultss['Status'] ?? $resultss['status'] ?? '';
        $isPendingDelete = ($rowStatus === 'pending_delete');
        $days_expired = $resultss['expired_at']
            ? floor((time() - strtotime($resultss['expired_at'])) / 86400)
            : 0;

        if (!$isPendingDelete && $days_expired < intval($setting['removedayc'])) {
            continue;
        }

        // Claim the row in a short transaction, then release the lock
        // before the panel HTTP call so a concurrent renew is not blocked.
        $justClaimed = false;
        $previousStatus = $rowStatus;

        $pdo->beginTransaction();
        $recheck = $pdo->prepare("SELECT status FROM invoice WHERE username = ? AND id_invoice = ? FOR UPDATE");
        $recheck->execute([$line, $resultss['id_invoice']]);
        $currentStatus = $recheck->fetchColumn();

        if ($currentStatus === 'pending_delete') {
            $pdo->rollBack();
        } elseif (in_array($currentStatus, ['end_of_time', 'end_of_volume'], true)) {
            $pendingStmt = $pdo->prepare("SELECT 1 FROM reserved_package WHERE invoice_id = ? AND status = 'pending' LIMIT 1");
            $pendingStmt->execute([$resultss['id_invoice']]);
            if ($pendingStmt->fetchColumn()) {
                $pdo->rollBack();
                error_log("removeexpire: Service '$line' has a pending reservation, skipping deletion");
                continue;
            }
            $previousStatus = $currentStatus;
            $claimStmt = $pdo->prepare("
                UPDATE invoice SET status = 'pending_delete'
                WHERE id_invoice = ? AND (status = 'end_of_time' OR status = 'end_of_volume')
            ");
            $claimStmt->execute([$resultss['id_invoice']]);
            if ($claimStmt->rowCount() === 0) {
                $pdo->rollBack();
                continue;
            }
            $pdo->commit();
            $justClaimed = true;
        } else {
            $pdo->rollBack();
            error_log("removeexpire: Service '$line' status changed to '$currentStatus', skipping deletion");
            continue;
        }

        $marzban_list_get = select("marzban_panel", "*", "name_panel", $resultss['Service_location'], "select");
        if ($marzban_list_get == false) {
            error_log("removeexpire: Panel '{$resultss['Service_location']}' not found for user '$line'; soft-deleting database row only");
        } else {
            try {
                $removeResult = $ManagePanel->RemoveUser($resultss['Service_location'], $line);
            } catch (Exception $panelError) {
                $failed++;
                error_log("removeexpire: Panel removal exception for '$line': " . $panelError->getMessage());
                if ($justClaimed) {
                    $revert = $pdo->prepare("UPDATE invoice SET status = ? WHERE id_invoice = ? AND status = 'pending_delete'");
                    $revert->execute([$previousStatus, $resultss['id_invoice']]);
                }
                continue;
            }
            if (!panelUserRemoved($removeResult)) {
                $failed++;
                $msg = is_array($removeResult) ? ($removeResult['msg'] ?? json_encode($removeResult)) : 'unknown';
                error_log("removeexpire: Panel removal failed for '$line': $msg; will retry next run");
                if ($justClaimed) {
                    $revert = $pdo->prepare("UPDATE invoice SET status = ? WHERE id_invoice = ? AND status = 'pending_delete'");
                    $revert->execute([$previousStatus, $resultss['id_invoice']]);
                }
                continue;
            }
            error_log("removeexpire: Removed user '$line' from panel '{$resultss['Service_location']}'");
        }

        $updateStmt = $pdo->prepare("
            UPDATE invoice
            SET status = 'deleted', deleted_at = NOW()
            WHERE username = :username
            AND id_invoice = :id_invoice
            AND status = 'pending_delete'
        ");
        $updateStmt->execute([
            ':username' => $line,
            ':id_invoice' => $resultss['id_invoice']
        ]);

        if ($updateStmt->rowCount() === 0) {
            $failed++;
            error_log("removeexpire: Failed to mark '$line' deleted after panel removal; will retry");
            continue;
        }

        try {
            sendmessage(
                $resultss['id_user'],
                sprintf($textbotlang['users']['cron']['removeexpire'], $resultss['username']),
                null,
                'HTML'
            );
        } catch (Exception $e) {
            error_log("removeexpire: Failed to send notification to user {$resultss['id_user']}: " . $e->getMessage());
        }

        if (strlen($setting['Channel_Report']) > 0) {
            try {
                $invoiceStatus = $resultss['Status'] ?? $resultss['status'] ?? '';
                $status_var = [
                    'end_of_time' => $textbotlang['users']['status']['expired'],
                    'end_of_volume' => $textbotlang['users']['status']['limited'],
                ][$invoiceStatus] ?? $invoiceStatus;

                sendmessage(
                    $setting['Channel_Report'],
                    sprintf($textbotlang['Admin']['Report']['reportremovecron'], $line, $status_var),
                    null,
                    'HTML'
                );
            } catch (Exception $e) {
                error_log("removeexpire: Failed to send report to channel: " . $e->getMessage());
            }
        }

        $deleted++;
        error_log("removeexpire: Successfully soft-deleted service '$line' (expired $days_expired days ago)");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        error_log("removeexpire: Error processing service '$line': " . $e->getMessage());
    }
}

error_log("removeexpire: Processed=$processed, Deleted=$deleted, Failed=$failed");
