<?php
declare(strict_types=1);

/**
 * Nightly ITS sync — pulls the latest student / staff / faculty roster
 * from the external ITS endpoint and upserts into the local its_users
 * table. Same logic as the Admin → ITS Integration "Fetch / Sync" button.
 *
 * Schedule via Windows Task Scheduler:
 *   schtasks /Create /SC DAILY /TN "LFMS Sync ITS" ^
 *            /TR "php C:\xampp\htdocs\feulib\db\sync_its.php" /ST 02:00
 *
 * Usage:
 *   php db/sync_its.php
 *
 * Output: one summary line per run.
 * Exit code: 0 on success, 1 on any failure.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "sync_its.php must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$summary = its_sync();

if ($summary['success']) {
    fwrite(STDOUT, sprintf(
        "[%s] ITS sync OK — inserted=%d, updated=%d, deactivated=%d, fetched_total=%d (started %s)\n",
        $summary['finished_at'],
        $summary['inserted'],
        $summary['updated'],
        $summary['deactivated'],
        $summary['fetched_total'],
        $summary['started_at']
    ));
    exit(0);
}

fwrite(STDERR, sprintf(
    "[%s] ITS sync FAILED: %s\n",
    $summary['finished_at'],
    (string) ($summary['error'] ?? 'unknown error')
));
exit(1);
