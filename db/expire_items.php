<?php
declare(strict_types=1);

/**
 * Daily expiry job — marks found items past the holding period as EXPIRED.
 *
 * Scheduled via Windows Task Scheduler at noon, Mon–Sat:
 *   schtasks /Create /SC WEEKLY /D MON,TUE,WED,THU,FRI,SAT ^
 *            /TN "LFMS Expire Items" /TR "php C:\xampp\htdocs\feulib\db\expire_items.php" ^
 *            /ST 12:00
 *
 * Usage:
 *   php db/expire_items.php          # run normally
 *   php db/expire_items.php --dry    # show what would change without writing
 *
 * Behaviour:
 *   - Reads holding_period_days from the settings table (default 365).
 *   - Selects every found_report with status='open' AND
 *     date_found <= CURDATE() - INTERVAL <holding_period_days> DAY.
 *   - Items in status 'matched' are NOT expired automatically — a match is
 *     in-flight and staff must rule on it before the item can be expired.
 *   - For each item: status → expired, related open lost_reports' matches
 *     that have status 'pending'|'needs_info' against this item are rejected
 *     (system review), and one audit row is appended per item.
 *
 * Output: one log line per affected item, plus a summary at the end.
 * Exit code: 0 on success, 1 on any database error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "expire_items.php must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$dry_run = in_array('--dry', $argv ?? [], true);

// Resolve holding-period setting (fallback: 365 days = 1 year).
$holding_period = (int) (q_value(
    "SELECT value FROM settings WHERE key_name = 'holding_period_days' LIMIT 1"
) ?? 365);
if ($holding_period < 1) {
    $holding_period = 365;
}

fwrite(STDOUT, sprintf(
    "[%s] expire_items: holding_period=%d days%s\n",
    date('Y-m-d H:i:s'),
    $holding_period,
    $dry_run ? ' (DRY RUN)' : ''
));

$candidates = q_all(
    "SELECT id, ref_number, date_found, category
       FROM found_reports
      WHERE status = 'open'
        AND date_found <= (CURDATE() - INTERVAL ? DAY)
      ORDER BY id",
    [$holding_period]
);

if (!$candidates) {
    fwrite(STDOUT, "Nothing to expire. Done.\n");
    exit(0);
}

fwrite(STDOUT, sprintf("Found %d item(s) to expire.\n", count($candidates)));

$expired_count = 0;
$matches_rejected = 0;
$errors = 0;

foreach ($candidates as $item) {
    $id  = (int) $item['id'];
    $ref = (string) $item['ref_number'];

    try {
        if (!$dry_run) {
            db_transaction(function () use ($id, &$matches_rejected) {
                // Reject any pending/needs_info matches for this found item.
                $pending = q_all(
                    "SELECT id, status FROM matches
                      WHERE found_report_id = ? AND status IN ('pending','needs_info')",
                    [$id]
                );
                foreach ($pending as $m) {
                    q(
                        "UPDATE matches
                            SET status = 'rejected',
                                review_notes = CONCAT(COALESCE(review_notes, ''),
                                    CASE WHEN review_notes IS NULL OR review_notes = '' THEN '' ELSE '\n' END,
                                    '[system] auto-rejected because found item expired'),
                                updated_at = NOW()
                          WHERE id = ?",
                        [(int) $m['id']]
                    );
                    audit_log('match.system_reject_expired', 'match', (int) $m['id'], [
                        'status' => [(string) $m['status'], 'rejected'],
                    ]);
                    $matches_rejected++;
                }

                // Expire the found item.
                q(
                    "UPDATE found_reports
                        SET status = 'expired', updated_at = NOW()
                      WHERE id = ? AND status = 'open'",
                    [$id]
                );
                audit_log('found_report.expire', 'found_report', $id, [
                    'status' => ['open', 'expired'],
                ]);
            });
        }
        $expired_count++;
        fwrite(STDOUT, sprintf(
            "  expired %-30s (date_found=%s, category=%s)\n",
            $ref,
            (string) $item['date_found'],
            (string) $item['category']
        ));
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, sprintf(
            "  ERROR expiring %s (id=%d): %s\n",
            $ref,
            $id,
            $e->getMessage()
        ));
    }
}

fwrite(STDOUT, sprintf(
    "[%s] Done. Expired=%d, matches_rejected=%d, errors=%d%s\n",
    date('Y-m-d H:i:s'),
    $expired_count,
    $matches_rejected,
    $errors,
    $dry_run ? ' (DRY RUN — no writes performed)' : ''
));

exit($errors > 0 ? 1 : 0);
