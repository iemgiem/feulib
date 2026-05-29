<?php
declare(strict_types=1);

/**
 * POST /index.php?p=api.backup
 *
 * Generates a full SQL dump of the database and streams it as a downloadable
 * file. Logs the backup run to backup_log. Admin-only.
 *
 * The dump is built entirely through PDO — no mysqldump binary required.
 */

require_login();
require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_check();

$pdo = db();

// --- Build SQL dump -----------------------------------------------------------

$lines   = [];
$lines[] = '-- FEU LFMS Database Backup';
$lines[] = '-- Generated: ' . date('Y-m-d H:i:s T');
$lines[] = '-- Host: ' . (cfg('db')['host'] ?? 'localhost');
$lines[] = '-- Database: ' . (cfg('db')['name'] ?? 'lfms');
$lines[] = '';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$lines[] = '';

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN, 0);

foreach ($tables as $raw_table) {
    $table = (string) $raw_table;

    // CREATE TABLE
    $create_row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    $create_sql = $create_row['Create Table'] ?? '';

    $lines[] = '-- -----------------------------------------------------------------';
    $lines[] = "-- Table: `{$table}`";
    $lines[] = '-- -----------------------------------------------------------------';
    $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
    $lines[] = $create_sql . ';';
    $lines[] = '';

    // INSERT data
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $cols     = array_keys($rows[0]);
        $col_list = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));

        foreach ($rows as $row) {
            $values = array_map(static function ($v) use ($pdo): string {
                if ($v === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $v);
            }, array_values($row));

            $lines[] = "INSERT INTO `{$table}` ({$col_list}) VALUES (" . implode(', ', $values) . ');';
        }

        $lines[] = '';
    }
}

$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';

$sql_dump  = implode("\n", $lines);
$file_size = strlen($sql_dump);
$filename  = 'lfms-backup-' . date('Ymd-His') . '.sql';

// --- Log the backup run -------------------------------------------------------

$actor_id = $_SESSION['user_id'] ?? null;

q(
    'INSERT INTO backup_log (actor_account_id, file_size_bytes) VALUES (?, ?)',
    [$actor_id, $file_size]
);

audit_log('backup.download', 'backup_log', db_last_id());

// --- Stream the file ----------------------------------------------------------

if (headers_sent($hfile, $hline)) {
    http_response_code(500);
    exit("Cannot send backup: headers already sent at {$hfile}:{$hline}");
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $sql_dump;
exit;
