# Browser verification — FEU LFMS `[~]` backlog

> Work through this top-to-bottom. Highest-risk first.
> Tick `[x]` as you go. Paste any failures back to Claude with the section
> letter + what broke. When every box is ticked, flip ROADMAP §1/§2 `[~]` → `[x]`
> and delete this file.

---

## Seed accounts (all share password `password123`)

| Role  | Email                        | Notes |
|-------|------------------------------|-------|
| Admin | admin@feu.edu.ph             | Full access |
| Staff | alice.reyes@feu.edu.ph       | Logs found items, validates matches |
| Staff | bob.santos@feu.edu.ph        | |
| User  | juan.delacruz@feu.edu.ph     | Reporter of lost bag LFMS-2026-00001 |
| User  | maria.santos@feu.edu.ph      | |

**Base URL:** `http://localhost/feulib/index.php`  
All links below are `?p=<token>` appended to that base.

---

## Setup — advance the seed data to a releasable state

The seed ships one match (id=1, status=`pending`) between Juan's lost bag and
Alice's found bag. The release flow requires `pending_verification` status on a
claim. Do this setup **once** before running sections F–H:

1. Log in as **Alice (staff)**.
2. Go to `?p=match.show&id=1` → click **Approve**. Match → `approved`.
3. Log out. Log in as **Juan (user)**.
4. Go to `?p=dashboard` → open the notification → follow the claim link, or
   go directly to `?p=claim.new&match=1`.
5. Step 1: confirm ownership → Step 2: upload any image as ID proof → Submit.
6. Claim is now `pending_verification`. Note the claim ID from the URL
   (`?p=claim.show&id=N`) — you'll need it for sections F–H.
7. Log out. Log back in as **Alice (staff)** for release testing.

---

## A. Reject-a-match modal (Task 27)

> Re-seed a fresh `pending` match first if you already approved match 1 above.
> Either log a new found item and let matching run, or insert one via phpMyAdmin.

URL: `?p=match.show&id=<a pending match>`

- [ ] Click **Reject** → dialog opens; focus jumps to the reason textarea; page
      scroll locks (`body.modal-open`).
- [ ] **Tab** repeatedly — focus cycles: textarea → Cancel → Reject button →
      back to textarea. It never escapes the dialog.
- [ ] Submit with textarea **empty** — form blocked; error shown on the textarea.
- [ ] Type a reason, click Reject — match flips to `rejected`; redirected to the
      queue list.
- [ ] Open another pending match's Reject dialog → press **Esc** — dialog closes;
      focus returns to the Reject button.
- [ ] Open again → click the **backdrop** — closes.

---

## B. Hold-to-confirm on Release (Task 27 / Task 13)

URL: `?p=release&claim=<claim id from Setup step 6>`

*(Do the full release setup in sections F + G first; this is the final confirm step.)*

- [ ] With signature drawn and selfie captured, click **Confirm release** →
      summary modal opens.
- [ ] **Tap then immediately release** the gold confirm button — nothing fires;
      the progress fill resets.
- [ ] **Hold** the button — a white fill sweeps left-to-right over ~1.5 s; on
      completion the form submits automatically.
- [ ] In the modal, click **Cancel** — modal closes; form is unchanged; no
      submission fired.

---

## C. Signature pad (Task 13)

URL: `?p=release&claim=<claim id>` — "Claimant signature" section.

- [ ] A `<canvas>` renders above the file input; raw `<input type="file">` is
      hidden; **Clear** and **Upload a signature image instead** are visible.
- [ ] Draw strokes with the mouse — ink renders; status reads **"Signature
      captured."**
- [ ] DevTools → Elements: `#signature` input `.files` has one entry
      `signature.png`. (Right-click the input → "Show in Sources" → check Files.)
- [ ] Draw on a touch device — strokes render; **the page does not scroll** while
      drawing (`touch-action: none` on the canvas).
- [ ] Click **Clear** — canvas empties, status clears, `.files` is empty again.
- [ ] Click **Upload a signature image instead** — canvas + toolbar hide; native
      file input appears and receives focus. Pick an image — it sticks.
- [ ] Toggle back to the pad — file input hides; pad returns ready to draw.

**Server guard:**
- [ ] Leave the canvas blank, don't pick a fallback file, complete the
      hold-to-confirm — server returns **"Signature photo is required."**

---

## D. Selfie capture (Task 13)

URL: same release page — "Photo with claimant" section.

- [ ] A **Start camera** button and **Upload a photo file instead** link appear;
      raw file input hidden.

**Happy path (camera allowed):**
- [ ] Click **Start camera** → browser permission prompt appears.
- [ ] Allow → live video preview; status **"Camera ready…"**; **Take photo** button
      appears.
- [ ] Click **Take photo** → frame freezes as a still preview; camera light turns
      **off** (stream stopped); **Retake** appears. DevTools: `#selfie` `.files`
      has one entry `selfie.jpg`.
- [ ] Click **Retake** → preview clears; returns to **Start camera**.

**Permission-denied path:**
- [ ] Deny the prompt → status shows **"Camera unavailable…"** with a note to use
      the file upload. No JS crash. File upload option still works.

**Navigation cleanup:**
- [ ] Start the camera, then press the browser **Back** button — camera light turns
      **off** (`pagehide` handler stops the stream).

**Server guard:**
- [ ] Leave selfie empty and complete hold-to-confirm — server returns **"Selfie
      with claimant is required."**

---

## E. Release end-to-end (Task 13)

URL: `?p=release&claim=<claim id>`, status `pending_verification`.

- [ ] Tick all three checklist boxes (ID checked / identity matches / item
      matches). **Confirm release** button stays disabled until all three ticked.
- [ ] Draw signature + capture selfie (or use fallback files), optionally add
      a note. Click **Confirm release** → hold-to-confirm modal.
- [ ] Hold button ~1.5 s → release fires. Flash: **"Item released. The claimant
      has been notified."** Page reloads showing the Release record card with
      released-by name, timestamp, and both attachments.
- [ ] Log in as **Juan (user)** → dashboard shows **"Your item has been released"**
      notification linking to the claim.
- [ ] Reload the release page as staff → form is gone; read-only release record
      only. No second-release possible.

**Phone block:**
- [ ] DevTools → toggle device emulation to 375 px wide → the release form is
      replaced by the **"This screen requires a tablet or larger"** message.

---

## F. Toast / notification bell (A11y #3, Task 20)

- [ ] DevTools → Elements: `#toast-region` exists in `<body>` with
      `aria-live="polite"` and `aria-atomic="false"`.
- [ ] Hard-reload on any authenticated page → `#toast-region` is **empty**.
- [ ] Trigger a notifiable event in another account (approve a match for a user)
      → within 60 s, a `<p>` appears in `#toast-region` with the notification
      title. At most 5 `<p>` children accumulate.
- [ ] Bell icon's `aria-label` attribute updates to include the unread count
      (e.g. "Notifications (3 unread)"). Check in DevTools after a poll fires.

---

## G. Field errors / `aria-describedby` (A11y #1)

URL: `?p=lost.new` — submit with required fields blank.

- [ ] Each errored input has both `aria-invalid="true"` **and**
      `aria-describedby="<name>-error"`. The matching `<p id="<name>-error">`
      contains the error text.
- [ ] Spot-check `?p=login` (bad password) and `?p=register` (mismatched
      passwords) — same pattern present.

---

## H. Sortable tables / `aria-sort` (A11y #2)

On each page below: click a column header to sort, then check DevTools that
the active `<th>` has `aria-sort="ascending"` (or `"descending"` on second
click), and **no other `<th>`** has an `aria-sort` attribute.

- [ ] `?p=found` — sort by any column
- [ ] `?p=matches`
- [ ] `?p=claims`
- [ ] `?p=staff.claims`
- [ ] `?p=staff.dashboard` — match queue column
- [ ] `?p=staff.dashboard` — claims queue column

DevTools shortcut: Elements search for `aria-sort` — should find exactly one
match per table at any given time.

---

## I. Responsive shell (Task 23, A11y #6)

**Desktop (≥1025 px):**
- [ ] Sidebar is a fixed left column; no hamburger button in the header.

**Tablet / mobile (≤1024 px — use DevTools device emulation):**
- [ ] Hamburger button appears in the header; sidebar is off-screen.
- [ ] Tap hamburger → sidebar slides in as a sheet with backdrop; focus moves
      to the first sidebar item.
- [ ] **Tab** — focus stays inside the drawer.
- [ ] Press **Esc** → drawer closes; focus returns to the hamburger button.
- [ ] Tap a nav link → drawer closes and the page navigates.
- [ ] Tap the backdrop → drawer closes.

**≤480 px:**
- [ ] User's full name hidden in header; role pill stays visible.
- [ ] Main-content padding is tighter (≤480px rule).

**Tablet advisory (staff/admin screens below 768 px):**
- [ ] Log in as staff or admin; shrink to 375 px → a gold-stripe advisory banner
      appears at the top of the main content: "This screen is built for a tablet
      or larger…"
- [ ] Same page at 768 px → banner is hidden.

**Match comparison panel:**
- [ ] `?p=match.show&id=1` at 1024 px — Lost and Found panels are **side by
      side** (`.compare-grid`).
- [ ] Same page at 800 px — panels **stack vertically** (Lost above Found).

---

## J. Admin Audit Log (Task 18)

URL: `?p=admin.audit` (log in as admin).

- [ ] Table renders with filters: actor search, action-type select, date range.
- [ ] Apply a filter — URL updates with query params; results narrow.
- [ ] Click a row with a diff → **Show changes** expands inline with JSON diff
      in monofont.
- [ ] Pagination controls appear when > 50 entries. Rows-per-page selector works.
- [ ] Click **Export CSV** in the page header → browser downloads a `.csv` file.
      Open it — all filtered rows present (no pagination limit), including a
      "Changes" column with JSON.

---

## K. Admin Settings — all 6 tabs (Task 19)

URL: `?p=admin.settings` (log in as admin).

**Tab: Users & Roles**
- [ ] Paginated account list renders with search.
- [ ] Change a user's role → saved; audit log entry written.
- [ ] Deactivate a user → their account can no longer log in.
- [ ] Guard: cannot deactivate yourself; cannot demote the last admin.

**Tab: Storage Locations**
- [ ] List shows all 3 seed locations with item counts.
- [ ] Add a new location → appears in the list and in the Found Item dropdown.
- [ ] Edit a location name → saved.
- [ ] Deactivate a location → removed from the Found Item dropdown; existing
      found items referencing it are unaffected.

**Tab: Holding Period**
- [ ] Current value shows (default 365). Change it → saved. Reload → new value.

**Tab: Notification Rules**
- [ ] Toggles for each event type render in a grid layout (no horizontal overflow).
- [ ] Disable a notification type → the corresponding event no longer creates a
      notification (test by approving a match and checking juan's notifications).

**Tab: Match Scoring Weights**
- [ ] Five number inputs render in a grid. Change a weight → saved. Reload →
      new value persists.

**Tab: Backup Status**
- [ ] Panel shows `last_backup_at` timestamp (or "No backup recorded yet").
      Read-only — no editable fields.

---

## L. Holding-period expiry + donation workflow (Task 22)

**CLI expiry job:**
- [ ] Open a terminal. Run: `php db/expire_items.php --dry`
      Output should list items that *would* expire — no DB changes. No PHP errors.
- [ ] Run without `--dry`. Check phpMyAdmin — aged `open` found/lost reports and
      stale `pending`/`needs_info` matches are now `expired` / `rejected`. Audit
      log entries present for each expiry.

**Admin expiry surface:**
- [ ] `?p=admin.dashboard` — **"Items expiring in next 30 days"** card shows a
      count (may be 0 with fresh seed data; adjust `holding_period_days` to 1
      temporarily if you want a non-zero count to appear).

**Bulk-donate workflow:**
- [ ] `?p=admin.donate` — lists `expired` found items with checkboxes.
- [ ] Select one or more → click **Donate selected** → required-reason modal opens.
- [ ] Submit without a partner/beneficiary note → blocked.
- [ ] Enter a note → confirm → selected items move to `donated` status; audit
      log entries written. Items no longer appear in the donate list.

---

## M. Mobile student-table card rows (Task 29)

DevTools device emulation at 375 px.

- [ ] `?p=lost` (My Lost Reports) — rows render as **stacked cards**, not a
      horizontal table. Each cell has a label above the value (via `data-label`
      attribute + `::before` CSS). No horizontal scroll.
- [ ] `?p=claims` — same card-row layout.
- [ ] `?p=notifications` — list-item layout already stacks naturally (uses `.list`,
      not `.data-table`); confirm nothing overflows at 375 px.

---

## N. a11y polish

**Photo alt text (A11y #8):**
- [ ] `?p=found.show&id=1` — image `alt` reads **"Bag, navy"**, not the generic
      "Photo of the found item". Check in DevTools.
- [ ] `?p=match.show&id=1` — both lost and found photos have differentiated alt
      text (category + colour).

**Page titles (A11y #9):**
- [ ] `?p=match.show&id=1` — browser tab reads **"Match LFMS-2026-00001 /
      LFMS-2026-F-00001 · FEU LFMS"**.
- [ ] `?p=claim.show&id=1` — title includes the claim reference number.

**`<details>` focus ring (A11y #10):**
- [ ] `?p=dashboard` — **Tab** to the "How does this work?" `<summary>` — a
      visible 2 px focus ring appears (not the browser default, which varies).
- [ ] **Enter** → panel toggles; focus ring stays visible.

---

## Reporting back

When done:
- **All pass** → flip every `[~]` in ROADMAP §1 and §2 to `[x]`, then delete
  this file. Only §4 (operations / deployment) remains before the project ships.
- **Any failures** → paste the section letter and what went wrong (e.g.
  "C — camera light stays on after Retake", "K Storage tab — deactivate button
  gives a 500"). Claude will scope and fix.
