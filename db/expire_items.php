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
 *   - Expires every OPEN found_report (by date_found) and OPEN lost_report
 *     (by date_lost) older than the holding period → status 'expired'.
 *     Records in status 'matched' are left alone — a match is in-flight and
 *     staff must rule on it first.
 *   - Cascade: any 'pending'|'needs_info' match tied to a report being expired
 *     is auto-rejected (system review).
 *   - Stale matches: any remaining 'pending'|'needs_info' match older than the
 *     holding period is auto-rejected too. (The matches table has no 'expired'
 *     state, so a system rejection is the terminal outcome.)
 *   - One audit row is appended per affected record.
 *
 * Output: one log line per affected record, plus a summary at the end.
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

fwrite(STDOUT, sprintf("Found %d found-item(s) to expire.\n", count($candidates)));

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

// ---------------------------------------------------------------------------
// Lost reports — same holding-period rule (by date_lost), only 'open' ones.
// ---------------------------------------------------------------------------
$lost_candidates = q_all(
    "SELECT id, ref_number, date_lost, category
       FROM lost_reports
      WHERE status = 'open'
        AND date_lost <= (CURDATE() - INTERVAL ? DAY)
      ORDER BY id",
    [$holding_period]
);
fwrite(STDOUT, sprintf("Found %d lost-report(s) to expire.\n", count($lost_candidates)));

$lost_expired_count = 0;
foreach ($lost_candidates as $item) {
    $id  = (int) $item['id'];
    $ref = (string) $item['ref_number'];

    try {
        if (!$dry_run) {
            db_transaction(function () use ($id, &$matches_rejected) {
                // Cascade-reject this lost report's open matches.
                $pending = q_all(
                    "SELECT id, status FROM matches
                      WHERE lost_report_id = ? AND status IN ('pending','needs_info')",
                    [$id]
                );
                foreach ($pending as $m) {
                    q(
                        "UPDATE matches
                            SET status = 'rejected',
                                review_notes = CONCAT(COALESCE(review_notes, ''),
                                    CASE WHEN review_notes IS NULL OR review_notes = '' THEN '' ELSE '\n' END,
                                    '[system] auto-rejected because lost report expired'),
                                updated_at = NOW()
                          WHERE id = ?",
                        [(int) $m['id']]
                    );
                    audit_log('match.system_reject_expired', 'match', (int) $m['id'], [
                        'status' => [(string) $m['status'], 'rejected'],
                    ]);
                    $matches_rejected++;
                }

                q(
                    "UPDATE lost_reports
                        SET status = 'expired', updated_at = NOW()
                      WHERE id = ? AND status = 'open'",
                    [$id]
                );
                audit_log('lost_report.expire', 'lost_report', $id, [
                    'status' => ['open', 'expired'],
                ]);
            });
        }
        $lost_expired_count++;
        fwrite(STDOUT, sprintf(
            "  expired lost %-25s (date_lost=%s, category=%s)\n",
            $ref,
            (string) $item['date_lost'],
            (string) $item['category']
        ));
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, sprintf("  ERROR expiring lost %s (id=%d): %s\n", $ref, $id, $e->getMessage()));
    }
}

// ---------------------------------------------------------------------------
// Stale matches — 'pending'|'needs_info' older than the holding period that
// weren't already closed by the report expiries above. No 'expired' state
// exists for matches, so the terminal outcome is a system rejection.
// ---------------------------------------------------------------------------
$stale_matches = q_all(
    "SELECT id, status FROM matches
      WHERE status IN ('pending','needs_info')
        AND created_at <= (NOW() - INTERVAL ? DAY)
      ORDER BY id",
    [$holding_period]
);
fwrite(STDOUT, sprintf("Found %d stale match(es) to close.\n", count($stale_matches)));

foreach ($stale_matches as $m) {
    $mid = (int) $m['id'];
    try {
        if (!$dry_run) {
            db_transaction(function () use ($mid, $m, &$matches_rejected) {
                q(
                    "UPDATE matches
                        SET status = 'rejected',
                            review_notes = CONCAT(COALESCE(review_notes, ''),
                                CASE WHEN review_notes IS NULL OR review_notes = '' THEN '' ELSE '\n' END,
                                '[system] auto-rejected: pending past the holding period'),
                            updated_at = NOW()
                      WHERE id = ? AND status IN ('pending','needs_info')",
                    [$mid]
                );
                audit_log('match.system_reject_expired', 'match', $mid, [
                    'status' => [(string) $m['status'], 'rejected'],
                ]);
                $matches_rejected++;
            });
        }
        fwrite(STDOUT, sprintf("  closed stale match #%d\n", $mid));
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, sprintf("  ERROR closing match #%d: %s\n", $mid, $e->getMessage()));
    }
}

fwrite(STDOUT, sprintf(
    "[%s] Done. Found-expired=%d, lost-expired=%d, matches_rejected=%d, errors=%d%s\n",
    date('Y-m-d H:i:s'),
    $expired_count,
    $lost_expired_count,
    $matches_rejected,
    $errors,
    $dry_run ? ' (DRY RUN — no writes performed)' : ''
));

exit($errors > 0 ? 1 : 0);
