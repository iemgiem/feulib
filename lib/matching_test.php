<?php
declare(strict_types=1);

/**
 * CLI test harness for the matching service.
 *
 *   php lib/matching_test.php
 *
 * Loads every found_report + every lost_report in the database, runs the
 * scoring algorithm against each pair, and prints a breakdown table. Nothing
 * is written to the database — this is purely for tuning weights/threshold
 * without polluting state.
 *
 * Run after `SOURCE seed.sql` to verify the seeded LFMS-2026-F-00001 ↔
 * LFMS-2026-00001 pair still hits the configured threshold after a weight
 * change in Admin → Settings → Match Scoring.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "matching_test.php must be run from the command line.\n");
    exit(1);
}

// session_boot() inside bootstrap.php works fine in CLI (cookie params are
// no-ops; PHP falls back to file-based storage). We don't depend on session
// state here — generate_candidates() isn't called from this script.
require __DIR__ . '/bootstrap.php';

$found_rows = q_all(
    'SELECT f.*, sl.description AS storage_description
       FROM found_reports f
  LEFT JOIN storage_locations sl ON sl.id = f.storage_location_id
   ORDER BY f.id'
);
$lost_rows = q_all('SELECT * FROM lost_reports ORDER BY id');

$weights   = match_weights();
$threshold = match_threshold();

echo "FEU LFMS — matching algorithm test harness\n";
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
