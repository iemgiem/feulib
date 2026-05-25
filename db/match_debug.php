<?php
declare(strict_types=1);

/**
 * Matching algorithm diagnostic — prints a scoring table for every
 * lost × found pair currently in the database. Not a test (no assertions,
 * no pass/fail); a visual tuning aid for the weights / threshold knobs
 * exposed under Admin → Settings → Match Scoring.
 *
 *   php db/match_debug.php
 *
 * Read-only — nothing is written to the database or the audit log.
 *
 * Typical use: after a weight change, re-run against the seed dataset
 * and confirm the seeded LFMS-2026-F-00001 ↔ LFMS-2026-00001 pair still
 * lands at or above the threshold.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "match_debug.php must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../lib/bootstrap.php';

$found_rows = q_all(
    'SELECT f.*, sl.description AS storage_description
       FROM found_reports f
  LEFT JOIN storage_locations sl ON sl.id = f.storage_location_id
   ORDER BY f.id'
);
$lost_rows = q_all('SELECT * FROM lost_reports ORDER BY id');

$weights   = match_weights();
$threshold = match_threshold();

echo "FEU LFMS — matching algorithm diagnostic\n";
echo str_repeat('=', 78), "\n";
echo "Weights:    ", json_encode($weights), "\n";
echo "Threshold:  ", $threshold, "\n";
echo "Lost open:  ", count(array_filter($lost_rows, fn ($r) => $r['status'] === 'open')),
     " (of ", count($lost_rows), " total)\n";
echo "Found:      ", count($found_rows), "\n\n";

if (!$found_rows || !$lost_rows) {
    echo "No data to score. Load db/seed.sql first.\n";
    exit(0);
}

printf(
    "%-18s  %-20s  %5s   %3s %3s %3s %3s %3s   %s\n",
    'LOST', 'FOUND', 'SCORE', 'CAT', 'COL', 'LOC', 'DAT', 'DES', 'RESULT'
);
echo str_repeat('-', 78), "\n";

$hits = 0;
foreach ($found_rows as $f) {
    foreach ($lost_rows as $l) {
        $r  = score_pair($l, $f);
        $fx = $r['factors'];
        $is_hit = $r['score'] >= $threshold;
        if ($is_hit) {
            $hits++;
        }
        printf(
            "%-18s  %-20s  %5d   %3d %3d %3d %3d %3d   %s\n",
            $l['ref_number'],
            $f['ref_number'],
            $r['score'],
            $fx['category'], $fx['color'], $fx['location'], $fx['date'], $fx['description'],
            $is_hit ? 'CANDIDATE' : '-'
        );
    }
    echo str_repeat('-', 78), "\n";
}

echo "\n", $hits, " pair(s) at or above threshold.\n";
