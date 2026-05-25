# Information Architecture: FEU LFMS

> Reads alongside `DESIGN_BRIEF.md`. Defines page hierarchy, URL strategy, navigation, and the critical user flows.
> **URL pattern:** front controller — `index.php?p=<page>[&...args]`
> **Auth model:** session-based; `index.php` runs auth gate before dispatching.

---

## Site Map

```
Public (unauthenticated)
- Login                          /index.php?p=login
- Register                       /index.php?p=register
- Forgot Password (v1: contact admin)  /index.php?p=forgot

User (student/faculty) — role: user
- User Dashboard (default)       /index.php?p=dashboard
- Report Lost Item               /index.php?p=lost.new
  - Confirmation                 /index.php?p=lost.created&id=<id>
- My Lost Reports (list)         /index.php?p=lost
  - Report Detail                /index.php?p=lost.show&id=<id>
- My Notifications               /index.php?p=notifications
- My Claims                      /index.php?p=claims
  - Submit Claim                 /index.php?p=claim.new&match=<match_id>
  - Claim Detail                 /index.php?p=claim.show&id=<id>
- My Profile                     /index.php?p=profile

Staff — role: staff (sees User pages plus the below)
- Staff Dashboard (default)      /index.php?p=staff.dashboard
- Log Found Item                 /index.php?p=found.new
  - Confirmation                 /index.php?p=found.created&id=<id>
- Found Items (list)             /index.php?p=found
  - Found Detail                 /index.php?p=found.show&id=<id>
- Match Review Queue             /index.php?p=matches
  - Match Validation Screen      /index.php?p=match.show&id=<id>
- Claims Queue                   /index.php?p=staff.claims
  - Claim Verify & Release       /index.php?p=release&claim=<id>

Admin — role: admin (sees Staff + User pages plus the below, all under /admin)
- Admin Dashboard                /index.php?p=admin.dashboard
- Reports                        /index.php?p=admin.reports
  - Report Detail / Export       /index.php?p=admin.report.show&id=<id>
- Audit Log                      /index.php?p=admin.audit
- Settings (tabbed)              /index.php?p=admin.settings&tab=users
  - Users & Roles                tab=users
  - Storage Locations            tab=locations
  - Holding Period                tab=holding
  - Notification Rules           tab=notify
  - Match Scoring Weights        tab=scoring
  - Backup Status                tab=backup

System
- Logout                         /index.php?p=logout
- 403 Forbidden                  /index.php?p=403
- 404 Not Found                  /index.php?p=404
- 500 Server Error               /index.php?p=500
```

## Navigation Model

### Primary navigation (left sidebar, role-aware)

| Item | User | Staff | Admin |
|---|:---:|:---:|:---:|
| Dashboard | ● | ● | ● |
| Report Lost Item | ● | ● | ● |
| My Lost Reports | ● | ● | ● |
| Notifications | ● | ● | ● |
| My Claims | ● | ● | ● |
| Log Found Item | | ● | ● |
| Found Items | | ● | ● |
| Match Review | | ● | ● |
| Claims Queue | | ● | ● |
| ── Admin ── | | | (section divider) |
| Reports | | | ● |
| Audit Log | | | ● |
| Settings | | | ● |

- Items are grouped visually with a thin divider between *User actions*, *Staff actions*, and *Admin actions*.
- The active item has a 4px green left bar.
- Sidebar collapses to icon-only rail at <1024px (tablet); becomes a hamburger sheet at <768px.

### Secondary navigation

- **Tabs inside Settings** for the six setting categories.
- **Filter chips** on list pages (`/p=lost`, `/p=found`, `/p=matches`, `/p=staff.claims`, `/p=admin.audit`): Status filter, Date range, Search box.
- **Breadcrumbs** on every page deeper than the dashboard: `Dashboard › Found Items › Found Detail`.

### Utility navigation (header, right side)

- Notification bell (with unread count) → `/p=notifications`
- User name + role pill (clickable → `/p=profile`)
- Logout button → `/p=logout`

### Mobile navigation (<768px)

- Sidebar becomes a slide-in sheet, triggered by a hamburger button in the header.
- Header collapses: logo + hamburger + bell + avatar only. Title shifts to a sub-header strip on the page.
- Tables convert to stacked card rows on student-facing screens (Lost reports, Notifications, Claims). Staff/admin tables remain horizontally scrollable.

## Content Hierarchy

### User Dashboard (`/p=dashboard`)

1. **Welcome strip** with user's first name and role pill — confirms identity at a glance.
2. **Primary CTA: "Report a Lost Item"** — single biggest button on the page. This is the action 80% of first-time users come for.
3. **Notifications panel** (latest 3, with "see all" link) — surfaces match approvals or status changes immediately.
4. **My Lost Reports** (compact table, latest 5) — lets returning users see status without an extra click.
5. **Help footer** ("How does this work?" expandable) — onboarding for first-time users.

### Staff Dashboard (`/p=staff.dashboard`)

1. **Stat strip** — 4 cards: Open Lost · Open Found · Matches awaiting validation · Claims pending verification.
2. **Match Validation Queue** (table, default sort: highest score first) — the staff cycle-time bottleneck; goes above the fold.
3. **Claims Queue** (table, default sort: oldest claim first) — second-priority work.
4. **Log Found Item button** (top-right of page header strip) — common staff action.

### Match Validation Screen (`/p=match.show&id=`)

1. **Header** with match ID, score chip, breadcrumb.
2. **Side-by-side comparison panel** — Lost (left), Found (right). Photo top, attributes below. Field diff highlighting in soft amber for mismatches.
3. **Score breakdown card** (collapsed by default) — factor weights and contributions.
4. **Action bar (sticky bottom)** — Approve / Needs Info / Reject. Always visible while scrolling.
5. **History thread** below the fold — prior staff notes, timestamps.

### Release Verification (`/p=release&claim=`)

1. **Read-only summary** of claim — what's being released, to whom.
2. **Verification checklist** (3 checkboxes; all must be ticked to proceed).
3. **Signature pad**.
4. **Selfie capture** (or file-upload fallback).
5. **Confirm Release button** — gold accent, disabled until checklist + signature + selfie all complete.
6. **Warning banner**: "Release is permanent and writes to the audit log."

### Admin Dashboard (`/p=admin.dashboard`)

1. **Stat strip** — 6 cards: Total Lost, Total Found, Match rate %, Avg time to claim, Items in storage, Items expiring soon.
2. **Recent Activity table** (last 25 audit-log entries) — operational visibility.
3. **Quick Links** — Generate Report · Manage Users · Configure Policies.

## User Flows

### Flow A — Student loses an item and gets it back

1. Student lands on **Login** → enters credentials → arrives on **User Dashboard**.
2. Clicks **"Report a Lost Item"** → arrives on **Report Lost form**.
3. Fills form, uploads optional photo, submits.
   - On success → **Confirmation screen** with reference number `LFMS-2026-00471`.
   - On validation error → inline errors, focus returns to first invalid field.
4. Student leaves. System generates match candidates in the background.
5. Days later, student logs in → **Dashboard** shows a notification: *"A match was proposed for your report LFMS-2026-00471."*
6. Clicks notification → arrives on **My Claims (Submit Claim)** form (a claim ticket was auto-created by staff approval).
7. Confirms ownership, uploads ID proof, submits → **Claim Detail** with status `PENDING` and pickup instructions.
8. Student visits library counter → Staff verifies → student signs + selfie → walks away with item.
9. Returning student sees `RELEASED` status on the claim record.

### Flow B — Staff logs a found item and validates the match

1. Staff lands on **Login** → arrives on **Staff Dashboard**.
2. Clicks **"Log Found Item"** → arrives on **Log Found form**.
3. Fills form, uploads photo, assigns storage location, submits → confirmation.
4. System runs matching algorithm immediately, generates candidates against open lost reports.
5. Staff goes to **Match Review Queue** → top of list shows highest-scoring new match.
6. Clicks into **Match Validation Screen** → reviews side-by-side comparison.
   - **Approve** → user is notified, claim ticket created, item status → `MATCHED`, match status → `APPROVED`.
   - **Reject** → modal opens, staff enters reason → match status → `REJECTED`, item stays open.
   - **Needs Info** → modal opens, staff enters question → notification sent to user, match status → `PENDING_INFO`.

### Flow C — Staff releases an item to a verified claimant

1. Claimant arrives at counter with reference number.
2. Staff opens **Claims Queue** → finds the claim → clicks **Verify & Release**.
3. Arrives on **Release Verification** screen.
4. Staff visually compares claimant's ID to the uploaded proof, ticks each checklist item.
5. Hands tablet/stylus to claimant for **signature**. Claimant signs, clicks Done.
6. Staff clicks **Capture Selfie** — browser prompts for camera permission.
   - **Permission granted** → live preview → click Capture → preview → Accept or Retake.
   - **Permission denied / no camera** → control swaps to file upload.
7. All three checklist items are ticked, signature exists, selfie exists → **Confirm Release** button activates (gold accent).
8. Staff clicks → 1.5-second hold-to-prevent-misclick → confirmation modal → **Confirm**.
9. System writes ReleaseLog + AuditLog entry (immutable). Claim status → `RELEASED`. Item status → `RELEASED`.
10. Success screen shows transaction summary; staff prints receipt (optional v1.5).

### Flow D — Admin generates a monthly report

1. Admin lands on **Login** → arrives on **Admin Dashboard**.
2. Clicks **Reports** in sidebar → arrives on **Reports** page.
3. Selects date range (last month preset) + report type (Operational Summary) → clicks Generate.
4. Server processes, returns preview on **Report Detail** page.
5. Admin clicks **Export → CSV** (or XLSX, or PDF) → file downloads.

### Flow E — New user self-registers

1. Visitor lands on **Login** → clicks "Register".
2. Arrives on **Register** form. Fills full name, role (Student/Faculty radio), ID number, email, password, confirm.
3. Server validates ID number format → on success creates account with role = `user` (default).
   - On duplicate email → inline error, focus on email field.
4. User is auto-logged-in → lands on **User Dashboard** with a one-time onboarding card explaining how to report a lost item.

### Flow F — Holding period expiration (system-driven, surfaced to admin)

1. Daily backup job (12:00 PM) also runs an expiry scan.
2. Items found > 365 days ago with status `OPEN` or `MATCHED` (never claimed) are marked `EXPIRED`.
3. Admin Dashboard "Items expiring soon" card surfaces upcoming expiries (next 30 days).
4. Admin can review expired items and mark them `DONATED` (writes to audit log with partner-beneficiary note).

## Naming Conventions

| Concept | Label in UI | Notes |
|---|---|---|
| A report filed by someone who lost something | **Lost Report** | Not "lost item" — the report is the record; the item may or may not exist. |
| A record of an item turned in to staff | **Found Item** | Not "found report" — it has been physically secured, not just reported. |
| The algorithmic pairing of a Lost Report with a Found Item | **Match** | Carries a 0–100 **score**. |
| The user's request to take possession after a match | **Claim** | Carries a **reference number** like `LFMS-2026-00471`. |
| The physical transaction of giving the item to the claimant | **Release** | One-way and immutable. |
| The bin/shelf in the back office | **Storage Location** | E.g., "Bin A-3". Managed in Admin Settings. |
| Score factor explanation | **Match factors** | Not "score components" or "weights" in the UI tooltip. |
| Approve / Reject / Needs Info | **Decision** | A staff Decision is part of every match's history. |
| Audit row | **Audit entry** | Never "log line". |
| Status flag for unclaimed > 365d | **EXPIRED** | Distinct from `DONATED` (which is what admin sets it to next). |

## Component Reuse Map

| Component | Used on | Behavior differences |
|---|---|---|
| App Shell (header + sidebar + main) | Every authenticated page | Sidebar role-aware; main padding constant. |
| Auth Card layout | Login, Register, Forgot, 403/404 | No sidebar; centered card on light bg. |
| Page header strip (H1 + breadcrumb + action button) | Every authenticated page | Action button optional. |
| Data Table | Lost lists, Found lists, Matches, Claims, Audit Log, Admin Users | Filter chips above, pagination below. Sticky header on >20 rows. |
| Filter Chips bar | All list views | Status, date range, search. Stored in URL query params. |
| Confirmation Screen | Lost.created, Found.created, Claim.created | Reference number + next-steps panel. Same template, different copy. |
| Status Badge | Tables, Detail pages, Card headers | One color per status; never alone — always with text. |
| Comparison Panel | Match Validation only | Two-column on desktop; stacked on tablet/mobile. |
| Signature Pad | Release Verification only | Mouse + touch; clear + done. |
| Selfie Capture | Release Verification only | getUserMedia + canvas; file-upload fallback. |
| Stat Card | User Dashboard, Staff Dashboard, Admin Dashboard | Same component, different values + optional trend caption. |
| Modal (confirm + reason capture) | Reject match, Donate item, Delete user, Confirm release | Required-reason variant has a textarea. |
| Notification Bell + dropdown | Header on every authenticated page | Polls every 60s. Click bell → quick-list dropdown; click "See all" → /p=notifications. |
| Empty State | Every list view + dashboards on first login | Same illustration slot (CSS-only), heading + helper text + CTA. |

## Content Growth Plan

| Section | Growth pattern | IA accommodation |
|---|---|---|
| Lost Reports | Linear, ~ a few per day | Paginated table, status filter, date range filter, search by reference number or keyword. Archive view (filter: status = `EXPIRED` or `DONATED` or `RELEASED`). |
| Found Items | Linear, similar volume | Same paginated table pattern. |
| Matches | Quadratic-ish in worst case (every new found item runs against all open lost reports) — practically bounded | Match table defaults to status = `PENDING` only. Resolved matches accessible via filter. |
| Claims | Tracks Matches | Default view shows in-flight claims; closed claims hidden behind filter. |
| Audit Log | Monotonic — never shrinks | Always paginated, default 50 rows. Filterable by actor, action type, date range, target entity. Export to CSV for offline retention. |
| Notifications | Per-user; auto-prunes read > 60d | List view paginated; older notifications archived but not deleted. |
| Users (Admin > Users) | Linear, hundreds to low thousands | Paginated table; search by name, ID number, email. Filter by role and active/inactive. |

## URL Strategy

- **Pattern:** `index.php?p=<page>[&...args]`
- **Page tokens** use dot-notation for hierarchy: `lost.new`, `lost.show`, `admin.settings`. This keeps the front-controller routing table flat while reading hierarchically.
- **Args**: `id`, `match`, `claim`, `tab`, plus list-view filters as query parameters:
  - `status` — single status code, e.g. `status=OPEN`
  - `from`, `to` — ISO date strings for date-range filter
  - `q` — search string (URL-encoded)
  - `page`, `per_page` — pagination (defaults: 1, 25)
  - `sort`, `dir` — sort column and direction
- **Reserved page tokens:** `login`, `register`, `forgot`, `logout`, `403`, `404`, `500`.
- **Auth gate:** `index.php` checks session → role → permission for `p` → dispatches to `pages/<token>.php` module. Unauthenticated requests for non-public tokens redirect to `login` with a `?next=` param to return after login.
- **Clean URLs** (e.g., `/dashboard` instead of `/?p=dashboard`) are explicitly *deferred* — front controller works today; rewriting via `.htaccess` is a coat of paint added later without breaking links.

## Role-Permission Matrix (URL access)

| Page token | Anonymous | User | Staff | Admin |
|---|:---:|:---:|:---:|:---:|
| `login`, `register`, `forgot`, 4xx | ✓ | ✓ | ✓ | ✓ |
| `dashboard`, `lost*`, `notifications`, `claim*`, `profile`, `logout` | | ✓ | ✓ | ✓ |
| `staff.dashboard`, `found*`, `matches`, `match.show`, `staff.claims`, `release` | | | ✓ | ✓ |
| `admin.*` | | | | ✓ |

Any role can fall through to a higher-privileged view; Admin sees everything in one continuous sidebar grouped by section dividers.

---

*IA written 2026-05-17. Next phase: Design Tokens.*
