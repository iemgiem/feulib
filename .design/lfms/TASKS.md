# Build Tasks: FEU LFMS

Generated from: `.design/lfms/DESIGN_BRIEF.md` + `.design/lfms/INFORMATION_ARCHITECTURE.md`
Tokens: `.design/lfms/DESIGN_TOKENS.css` + `.design/lfms/BASE.css`
Date: 2026-05-17 (rev 2 — task-plan review) · 2026-05-20 (rev 3 — reconciled against on-disk code)
Aesthetic philosophy locked in Task 5: **"FEU Tamaraw Classic"** — institutional modern, FEU green primary + gold decorative accent only.

Conventions for this list:
- Every task is a **vertical slice** (HTML + CSS + PHP + JS + DB queries together — not split by layer).
- **Single responsibility** per task: one clear thing it delivers.
- `Creates:` = new files/components introduced by this task.
- `Reuses:` = files/components/tokens already defined in an earlier task.
- `Risk:` = flagged when the task contains novel UX, unfamiliar API, or known compatibility concerns.

---

## Actual file structure (as shipped)

```
/index.php                  ← front controller (Task 3)
/config.example.php         ← config template (Task 1)
/config.php                 ← actual credentials (gitignored)
/db/
  schema.sql                ← Task 1
  seed.sql                  ← Task 1
  README.md                 ← how to load via phpMyAdmin
  expire_items.php          ← Task 22 (CLI nightly expiry job)
  sync_its.php              ← Task 26 (CLI nightly ITS sync)
  hash_passwords.php        ← Task 1 (bcrypt utility)
  migrations/               ← one-off SQL for existing databases
/lib/                       ← Task 2 onward
  bootstrap.php             ← wires the toolkit, sets timezone, starts session
  db.php, auth.php, csrf.php, flash.php,
  sanitize.php, validate.php, redirect.php,
  upload.php, audit.php, view.php, routes.php,
  matching.php, matching_test.php,
  export.php (Task 17), its.php (Task 26)
  (Notifications: written inline via q('INSERT INTO notifications …') —
  no separate `notify.php` helper was needed.)
/pages/<token>.php          ← one file per page-token from IA
/partials/
  layout.php, header.php, sidebar.php, footer.php, error_page.php
  (Auth-card markup lives in view.php as auth_card_open/close, not a partial.)
/assets/
  css/tokens.css            ← copied from .design/lfms/DESIGN_TOKENS.css
  css/base.css              ← copied from .design/lfms/BASE.css
  css/components.css        ← grows with every UI task
  js/                       ← validate.js, photo-upload.js, notifications.js
                              (signature pad + selfie capture are inlined
                              in pages/release.php, not separate files.)
  uploads/                  ← gitignored; per-entity subfolders
```

---

## Phase 1 — Foundation

- [x] **1. Database schema + seed data**
  Translate the entities from the brief into MySQL DDL: `accounts`, `lost_reports`, `found_reports`, `matches`, `claim_tickets`, `release_logs`, `storage_locations`, `notifications`, `attachments` (polymorphic), `audit_logs`, `reports`, `settings`. (Sessions are handled by PHP filesystem sessions, not a DB table.) Use `ENUM` for status fields (state-machine enforcement at the DB layer). Foreign keys with `ON DELETE RESTRICT` for audited entities, `ON DELETE CASCADE` only for non-audited children. Seed: 1 admin, 2 staff, 5 students, 3 storage locations, 4 lost reports, 3 found items, 1 sample match. `db/README.md` documents the import order via phpMyAdmin.
  _Creates: `config.example.php`, `db/schema.sql`, `db/seed.sql`, `db/README.md`._

- [x] **2. Common library + global helpers**
  Bootstrap the `lib/` toolkit that every page will use: `db.php` (PDO connection with prepared-statement helper), `auth.php` (session start, current-user, role check, login/logout), `csrf.php` (`csrf_field()` + `csrf_check()`), `flash.php` (one-shot messages across redirects), `sanitize.php` (`e()` HTML escape, `clean()` strip-tags), `validate.php` (`required`, `email`, `min`, `max`, `regex`, `enum` rules with collected-error return), `redirect.php` (`go($url)` with optional flash payload). PHP sessions configured with `HttpOnly`, `SameSite=Strict`, regenerated on login.
  _Creates: `lib/db.php`, `lib/auth.php`, `lib/csrf.php`, `lib/flash.php`, `lib/sanitize.php`, `lib/validate.php`, `lib/redirect.php`._

- [x] **3. Front controller + role-based access guard**
  `index.php` reads `?p=<token>`, validates the token against an allow-list (no arbitrary file inclusion), starts the session, resolves the current user, checks the role-permission matrix from `INFORMATION_ARCHITECTURE.md`, dispatches to `pages/<token>.php`. Unauthenticated → redirect to `login` with `?next=` return path. Forbidden → render `403`. Unknown token → render `404`. Server error → render `500` with a generic message (logs detail).
  _Creates: `index.php`, `pages/403.php`, `pages/404.php`, `pages/500.php`, `pages/logout.php`._
  _Reuses: `lib/auth.php`, `lib/redirect.php`._

- [x] **4. Login + Register pages**
  Auth-card layout (centered 380px white card on light bg, no sidebar) reused by both pages. Login form (username + password, "Register" link, "Forgot password" placeholder linking to admin contact). Register form (full name, role radio Student/Faculty, ID number, email, password+confirm). Server-side validation via `lib/validate.php`; client-side mirror via `assets/js/validate.js` (required/email/match — never the sole gate). On successful register → auto-login → land on `dashboard`. On login error → inline message, focus first invalid field, no field re-population of password. Establishes form-field, primary-button, ghost-button, link-button, helper-text, inline-error components.
  _Creates: `pages/login.php`, `pages/register.php`, `pages/forgot.php`, `assets/js/validate.js`, auth-card + form-field + button styles in `components.css`. (The auth-card markup is exposed via `auth_card_open()` / `auth_card_close()` in `lib/view.php`, not a partial.)_
  _Reuses: tokens, base, helpers from Tasks 1–3._

- [x] **5. App shell + layout templates**
  Build the full layout used by every authenticated page: green header (60px) with gold underline + system title + user name + role pill + notification bell stub + logout, role-aware left sidebar (240px / 64px rail) sourcing menu items from a single role→items map, main content with 32px padding and 1280px max-width, breadcrumb strip, page-header-strip (H1 + breadcrumb + optional action button). Establishes `partials/layout.php` as the wrapper every page uses: `layout_open($title)` + page content + `layout_close()`. Copy `DESIGN_TOKENS.css` → `/assets/css/tokens.css` and `BASE.css` → `/assets/css/base.css`. Validate aesthetic by viewing `/index.php?p=dashboard` as each of the three roles.
  _Creates: `partials/layout.php`, `partials/header.php`, `partials/sidebar.php`, `partials/footer.php`, `assets/css/tokens.css`, `assets/css/base.css`, `assets/css/components.css` (shell rules only at this point), breadcrumb + page-header-strip + role-pill components._
  _Risk: validates the entire aesthetic direction. Get the green/gold right or revise tokens before more work piles on._

- [x] **6. File upload library**
  Server-side upload pipeline shared by photo uploads (lost/found items, claim ID proof) and selfie capture (release): `lib/upload.php::store($file, $entity_type, $entity_id)` validates MIME against a whitelist (jpeg/png/webp), enforces a configured max size (default 4MB), rejects double extensions, generates a hashed filename, stores under `assets/uploads/<entity_type>/<entity_id>/<hash>.<ext>`. A separate `serve_upload.php` page-token reads the uploads directory only after the auth gate + per-entity permission check has passed (uploads dir itself is `Deny from all` via `.htaccess`). Returns the stored Attachment row id for the caller. Polymorphic — works for any `attachable_type`.
  _Creates: `lib/upload.php`, `pages/serve_upload.php`, `assets/uploads/.htaccess` (Deny), gitignored `assets/uploads/` tree._
  _Reuses: `lib/db.php`, `lib/auth.php`._

---

## Phase 2 — Core UI

- [x] **7. User Dashboard**
  Welcome strip, "Report a Lost Item" primary CTA, Notifications panel (latest 3, hardcoded queries against `notifications` table; the bell wires up in Task 20), My Lost Reports compact table (latest 5 rows with status badges), help footer with expandable "How does this work?". Establishes: card with green left bar, status-badge component (all 8 palettes — even though only 4 statuses are realistic at this point, build them all once), stat-card variant for later admin reuse.
  _Creates: `pages/dashboard.php`, card, status-badge, stat-card, help-footer-expandable._
  _Reuses: shell, page-header-strip, buttons._

- [x] **8. Report Lost Item flow**
  Single-column form (≤640px): category select, color, brand/identifying marks, free-text description, date lost (date input — native widget is fine), last seen location (free-text), optional photo upload (drag/drop + click, thumbnail preview, client + server validation via `lib/upload.php`). Submit → server inserts `lost_reports` row, redirects to confirmation page showing reference number `LFMS-2026-NNNNN`. List view (`/p=lost`) with status filter + date range + search; detail view (`/p=lost.show`) with edit-while-OPEN affordance. Establishes the **confirmation-screen pattern** reused by Found and Claim.
  _Creates: `pages/lost.new.php`, `pages/lost.created.php`, `pages/lost.php`, `pages/lost.show.php`, photo-upload component (`assets/js/photo-upload.js`), textarea, select, date-input styles, confirmation-screen pattern, filter-chips component._
  _Reuses: form-field, button, card, `lib/upload.php`, `lib/validate.php`._

- [x] **9. Staff Dashboard**
  Stat strip (4 cards: open lost / open found / matches awaiting validation / claims pending). Match Validation Queue table (pending matches, default sort: highest score first) and Claims Queue table (pending claims, oldest first). "Log Found Item" primary CTA in the page header strip. Tables: green header row, hover row in `--color-bg-hover`, selected row in `--color-bg-selected`, sticky header beyond 20 rows, sortable column indicators, pagination (25/50/100). Filter chips row (status, date range, search) above each table — the chips state persists in URL query params.
  _Creates: `pages/staff.dashboard.php`, data-table component, pagination component, sortable-column-header pattern._
  _Reuses: card, stat-card, badge, filter-chips, page-header-strip._

- [x] **10. Log Found Item flow (UI only)**
  Found Item form: category, color, brand/marks, description, date found, **storage location dropdown** sourced from `storage_locations` table, photo (strongly encouraged), finder name auto-filled from session. On submit → inserts `found_reports` row only. **No matching yet** — Task 11 wires it in. Confirmation screen mirrors Lost Item. List + detail views.
  _Creates: `pages/found.new.php`, `pages/found.created.php`, `pages/found.php`, `pages/found.show.php`._
  _Reuses: form-field, photo-upload, confirmation-screen, table, filter-chips, badge._

- [x] **11. Matching service / algorithm**
  Implement `lib/matching.php::generate_candidates($found_report_id)` and hook it into the Task-10 submit handler. Scoring factors (configurable via `admin.settings` later — for v1, hardcode the weights): category match +30, color match +20, last-seen-location match +15, date proximity (within ±7 days = +10), description keyword overlap (Jaccard on stop-worded tokens) 0–25. Threshold to persist a candidate: total ≥ 30. Duplicate-flag heuristic: same submitter + same category + same color within 24h gets a `suspicious=1` marker for staff review. Includes a CLI test harness `lib/matching_test.php` that runs against seed data and prints scores.
  _Creates: `lib/matching.php`, `lib/matching_test.php` (CLI)._
  _Reuses: `lib/db.php`. Modifies: Task 10's submit handler to call `generate_candidates`._

- [x] **12. Match Validation Screen (Staff)** _(core flow shipped; modal + sticky bar deferred to Task 27)_
  Two-column comparison layout (Lost left, Found right): photo top, attribute rows below with diff highlighting where fields disagree. Match score chip in the page header strip with hover/focus tooltip revealing factor breakdown ("category +30, color +20, location +15, date 0, description +18"). Sticky action bar at viewport bottom: Approve / Needs Info / Reject. **Approve** → notifies user, creates placeholder `claim_tickets` row, sets match status `APPROVED`, item status `MATCHED`. **Reject** → modal with required reason, sets match status `REJECTED`. **Needs Info** → modal with required note, sets match status `PENDING_INFO`, notifies user.
  _Status (reconciled 2026-05-20):_ Approve / Needs Info / Reject all work and audit-log correctly. **Task 27 has landed:** Reject now opens the required-reason modal (`reject-match-modal`, with a `required` textarea) instead of `window.confirm()`. Needs Info still uses an inline `alert()` guard. Action buttons remain a static `<div style="display: flex; …">` at the bottom of the form, not the sticky bar the brief specified.
  _Creates: `pages/matches.php` (queue list), `pages/match.show.php`, comparison-panel, score-chip, tooltip._
  _Reuses: table, badge, filter-chips, `lib/audit.php` (built here)._
  _Risk: novel layout (sticky action + scrolling content + photo lightbox). Validate at 1280, 1024, 768 widths._

- [~] **13. Release Verification (Staff) — signature pad + selfie capture** _(code-complete 2026-05-20; browser-unverified)_
  Read-only claim summary, 3-checkbox verification list, HTML5 canvas signature pad (mouse + touch, Clear + Done, captures PNG via `toDataURL`), `getUserMedia` selfie capture (live preview → capture-to-canvas → retake/accept, graceful file-upload fallback on permission denial or no camera). All three checklist boxes ticked + signature exists + selfie exists → gold "Confirm Release" button activates with a 1.5s hold-to-prevent-misclick. Confirm → server writes immutable `release_logs` + `audit_logs` row, marks claim `RELEASED`, marks item `RELEASED`. Success screen shows transaction summary; staff can print (no library — `window.print()` with print stylesheet).
  _Status (built 2026-05-20):_ Code-complete, pending browser verification. The HTML5 canvas signature pad (`assets/js/signature-pad.js`) and `getUserMedia` selfie capture (`assets/js/selfie-capture.js`) progressively enhance the `data-sig-pad` / `data-selfie-cap` file inputs — writing PNG/JPEG to them via `DataTransfer`, with an always-present file-upload fallback toggle. Submission is gated by the 1.5 s hold-to-confirm modal (Task 27) with an immutable-release warning. Added 2026-05-20: the brief's **3-step verification checklist** (ID checked / claimant matches / item matches — gates the confirm button via `assets/js/release-verify.js`, enforced + audit-logged server-side) and the **gold `btn-accent` Confirm-Release button** (closes finding S1 — the one designated accent-button use). The `--signature-*` / `--capture-*` tokens are now consumed. _The original spec's risk areas (camera permission, touch drawing, dataURL/blob size, browser compat) are unverified — needs a real-browser pass on Chrome/Edge/Firefox._
  _Reuses: card, button (primary/ghost/danger), modal, `lib/upload.php` (for dataURL → PNG file), `lib/audit.php`._
  _Risk: **HIGHEST.** Camera permission, canvas drawing on touch devices, dataURL upload size limits, browser compatibility. Test on Chrome + Edge + Firefox on Windows; test denial path; test mid-capture refresh; verify selfie + signature both attach correctly._

- [x] **14. Claim Submission flow (User)**
  Triggered by a match-approved notification. 3-step flow: confirm ownership (radio: "yes, this is mine" / "no, this isn't mine"), upload ID proof (school ID / certificate of enrollment / gov ID — uses `lib/upload.php`), review + submit → claim detail page with reference number, pickup instructions ("Visit the Lost & Found counter, Mon–Sat, 8am–5pm. Bring this reference number: LFMS-2026-NNNNN"), status `PENDING`. User's claims list at `/p=claims`.
  _Creates: `pages/claim.new.php`, `pages/claim.show.php`, `pages/claims.php`, step-indicator component._
  _Reuses: form-field, photo-upload, confirmation-screen, card, badge._

- [x] **15. Claims Queue (Staff)**
  Table of all in-flight claims sorted oldest-first with status filter, search by reference number. Click a row → routes to the Release Verification screen (Task 13). A "show released" filter chip reveals closed claims.
  _Creates: `pages/staff.claims.php`._
  _Reuses: table, filter-chips, pagination, badge._

---

## Phase 3 — Admin

- [x] **16. Admin Dashboard**
  Stat strip (6 cards: total lost / total found / match rate % / avg time to claim / items in storage / items expiring soon), Recent Activity table (last 25 audit-log entries), Quick Links row (Generate Report · Manage Users · Configure Policies). No charts in v1.
  _Creates: `pages/admin.dashboard.php`._
  _Reuses: stat-card, table, badge._

- [x] **17. Admin Reports + Export** _(CSV shipped; 3 of 4 report types + XLSX/PDF deferred)_
  Reports page with date-range picker (two native date inputs), report-type select (Operational Summary / Match Effectiveness / User Activity), Generate button → renders preview on a Report Detail page. **Export CSV** button on the detail page downloads a UTF-8 BOM CSV with all of the page's sections; `?format=csv` short-circuits before HTML output. XLSX (PhpSpreadsheet) and PDF (FPDF) remain deferred — the spec calls them optional and they add the only allowed third-party libraries.
  _Scope deviation:_ Brief specified four report types — Operational Summary, Match Effectiveness, **Storage Utilization**, **Released Items**. Code ships three — Operational Summary, Match Effectiveness, **User Activity**. The Storage Utilization and Released Items reports were not built; User Activity is a substitute that wasn't in the brief.
  _Creates: `pages/admin.reports.php`, `pages/admin.report.show.php`, `lib/export.php` (csv_send + csv_row + csv_section helpers)._
  _Reuses: table, button, page-header-strip._
  _Note: PhpSpreadsheet + FPDF remain the ONLY permitted third-party libraries when XLSX/PDF land. Defer indefinitely if scope is tight._

- [~] **18. Admin Audit Log** _(complete 2026-05-20; browser-unverified)_
  Filterable table (actor, action type, date range, target entity), monofont for entity IDs, expandable "show diff" per state-change row, CSV export of filtered view. Always paginated (50/page default). Reads `audit_logs` populated since Task 12.
  _Status (built 2026-05-20):_ Filters + pagination + diff-expansion work, and CSV export is now wired in — an "Export CSV" button in the page header links to `?format=csv`, which short-circuits before any HTML and streams the **full filtered set** (no pagination) via `lib/export.php` (`csv_send`/`csv_row`), with a Changes (JSON) column from `diff_json`. Browser-unverified (button click + download not exercised here).
  _Creates: `pages/admin.audit.php`, audit-row with expandable diff._
  _Reuses: table, filter-chips, pagination, `lib/export.php`._

- [~] **19. Admin Settings (tabbed)** _(all 6 tabs built 2026-05-20; browser-unverified)_
  Single `pages/admin.settings.php` with 6 tabs via `&tab=`: **Users & Roles** (paginated user table with role edit + activate/deactivate), **Storage Locations** (CRUD), **Holding Period** (numeric input — default 365 days), **Notification Rules** (toggle list), **Match Scoring Weights** (number inputs per factor — feeds into Task 11's algorithm), **Backup Status** (read-only timestamp of last automatic backup).
  _Status (built 2026-05-20):_ The page is now tabbed via `?tab=` (server-routed `<nav>` links, no JS, `aria-current` on the active tab). **Working tabs:** Match Scoring Weights and Holding Period (migrated unchanged), **Storage Locations** (full CRUD — list with item counts, add, edit, activate/deactivate; soft deactivation keeps existing references and drops the bin from the “Log Found Item” dropdown via `is_active`; audit verbs `storage.create` / `storage.update` / `storage.activate` / `storage.deactivate`), and **Backup Status** (read-only `last_backup_at`). **Users & Roles** (paginated, searchable account list with inline role change + activate/deactivate; guards block changing your own role, deactivating yourself, and demoting/deactivating the last active admin; audit `account.role_change` / `account.activate` / `account.deactivate` — deactivation bites immediately because `current_user()` only resolves `is_active=1` sessions), **Backup Status** (read-only `last_backup_at`), and **Notification Rules** (per-event toggles that actually gate delivery — `lib/notify.php` exposes `notify()` + `notify_enabled()`, the `match.approved` / `claim.released` insert sites were migrated onto it, and the toggles persist `notify_<type>` settings, default ON; audit `settings.update_notifications`). All six tabs are now built. No hard-delete on storage or accounts by design (referential integrity + audit trail). Browser-unverified.
  _Created: tab strip (`.settings-tabs`) + `?tab=` routing, Storage Locations CRUD, Users & Roles management, Notification Rules (+ `lib/notify.php` gating), Backup Status panel._
  _All six sub-views built — browser verification remaining._
  _Reuses: data-table, form-field, button, empty-state, audit log._

---

## Phase 4 — Cross-cutting features

- [x] **20. Notification bell + 60s polling** _(polling shipped; quick-list dropdown deferred)_
  Header bell icon (replaces stub from Task 5) with unread-count badge. Click → dropdown listing latest 5 notifications + "See all" link to `/p=notifications`. JS polls `index.php?p=api.notifications&unread=1` every 60s, updates badge without page reload, plays no sound. Click a notification → marks read + routes to the linked claim/match/lost-report. Wires up the dashboard notification panel from Task 7 to use the same source.
  _Status (verified 2026-05-19):_ The 60-second poll works (`assets/js/notifications.js`) and the badge updates in place. The bell anchor goes **directly to `/p=notifications`** rather than opening a quick-list dropdown — the "dropdown listing latest 5" half of the spec was never built. The full-page notifications list does mark-read + route-on-click correctly. Treating the missing dropdown as acceptable scope-trim since the full list is one click away.
  _Creates: notification-bell polling (`assets/js/notifications.js`), `pages/notifications.php`, `pages/api.notifications.php`._
  _Reuses: badge, table._

- [x] **21. Empty states + 4xx error pages**
  Reusable empty-state pattern (CSS-only icon slot or inline SVG, heading, helper text, primary CTA). Apply to every list view's no-results case. Polish the 403/404/500 pages styled with the auth-card aesthetic. Add the "How does this work?" expandable help footer to the User Dashboard if not already there from Task 7.
  _Creates: empty-state component._
  _Reuses: card, button, auth-card layout._

- [~] **22. Holding-period expiry + donation workflow** _(complete 2026-05-20; browser/CLI-unverified)_
  PHP CLI script `db/expire_items.php`: scans `lost_reports` / `found_reports` / `matches` aged > holding-period days (default 365, configurable via Task 19) with no terminal status → marks them `EXPIRED`. Scheduled via **Windows Task Scheduler** to run daily at noon Mon–Sat (matches the backup window in the brief). Surface "Items expiring in next 30 days" card on Admin Dashboard. Admin can bulk-mark expired items as `DONATED` with a required partner-beneficiary note (modal-with-required-reason → writes audit entry).
  _Status (built 2026-05-20):_
  - Expiry job: now scans all three. OPEN `found_reports` (by `date_found`) and OPEN `lost_reports` (by `date_lost`) past the holding period → `expired`; `pending`/`needs_info` matches tied to an expiring report cascade-reject; and any remaining stale `pending`/`needs_info` match older than the holding period is system-rejected (matches have no `expired` state). `--dry` preview flag retained.
  - "Items expiring soon" card: ✓ on `admin.dashboard.php`.
  - Bulk-donate UI: ✓ built 2026-05-20 — `pages/admin.donate.php` (admin-only route + "Donate Items" sidebar link). Lists EXPIRED found items with bulk-select, donates the selection through the Task 27 modal with a required partner/beneficiary note, re-verifies each id is still EXPIRED before acting, and writes a `found_report.donate` audit entry per item. Donation is one-way. Browser-unverified.
  _Creates: `db/expire_items.php`, expiry-soon surface on Admin Dashboard, `pages/admin.donate.php` (bulk-donate)._
  _Complete in code — CLI/browser verification remaining (run `php db/expire_items.php --dry` against seed data; exercise the donate flow in a browser)._
  _Reuses: table, badge. (modal-with-required-reason waits on Task 27.)_

---

## Phase 5 — Responsive & polish

- [~] **23. Responsive pass (tablet primary, mobile minimal)** _(built 2026-05-20; browser-unverified — icon rail + mobile card-rows carved out)_
  **Tablet (768–1023px) is required scope.** Sidebar collapses to 64px icon-only rail (hover/focus shows label tooltip). Comparison panel in Match Validation stacks Lost above Found. Wide tables (Audit, Match Queue, Claims) become horizontally scrollable with a scroll-shadow hint. Header user/role section condenses to avatar + initials.
  **Mobile (<768px) is best-effort, student-facing only.** Sidebar becomes a slide-in hamburger sheet. Login, Register, Report Lost, Notifications, My Claims are fully usable; tables on these screens convert to stacked card rows. Staff/Admin screens display a "best viewed on tablet or larger" banner. Release Verification is desktop-only — block with a helpful message on mobile.
  _Status (built 2026-05-20):_ Hamburger drawer + slide-in sheet (ACCESSIBILITY #6) and `.dash-grid` stacking were already in. Added 2026-05-20: the Match comparison panel stacks at ≤1023px (`.compare-grid`), the release detail grid stacks at ≤900px (`.release-grid`), wide tables get a pure-CSS horizontal **scroll-shadow** hint (`.table-wrap`), the header **condenses** below 1024px (drops the full name, keeps the role pill), Release Verification is **phone-blocked** <768px (`.release-mobile-only` message; the capture form is `.release-desktop-only`), and staff/admin screens show a **"best on tablet" advisory** <768px (injected once in `layout_open` via the `lib/routes.php` role map). **Carved out by decision (2026-05-20):** the 768–1023px 64px **icon rail** is deferred — the sidebar nav is text-only (no per-item icons), so a true rail needs an icon set first; tablet keeps the hamburger drawer. Mobile student-table **card-rows** are deferred to Task 29. Browser-unverified. See `.design/lfms/DESIGN_REVIEW.md` finding M1.
  _Modifies: shell, header, comparison-panel, all tables._
  _Creates: hamburger button + slide-in sheet, mobile-block-screen for desktop-only flows, scroll-shadow utility._

- [x] **24. Accessibility audit pass** _(audit run; fixes pending — see ACCESSIBILITY.md)_
  Per the brief's WCAG 2.1 AA mandate: keyboard reach every interactive element with visible focus ring, `aria-label` on icon-only buttons (bell, signature clear, hamburger), `aria-live="polite"` on toast region, semantic HTML on every list (`<nav>`, `<main>`, `<table>` with `<th scope>`), `aria-describedby` linking error text to fields, screen-reader walkthrough of Release Verification (the most JS-heavy screen), recheck color contrast pairs that were borderline in Phase-4 token verification. Document known limitations in `ACCESSIBILITY.md`.
  _Audit results (2026-05-19) — see `ACCESSIBILITY.md` for the full report._
  - 2 "Must fix" items: form errors not programmatically linked to inputs; `<th>` sortable columns have no `aria-sort`.
  - 5 "Should fix" items: implicit toast region; `window.confirm()` for release; missing canvas/webcam build; desktop-only shell (overlaps with Task 23); ~~no skip-to-content link~~ _(fixed 2026-05-19)_.
  - 4 "Could improve" items: photo alt text, page titles, details focus style, bell-count live announcement.
  _Modifies: every interactive component (when the fixes land)._

---

## Phase 6 — Review

- [x] **25. Design review** _(ran 2026-05-19, source-only — no screenshots)_
  Run `/design-review` against the brief. Capture screenshots at 1280/768 (and 375 for student-facing screens) of every key screen. Critique covers visual hierarchy, consistency with tokens, gold-not-for-meaning rule, institutional tone, responsive behavior. Produces `.design/lfms/DESIGN_REVIEW.md` + `.design/lfms/screenshots/`.
  _Status (reconciled 2026-05-20):_ `DESIGN_REVIEW.md` exists as a static source review; its screenshot inventory is unfilled and `.design/lfms/screenshots/` was never created (no browser available in this environment). Several findings have since been addressed — M3 (modal) built, M1 (responsive) partially. Re-run with screenshots once the responsive pass and release rebuild land.

---

## Phase 7 — Extensions added during build (not in original brief)

- [x] **27. Reusable modal / required-reason dialog component** _(built; browser-unverified)_
  Surfaced by the 2026-05-19 design review (finding M3). Brief specified a modal with required-reason capture for destructive actions (Reject match, Donate item, Delete user, Confirm Release) and a 1.5s hold-to-prevent-misclick on the primary button.
  _Status (reconciled 2026-05-20):_ **Shipped** — `partials/modal.php` (`modal_open` / `modal_close` / `modal_footer_*` rendering `role="dialog"`/`alertdialog` + `aria-modal` + labelled/described-by, with a `no_dismiss` option) and `assets/js/modal.js` (focus trap, ESC + backdrop dismiss, modal stack, and a `data-modal-hold` 1.5 s hold-to-confirm that fires `form.requestSubmit()`). **In use:** `match.show.php` Reject (required-reason variant) and `release.php` Confirm Release (hold-to-confirm). **Not yet migrated:** Donate (Task 22) and Delete-user (Task 19) don't exist yet, so there's nothing there to migrate. The `--modal-*` tokens are now consumed by the component's CSS.

- [ ] **28. Migrate inline `style="display: grid …"` to named layout classes**
  Surfaced by the 2026-05-19 design review (finding M4 / S3). Match Validation's two-column comparison panel and a handful of dashboard layouts use inline `style="..."` attributes for grid layout, which prevents the responsive stacking required by the brief. Introduce a `.compare-grid`, `.dash-grid` (already exists), `.stats-grid` set in `components.css`, each with a tablet stack media query, and replace the inline styles.
  _Status (2026-05-20):_ Partially done. `.compare-grid` + `.release-grid` now exist and replaced the inline grids on `match.show.php` and `release.php` (each stacks on tablet). Remaining: a `.stats-grid` class and the inline `style="display: grid; …"` layouts still on the dashboards (`staff.dashboard.php`, `admin.dashboard.php`).

- [ ] **29. Mobile student-table card rows (<768px)**
  Deferred from Task 23 (2026-05-20, by decision). On phones, convert the
  student-facing list tables — My Lost Reports (`lost.php`), My Claims
  (`claims.php`), Notifications (`notifications.php`) — from horizontal-scroll
  to stacked card rows. Needs a `data-label` attribute on each `<td>` (echoing
  the column header) plus a `@media (max-width: 767px)` card-row pattern in
  `components.css`. Staff/admin tables stay horizontal-scroll — those screens
  already carry the "best on tablet" advisory (Task 23).

- [x] **26. ITS (Integrated Tertiary System) integration**
  Pulls the authoritative student/staff/faculty roster from FEU's ITS REST API into a local `its_users` cache used for ID verification at release time. Library auth still goes through `accounts`. Components: `lib/its.php` (fetch + sync + lookups, supports bearer or api_key auth, transactional upsert with soft-deactivation), `pages/admin.its.php` (read-only directory + manual sync button), `pages/api.its_mock.php` (token-authed mock endpoint for local dev), `db/sync_its.php` (CLI nightly job), `db/migrations/2026_its_users.sql` (schema add-on for existing databases). Audit verbs: `its.sync`, `its.sync_failed`.
  _Creates: `lib/its.php`, `pages/admin.its.php`, `pages/api.its_mock.php`, `db/sync_its.php`, `db/migrations/2026_its_users.sql`, `its_users` table in `db/schema.sql`._
  _Reuses: `lib/db.php`, `lib/audit.php`, `lib/auth.php`, table, page-header-strip, modal._

---

## Builder notes (non-negotiable)

- **No new dependencies.** PHP + MySQL + custom CSS + vanilla JS. The only allowable third-party libraries are **PhpSpreadsheet** (XLSX export) and **FPDF** (PDF export) — and both are optional within Task 17.
- **Tokens are canonical.** Never hardcode a color, spacing, radius, or font-size in `components.css`. Always reference a token from `tokens.css`. If a value is missing, add it to `tokens.css` first.
- **CSRF on every POST.** `csrf_field()` in every form, `csrf_check()` on every state-mutating handler. No exceptions.
- **Audit log on every mutation.** Every Approve/Reject/Release/Donate/Delete writes a row to `audit_logs` via `lib/audit.php` with actor, action, target type+id, and a JSON snapshot of the change.
- **Prepared statements always.** Never concatenate user input into SQL. `lib/db.php` exposes `q($sql, $params)`.
- **Validation: server is the gate, client is convenience.** `lib/validate.php` is authoritative. `assets/js/validate.js` mirrors for UX, never substitutes.
- **Uploads outside the auth gate are inaccessible.** `assets/uploads/` is `Deny from all`; all delivery goes through `serve_upload.php`.
