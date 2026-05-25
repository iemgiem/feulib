# CLAUDE.md — agent memory for FEU LFMS

> Auto-loaded by Claude Code at session start. Keep tight.

## Read this first

**`ROADMAP.md`** is the single source of truth for project goals, status,
and what's left. Read it before doing anything else. Update it as work
lands — checkboxes are the contract between sessions.

## House rules

- **Don't expand scope silently.** If the user's small request implies
  bigger work, or pulls in something from `ACCESSIBILITY.md` that wasn't
  explicitly named, flag it before starting. `task27modal` has already
  drifted once; don't repeat it.
- **Honest status markers in ROADMAP.md.** Use `[~]` for "code complete
  but not browser-verified," not `[x]`. The user has no way to verify
  UI work themselves between sessions — own that gap in writing.
- **Don't create *.md files unless asked.** `ROADMAP.md` and this file
  are the exceptions; everything else (notes, plans, summaries) lives in
  the conversation or the PR description.

## Project shape

- PHP 8.x + MySQL, served via XAMPP on Windows. No build step, no
  framework — vanilla PHP routed through `index.php?p=...` and helpers
  in `lib/`.
- Config in `config.php` (per-environment, tracked) and
  `config.example.php` (template). Both are committed.
- Pages live in `pages/`, partials in `partials/`, assets under
  `assets/{css,js,uploads}`. The accessibility audit lives in
  `ACCESSIBILITY.md`; the developer guide in `DEVELOPMENT.md`.
- No test runner is wired up. `db/match_debug.php` is a tuning diagnostic,
  not a test (no assertions, no pass/fail).

## Environment notes

- The project runs **directly on the user's Windows machine** via XAMPP
  (`C:\xampp\htdocs\feulib`). PHP is at `C:\xampp\php\php.exe`. Use full
  path when running PHP from PowerShell; `php` alone is not on PATH.
- I cannot open a browser, so any UI change ships `[~]` until the user
  verifies in a real browser. Never mark visual work `[x]` without human
  confirmation.
- GitHub access is limited to `iemgiem/feulib` via the `mcp__github__*`
  tools. No `gh` CLI.
- Development branch convention: feature branches named after the task
  (e.g. `task27modal`). The user is explicit about which branch to push
  to — don't assume.

## Reference

- `ROADMAP.md` — end goal, objectives, current status, what's next
- `README.md` — user-facing setup + feature summary
- `DEVELOPMENT.md` — helpers, conventions, scheduled tasks
- `ACCESSIBILITY.md` — WCAG audit with numbered findings
- `db/README.md` — schema, migrations, CLI scripts
