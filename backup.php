<?php
/**
 * backup.php — packages the MySQL database and the bot's source tree into a
 * single zip and ships it to the private backup channel as a Telegram document.
 *
 * Used by both cron/cronbackup.php (daily) and the admin panel button in
 * admin.php. Requires config.php/botapi.php/functions.php to already be loaded
 * by the caller (both entry points do).
 */

/**
 * Files and directories never included in the code archive, relative to the
 * project root. vendor/ is reinstallable from composer.json; .git and
 * .codegraph are large and derivable; error_log and *.bak are local noise.
 */const BACKUP_EXCLUDES = [
    'vendor',
    '.git',
    '.codegraph',
    'node_modules',
    'error_log',
    'backups',
];

/** Telegram's hard ceiling for bot-uploaded documents. */
const BACKUP_MAX_BYTES = 49 * 1024 * 1024;

/**
 * Dumps the whole database to a .sql file using PDO only.
 *
 * Deliberately does not shell out to mysqldump: shell_exec is disabled on a
 * lot of the shared hosts this bot runs on (admin.php already probes for that),
 * and the binary often isn't installed even when exec is allowed.
 *
 * @return string Path of the written .sql file.
 */
function backupDumpDatabase(string $targetSqlPath): string
{
    global $pdo, $dbname;

    $handle = fopen($targetSqlPath, 'w');
    if (!$handle) {
        throw new \RuntimeException("cannot open $targetSqlPath for writing");
    }

    fwrite($handle, "-- Backup of `$dbname`\n");
    fwrite($handle, "-- Generated " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "SET NAMES utf8mb4;\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $createRow = $pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM);
        fwrite($handle, "DROP TABLE IF EXISTS $quoted;\n");
        fwrite($handle, $createRow[1] . ";\n\n");

        // Written row-by-row instead of fetchAll(): invoice/user tables grow
        // large and materializing them all would hit the PHP memory limit.
        $rows = $pdo->query("SELECT * FROM $quoted");
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            fwrite($handle, "INSERT INTO $quoted VALUES (" . implode(',', $values) . ");\n");
        }
        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    return $targetSqlPath;
}

/**
 * Adds the project's source tree to an open zip, skipping BACKUP_EXCLUDES.
 */
function backupAddCodebase(ZipArchive $zip, string $rootDir): void
{
    $rootDir = rtrim(str_replace('\\', '/', realpath($rootDir)), '/');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $absolute = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($absolute, strlen($rootDir)), '/');

        $topSegment = explode('/', $relative)[0];
        if (in_array($topSegment, BACKUP_EXCLUDES, true)) {
            continue;
        }
        if (preg_match('/\.bak[0-9]*$/', $relative) || preg_match('/\.lock$/', $relative)) {
            continue;
        }

        if ($item->isDir()) {
            $zip->addEmptyDir('code/' . $relative);
        } else {
            $zip->addFile($absolute, 'code/' . $relative);
        }
    }
}

/**
 * Builds the backup zip and sends it to the backup channel.
 *
 * @param string|null $override Chat id/@username to send to instead of the
 *                             configured setting.Channel_Backup (unused by the
 *                             normal flows; handy for a one-off test send).
 * @return array{ok: bool, error?: string, file?: string, bytes?: int}
 */
function runBackup(?string $override = null): array
{
    $setting = select("setting", "*", null, null, "select");
    $channel = $override ?? ($setting['Channel_Backup'] ?? '');
    if (strlen(trim((string) $channel)) === 0) {
        return ['ok' => false, 'error' => 'no_channel'];
    }

    $rootDir  = __DIR__;
    $workDir  = $rootDir . '/backups';
    if (!is_dir($workDir) && !mkdir($workDir, 0700, true)) {
        return ['ok' => false, 'error' => 'cannot_create_workdir'];
    }
    // The project root is the webroot, so the archive is briefly reachable over
    // HTTP while it's being built. It contains .env and a full DB dump.
    if (!file_exists($workDir . '/.htaccess')) {
        file_put_contents($workDir . '/.htaccess', "Require all denied\nDeny from all\n");
    }

    $stamp   = date('Y-m-d_H-i-s');
    $sqlPath = $workDir . "/db_$stamp.sql";
    $zipPath = $workDir . "/backup_$stamp.zip";

    try {
        backupDumpDatabase($sqlPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('cannot create zip');
        }
        $zip->addFile($sqlPath, 'database.sql');
        backupAddCodebase($zip, $rootDir);
        $zip->close();

        $bytes = filesize($zipPath);
        if ($bytes > BACKUP_MAX_BYTES) {
            return ['ok' => false, 'error' => 'too_large', 'bytes' => $bytes];
        }

        $caption = "Backup $stamp\nDB + code\n" . round($bytes / 1048576, 2) . " MB";
        $response = sendDocument($channel, $zipPath, $caption);
        if (!isset($response['ok']) || !$response['ok']) {
            return ['ok' => false, 'error' => 'telegram_failed'];
        }

        return ['ok' => true, 'file' => basename($zipPath), 'bytes' => $bytes];
    } catch (\Throwable $e) {
        error_log('backup.php: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'exception'];
    } finally {
        // The archive carries DB credentials (.env) — never leave a copy behind
        // on a webroot-served directory.
        @unlink($sqlPath);
        @unlink($zipPath);
    }
}
