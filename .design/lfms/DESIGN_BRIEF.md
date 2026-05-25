# Design Brief: FEU Library Lost & Found Management System (LFMS)

> **Theme name:** *FEU Tamaraw Classic*
> **Stack:** PHP + MySQL (XAMPP) · server-rendered PHP · vanilla JavaScript · custom CSS with CSS variables · **no frameworks, no libraries**
> **Deployment:** LAN-only, library-internal server
> **Slug:** `lfms`

---

## Problem

A student rushes back to the library twenty minutes before her next class. She left her ID and her notes on a study desk. The student assistant at the counter scribbles "blue notebook + ID, table 4" on a piece of paper and shoves it in a drawer. There is no number to call back, no way to know if anyone turned anything in, and no way to prove the item was hers when she finally claims it.

For staff, the problem mirrors the user's: paper logs go missing, duplicate reports waste shelf space, claimants can't be verified, and there is no audit trail when an item is released. When an item is unclaimed for months, nobody knows what to do with it. The current process is slow, opaque, and accountability-free — exactly the opposite of what a library should stand for.

## Solution

LFMS is a quiet, institutional web tool that lives on the library LAN and replaces the paper drawer. It standardizes how lost items are reported, how found items are logged, how the system proposes matches, and how a release is recorded with proof. It feels less like a "modern app" and more like a piece of the library itself — calm, classic, formal, and trustworthy.

Three users meet on one platform:

- **Students/Faculty** open a clean form, describe what they lost, optionally upload a photo, and walk away knowing the system is watching for matches.
- **Library Staff** log every found item against a shelf location, review the system's match proposals on a side-by-side comparison screen, and execute a release with a signature and a selfie — captured right there on the staff terminal.
- **Library Admins** see the whole picture in a quiet dashboard: counts, trends, audit logs, exportable reports, and policy controls.

The UI is the FEU green-and-gold uniform of the project — institutional, restrained, and unmistakably a library system.

## Experience Principles

1. **Institutional over trendy** — The system must read as *FEU Library*, not as a SaaS product. We pick the classic option over the fashionable one every time. Borders are clean lines, not glassy gradients; corners are 4–6px, not 16; cards have a left accent stripe, not a drop shadow halo.
2. **Confidence over speed** — A lost item is stressful for a student and high-stakes for staff (wrong release = real consequence). Every destructive or irreversible action (release, reject, donate) shows clear confirmation, captures a reason, and writes to the audit log. Empty states and form errors are explicit and reassuring, not cute.
3. **Density where it serves, calm where it doesn't** — Student-facing screens (report a lost item, see my notifications) are spacious and welcoming. Staff-facing screens (match queue, claims queue, audit log) are dense, scannable, table-driven, and optimized for cycle time. The same design tokens dress both, but the layout shifts.

## Aesthetic Direction

- **Philosophy:** *Institutional Modern* — clean, professional, faintly formal. Picture a well-maintained university intranet from a school that takes itself seriously, but redrawn with current best practices in spacing, contrast, and accessibility. Think bank-of-record meets library-card-catalog.
- **Tone:** Formal, calm, authoritative. Speaks in full sentences, no exclamation marks. Microcopy is direct ("Submit lost item report", "Approve match"), not playful.
- **Reference points:** University registrar portals; classic government services done right (gov.uk's calm tone, not its palette); old-school library catalog OPACs; Tailwind UI's "application" templates stripped of ornament.
- **Anti-references:** Stripe-style gradients · glassmorphism · bouncy framer-motion choreography · dark sidebars with neon accents · "fintech minimalism" with pastel illustrations · any aesthetic that would look out of place on a printed FEU document.

## Brand & Color Identity

| Token | Value | Use |
|---|---|---|
| `--feu-green` | `#006400` | Primary actions, header background, table header, focus rings, primary brand |
| `--feu-green-dark` | `#004d00` | Pressed states, hover on primary, sidebar active item |
| `--feu-gold` | `#FFD700` | Accent only — header underline, card left border, badge highlights. **Never a full background.** |
| `--bg` | `#f7f9f7` | Page background |
| `--surface` | `#ffffff` | Cards, modals, table rows, form fields |
| `--text` | `#222222` | Body text |
| `--text-muted` | `#5a6b5a` | Secondary text, helper labels |
| `--border` | `#e2e6e2` | Dividers, input borders, card borders |
| `--success` | `#1f8f3a` | "Released", "Approved", success toast |
| `--warning` | `#d48806` | "Pending", "Needs Info" |
| `--danger` | `#c0392b` | "Rejected", "Expired", destructive button |
| `--info` | `#1c6ea4` | "Open", neutral status |

Gold is jewelry, not paint. It appears as the header underline, as the left stripe on cards, and as the accent on small status pills — never as a full button fill except for the one designated "Accent" button class (used sparingly for celebratory actions like "Confirm Release").

## Typography

- **System font stack only.** No external font loading. `font-family: "Segoe UI", Tahoma, Arial, sans-serif;`
- Type scale (modular, 1.125 ratio, base 16px):
  - `--fs-xs` 12px · `--fs-sm` 14px · `--fs-base` 16px · `--fs-md` 18px · `--fs-lg` 20px · `--fs-xl` 24px · `--fs-2xl` 30px
- Weights: 400 body · 600 emphasis/labels · 700 page titles (no 800/900 — too loud for this aesthetic)
- Line-height: 1.5 body · 1.25 headings
- All caps used only for status badges and column headers, with `letter-spacing: 0.04em`.

## Layout

- **Header** (60px tall): FEU green background · 3px gold bottom border · system title left-aligned in white · user name + role pill + logout button right-aligned.
- **Sidebar** (240px wide, collapsible to 64px on small screens): white background · vertical menu · 4px green left bar on the active item · green text on hover · role-aware (User sees 3 items, Staff sees 6, Admin sees 9).
- **Main content area** (fluid, max-width 1280px, 32px padding): light bg, cards stack vertically with consistent 24px gaps.
- **Page header strip**: H1 (page title), breadcrumb, primary action button right-aligned.

## Existing Patterns

There are no existing components, tokens, or pages in this project — `C:\xampp\htdocs\feulib` is greenfield except for the `.claude/skills` directory. Everything in this brief is being established from scratch, which means the tokens file we generate in Phase 4 becomes the *source of truth* for everything that follows.

## Component Inventory

| Component | Status | Notes |
|---|---|---|
| App shell (header + sidebar + main) | New | Single shared PHP layout include. Role-aware sidebar. |
| Button — primary, accent, ghost, danger, link | New | Solid green primary; gold accent (rare); white-with-border ghost; red danger. 36px default height, 44px on touch. |
| Card | New | White surface, 1px border, 4px green left bar, 16–24px padding. No drop shadow. |
| Form field (text/number/email/password/textarea/select/file) | New | Stacked label, helper text, error state in red. Green 2px focus ring. |
| Photo upload (drag/drop + click) | New | Vanilla JS only. Shows thumbnail preview. Validates type + size client-side, re-validates server-side. |
| Data table | New | Green header row (white text), 1px row dividers, hover row in soft green tint. Sticky header on tall tables. Sortable column indicators. |
| Pagination | New | Page numbers + prev/next. 25/50/100 rows-per-page selector. |
| Status badge | New | Pill, 12px text, all caps. One per state: `OPEN`, `MATCHED`, `PENDING`, `APPROVED`, `REJECTED`, `RELEASED`, `EXPIRED`, `DONATED`. |
| Match score chip | New | 0–100 with traffic-light color (≥70 green, 40–69 amber, <40 red). Shows on Match list + Match comparison screen. |
| Side-by-side comparison panel | New | Two columns: "Lost" left, "Found" right. Field-by-field rows with diff highlighting. Photo lightbox. Action bar at bottom: Approve / Reject / Needs Info. |
| Signature pad | New | HTML5 canvas. Mouse + touch. Clear + Done buttons. Saves to PNG via `toDataURL`. |
| Selfie capture | New | `getUserMedia` for webcam preview, capture-on-click to canvas, retake + accept. Falls back to file upload if no camera. |
| Notification bell + dropdown | New | Header icon with unread count. Polls in-app notifications endpoint every 60s (no WebSockets). |
| Toast / inline alert | New | Top-right toast for transient feedback; inline alerts for form-level errors. |
| Modal / confirm dialog | New | For destructive actions (Reject, Donate, Delete) and Release confirmation. Always captures reason for destructive paths. |
| Empty state | New | Centered icon (CSS only — no icon library; we'll use Unicode symbols or minimal inline SVG), heading, helper text, primary CTA. |
| Breadcrumb | New | Plain text trail with `›` separators. |
| Stat card (admin dashboard) | New | Large number + label + small trend caption. No charts in v1 (keep it institutional). |
| Audit log row | New | Timestamp · actor · action · target · diff snippet. Mono font for IDs. |

## Key Screens

1. **Login** — Centered 380px card on the light bg. FEU header bar across the top. Username + password fields. "Forgot password" link (resets routed through admin in v1). Below the card: "Don't have an account? **Register**".
2. **Register** — Same centered card pattern. Fields: Full name, role (Student/Faculty), Student/Employee number, Email, Password, Confirm. Server validates that the ID matches enrollment format before submission.
3. **User Dashboard** — Welcome strip, "Report a Lost Item" primary CTA, two cards: *My Reports* (table of my lost reports + status), *Notifications* (latest 5).
4. **Report Lost Item** — Single-column form. Fields: Item category, color, brand/identifying marks, description, date lost, last seen location (free text + map of library zones eventually), photo (optional). On submit → confirmation screen with reference number.
5. **Staff Dashboard** — Strip of stat cards (open lost, open found, awaiting validation, claims pending), then *Match Validation Queue* table, then *Claims Queue* table.
6. **Log Found Item** — Same form pattern as Report Lost but with extra fields: storage location (dropdown — managed in Admin settings), date found, finder name (staff member). Photo strongly encouraged.
7. **Match Validation** — Side-by-side comparison panel. Lost report on left, found item on right. Score chip in the header. Action bar: Approve / Reject (requires reason) / Needs Info (requires note). On Approve: triggers notification to user + creates claim ticket placeholder.
8. **My Claim** (user) — Step 1: Confirm this is yours. Step 2: Upload ID proof. Step 3: See pickup instructions ("Visit the Lost & Found counter, Mon–Sat, 8am–5pm. Bring this reference number: LFMS-2026-00471").
9. **Release Verification** (staff) — Checklist (ID checked, claimant matches reported owner, item matches photo), signature pad, selfie capture, "Confirm Release" — the only screen that uses the gold accent button. On confirm → permanent ReleaseLog with all attachments + writes to audit log.
10. **Admin Dashboard** — Stat cards (totals + this-week deltas), Reports section (date-range picker + export CSV/XLSX/PDF), Audit Log table (filterable by actor/action/date).
11. **Admin Settings** — Tabs: Users & Roles · Storage Locations · Holding Period (currently 1 year) · Notification rules · Match scoring weights · Backup status.

## Key Interactions

- **Submit-and-confirm pattern.** Every form ends on a dedicated confirmation screen with a reference number, not a toast. Library users will print/screenshot this.
- **Match score reveals on hover.** The score chip shows just the number; hover/focus reveals a tooltip with the contributing factors ("category +30, color +20, location +15, date proximity +10, photo similarity +0").
- **Destructive actions are two-step.** Reject/Donate/Delete open a modal requesting a reason, with a 1.5-second-disabled primary button to prevent accidental click-through.
- **Release is one-way.** Once confirmed, the ReleaseLog is immutable. The screen makes this explicit before final confirmation.
- **Notification bell polls every 60s.** No WebSockets (LAN, low traffic, simple). Unread count updates without page reload.
- **Webcam permission asked once.** If denied, selfie field gracefully falls back to a file upload control, with a note explaining why the photo is required.

## Responsive Behavior

- **Desktop (≥1024px):** Sidebar visible, full layout.
- **Tablet (768–1023px):** Sidebar collapses to icon-only rail (64px). Tables become horizontally scrollable; comparison panel stacks Lost above Found on the narrowest tablets.
- **Mobile (<768px):** Best-effort only — staff release flow and admin tools are not optimized for mobile. Student-facing screens (Login, Register, Report Lost, My Claim, Notifications) remain fully usable: sidebar becomes a hamburger sheet, forms go full-width, tables convert to card rows.
- Breakpoints in tokens: `--bp-sm: 640px`, `--bp-md: 768px`, `--bp-lg: 1024px`, `--bp-xl: 1280px`.

## Accessibility Requirements

- **WCAG 2.1 AA** minimum.
- Color contrast: all text ≥ 4.5:1; large text ≥ 3:1. **Note:** Gold (#FFD700) fails contrast on white — it is *never* used for text or for icons that carry meaning. It is decorative.
- Keyboard navigation: every interactive element reachable by Tab; visible 2px green focus ring on all focusable elements.
- Screen reader: semantic HTML (`<nav>`, `<main>`, `<table>` with proper headers); `aria-label` on icon-only buttons (notification bell, signature clear); `aria-live="polite"` on toast region.
- Form labels: every input has a visible `<label>`; error messages associated via `aria-describedby`.
- Signature pad and selfie capture both have file-upload fallbacks for users who cannot use a pointer device or do not have a camera.
- Status is never conveyed by color alone — every badge includes its text label.

## Out of Scope

- **No mobile app.** Web only.
- **No external integrations** beyond an optional future enrollment-verification API.
- **No SMS or email notifications in v1.** In-app only.
- **No charts/graphs in v1.** Stat cards with numbers only. Reports export raw data; visualization is a "could" feature.
- **No dark mode.**
- **No localization beyond English** (Filipino translation is a future task).
- **No real-time updates.** Polling only.
- **No public-internet access.** Authentication assumes LAN-bound trust; no MFA in v1, no rate-limiting-as-a-service.
- **No advanced search / filters beyond category + status + date range** in v1.
- **The engineering deliverables in section 9 of the original brief** (full PRD, ERD with DDL, REST endpoint catalog, security model, test plan, roadmap) are not produced by this design brief. The brief covers UX/UI direction only. We can produce those as separate documents alongside the build.

---

*Brief written 2026-05-17. Next phase: Information Architecture.*
