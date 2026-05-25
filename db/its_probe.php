<?php
declare(strict_types=1);

/**
 * ITS endpoint probe — read-only smoke test.
 *
 * Hits the configured ITS endpoint(s), validates the response, and prints
 * a summary including a sample normalised row. Does NOT touch the database
 * and does NOT write to the audit log.
 *
 * Intended use: after dropping real ITS credentials into config.php, run
 * this before scheduling db/sync_its.php so a misconfig is caught against
 * a dry run rather than during the first nightly write.
 *
 * Usage:
 *   php db/its_probe.php                  # probe both students + staff
 *   php db/its_probe.php --students       # probe students only
 *   php db/its_probe.php --staff          # probe staff only
 *
 * Exit code: 0 if every probed endpoint succeeds, 1 if any failed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "its_probe.php must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$args = $argv ?? [];
$do_students = in_array('--students', $args, true);
$do_staff    = in_array('--staff',    $args, true);
if (!$do_students && !$do_staff) {
    // No flag means "both" — the friendly default.
    $do_students = $do_staff = true;
}

$kinds = [];
if ($do_students) { $kinds[] = 'students'; }
if ($do_staff)    { $kinds[] = 'staff'; }

$any_failure = false;

foreach ($kinds as $kind) {
    $r = its_probe($kind);

    fwrite(STDOUT, "----- ITS probe: {$kind} -----\n");
    fwrite(STDOUT, sprintf("  url        : %s\n", $r['url'] !== '' ? $r['url'] : '(not configured)'));
    fwrite(STDOUT, sprintf("  auth_mode  : %s\n", $r['auth_mode']));

    if ($r['using_dev_token']) {
        fwrite(STDOUT, "  WARNING    : its.auth_value is still the shipped dev placeholder.\n");
    }

    if (!$r['success']) {
        fwrite(STDERR, sprintf("  FAILED     : %s\n", $r['error'] ?? 'unknown error'));
        $any_failure = true;
        continue;
    }

    fwrite(STDOUT, sprintf(
        "  rows       : %d raw, %d parsed, %d active\n",
        $r['raw_count'],
        $r['normalised_count'],
        $r['active_count']
    ));

    if ($r['normalised_count'] === 0) {
        fwrite(STDOUT, "  NOTE       : endpoint returned data but no rows survived normalisation.\n");
        fwrite(STDOUT, "               Check that rows have an `id` and a `name` / `full_name` field.\n");
    }

    if ($r['sample'] !== null) {
        // Print a tidy one-line summary of the first normalised row. raw_json
        // is bulky, drop it.
        $s = $r['sample'];
        unset($s['raw_json']);
        fwrite(STDOUT, sprintf(
            "  sample     : its_id=%s, role=%s, status=%s, name=%s, email=%s\n",
            $s['its_id'],
            $s['role'],
            $s['status'],
            $s['full_name'],
            $s['email'] ?? '(none)'
        ));
    }
}

fwrite(STDOUT, $any_failure ? "\nOne or more probes failed.\n" : "\nAll probes succeeded.\n");
exit($any_failure ? 1 : 0);
