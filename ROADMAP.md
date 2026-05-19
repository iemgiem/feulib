# FEU LFMS — Project Roadmap

> Single source of truth for "what's the goal, and what's left." When picking
> up this project in a new session, read this file first.
>
> Anchor commit: `b308ea0` on branch `task27modal` (2026-05-19).

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

**Recommended order, smallest-risk first:**

1. **Browser-verify `task27modal` commits** (`3014fa1` → `b308ea0`). Five
   commits of UI work — modal focus trap, hold-to-confirm, drawer with focus
   trap, aria-describedby, aria-sort — none of it tested in a real browser.
   The two highest-risk paths to exercise: rejecting a match (modal flow) and
   releasing an item (hold-to-confirm).
2. **Fix the two code-review nits** — expired count in
   `pages/admin.report.show.php` and the PHP-8.4 `fputcsv` deprecation in
   `lib/export.php`. Both are server-side, low-risk, ~15 min combined.
3. **Build #5 — signature pad + selfie capture.** Biggest remaining feature
   and the only thing blocking brief sign-off. Start a fresh branch off
   `task27modal` once it merges.
4. **Could-improve items** (#8 alt text, #9 page titles, #10 `<details>`
   focus). Each is a one-commit pass.
5. **Operations / deployment** — real ITS endpoint, scheduled tasks, backup
   procedure, HTTPS.

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
- [ ] Release — signature pad (ACCESSIBILITY.md #5)
- [ ] Release — selfie capture (ACCESSIBILITY.md #5)
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
- [~] #4 Required-reason modal replaces `confirm()` (commit `95aa5e4`)
- [ ] #5 Signature pad + selfie capture — see Features above
- [~] #6 Responsive shell with hamburger drawer (commit `7f2afeb`)
- [x] #7 Skip-to-content link (already shipped before `task27modal`)

Could improve:
- [~] #8 Photo `alt` text differentiation (branch `a11y-polish`)
- [~] #9 Page titles differentiate beyond "FEU LFMS" (branch `a11y-polish`)
- [ ] #10 `<details>` keyboard / focus check
- [x] #11 Notification bell `aria-live` (subsumed by #3 toast region)

### 3. Code health

- [x] Fix expired-count bug in `pages/admin.report.show.php` — now counts
      `audit_logs` rows with `action='found_report.expire'` instead of
      `found_reports.updated_at`, which auto-bumps on any later edit.
- [x] Replace deprecated `fputcsv()` signature in `lib/export.php` for PHP 8.4
      — escape arg is now `""` (RFC-4180), no more 8.4 deprecation warning.
- [ ] Browser-verify every `[~]` above
- [ ] Decide on test infrastructure — `lib/matching_test.php` is orphaned,
      not wired into any runner. Either delete it, or add a minimal harness
      and a CI hook.

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
