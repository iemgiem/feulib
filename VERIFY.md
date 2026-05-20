# Browser verification — `task27modal` `[~]` commits

> Temporary working file. Delete (or `git rm`) after verification is done
> and ROADMAP `[~]` markers are flipped to `[x]`.

Each check: **where to go**, **what to do**, **what should happen**.
Tick `[x]` when verified; note any failures inline so the fix can be scoped.

Anchor commits covered: `3014fa1`, `3cf74f1`, `0ed1726`, `7f2afeb`,
`b308ea0`, plus `95aa5e4` (already on branch before the `[~]` range).
Newly merged onto `integration`: `8eb874b` (signature pad + selfie),
`c44a7d0` / `b888558` / `9739f21` (a11y-polish #8 / #9 / #10).

---

## A. Modal flows — `95aa5e4` (highest-risk; do first)

### A1. Reject-a-match modal
URL: `index.php?p=match.show&id=<a pending match>`

- [ ] Click **Reject** → dialog appears, focus jumps to reason textarea,
      page scroll is locked.
- [ ] Press **Tab** repeatedly — focus stays inside the dialog
      (cycles textarea → Cancel → Confirm → back).
- [ ] Submit with textarea **empty** — blocked client-side AND, if you
      bypass JS, the server also rejects (no empty-reason match.reject
      should ever land).
- [ ] Type a reason, confirm — match flips to `rejected`, redirect back.
- [ ] Reopen, press **Esc** — closes; focus returns to the **Reject** button.
- [ ] Reopen, click the **backdrop** — closes.

### A2. Release hold-to-confirm
URL: `index.php?p=release&claim_id=<an approved claim>`

- [ ] Fill the form, click **Release item** — summary modal opens.
- [ ] **Tap-release** the confirm button (don't hold) — nothing happens;
      no submission fires.
- [ ] **Hold** the confirm button — visible progress fills over ~1.5s;
      release lands on completion.
- [ ] Cancel modal, deliberately omit a required file (e.g., signature
      when required) — native file-input validation fires; the modal
      does not eat the validation message.

---

## B. Toast notifications — `3014fa1`

### B1. Logged in as a user with notifications
- [ ] DevTools → Elements: `#toast-region` exists with
      `aria-live="polite"`.
- [ ] Hard-reload — toast region is **empty** (no re-announcement of
      existing unread notifications).
- [ ] In another tab/account, trigger a notifiable event (new match,
      claim approved, etc.) — within the next poll interval, a fresh
      `<p>` appears in `#toast-region` with the item title.
- [ ] Trigger several more — at most 5 history entries hang around.

---

## C. Field errors — `3cf74f1`

### C1. Pick `index.php?p=lost.new` as representative
- [ ] Submit with required fields blank.
- [ ] For each error rendered, the input has `aria-invalid="true"` AND
      `aria-describedby="<id>-error"`, and a matching
      `<p id="<id>-error">` holds the error text.
- [ ] (Optional, with screen reader) Tab onto an errored field — the
      error is announced after the label.
- [ ] Smoke-check **login** (`p=login`) and **register** (`p=register`)
      — same pattern present.

---

## D. Sortable tables — `0ed1726`

### D1. Click one column header to sort, on each page below
- [ ] `index.php?p=found`
- [ ] `index.php?p=matches`
- [ ] `index.php?p=claims`
- [ ] `index.php?p=staff.claims`
- [ ] `index.php?p=staff.dashboard` (matches table)
- [ ] `index.php?p=staff.dashboard` (claims table)

For each: after clicking, the active `<th>` has
`aria-sort="ascending"` (or `descending` on a second click); inactive
columns have no `aria-sort` attribute. Quick check: DevTools → Elements
→ search "aria-sort" — exactly one match per table.

---

## E. Responsive shell / hamburger drawer — `7f2afeb`

### E1. Desktop layout
- [ ] At 1024px+: sidebar visible as a column, no hamburger button.

### E2. Mobile / tablet drawer (test at 375px and 768px)
- [ ] Hamburger button appears in the header; sidebar is hidden.
- [ ] Tap hamburger — sidebar slides in as a sheet with backdrop;
      focus moves into the drawer.
- [ ] **Tab** cycles within the drawer only.
- [ ] Press **Esc** — closes; focus returns to the hamburger button.
- [ ] Tap a nav link — drawer auto-closes and the link navigates.
- [ ] Tap the backdrop — closes.

### E3. Sub-480px tweaks
- [ ] User's full name hidden in header (role pill stays).
- [ ] Header padding tighter; main-content padding tighter.

### E4. Resize edge case
- [ ] Open the drawer at 480px, then resize past 1024px — drawer closes
      cleanly; no stuck body class blocking scroll.

---

## F. ITS auth — `b308ea0` (server-side, optional)

- [ ] If ITS is configured locally: trigger a sync, confirm it
      authenticates against the endpoint.
- [ ] If not: code-review tick — change was narrow (config restore +
      Authorization header passthrough). `config.example.php` matches
      the new shape.

---

## G. Signature pad — `8eb874b` (#5)

URL: `index.php?p=release&claim=<a claim in pending_verification>`
(needs a claim where the user has uploaded ID; check `staff.claims`).

### G1. Progressive enhancement renders
- [ ] Above the **Claimant signature** file input: a canvas appears, with a
      **Clear** button and an **Upload a signature image instead** toggle.
      The raw file input is hidden (JS-driven; no canvas = JS failed to load).

### G2. Drawing
- [ ] Draw on the canvas with mouse / finger / pen — strokes render; status
      text reads **"Signature captured."**
- [ ] DevTools → the `#signature` input's `.files` now holds one entry
      (`signature.png`) — the canvas writes the PNG back to the file input.
- [ ] On a touch device: drawing on the pad does **not** scroll the page
      (`touch-action: none`).
- [ ] Click **Clear** — canvas empties, status clears, file input emptied.

### G3. Fallback path (keyboard / no-pointer users)
- [ ] Click **Upload a signature image instead** — canvas + toolbar hide,
      the native file input appears and receives focus; label flips to
      **"Use the signature pad instead"**. Pick a real image — it sticks.
- [ ] Toggle back — file input hides, pad returns.

### G4. Server-side guard
- [ ] Leave the pad empty AND upload no file, complete the hold-to-confirm —
      server blocks with **"Signature photo is required."** (the JS pad is a
      convenience; the requirement is enforced in `release.php`).

---

## H. Selfie capture — `8eb874b` (#5)

URL: same release page.

### H1. Renders (camera-capable browser)
- [ ] A video stage, **Start camera** button, and **Upload a photo file
      instead** toggle appear; raw file input hidden.
- [ ] On a browser with **no** `getUserMedia` (or insecure origin): the plain
      file input stays visible as the only control — no broken widget.

### H2. Capture flow
- [ ] Click **Start camera** → browser prompts for camera permission.
      On allow: live preview shows, status **"Camera ready…"**, **Take photo**
      appears and takes focus.
- [ ] Click **Take photo** → frame freezes as a preview image, camera light
      goes **off** (stream stopped), **Retake** appears. `#selfie` `.files`
      holds one entry (`selfie.jpg`).
- [ ] Click **Retake** → preview clears, returns to **Start camera**.

### H3. Permission-denied + fallback
- [ ] **Deny** the camera prompt → status shows **"Camera unavailable… Use
      the file upload option below."**; no crash.
- [ ] Click **Upload a photo file instead** → camera stops, native file input
      appears focused; pick a file — it sticks.
- [ ] Start the camera, then navigate away (close tab / back) → camera light
      turns off (the `pagehide` handler stops the stream).

---

## I. Release end-to-end — `8eb874b` (overlaps A2, now with real media)

URL: same release page, claim in `pending_verification`.

- [ ] Capture a signature (G) and a selfie (H), optionally add notes, click
      **Confirm release** → hold-to-confirm modal opens listing claim / lost /
      found refs.
- [ ] **Hold** the confirm button ~1.5s → release lands; redirect; success
      flash **"Item released. The claimant has been notified."**
- [ ] The page now shows a **Release record** card with released-by name,
      timestamp, and **both** the signature and selfie images.
- [ ] Log in as the claimant — a **"Your item has been released"**
      notification is present, linking to the claim.
- [ ] Reload the released page — the form is gone (status is `released`, not
      `pending_verification`); no way to double-release.

---

## J. a11y-polish — `c44a7d0` / `b888558` / `9739f21`

### J1. Photo alt text (#8) — `c44a7d0`
DevTools → inspect the item photo's `alt` attribute on each:
- [ ] `index.php?p=found.show&id=<one with a photo>` — alt reads
      **"\<Category\>, \<color\>"** (e.g. "Umbrella, black"), not the generic
      "Photo of the found item".
- [ ] `index.php?p=lost.show&id=<one with a photo>` — same pattern.
- [ ] `index.php?p=match.show&id=<…>` — both lost and found photos have
      differentiated alt text.

### J2. Page titles (#9) — `b888558`
Check the browser tab / `<title>`:
- [ ] `index.php?p=match.show&id=<…>` — title is **"Match \<lost-ref\> /
      \<found-ref\>"**, not "Match #\<id\>".
- [ ] `index.php?p=admin.report.show&type=<…>&from=<date>&to=<date>` — title
      includes the report name plus **"\<from\> to \<to\>"**; with no range,
      just the report name.

### J3. `<details>` focus ring (#10) — `9739f21`
URL: `index.php?p=dashboard` (uses `details.expandable`).
- [ ] **Tab** to a `<summary>` element — a visible 2px focus outline appears
      (not the inconsistent browser default).
- [ ] Press **Enter** or **Space** — the panel toggles open/closed; the focus
      ring stays visible in both states.

---

## Reporting back

When done, paste the failed lines (e.g. "A1 focus trap broken on Firefox,
H2 camera light stays on after capture") and I'll fix on `integration`.
If everything passes, flip the `[~]` markers in `ROADMAP.md` §1/§2 to `[x]`,
delete this file, and the only work left is §4 (operations / deployment)
on the target host.
