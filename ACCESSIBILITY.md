# Accessibility audit — FEU LFMS

Audit date: 2026-05-19
Target conformance: **WCAG 2.1 Level AA** (per `DESIGN_BRIEF.md`).
Scope: every page under `/pages/`, the app-shell partials, and the three CSS files. Static review only — no automated scanner run, no browser walkthrough yet.

Findings are ordered by severity. Each "Must fix" item blocks AA conformance; "Should fix" items are real gaps the brief explicitly required; "Could improve" is polish.

---

## What's already good

These were verified in the codebase and are working as intended:

- **Semantic landmarks present.** `<header role="banner">`, `<main role="main">`, `<aside aria-label="Primary navigation">`, `<nav>` and `<table>` elements used appropriately. Active sidebar item uses `aria-current="page"`. `partials/header.php:17`, `partials/sidebar.php:11`, `partials/layout.php:44`.
- **Status badges convey meaning via text, not just color.** Every badge prints an uppercase label (`OPEN`, `PENDING`, `APPROVED`, …) regardless of palette. `lib/view.php:106`.
- **Tokens were calibrated for contrast.** `tokens.css:61-83` notes which status colors were darkened to clear 4.5:1 against their pastel backgrounds. `--green-fg` and `--amber-fg` were explicitly adjusted from the brief's draft values.
- **Notification bell has aria-label and the SVG icon is `aria-hidden="true"`.** `partials/header.php:22-24`.
- **Sort indicators are aria-hidden.** Arrow glyphs are decoration; the link's text label carries the meaning. `lib/view.php:250`.
- **Flash region uses role="status" / role="alert".** Success messages are polite; errors are assertive. Consistent across 30+ render sites.
- **Search inputs have aria-label.** Verified in `found.php`, `lost.php`, `matches.php`, `admin.audit.php`, `admin.its.php`, `staff.claims.php`, `staff.dashboard.php`.
- **Form labels are linked.** Every `<input>` and `<select>` reviewed has a `<label for="…">` partner.
- **Global `:focus-visible` ring.** `base.css:119` applies a 2px green outline with 2px offset to every keyboard-focused element. Sidebar items have an extra inset rule (`components.css:523`) so the ring sits inside their left bar. Keyboard navigation has a consistent visible ring everywhere.

---

## Must fix (blocks AA conformance)

### 1. Form errors not programmatically linked to their inputs
Every field that can fail validation renders an error like this:

```html
<div class="field field-error">
  <label for="color">Primary color</label>
  <input id="color" name="color" class="field-input" required>
  <p class="field-error-text">This field is required.</p>
</div>
```

A sighted user sees the error sitting under the field. A screen-reader user moves focus to the input and hears only the label and value — the error text is unannounced. WCAG 3.3.1 (Error Identification) and 1.3.1 (Info and Relationships) require the connection to be programmatic.

**Fix pattern:**
```html
<input id="color" aria-invalid="true" aria-describedby="color-error">
<p id="color-error" class="field-error-text">This field is required.</p>
```

**Scope:** Every form with field-level errors. Audit grep: `field-error-text|form-error` returns ~25 sites across `lost.new.php`, `register.php`, `claim.new.php`, `found.new.php`, `profile.php`, `admin.settings.php`, `admin.reports.php`, `match.show.php`, `release.php`.

### 2. Sortable column headers don't expose sort state to screen readers
The `<th>` wrapping a `sort_link()` call has no `aria-sort` attribute, so a screen-reader user can't tell which column is currently sorted or in which direction. WCAG 4.1.2 (Name, Role, Value).

**Fix:** Either pass the column-state into the table header from the page, or extend `sort_link()` to also return a `[aria-sort]` value the caller emits on the `<th>`. Minimum: when `$state['sort'] === $column`, render `<th aria-sort="ascending">` or `"descending"`; otherwise omit the attribute.

---

## Should fix (brief requirement, not yet met)

### 3. Toast region is implicit, not explicit
The brief calls for an `aria-live="polite"` toast region in the shell. Today each page emits its own `role="status"` div on render, so screen readers do announce updates after a flash redirect — but there's no persistent region for transient updates (e.g. notification poll). When Task 20's polling notifies on a new match, the badge changes silently. If LFMS later wants to surface "New notification: …" without a page reload, the live region needs to exist.

**Fix:** Add a single `<div id="toast-region" aria-live="polite" aria-atomic="false" class="visually-hidden"></div>` to `partials/layout.php`, expose it to `notifications.js`, and route announce-events there.

### 4. Inline confirm() instead of the brief's required-reason modal
`pages/release.php:316` uses `onclick="return confirm('Confirm release …')"` for the most consequential action in the system. The brief (`DESIGN_BRIEF.md:122`) and Task 12/13 specs call for a modal that captures a required reason for destructive actions (Reject/Donate/Delete) and a 1.5-second hold-to-prevent-misclick on Release. Neither is implemented — there is no modal CSS or markup anywhere in the codebase. `window.confirm()` also doesn't honour custom keyboard focus management and varies wildly by browser.

**Fix:** Build the modal + required-reason component the brief specifies. See `DESIGN_REVIEW.md` for the broader signature-pad / selfie-capture gap this sits next to.

### 5. Signature pad and selfie capture were never built
The release flow specifies an HTML5 canvas signature pad and `getUserMedia` selfie capture, with file-upload as a graceful fallback when the camera is denied. `pages/release.php` ships only the fallback path: two `<input type="file" accept="image/*">` controls. WCAG 2.1.1 (Keyboard) and 2.5.1 (Pointer Gestures) require non-pointer alternatives; today's implementation is keyboard-accessible because it's plain file inputs — but the *intended* canvas/webcam UX, when built, must include a file-upload alternative for users without a pointer or camera. Plan the a11y story before writing the JS.

### 6. The app-shell is desktop-only
Brief calls for a 64px sidebar rail at tablet widths and a hamburger sheet at mobile widths. Only two media queries exist in `components.css` (lines 856 and 1210) — both for narrow dashboard/detail grids. The sidebar stays 240px and the header keeps full padding all the way down. On a 768px tablet, sidebar + main contend for the same space; on a 375px phone the sidebar overflows and breaks the layout entirely.

Accessibility consequence: at typical tablet widths, the primary nav consumes 31% of the viewport and the main content area becomes uncomfortably narrow. At phone widths the page is unusable without horizontal scroll. WCAG 1.4.10 (Reflow) requires content to function at 320 CSS pixels without two-dimensional scroll. **Currently failing.**

This is the single largest a11y deficit. See `DESIGN_REVIEW.md` for the responsive-design half of the same finding.

### 7. Skip-to-content link _(FIXED 2026-05-19)_
Keyboard users had to Tab through the entire header + sidebar on every page load before reaching the page heading. WCAG 2.4.1 (Bypass Blocks).

**Fix shipped:** `<a class="skip-link" href="#main-content">Skip to content</a>` added at the top of `<body>` in `partials/layout.php`; matching `.skip-link` CSS in `base.css` keeps it visually offscreen until it receives focus, at which point it slides into view at top-left. The `<main>` element now carries `id="main-content"` and `tabindex="-1"` so focus moves into the content region when the link is activated.

---

## Could improve

### 8. Photo `alt` text is generic
Every uploaded photo renders with `alt="Photo of the lost item"` / `alt="Photo of the found item"`. For a comparison screen where a user wants to know "do these look like the same backpack?", a richer alt — built from the item's color + category — would help screen-reader users. Suggestion: `alt="<?= e(category_label($cat)) . ', ' . e($color) ?>"`.

### 9. Page titles don't differentiate enough
Every `<title>` ends with `· FEU LFMS`. Multiple list views (`/p=lost`, `/p=found`, `/p=matches`) have titles like "My Lost Reports · FEU LFMS" and "Found Items · FEU LFMS" — fine, but the detail pages all use the same generic page-name title rather than including the reference number. Including `LFMS-2026-NNNNN` in detail-page titles helps screen-reader users orient in browser-history navigation.

### 10. Help-footer and other `<details>` elements
Spot-checked in `pages/dashboard.php`. Native `<details>` is keyboard-accessible by default, but verify the summary's focus style is visible against the page bg.

### 11. `aria-live` on the notification bell badge
When `notifications.js` updates the unread count, the badge text changes silently. Wrap the badge counter in `aria-live="polite"` or trigger an announcement via the toast region (Should fix #4) so a screen-reader user is told "3 unread notifications" on poll-update.

---

## Out of scope for this pass

- **Automated scanner (axe, Lighthouse, Pa11y).** No browser was driven; static review only. Once "Must fix" items are addressed, re-run with a scanner before final sign-off.
- **Live screen-reader walkthrough.** Especially needed for the Release Verification flow once the canvas/webcam components are built.
- **Mobile-specific touch-target sizing.** Brief calls for 44px on touch; current `--btn-height-lg` is 44px but most buttons use `--btn-height-md` (36px). Re-check when responsive pass lands.
- **Reduced-motion respect.** `base.css:139` honors `prefers-reduced-motion`. Spot-check passes.

---

## Sign-off

Audit run by: Claude (Opus 4.7), static review
Date: 2026-05-19
Tools used: source grep, file read. **Not yet** run: NVDA, JAWS, Lighthouse, axe, browser keyboard walkthrough.
