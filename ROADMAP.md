# FEU LFMS — Project Roadmap

> Single source of truth for "what's the goal, and what's left." When picking
> up this project in a new session, read this file first.
>
> Anchor commit: `b308ea0` on branch `task27modal` (2026-05-19).
> Last reconciled against on-disk code: **2026-05-23**.

---

## End goal

**A WCAG 2.1 AA-conformant Lost & Found Management System ready for production
deployment at the FEU library — replacing the current ad-hoc / paper-based
process — with the full release workflow (signature + selfie + audit) operating
against the real ITS user directory.**

The system is "done" when library staff can run a full shift on it
unsupervised: log a found item, match it to a lost report, release it to the
verified owner with signed and photographed proof, and have the admin report
reflect the activity — all without needing a developer present, and with
nothing on the WCAG-AA blocker list.

---

## Definition of done

The project ships when ALL of the following are true:

1. Every must-have feature in `DESIGN_BRIEF.md` is implemented end-to-end.
2. Every "Must fix" and "Should fix" item in `ACCESSIBILITY.md` is resolved.
3. The full release flow (modal → hold-to-confirm → signature → selfie → audit)
   has been browser-verified by a human, not just code-reviewed.
4. The ITS integration runs against a real (non-mock) endpoint with rotated
   credentials, and the nightly sync has run successfully at least once.
5. The expire job, ITS sync, and any other scheduled tasks are documented in
   `DEVELOPMENT.md` and confirmed running on the target Windows host.
6. `config.php` is reviewed for production: HTTPS-only sessions, real DB
   password, real ITS credentials, `env=production`.
7. A staff acceptance test (one librarian completes a real workflow on a
   staging instance) passes without developer intervention.

---

## Next up (pickup point for the next session)

> **Reconciled 2026-05-22 (updated 2026-05-23).** Browser verification is in
> progress. Two static-analysis passes (2026-05-22 and 2026-05-23) found one
> real bug each. Both CLI scheduled jobs are now verified. No `[~]` items
> cleared yet — all require browser interaction.
>
> **Bugs found and fixed during 2026-05-23 browser verification:**
> - **`thead` not hidden in card layout** — `clip: rect(0 0 0 0)` on
>   `position: absolute` doesn't reliably hide table row-group elements across
>   browsers. Changed to `display: none` in `.table-wrap-cards` media query.
>   Labels are preserved for AT via `td::before { content: attr(data-label) }`.
> - **Sticky table header covering first data row** — `overflow-x: auto` on
>   `.table-wrap` coerces the browser to treat the y-axis as `auto` too, making
>   `.table-wrap` the sticky scroll container. `th { top: 60px }` then means
>   "60px from the top of .table-wrap" — the header was at y=0, so the browser
>   pushed it DOWN to y=60px, landing it on top of the Description/Items cells.
>   Fixed by adding `overflow-y: clip` to `.table-wrap`; unlike `hidden`, `clip`
>   does not create a scroll container, so sticky escapes to the viewport and
>   `top: shell-header-height` works as designed.
>
> **CLI verification complete (2026-05-23):**
> - `php db/expire_items.php --dry` — boots, connects, reads holding_period=365
>   from settings, scans three sets (found / lost / stale matches), exits 0.
>   No items expire against the current dev data (none old enough). ✓
> - `php db/sync_its.php` — boots, calls both mock endpoints, upserts 11
>   records, exits 0 with summary line. ✓
>
> **Static-analysis review (2026-05-23, no further bugs found):**
> Pages reviewed and confirmed clean: `admin.audit.php`, `admin.settings.php`
> (all 6 tabs), `admin.donate.php`, `admin.its.php`, `lib/its.php`,
> `claim.new.php`. CSS aliases (`form-*` → `field-*`) verified complete — all
> 7 remaining pages covered, nothing missed. `profile.php` confirmed fully
> converted in PHP. `notify_event_types` / `notify_enabled` logic confirmed
> correct (default ON). ITS code is ready for a real endpoint: only
> `its.auth_value` and `its.endpoints.*` in `config.php` need updating.
>
> **Bug found and fixed in 2026-05-22 static-analysis pass:**
> - **`form.requestSubmit(btn)` TypeError** — `assets/js/modal.js` was passing
>   the hold button (`type="button"`) as the submitter argument to
>   `requestSubmit()`. The HTML spec requires the submitter to be a
>   `type="submit"` button; spec-compliant browsers (Chrome, Firefox, Safari)
>   throw a TypeError and the form never submits. Fixed to `form.requestSubmit()`
>   (no argument) — constraint validation still runs, nothing is lost because
>   the hold button has no `name`/`value`. Comment in `release.php` updated to
>   match.
>
> **Bugs found and fixed during 2026-05-22 browser verification:**
> - **Transaction nesting crash** (`found.new.php` / `lost.new.php` / `release.php`
>   with photo upload) — `lib/db.php` now uses MySQL `SAVEPOINT`/`RELEASE SAVEPOINT`
>   for nested `db_transaction()` calls instead of throwing on a second
>   `beginTransaction()`. Affects any page that calls `upload_store()` inside a
>   transaction.
> - **Green-on-green buttons in match tables** — `.data-table a` selector was
>   overriding `.btn-primary` text colour. Scoped to
>   `.data-table tbody a:not(.btn)` in `components.css`.
> - **`match.show.php` form unstyled** — wrong class names (`form-group`,
>   `form-label`, `form-control`, `form-hint`) corrected to design-system names
>   (`field`, `field-label`, `field-input`, `field-helper`). Score chip was also
>   being HTML-escaped because it was passed as the heading arg to `page_header()`
>   rather than as `$action_html`; moved to the second parameter.
> - **Score breakdown table misalignment** — `.table-wrap` applies negative bleed
>   margins unsuitable for a narrow 2-column table. Removed wrapper; added
>   `.score-breakdown-table { width: auto; min-width: 280px }`.
> - **CSS cache not busting** — `partials/layout.php` stylesheet `<link>` tags
>   now append `?v=<filemtime>` so browsers pick up CSS edits without a hard
>   refresh.
> - **`form-*` class names undefined across 8 pages** — `release.php`,
>   `admin.settings.php`, `admin.reports.php`, `claim.new.php`, `admin.donate.php`,
>   `admin.audit.php`, `admin.its.php`, `profile.php` all used class names that
>   don't exist in `components.css`. Fixed in CSS by aliasing `form-group`,
>   `form-label`, `form-label-required`, `form-control`, `form-hint`, and
>   `form-control.is-invalid` onto the `field-*` selector lists.
>   `profile.php` additionally converted to `field-*` in PHP.
> - **`field_error_html()` default wrong** — `lib/view.php` defaulted to
>   `class="form-error"` which was undefined. Default changed to
>   `"field-error-text"`; `.form-error` added as a CSS alias for back-compat.
> - **Missing `mt-*`/`mb-*` spacing utilities** — `mt-2` through `mt-6` and
>   `mb-2` through `mb-6` added to `components.css`; used by `admin.dashboard.php`,
>   `claim.show.php`, `found.new.php`, `lost.new.php`, `lost.php`, `dashboard.php`
>   and others.
> - **Claims queue broken link in staff dashboard** — `lost_reports.id AS lost_id`
>   was missing from the `$claims` SELECT, making every "View lost report" link
>   point to `id=0`. Added to `pages/staff.dashboard.php`.
> - **`its_users` table missing from live DB** — table is in `schema.sql` but the
>   live database was created from an older snapshot. Fix: run
>   `db/migrations/2026_its_users.sql` once (idempotent `CREATE TABLE IF NOT
>   EXISTS`). No code change needed.

**The path to shippable — in order:**

1. **Browser-verify the `[~]` backlog** — prerequisite bugs are now fixed;
   resume verification. Highest-risk screen first: **Task 13** (signature pad +
   webcam selfie + hold-to-confirm on `release.php`). Then Tasks 18, 19, 22,
   23, 27 and A11y `[~]` items (#1–6). See §3 for the full list.

2. **ITS real endpoint** — swap the mock token for the real FEU ITS URL +
   credential in `config.php` (change `its.auth_value` and both
   `its.endpoints.*`; set `auth_mode` if not bearer). Click "Fetch / Sync
   Users from ITS" on `admin.its.php`; confirm row counts look right. Then
   verify `db/sync_its.php` produces exit 0 from the command line. The
   CLI is already verified against the mock — only the credentials change.
   Run `db/migrations/2026_its_users.sql` first on any DB created before
   this table was added (the live DB has the table — the CLI sync ran ✓).

3. **Operations / deployment (§4)** — HTTPS, production config, scheduled tasks
   on the Windows host (Task Scheduler entries for `expire_items.php` +
   `sync_its.php`), DB password, staff acceptance test.

The two code-review nits (expired count, `fputcsv`) and the test-infra
decision are done — see §3.

---

## Objectives

Format: `[x]` = done in code, `[~]` = done in code but unverified in browser,
`[ ]` = not started.

### 1. Brief-required features

- [x] Lost-item logging (`pages/lost.*.php`)
- [x] Found-item logging (`pages/found.*.php`)
- [x] Match queue with approve / reject (`pages/match.*.php`)
- [x] Release workflow — base
- [~] Release — required-reason modal (commit `95aa5e4`)
- [~] Release — 1.5s hold-to-confirm button (commit `95aa5e4`)
- [~] Release — signature pad — canvas pad (`assets/js/signature-pad.js`) + file-upload fallback shipped; browser-unverified (Task 13, ACCESSIBILITY.md #5)
- [~] Release — selfie capture — `getUserMedia` capture (`assets/js/selfie-capture.js`) + file-upload fallback shipped; browser-unverified (Task 13, ACCESSIBILITY.md #5)
- [x] Audit log (`lib/audit.php` + `audit_logs` table)
- [x] Admin reports + CSV export (`lib/export.php`)
- [x] Notification polling (`assets/js/notifications.js`)
- [x] Expire job (CLI, `db/expire_items.php`)
- [x] ITS user directory integration — mock (`lib/its.php`)
- [ ] ITS integration — against real endpoint

### 2. Accessibility (WCAG 2.1 AA — from ACCESSIBILITY.md)

Must fix:
- [~] #1 Field errors linked via `aria-describedby` (commit `3cf74f1`)
- [~] #2 `aria-sort` on sortable column headers (commit `0ed1726`)

Should fix:
- [~] #3 Explicit toast live region (commit `95aa5e4` + `3014fa1`)
- [~] #4 Required-reason modal replaces `confirm()` — **built**: Reject on `match.show.php` uses a `required`-reason modal; release uses the hold-to-confirm modal (Task 27)
- [~] #5 Signature pad + selfie capture — canvas pad + `getUserMedia` capture + 3-step verification checklist + gold Confirm-Release button all shipped; browser-unverified (see Features above)
- [~] #6 Responsive shell — hamburger drawer ≤1024px (commit `7f2afeb`) plus comparison stack, table scroll-shadow, header condense, release phone-block, staff/admin tablet advisory (2026-05-20), and mobile card-row rendering on every data table in the app (`.table-wrap-cards` expanded to all 14 tables, 2026-05-23). Deferred: 64px icon rail (text-only nav — needs icon-set decision). Browser-unverified.
- [x] #7 Skip-to-content link (already shipped before `task27modal`)

Could improve:
- [~] #8 Photo `alt` text differentiation (branch `a11y-polish`)
- [~] #9 Page titles differentiate beyond "FEU LFMS" (branch `a11y-polish`)
- [~] #10 `<details>` keyboard / focus check (branch `a11y-polish`)
- [x] #11 Notification bell `aria-live` (subsumed by #3 toast region)

### 3. Code health

- [x] Fix expired-count bug in `pages/admin.report.show.php` — now counts
      `audit_logs` rows with `action='found_report.expire'` instead of
      `found_reports.updated_at`, which auto-bumps on any later edit.
- [x] Replace deprecated `fputcsv()` signature in `lib/export.php` for PHP 8.4
      — escape arg is now `""` (RFC-4180), no more 8.4 deprecation warning.
- [x] Decide on test infrastructure — renamed `lib/matching_test.php` to
      `db/match_debug.php` and reframed as a tuning diagnostic (no
      assertions, no pass/fail). README, DEVELOPMENT, and CLAUDE updated
      to stop calling it tests. A real test runner is still not wired up
      and remains out of scope for this phase.
- [x] Task 28 — all inline `style="display:grid…"` replaced with named layout
      classes in `components.css`; no inline grid attributes remain in any page.
      (2026-05-21)
- [x] Task 29 — `.table-wrap-cards` pattern added to `components.css`; applied
      to `lost.php` and `claims.php` for mobile card-row rendering. `notifications.php`
      uses `.list` which stacks naturally; no table markup to convert. (2026-05-21)
- [x] Responsive table coverage expanded — `.table-wrap-cards` + `data-label`
      applied to every remaining data table in the app: `dashboard.php`,
      `staff.claims.php`, `staff.dashboard.php` (match queue + claims queue),
      `found.php`, `found.show.php` (related matches), `matches.php`,
      `admin.audit.php`, `admin.donate.php`, `admin.its.php`, `admin.reports.php`,
      `admin.report.show.php` (4 tables). No plain `class="table-wrap"` divs
      remain. Root cause of storage-location display bug (scroll position
      restored to rightmost column after POST) also fixed by this. (2026-05-23)
- [x] Design review Phase 7 complete — `.design/lfms/DESIGN_REVIEW.md` updated
      2026-05-21. Sort indicators now use inline SVG (no emoji-font risk); dead
      `--color-brand-accent` token reference removed; duplicate CSS hide rule
      removed. All review findings closed except icon-rail deferral.
- [x] Button system enhancements — `components.css` + `admin.settings.php`:
      Primary button hover text fixed (gold, was invisible — CSS specificity bug).
      Active/pressed inset-shadow states added to all 5 button variants.
      `.btn-warning` class created for secondary destructive actions (amber at
      rest, solid amber on hover). Applied conditionally to Deactivate buttons in
      storage locations + user accounts sections. (2026-05-24)
- [x] Restore sticky table headers — `overflow-y: clip` added to `.table-wrap`;
      `position: sticky; top: var(--shell-header-height)` added to
      `.data-table thead th`. `data-table-static` opt-out removed from the 5 long
      list pages (found, lost, matches, staff.claims, staff.dashboard ×2).
      Short/reference tables keep `data-table-static`. (2026-05-25)
- [x] Fix `.btn-warning` token layering — resting state now consumes
      `--color-warning-*` semantic tokens. `--btn-warning-*` component token group
      added to tokens.css §4 for consistency with all other button variants.
      (2026-05-25)
- [x] Fix inline styles in `admin.audit.php` — 4 inline `style=""` attributes
      replaced with `.filter-bar-wrap`, `.field-flex`, `.field-auto`,
      `.filter-date-range` utility classes in `components.css`. (2026-05-25)
- [x] Production security hardening — HTTP security headers (`X-Content-Type-Options`,
      `X-Frame-Options`, `Referrer-Policy`) added to `lib/bootstrap.php`. Root
      `.htaccess` updated: directory listing disabled, `lib/`, `pages/`, `partials/`,
      `db/` blocked from direct HTTP access, per-directory `.htaccess` files created,
      `Header` directives added for belt-and-suspenders coverage. Production config
      guard added to bootstrap: logs warnings if `db.pass`, `its.auth_value`, or
      `session.cookie_secure` are still at dev defaults when `app.env=production`.
      (2026-05-25)
- [x] Fix transaction nesting crash — `lib/db.php` `db_transaction()` upgraded
      to detect `inTransaction()` and use MySQL `SAVEPOINT`/`RELEASE SAVEPOINT`/
      `ROLLBACK TO SAVEPOINT` for nested calls. Affects `found.new.php`,
      `lost.new.php`, `release.php` (any page calling `upload_store()` inside a
      transaction). (2026-05-22)
- [x] Fix green-on-green button text in match tables — `.data-table a` scoped to
      `.data-table tbody a:not(.btn)` so `.btn-primary` inside table cells is no
      longer overridden. (2026-05-22)
- [x] Fix `match.show.php` form styling and score chip — wrong `form-*` class
      names corrected to `field-*`; score chip moved to `page_header()`
      `$action_html` parameter so it is not HTML-escaped. Score breakdown table
      removed from `.table-wrap` bleed container; `.score-breakdown-table` class
      added. (2026-05-22)
- [x] Add CSS cache busting — `partials/layout.php` appends `?v=<filemtime>` to
      all three stylesheet `<link>` tags. (2026-05-22)
- [x] Fix undefined `form-*` CSS class names across 8 pages — aliased onto
      `field-*` selector lists in `components.css` for back-compat. `profile.php`
      additionally converted to `field-*` in PHP. `field_error_html()` default
      fixed from `"form-error"` to `"field-error-text"`; `.form-error` alias
      added. (2026-05-22)
- [x] Add `mt-*`/`mb-*` spacing utilities — `mt-2` through `mt-6`, `mb-2`
      through `mb-6` added to `components.css`. (2026-05-22)
- [x] Fix claims queue link in `staff.dashboard.php` — `lost_reports.id AS
      lost_id` was missing from the claims `SELECT`; links were pointing to
      `id=0`. (2026-05-22)
- [x] `its_users` table reconciled — already in `schema.sql`; live databases
      created from older snapshots need `db/migrations/2026_its_users.sql`
      applied once. (2026-05-22)
- [x] Fix `form.requestSubmit(btn)` TypeError in hold-to-confirm — `modal.js`
      was passing the hold button (`type="button"`) to `requestSubmit()`; the
      spec requires a `type="submit"` submitter, so Chrome/Firefox/Safari throw
      TypeError and the release form never submits. Changed to `form.requestSubmit()`
      (no arg). (2026-05-22)
- [ ] Browser-verify every `[~]` above

### 4. Operations & deployment

- [ ] Real ITS endpoint URL + credentials placed in production `config.php`
- [ ] `auth_value` rotated off the dev token
- [ ] Nightly ITS sync scheduled (`schtasks` command in `DEVELOPMENT.md:331`)
      and confirmed running on the target host
- [ ] Daily expire job scheduled (`db/expire_items.php`) and confirmed
- [ ] MySQL backup procedure documented and tested
- [ ] HTTPS configured; `session.cookie_secure = true` flipped on
- [ ] `app.env` set to `'production'`
- [ ] DB password set to something other than empty
- [ ] One end-to-end staff acceptance test passes on staging

---

## Out of scope

These have come up in discussion but are explicitly NOT goals of this
project — flag for a future phase rather than building now.

- Multi-tenant / multi-branch support (single library, single instance)
- Public-facing claim portal (workflow remains staff-mediated)
- Native mobile app (the responsive web shell is the answer for mobile)
- Integrations beyond ITS (no LMS, no email gateway, no SMS)
- Real-time push notifications (polling is sufficient at this scale)
- Internationalisation (English-only is fine for the target deployment)

---

## How to use this file

- **Read this first** when starting a session on this project. It supersedes
  any ad-hoc TODO list in conversation memory.
- **Update checkboxes as work lands.** When you close a finding, change `[ ]`
  or `[~]` to `[x]` and note the commit. Keep the section reference
  (e.g. "ACCESSIBILITY.md #5") so traceability survives.
- **When the user proposes new work**, place it in the right section before
  starting. If it doesn't fit any section, ask whether it's actually in scope
  for this project's end goal or whether it should go in "Out of scope".
- **Don't expand scope silently.** If a small request implies bigger work
  (e.g. "fix the API" → "the API needs a full rewrite"), flag it and ask.
- **When everything in §1 and §2 (must/should) is `[x]` and §4 is complete,
  this project is shippable.** The "could improve" items in §2 and the test
  infrastructure in §3 are polish, not blockers.

---

## Document references

- `README.md` — user-facing setup + feature summary
- `DEVELOPMENT.md` — developer guide (helpers, conventions, scheduled tasks)
- `ACCESSIBILITY.md` — audit report with the numbered findings referenced here
- `db/README.md` — schema, migrations, CLI scripts
- `config.example.php` — every configurable knob, with defaults that work on
  XAMPP out of the box
