# Design Review — FEU LFMS

Review date: **2026-05-21** (rev 2 — full source re-review)
Reviewed against: `.design/lfms/DESIGN_BRIEF.md` + `INFORMATION_ARCHITECTURE.md`
Philosophy: **FEU Tamaraw Classic** — Institutional Modern
Method: Static source review of all pages, partials, CSS, and JS. No screenshots captured.

> **Context.** The original review (2026-05-19) identified 4 Must-fix structural gaps and 11
> accessibility findings. This review re-examines each one against the current codebase,
> adds new observations, and produces a fresh prioritized list. Where a finding is resolved,
> it is noted inline with the commit/task that closed it.

---

## What was found and closed between reviews

| ID | Original finding | Closed by |
|---|---|---|
| M1 | No responsive shell at any breakpoint | Task 23 — hamburger drawer, `.tablet-recommended`, `.release-desktop-only / -mobile-only`, `.compare-grid` ≤1023px, scroll-shadow, header condensed at ≤1024px |
| M2 | Release flow shipped file inputs instead of canvas+webcam | Task 13 re-opened — `signature-pad.js` + `selfie-capture.js` progressive enhancement |
| M3 | No modal / required-reason dialog anywhere | Task 27 — `partials/modal.php` + `assets/js/modal.js`, used in Reject (required reason) and Release (hold-to-confirm) |
| M4 | Comparison panel uses non-responsive inline grid | Task 28 — `.compare-grid`, `.release-grid`, `.dash-grid`, `.aside-grid`, `.reports-grid` named in `components.css`; all dashboard pages clean |
| S1 | Confirm Release uses `btn-primary` (green), not gold | Task 13 — `release.php:337,375` now use `btn-accent`; confirmed in source |
| A11y #1 | Form errors not linked to inputs programmatically | `lib/view.php` — `field_aria()` emits `aria-invalid="true" aria-describedby="<name>-error"`; `field_error_html()` emits matching `id`; 34 call sites across 9 form pages |
| A11y #2 | Sortable `<th>` has no `aria-sort` | `lib/view.php` — `sort_aria()` helper added; called on every sortable column in `claims.php`, `found.php`, `matches.php`, `staff.claims.php`, `staff.dashboard.php` |
| A11y #3 | Toast region is implicit | `partials/layout.php:42` — `<div id="toast-region" class="sr-only" aria-live="polite" aria-atomic="false">` now in the shell; `notifications.js` writes to it via `announce()` with notification title on each count increase |
| A11y #4 | `window.confirm()` on Release | `modal.js` hold-to-confirm replaces it; the 1.5 s press gate and immutable-release warning are in place |
| A11y #5 | Signature pad and selfie were never built | `signature-pad.js` + `selfie-capture.js` progressive-enhance the file inputs; file-upload fallback toggle always visible |
| A11y #6 | Shell desktop-only — WCAG 1.4.10 failure | Task 23 — hamburger sheet ≤1024px, phone block on release, tablet advisory on staff/admin screens |
| A11y #7 | No skip-to-content link | Fixed 2026-05-19 — `.skip-link` → `#main-content` in `layout.php` |
| A11y #8 | Photo alt text generic | Already done — `lost.show.php`, `found.show.php`, `match.show.php` all use `category_label() . ', ' . $color` |
| A11y #9 | Detail page titles don't include ref numbers | Already done — `lost.show.php:61`, `found.show.php:67`, `match.show.php:214`, `claim.show.php:79`, `release.php:195` all include the ref in `layout_open()` |
| C4 | Footer unknown/empty | `partials/footer.php` — "FEU Library · Lost & Found Management System · 2026" — clean, institutional |

---

## Summary

The codebase has made substantial progress since the first review. All four Must-fix structural
gaps and all seven of the original should-fix/must-fix accessibility findings are now closed in
source. The system reads as a coherent institutional tool: token discipline is strong, semantic
markup is the default, and the handful of remaining gaps are well-contained.

The primary must-fix item in this review is a **silent notification update** — the bell badge is
updated by JS polling without any screen-reader announcement, despite a `#toast-region` now
existing in the shell. The second is a **single remaining inline grid** in `admin.settings.php`
that escaped Task 28. Everything else is a should-fix or polish item.

---

## Must Fix

### M1. ~~Bell badge updates are silent to screen readers~~ — RESOLVED (pre-existing)

`notifications.js` already has a complete `announce()` function (lines 36–50) that appends
notification titles to `#toast-region` whenever the unread count rises. `updateBell()` also
patches `aria-label` on the bell anchor with the live count. This finding in the v1 review was
based on an incomplete scan of the JS file — the implementation was already correct.

---

### M2. One inline grid escaped Task 28 — **FIXED 2026-05-21**

**File:** `pages/admin.settings.php:305`

```html
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--space-3);">
```

This is the notification-rules checkbox grid on the Admin Settings page. It was not migrated
during Task 28 because `auto-fill/minmax` is inherently responsive (the grid reflowss itself
without a media query), but it still violates the project's style rule against inline layout
attributes and blocks any future override.

**Fix applied:** `.notify-grid` added to `components.css` (Task 28 section); `admin.settings.php:305`
inline style replaced with `class="notify-grid"`. Zero visual difference.

---

## Should Fix

### S1. ~~Detail page titles don't include ref numbers~~ — RESOLVED (pre-existing)

All five detail pages already pass the reference number into `layout_open()`: `lost.show.php:61`,
`found.show.php:67`, `match.show.php:214` (both refs), `claim.show.php:79`, `release.php:195`.

### S2. ~~Photo alt text is generic~~ — RESOLVED (pre-existing)

`lost.show.php`, `found.show.php`, and `match.show.php` (both panels) all use
`category_label() . ', ' . $color` as alt text. No generic fallback remaining.

### S3. 64px icon-rail at 768–1023px — still intentionally deferred

The brief required a 64px icon-only rail at tablet widths. It was deferred in Task 23
because the sidebar navigation has no per-item icons — a true rail needs an icon set first.
The current implementation (hamburger drawer at all widths ≤1024px) is functional but does
not match the brief.

This is not a blocker in the current environment (LAN-only, staff-terminal use) but should
be scheduled when an icon set is chosen. **Prerequisite:** decide on an icon approach (inline
SVG sprites, a CSS icon font, or Unicode fallbacks) before attempting the rail.

### S4. ~~Sort indicator glyphs may render in emoji font on Windows/Edge~~ — **FIXED 2026-05-21**

`sort_link()` in `lib/view.php` now emits compact inline SVG triangles (`▲`/`▼`/`⇅` shapes)
using `fill="currentColor"` and `focusable="false"`. Immune to emoji-font substitution;
renders with the same stroke weight as all other icons in the shell.

### S5. `notifications.php` has no card-row treatment on mobile

`claims.php` and `lost.php` correctly use `.table-wrap-cards` for the phone breakpoint (Task 29).
`notifications.php` uses the `.list` / `.list-item` pattern instead of a table — it stacks
naturally and does not need `.table-wrap-cards`. However, the brief's Task 29 spec listed it
as a target. Verify in a browser that the `.list-item` layout is comfortable at 375px; if items
truncate or the unread dot is misaligned, a small `max-width` clamp on `.list-item-text` may help.

---

## Could Improve

### C1. Card stripe is always FEU green — no status-driven modifiers

Every `.card` renders its left stripe in `--card-bar-color` (FEU green), regardless of the
card's content type. The brief mentions "Confidence over speed" and "immutable warning banner"
for release — but the immutable-warning card on `release.php` and the expiry-soon card on
the admin dashboard currently show the same green stripe as a neutral info card. A `.card-warning`
and `.card-danger` modifier (swapping the stripe to `--color-warning` or `--color-danger`)
would let status-sensitive cards communicate their severity at a glance without adding new visual
vocabulary. Low priority — the hierarchy reads fine without it.

### C2. ~~Token `--color-brand-accent` undefined~~ — **FIXED 2026-05-21**

`components.css:1254` (`.confirmation-next` left stripe) was using
`var(--color-brand-accent, var(--color-accent-secondary))`. `--color-accent-secondary` IS
defined in `tokens.css:101` as `var(--feu-gold)`, so the stripe was already rendering in gold
— but the dead `--color-brand-accent` primary reference was confusing. Replaced with a direct
`var(--color-accent-secondary)` reference. The gold "next steps" stripe on confirmation
screens is now explicit and unambiguous.

### C3. ~~`.app-header-user` double-hide rule~~ — **FIXED 2026-05-21**

`.app-header-user span:first-child { display: none }` existed at both ≤480px (line 637) and
≤1024px (line 1757). Since 480 < 1024 the narrower rule was fully subsumed — a confusing
duplicate. The ≤480px rule was removed; the ≤1024px rule alone now covers the full range.

### C4. Step indicator on the claim flow is not in the component inventory

`pages/claim.new.php` ships a multi-step UI. The brief's component inventory lists a
"Step indicator" as a new component. Confirm it has CSS in `components.css` and not just
inline styles, and that it uses the established token set.

---

## What Works Well — keep doing this

- **Token discipline is near-perfect.** `components.css` references named tokens throughout.
  The one inline-grid remnant in `admin.settings.php` stands out precisely because everything
  else is clean. No raw hex values found outside `tokens.css`.

- **`field_aria()` + `field_error_html()` pattern is correct and complete.** 34 call sites,
  matching IDs on input and error paragraph — this is a textbook implementation of WCAG 3.3.1.
  Future form pages should follow the same pattern.

- **`sort_aria()` closes the loop on sortable tables correctly.** The helper is tightly paired
  with `sort_link()` and emits `aria-sort="ascending|descending"` only on the active column.

- **Semantic markup stays the default.** `<main role="main">`, `<nav>`, `<table>` with `<th scope>`,
  `<dl>` for detail grids, `<fieldset>`/`<legend>` on checklist and radio groups — every
  reviewed page uses the right element, not a `<div>` surrogate.

- **Status badges carry text, always.** No color-only status anywhere. All 8 lifecycle states
  have a text label in the badge, closed by the token contrast calibration in `tokens.css:61-83`.

- **`prefers-reduced-motion` is respected.** Both the sidebar slide animation (`components.css:642`)
  and the modal open animations (`components.css:1593`) are disabled under the system preference.

- **Audit trail is complete and consistent.** Every state-mutating handler in the reviewed pages
  writes to `audit_logs` with actor, action, target type + id, and a JSON snapshot via `lib/audit.php`.
  No handler found that mutates state without an audit entry.

- **The modal component is correctly architected.** `partials/modal.php` + `modal.js` form a
  clean, accessible dialog system: focus trap, ESC-to-close, backdrop-click, `aria-modal="true"`,
  labelled by title, described by body. The `no_dismiss` option correctly locks destructive modals.
  The 1.5 s hold-to-confirm prevents misclick on irreversible actions.

- **Gold is used exactly once as paint.** The `btn-accent` style appears only on `release.php`'s
  Confirm Release button — exactly the "one designated accent-button" use the brief prescribes.
  Every other gold reference is decorative (header underline, card left bar, bell badge count).

---

## Sign-off

- [x] M1 — Bell badge / toast-region (was already correct in `notifications.js`)
- [x] M2 — `.notify-grid` class + `admin.settings.php:305` inline style removed (2026-05-21)
- [x] S1 — Reference numbers in detail page `<title>` tags (pre-existing)
- [x] S2 — Photo alt text from category + color (pre-existing)
- [x] S4 — Sort indicator SVG triangles replacing Unicode arrows (2026-05-21)
- [x] C2 — `--color-brand-accent` dead reference removed; direct `--color-accent-secondary` (2026-05-21)
- [x] C3 — Duplicate ≤480px hide rule removed from `components.css` (2026-05-21)
- [x] All structural gaps from v1 review closed (M1–M4, S1)
- [x] All original accessibility must-fix / should-fix items closed (A11y #1–9)
- [ ] S3 — 64px icon rail (blocked on icon-set decision — intentional deferral)
- [ ] Browser verification of all `[~]` tasks (13, 18, 19, 22, 23, 27) still pending

---

---

# Design Review — FEU LFMS (rev 3)

Review date: **2026-05-25**
Reviewed against: `.design/lfms/DESIGN_BRIEF.md`
Philosophy: **FEU Tamaraw Classic** — Institutional Modern
Method: Static source review of `assets/css/` (all three files) and all 35 `pages/*.php`. No screenshots captured (no browser tool available; visual findings marked `[~]`).

> **Scope.** This review covers work landed since rev 2 (2026-05-21): button system
> enhancements (`components.css` + `admin.settings.php` — S29/S30), `data-table-static`
> sticky-header opt-out applied across all tables, `filter-bar` moved inside card containers,
> and horizontal padding added to `app-content`.

---

## What closed since rev 2

| ID | Finding | Closed by |
|---|---|---|
| (new) | Filter-bar was a sibling of `.card` on `claims.php`, `staff.claims.php`, `matches.php` | Moved inside `.card` — 2026-05-24 |
| (new) | Sticky table header overlapping first data row | `.data-table-static` opt-out applied to every table — 2026-05-24 |
| (new) | Primary button hover text was near-invisible (CSS specificity conflict `a:hover` > `.btn-primary`) | Explicit `.btn-primary:hover:not(:disabled)` rule now takes precedence — 2026-05-24 |
| (new) | No pressed/active feedback on buttons | Inset `box-shadow` added to `:active:not(:disabled)` on all five variants — 2026-05-24 |
| (new) | Deactivate actions visually identical to neutral actions | `.btn-warning` class created; applied conditionally in `admin.settings.php` — 2026-05-24 |

---

## Summary

The button system is the most significant change in this cycle and it's directionally right:
the new amber warning state communicates secondary-destructive intent clearly, the inset
active shadows give the flat design physicality, and the gold primary-hover is distinctly
on-brand. Two token-layering inconsistencies were introduced with the new button variant;
neither breaks anything today but both create maintenance ambiguity.

The bigger structural gap is the sticky table header: the brief lists it as a required
component, the ROADMAP described the correct fix (`overflow-y: clip` + `position: sticky`),
but that fix was never applied — instead all tables were opted out. Long list tables
(staff claims queue, found items, match queue, lost reports) are currently non-sticky
on a desktop-1080p layout that could meaningfully benefit from them.

Three inline `style=""` attributes in `admin.audit.php` are the only remaining violations
of the no-inline-layout rule established in Task 28.

---

## Must Fix

None. All prior must-fixes remain closed.

---

## Should Fix

### S1. Sticky table headers not implemented on long list tables

**Files:** `assets/css/components.css:765–780`, `pages/found.php`, `pages/lost.php`,
`pages/matches.php`, `pages/staff.claims.php`, `pages/staff.dashboard.php` (both tables)

The design brief component inventory explicitly requires: *"Sticky header on tall tables."*

**Current state:** `.data-table thead th` has no `position: sticky` — sticky headers
were never (re-)enabled after the overflow bug. `.data-table-static thead th { position: static }`
overrides a base that doesn't set sticky, making the opt-out class a no-op. Every table in the
application — including long paginated queues that would genuinely benefit from a fixed
header — has `data-table-static` applied.

**Root cause:** The ROADMAP (2026-05-23 notes) correctly described the fix:
> "Fixed by adding `overflow-y: clip` to `.table-wrap`; unlike `hidden`, `clip` does not create
> a scroll container, so sticky escapes to the viewport."
The CSS change was never applied; the `data-table-static` approach was used as a workaround
instead, and then applied to all tables.

**Fix:** Two CSS lines restore the feature correctly:

```css
/* In .table-wrap: */
overflow-y: clip;   /* add alongside overflow-x: auto */

/* In .data-table thead th: */
position: sticky;
top: var(--shell-header-height);   /* 64px */
z-index: var(--z-raised);          /* 10 — above rows, below header */
```

Then remove `data-table-static` from the long list tables (keep it on the reference /
summary tables: `admin.settings.php` ×2, `admin.its.php`, `admin.donate.php`,
`admin.report.show.php` ×4, `dashboard.php`, `found.show.php`).

**Visual verification required** `[~]` — confirm header sticks at 64px with no gap and
no overlap with the app-header on scroll.

---

### S2. `.btn-warning` bypasses semantic token layer

**File:** `assets/css/components.css:296–307`

```css
/* Current — uses Layer 1 raw brand tokens directly */
.btn-warning { background: var(--amber-bg); color: var(--amber-fg); border-color: var(--amber-border); }
```

The token architecture in `tokens.css` establishes a strict three-layer rule:
*Layer 1 (brand) → Layer 2 (semantic) → Layer 3/4 (component).* Components must consume
semantic tokens, not raw palette tokens, so a single edit to `tokens.css` propagates
everywhere. The semantic equivalents already exist:

| Raw token used | Semantic equivalent (should use) |
|---|---|
| `--amber-bg` | `--color-warning-bg` |
| `--amber-fg` | `--color-warning` |
| `--amber-border` | `--color-warning-border` |

The hover state (`background: var(--amber-fg)` for solid fill) is an acceptable direct
reference — no semantic "solid warning as background" token exists — but the resting
state can and should use the semantic layer.

Additionally, all other button variants have a `--btn-*` component token group in
`tokens.css` §4 (`--btn-primary-bg`, `--btn-ghost-fg-hover`, `--btn-danger-bg-hover`,
etc.). The new warning variant has none, so its source of truth is split between
`tokens.css` and `components.css` inconsistently.

**Fix (two parts):**

1. In `components.css:296`, replace raw tokens with semantic tokens in the resting state:
   ```css
   .btn-warning { background: var(--color-warning-bg); color: var(--color-warning); border-color: var(--color-warning-border); }
   ```

2. In `tokens.css` §4 COMPONENT TOKENS, add a `--btn-warning-*` group after the existing
   `--btn-danger-*` entries:
   ```css
   --btn-warning-bg:         var(--color-warning-bg);
   --btn-warning-fg:         var(--color-warning);
   --btn-warning-border:     var(--color-warning-border);
   --btn-warning-bg-hover:   var(--amber-fg);     /* solid fill on hover — intentional */
   --btn-warning-fg-hover:   var(--color-text-inverse);
   ```
   Then consume these in `components.css`.

---

### S3. Three inline `style=""` attributes in `admin.audit.php`

**File:** `pages/admin.audit.php:139, 144, 147, 157`

Task 28 explicitly removed all inline `style="display:grid…"` layout attributes from
every page. Three inline styles were missed in `admin.audit.php`:

- Line 139: `<form class="filter-bar" style="flex-wrap: wrap; gap: var(--space-3);">`
- Line 144: `<input class="form-control" style="flex: 1; min-width: 180px;">`
- Line 147: `<select class="form-control" style="width: auto;">`
- Line 157: `<div style="display: flex; align-items: center; gap: var(--space-2);">`

**Fix:** Create a `.filter-bar-audit` modifier in `components.css` (or a `.filter-bar-flex`
utility) and a `.field-flex` helper, replacing the inline styles. The date-range `<div>`
wrapper can become `.filter-date-range` with the flex rule in CSS.

---

## Could Improve

### C1. No `.card-warning` / `.card-danger` stripe modifiers

Carried forward from rev 2 C1. The immutable-release warning panel on `release.php` and
any expiry-warning cards use the same green left stripe as neutral info cards. A
`.card-warning { --card-bar-color: var(--color-warning); }` modifier (two lines per variant)
would communicate severity at a glance without new visual vocabulary.

### C2. `data-table-static` should be documented as a forward-compat stub

Until S1 is fixed, the class has no effect. A CSS comment on the rule (`/* No-op until
position:sticky is re-enabled on .data-table thead th — see S1 in DESIGN_REVIEW.md */`)
prevents future confusion about why the override exists.

### C3. 64px icon rail still deferred

Carried forward from rev 2 S3. Prerequisite: icon-set decision. Not a blocker.

---

## What Works Well — keep doing this

- **Button active states are done right.** `inset 0 1px 3px rgba(0,0,0,…)` on `:active`
  is the correct flat-design physical-press affordance — subtle enough to stay institutional,
  clear enough to feel responsive. All five variants are consistent.

- **`.btn-warning` semantic intent is precise.** Amber at rest = caution; solid amber on
  hover = intent confirmed. Activating only on `is_active` records in `admin.settings.php`
  — the conditional `<?= $isActive ? 'btn-warning' : 'btn-ghost' ?>` pattern — means the
  button changes meaning with the record state. That's correct UI.

- **Amber color tokens are AA-compliant.** `--amber-fg: #8a5800` on `--amber-bg: #fff4e0`
  was pre-verified in `tokens.css` (comment: *"was #d48806 — failed AA on amber-bg"*). The
  new warning button inherits a correctly-calibrated palette.

- **filter-bar is consistently inside `.card` across all main list pages.** `claims.php`,
  `staff.claims.php`, `lost.php`, `found.php`, `matches.php` all wrap filter-bar and
  table-wrap inside the same card element. Visual separation between controls and data is
  gone; the card reads as one unit.

- **Token discipline held through new work.** The button additions in `components.css` use
  no raw hex values. All color references trace back to `tokens.css`. The layering slip
  (S2) uses *wrong-layer* tokens, not hardcoded values — easier to fix.

- **CSS cache busting (`?v=<filemtime>`) means this review's fixes will load without a
  hard refresh** — the stylesheet link in `layout.php` already appends a version stamp.

---

## Sign-off

- [x] Filter-bar moved inside card (claims.php, staff.claims.php, matches.php) — 2026-05-24
- [x] `.data-table-static` opt-out applied to all tables — sticky-header overlap eliminated — 2026-05-24
- [x] Primary button hover text color fixed (specificity bug) — 2026-05-24
- [x] Active/pressed states added to all 5 button variants — 2026-05-24
- [x] `.btn-warning` class created; applied to Deactivate actions in admin.settings.php — 2026-05-24
- [x] S1 — `overflow-y: clip` on `.table-wrap`; sticky `thead th` restored; `data-table-static` removed from found/lost/matches/staff.claims/staff.dashboard (2026-05-25)
- [x] S2 — `.btn-warning` resting state uses `--color-warning-*` semantic tokens; `--btn-warning-*` component group added to tokens.css (2026-05-25)
- [x] S3 — Inline styles in `admin.audit.php` replaced with `.filter-bar-wrap`, `.field-flex`, `.field-auto`, `.filter-date-range` classes (2026-05-25)
- [ ] C1 — `.card-warning` / `.card-danger` stripe modifiers (low priority, not a blocker)
- [x] C2 — `.data-table-static` comment updated — no longer a no-op after S1 fix (2026-05-25)
- [ ] S3 (from rev 2) — 64px icon rail (blocked on icon-set decision)
- [~] S1 visual — confirm sticky header sits at 64px, no gap, no overlap on scroll (browser verification needed)
