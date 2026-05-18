<?php
declare(strict_types=1);

/**
 * Matching service — pairs Lost reports and Found items based on a
 * configurable scoring algorithm.
 *
 *   $created = generate_candidates_for_found($found_report_id);  // staff logs a Found
 *   $created = generate_candidates_for_lost($lost_report_id);    // user files a Lost
 *
 * Both entry points compute a 0..100 score per candidate pair, persist any
 * candidate at or above the threshold into `matches` with status='pending',
 * and return the number of NEW matches written (existing pairs are preserved
 * by the `uq_match_pair` unique index, so re-running is idempotent).
 *
 * Weights + threshold come from the `settings` table when present, with the
 * hardcoded defaults below as a fallback. To tune the algorithm, edit settings
 * via Admin → Settings → Match Scoring (Task 19) — no code changes needed.
 *
 * Suspicious-duplicate heuristic: if a lost report's reporter has filed
 * another lost report with the same category + color in the last 24 hours,
 * every match involving that lost report is flagged is_suspicious=1 and the
 * lost report itself is flagged. The cluster set is fetched once per call,
 * not per candidate.
 */

const MATCH_WEIGHT_DEFAULTS = [
    'category'    => 30,
    'color'       => 20,
    'location'    => 15,
    'date'        => 10,
    'description' => 25,
];

const MATCH_THRESHOLD_DEFAULT = 30;
const MATCH_DATE_WINDOW_DAYS  = 7;

const MATCH_STOPWORDS = [
    'a','an','and','any','are','as','at','be','been','being','but','by','can',
    'do','does','for','from','had','has','have','he','her','his','i','if','in',
    'into','is','it','its','me','my','no','not','of','on','one','or','our','out',
    'she','so','some','than','that','the','their','them','there','these','they',
    'this','those','to','was','we','were','what','when','where','which','who',
    'will','with','you','your',
];

/**
 * Current scoring weights, merging `settings` rows over the defaults.
 * Cached per-request — admins changing weights mid-request won't see the
 * new values until the next page load (acceptable; weights rarely change).
 */
function match_weights(): array
{
    static $weights = null;
    if ($weights !== null) {
        return $weights;
    }
    $weights = MATCH_WEIGHT_DEFAULTS;
    $keys = [
        'match_weight_category'    => 'category',
        'match_weight_color'       => 'color',
        'match_weight_location'    => 'location',
        'match_weight_date'        => 'date',
        'match_weight_description' => 'description',
    ];
    $rows = q_all(
        'SELECT key_name, value FROM settings
          WHERE key_name IN (?, ?, ?, ?, ?)',
        array_keys($keys)
    );
    foreach ($rows as $row) {
        $factor = $keys[$row['key_name']] ?? null;
        if ($factor !== null && is_numeric($row['value'])) {
            $weights[$factor] = max(0, (int) $row['value']);
        }
    }
    return $weights;
}

/**
 * Minimum total score for a candidate to be persisted.
 */
function match_threshold(): int
{
    $raw = q_value('SELECT value FROM settings WHERE key_name = ?', ['match_threshold']);
    if ($raw === null || !is_numeric($raw)) {
        return MATCH_THRESHOLD_DEFAULT;
    }
    return max(0, (int) $raw);
}

/**
 * Lowercase + tokenize a free-text field. Drops stop words and 1-char tokens.
 * Returns unique tokens in insertion order.
 */
function match_tokens(string $text): array
{
    $parts = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];
    $set = [];
    foreach ($parts as $tok) {
        if ($tok === '' || strlen($tok) < 2) {
            continue;
        }
        if (in_array($tok, MATCH_STOPWORDS, true)) {
            continue;
        }
        $set[$tok] = true;
    }
    return array_keys($set);
}

/**
 * Score one lost+found pair.
 *
 * @param array $lost   A row from lost_reports.
 * @param array $found  A row from found_reports; must include 'storage_description'
 *                      (the joined storage_locations.description).
 * @return array{score:int, factors:array<string,int>}
 */
function score_pair(array $lost, array $found): array
{
    $w = match_weights();

    $factors = [
        'category'    => 0,
        'color'       => 0,
        'location'    => 0,
        'date'        => 0,
        'description' => 0,
    ];

    if (strcasecmp((string) $lost['category'], (string) $found['category']) === 0) {
        $factors['category'] = $w['category'];
    }

    if (strcasecmp(trim((string) $lost['color']), trim((string) $found['color'])) === 0) {
        $factors['color'] = $w['color'];
    }

    $lost_loc  = match_tokens((string) ($lost['last_seen_location'] ?? ''));
    $found_loc = match_tokens((string) ($found['storage_description'] ?? ''));
    if ($lost_loc && $found_loc && array_intersect($lost_loc, $found_loc)) {
        $factors['location'] = $w['location'];
    }

    if (!empty($lost['date_lost']) && !empty($found['date_found'])) {
        $a = strtotime((string) $lost['date_lost']);
        $b = strtotime((string) $found['date_found']);
        if ($a !== false && $b !== false) {
            $diff_days = abs($a - $b) / 86400;
            if ($diff_days <= MATCH_DATE_WINDOW_DAYS) {
                $factors['date'] = $w['date'];
            }
        }
    }

    $a_toks = match_tokens((string) ($lost['description'] ?? ''));
    $b_toks = match_tokens((string) ($found['description'] ?? ''));
    if ($a_toks && $b_toks) {
        $intersection = count(array_intersect($a_toks, $b_toks));
        $union        = count(array_unique(array_merge($a_toks, $b_toks)));
        $jaccard      = $union > 0 ? $intersection / $union : 0.0;
        $factors['description'] = (int) round($jaccard * $w['description']);
    }

    return [
        'score'   => (int) array_sum($factors),
        'factors' => $factors,
    ];
}

/**
 * Pre-fetch the set of (reporter, category, color) tuples that appear in 2+
 * lost reports filed in the last 24h. The returned array is keyed by
 * "{reporter_id}|{lower_trim_category}|{lower_trim_color}" with value `true`,
 * so membership can be tested with isset() in O(1).
 *
 * Called once per generate_candidates_* invocation so the dup-check is a
 * single round-trip regardless of how many candidates are iterated.
 */
function _match_suspicious_clusters(): array
{
    $rows = q_all(
        "SELECT reporter_account_id,
                LOWER(TRIM(category)) AS category,
                LOWER(TRIM(color))    AS color
           FROM lost_reports
          WHERE created_at >= (NOW() - INTERVAL 1 DAY)
       GROUP BY reporter_account_id, LOWER(TRIM(category)), LOWER(TRIM(color))
         HAVING COUNT(*) > 1"
    );
    $set = [];
    foreach ($rows as $r) {
        $key = $r['reporter_account_id'] . '|' . $r['category'] . '|' . $r['color'];
        $set[$key] = true;
    }
    return $set;
}

/**
 * True if the given lost report belongs to a same-reporter / same-category /
 * same-color cluster of 2+ filings within the last 24h.
 */
function _lost_is_suspicious(array $lost, array $cluster_set): bool
{
    $key = $lost['reporter_account_id']
         . '|' . strtolower(trim((string) $lost['category']))
         . '|' . strtolower(trim((string) $lost['color']));
    return isset($cluster_set[$key]);
}

/**
 * Persist one match row. Returns true if a new row was inserted, false if the
 * pair was already in `matches` (uq_match_pair caught it). Writes the audit
 * trail and propagates the suspicious flag to lost_reports on first insert.
 *
 * Note on PDO::lastInsertId after INSERT IGNORE: the value is NOT updated
 * when the IGNORE skips, so the caller MUST gate on rowCount() before reading
 * db_last_id(). The guard below preserves audit-row correctness.
 */
function _persist_match(array $lost, array $found, array $score_result, bool $suspicious): bool
{
    $stmt = q(
        'INSERT IGNORE INTO matches
            (lost_report_id, found_report_id, score, factors_json, status, is_suspicious)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            (int) $lost['id'],
            (int) $found['id'],
            (int) $score_result['score'],
            json_encode($score_result['factors'], JSON_UNESCAPED_UNICODE),
            'pending',
            $suspicious ? 1 : 0,
        ]
    );
    if ($stmt->rowCount() === 0) {
        return false;
    }

    $match_id = db_last_id();
    audit_log('match.generate', 'match', $match_id, [
        'score'         => $score_result['score'],
        'factors'       => $score_result['factors'],
        'lost_id'       => (int) $lost['id'],
        'found_id'      => (int) $found['id'],
        'is_suspicious' => $suspicious,
    ]);

    if ($suspicious && (int) ($lost['is_suspicious'] ?? 0) === 0) {
        q('UPDATE lost_reports SET is_suspicious = 1 WHERE id = ?', [(int) $lost['id']]);
    }
    return true;
}

/**
 * Match the given Found report against every open Lost report. Persists
 * candidates at or above the threshold. Returns count of new matches.
 *
 * Idempotent: re-running for the same Found report yields 0 new matches.
 */
function generate_candidates_for_found(int $found_report_id): int
{
    $found = q_one(
        'SELECT f.*, sl.description AS storage_description
           FROM found_reports f
      LEFT JOIN storage_locations sl ON sl.id = f.storage_location_id
          WHERE f.id = ?
          LIMIT 1',
        [$found_report_id]
    );
    if ($found === null) {
        return 0;
    }

    $candidates = q_all(
        "SELECT * FROM lost_reports WHERE status = 'open' ORDER BY id"
    );
    if (!$candidates) {
        return 0;
    }

    $cluster_set = _match_suspicious_clusters();
    $threshold   = match_threshold();
    $created     = 0;

    foreach ($candidates as $lost) {
        $result = score_pair($lost, $found);
        if ($result['score'] < $threshold) {
            continue;
        }
        $suspicious = _lost_is_suspicious($lost, $cluster_set);
        if (_persist_match($lost, $found, $result, $suspicious)) {
            $created++;
        }
    }

    return $created;
}

/**
 * Match the given Lost report against every open Found item. Persists
 * candidates at or above the threshold. Returns count of new matches.
 *
 * Symmetric counterpart to generate_candidates_for_found — needed so that a
 * Lost report filed AFTER a matching Found item still proposes a match,
 * instead of waiting for staff to touch the found row.
 */
function generate_candidates_for_lost(int $lost_report_id): int
{
    $lost = q_one(
        "SELECT * FROM lost_reports WHERE id = ? LIMIT 1",
        [$lost_report_id]
    );
    if ($lost === null || $lost['status'] !== 'open') {
        return 0;
    }

    $candidates = q_all(
        "SELECT f.*, sl.description AS storage_description
           FROM found_reports f
      LEFT JOIN storage_locations sl ON sl.id = f.storage_location_id
          WHERE f.status = 'open'
          ORDER BY f.id"
    );
    if (!$candidates) {
        return 0;
    }

    $cluster_set = _match_suspicious_clusters();
    $suspicious  = _lost_is_suspicious($lost, $cluster_set);
    $threshold   = match_threshold();
    $created     = 0;

    foreach ($candidates as $found) {
        $result = score_pair($lost, $found);
        if ($result['score'] < $threshold) {
            continue;
        }
        if (_persist_match($lost, $found, $result, $suspicious)) {
            $created++;
        }
    }

    return $created;
}
