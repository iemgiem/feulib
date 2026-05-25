# Task 11 Review — Matching service / algorithm

Reviewed against: `.design/lfms/DESIGN_BRIEF.md`, `.design/lfms/TASKS.md` (Task 11 spec)
Scope: backend critique only (no UI shipped in this task).
Date: 2026-05-18
**Status: RESOLVED 2026-05-18** — all three "Should fix" items have been addressed in code (`lib/matching.php`, `pages/lost.new.php`, `db/seed.sql`). The "Could improve" items remain as known trade-offs documented for future maintainers.

## Summary

The matching service meets the Task 11 spec end-to-end: weights, threshold, factor list, duplicate-suspicious heuristic, CLI harness, and the Task-10 submit hook are all in place. The algorithm produces sensible scores against seed data (verified by `lib/matching_test.php` — see breakdown below). Two issues worth fixing before more code lands on top of it: (1) a one-directional matching gap that breaks UX when a user files a lost report after a matching found item already exists, and (2) an N+1 suspicious-check inside the candidate loop. Everything else is polish.

## Verification run (CLI harness, current seed)

```
Weights:    {"category":30,"color":20,"location":15,"date":10,"description":25}
Threshold:  30
Lost open:  4 (of 4 total)
Found:      3

LOST              FOUND                 SCORE   CAT COL LOC DAT DES   RESULT
LFMS-2026-00001   LFMS-2026-F-00001        76    30  20   0  10  16   CANDIDATE
LFMS-2026-00002   LFMS-2026-F-00002        32     0  20   0  10   2   CANDIDATE
LFMS-2026-00003   LFMS-2026-F-00002        30     0  20   0  10   0   CANDIDATE
…all other pairs < threshold
```

The intended seed pair (navy Jansport bag, lost↔found) lands cleanly. The two color-only candidates (black umbrella vs. iPhone / Casio) are noise that staff will filter in Task 12. That noise is the threshold's fault, not the scorer's — see "Could improve #2" below.

## Must fix

None. The code is correct, persists safely, and matches the spec.

## Should fix _(all resolved)_

1. **[FIXED] One-directional matching gap — `pages/lost.new.php` does not call the matcher.**
   Task 11 only hooks the found-insert path. If a user files a lost report *after* a matching found item was already logged, the system never proposes a match — the candidate sits invisible until a staff member edits/re-creates the found item. This is a real UX bug at LAN scale where lost reports often trail found items. Fix: add a symmetric `generate_candidates_for_lost(int $lost_id)` (≈10 lines, reuses `score_pair`) and call it from `pages/lost.new.php:117` inside the existing `db_transaction`. Roughly mirrors the found-side hook.

2. **[FIXED] N+1 query inside the candidate loop — `lib/matching.php:is_suspicious_lost`.**
   Every candidate triggers a separate `COUNT(*)` query against `lost_reports`. At pilot scale this is invisible; at a few hundred open reports each found-item submit fires hundreds of round-trips while holding the transaction open. Fix: pre-fetch the duplicate-cluster set once at the top of `generate_candidates` —
   ```sql
   SELECT reporter_account_id, category, color, COUNT(*) AS n
     FROM lost_reports
    WHERE status = 'open' AND created_at >= NOW() - INTERVAL 1 DAY
    GROUP BY reporter_account_id, category, color
   HAVING n > 1
   ```
   Build a lookup set and check membership in PHP. Drops to one extra query regardless of candidate count.

3. **[FIXED] Seed match row is stale — `db/seed.sql:86-88` shows score 85 / description 25.**
   The live algorithm computes 76 / description 16 for the same pair (Jaccard 7/11 ≈ 0.636 × 25). Both clear the threshold, so behaviour is fine, but a developer comparing the seeded row to algorithm output will lose ten minutes debugging a non-bug. Fix: regenerate the seeded match row to `score=76, factors={category:30,color:20,location:0,date:10,description:16}` so the database reflects what `generate_candidates` would actually produce.

## Could improve

1. **Location factor is a structural proxy, not a real location match.**
   `score_pair` compares `lost.last_seen_location` (where the user lost it, free text) against `found.storage_description` (which bin staff put it in). The schema has no "where the found item was discovered" column. The brief lists "last-seen-location match +15" as a factor, which only works if found-side has a comparable field. For v1 this is acceptable — staff write storage descriptions like "Front Desk Bin A — items found in main lobby" — but it's a known weak factor and worth flagging in the Task 19 admin docs.

2. **Threshold of 30 is permissive given the weight shape.**
   Color (20) + date (10) alone clears it. Any two same-color items reported within 7 days produce a candidate (see the umbrella↔iPhone / umbrella↔Casio rows above). Staff will catch these in the Task 12 queue, but consider raising `settings.match_threshold` to 35 before the pilot, or dropping the date weight to 5. No code change needed — adjustable via the settings row.

3. **No audit row for the `lost_reports.is_suspicious` side-effect.**
   `match.generate`'s audit `diff_json` records `is_suspicious: true`, which captures the information — but a future query like `audit_logs WHERE target_type='lost_report'` won't surface this state change. Acceptable trade-off (the info IS in the audit trail, just under a different target_type), but worth a comment in `matching.php` so future maintainers don't double-write.

4. **CLI harness opens a PHP session in `lib/bootstrap.php`.**
   `session_boot()` runs in CLI too. Harmless (writes a session file to the temp dir, never read), but creates noise. Optional: add `if (PHP_SAPI === 'cli') return;` at the top of `session_boot` in `lib/auth.php:27`. Low priority — doesn't affect correctness.

5. **Stopword list is short.**
   Missing common fillers: `while`, `very`, `all`, `just`, `back`, `side`, `also`, `each`, `over`. Adequate for v1; a richer list would marginally improve Jaccard precision on long descriptions.

## What works well

- **SQL injection surface is zero.** Every query uses placeholders. No string concatenation of user input.
- **Idempotent by design.** `INSERT IGNORE` on `uq_match_pair (lost_report_id, found_report_id)` makes `generate_candidates` safe to re-run on the same found item without producing duplicates. This is what unlocks the "rerun matching" admin action that's likely needed later.
- **Settings-driven weights with hardcoded fallback.** The Task 19 admin UI lands as a pure consumer — no algorithm changes needed when weights become editable. The `MATCH_WEIGHT_DEFAULTS` constant means a missing/corrupt settings table doesn't break matching.
- **Transaction-safe wiring.** `generate_candidates` runs inside `found.new.php`'s existing `db_transaction`. If matching fails mid-loop, the found_report insert rolls back too — no orphan rows, no partial state.
- **Full factor breakdown persisted, not just the total.** `matches.factors_json` stores `{category:30, color:20, ...}` so Task 12's score-chip tooltip ("category +30, color +20, location +15, date 0, description +18") reads straight from the row — no need to re-run scoring at render time.
- **`db_last_id()` after `INSERT IGNORE` is correctly guarded.** The `if ($stmt->rowCount() === 0) continue;` check is the right gate — PDO's `lastInsertId()` doesn't change when an IGNORE skips, so without that guard the audit row would point at the wrong target. Easy mistake; correctly avoided.
- **CLI harness is non-destructive.** It only reads — staff/admins can re-tune weights via settings and run `php lib/matching_test.php` to preview impact before saving. Worth mentioning in Task 19's admin docs.
