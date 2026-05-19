# Browser verification — `task27modal` `[~]` commits

> Temporary working file. Delete (or `git rm`) after verification is done
> and ROADMAP `[~]` markers are flipped to `[x]`.

Each check: **where to go**, **what to do**, **what should happen**.
Tick `[x]` when verified; note any failures inline so the fix can be scoped.

Anchor commits covered: `3014fa1`, `3cf74f1`, `0ed1726`, `7f2afeb`,
`b308ea0`, plus `95aa5e4` (already on branch before the `[~]` range).

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

## Reporting back

When done, paste the failed lines (e.g. "A1 focus trap broken on Firefox,
E2 backdrop doesn't dismiss on iOS Safari") and I'll fix on `task27modal`.
If everything passes, flip the `[~]` markers in `ROADMAP.md` §1/§2 to `[x]`,
delete this file, and we move to roadmap item #2 (signature pad + selfie).
