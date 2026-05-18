# FEU Library — Lost & Found Management System

A web-based system for FEU library staff and students to report lost items, log found items, review AI-assisted matches, and manage the full release workflow.

---

## Features

- **Lost report submission** — students/faculty file reports with photos, description, and last-seen location.
- **Found item logging** — library staff log found items with category, color, storage bin, and photos.
- **Automated matching** — a weighted scoring algorithm compares found items against all open lost reports and surfaces candidates above a configurable threshold.
- **Match review** — staff approve, reject, or request more info. Approval automatically creates a claim ticket and notifies the reporter.
- **Claim workflow** — claimants upload a government/school ID; staff verify and record the physical release with a signature and selfie.
- **Expiry job** — a scheduled CLI script (`db/expire_items.php`) marks unclaimed items past the holding period as expired and rejects their pending matches.
- **Audit log** — every state-changing action is appended to `audit_logs` with actor, timestamp, IP address, and a field-level diff.
- **Role-based access** — three roles (user / staff / admin) enforced at the routing layer.
- **Live notification bell** — a JS polling endpoint keeps the unread badge current without page reloads.
- **ITS integration** — pulls the authoritative student/staff/faculty roster from FEU's Integrated Tertiary System (or the bundled mock endpoint) into a local `its_users` cache; admins can trigger a sync from the UI and a nightly CLI script runs unattended.
- **Admin reports + CSV export** — admins generate Operational Summary / Match Effectiveness / User Activity reports for any date range, preview on-screen, and export to UTF-8 BOM CSV.

---

## Roles

| Role | Who | What they can do |
|------|-----|-----------------|
| **user** | Students and faculty | File lost reports, track claims, manage profile |
| **staff** | Library staff | Log found items, review matches, process releases |
| **admin** | Library manager | Everything above + settings, reports, audit log, user management |

---

## Requirements

- PHP 8.1+
- MySQL 8.0+ or MariaDB 10.4+ (ships with XAMPP for Windows)
- Apache with `mod_rewrite` (XAMPP default)

---

## Local setup

### 1. Clone / copy files

Place the project folder under your XAMPP `htdocs/` directory, e.g. `C:\xampp\htdocs\feulib`.

### 2. Create the database

Open phpMyAdmin (`http://localhost/phpmyadmin`) and run:

```sql
CREATE DATABASE lfms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 3. Import schema and seed

In phpMyAdmin → select `lfms` → **Import** → choose `db/schema.sql` → **Go**.  
Repeat for `db/seed.sql` (demo data; skip on a clean production install).

### 4. Create `config.php`

```sh
cp config.example.php config.php
```

Edit `config.php` to match your environment. The XAMPP defaults already work if you created the `lfms` database under the default `root` account. Key settings:

```php
'app' => [
    'base_url'  => 'http://localhost/feulib',
    'base_path' => '/feulib',
    'timezone'  => 'Asia/Manila',
],
'db' => [
    'host' => '127.0.0.1',
    'name' => 'lfms',
    'user' => 'root',
    'pass' => '',
],
'upload' => [
    'max_bytes'     => 4 * 1024 * 1024,   // 4 MB
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'storage_path'  => __DIR__ . '/assets/uploads',
],
```

`config.php` holds real secrets — **never commit it**. It is listed in `.gitignore`.

### 5. Verify

Open `http://localhost/feulib` in a browser. You should reach the login page. Log in with any [demo account](#demo-accounts).

---

## Demo accounts

All seed accounts share the password **`password123`**.

| Email | Role |
|-------|------|
| `admin@feu.edu.ph` | Admin |
| `alice.reyes@feu.edu.ph` | Staff |
| `bob.santos@feu.edu.ph` | Staff |
| `juan.delacruz@feu.edu.ph` | User (student) |
| `maria.santos@feu.edu.ph` | User (student) |
| `pedro.gomez@feu.edu.ph` | User (student) |
| `ana.lopez@feu.edu.ph` | User (student) |
| `luis.tan@feu.edu.ph` | User (faculty) |

Rotate the seed password before any non-local deployment:

```sh
php db/hash_passwords.php "your-new-password"
# Copy the printed hash into seed.sql or UPDATE accounts directly.
```

---

## Project structure

```
feulib/
├── index.php               # Front controller — the only public PHP entry point
├── config.php              # Local secrets (not committed)
├── config.example.php      # Config template
│
├── lib/                    # Shared PHP toolkit (not web-accessible)
│   ├── bootstrap.php       # Config loader, error handling, session start
│   ├── routes.php          # Route allow-list: token → file + role requirement
│   ├── auth.php            # current_user(), has_role(), is_authenticated()
│   ├── db.php              # PDO wrapper: q(), q_one(), q_all(), q_value(), db_transaction()
│   ├── audit.php           # audit_log(action, type, id, ?diff)
│   ├── csrf.php            # csrf_field(), csrf_check()
│   ├── flash.php           # flash_set(), flash_get()
│   ├── redirect.php        # go(), back()
│   ├── upload.php          # upload_store(): validates MIME/size, saves to assets/uploads/
│   ├── matching.php        # generate_candidates_for_lost/found() scoring algorithm
│   ├── matching_test.php   # Unit tests for the matching algorithm
│   ├── validate.php        # Server-side validation rules (mirrored in assets/js/validate.js)
│   ├── sanitize.php        # e() HTML escape, clean() strip-tags
│   ├── its.php             # ITS (Integrated Tertiary System) integration — fetch + sync
│   └── view.php            # url(), asset(), status_badge(), make_ref_number(), table_state(), sort_link(), render_pagination()
│
├── pages/                  # Page controllers, one per route token
│   ├── login.php / register.php / forgot.php / logout.php
│   ├── dashboard.php       # User home
│   ├── lost.php / lost.new.php / lost.show.php / lost.created.php
│   ├── claims.php / claim.new.php / claim.show.php
│   ├── notifications.php
│   ├── profile.php
│   ├── staff.dashboard.php
│   ├── found.php / found.new.php / found.show.php / found.created.php
│   ├── matches.php / match.show.php
│   ├── staff.claims.php / release.php
│   ├── admin.dashboard.php / admin.audit.php / admin.settings.php
│   ├── admin.reports.php / admin.report.show.php
│   ├── admin.its.php           # ITS roster viewer + manual sync trigger
│   ├── api.notifications.php   # JSON polling endpoint
│   ├── api.its_mock.php        # Mock ITS API for local dev (token-auth)
│   └── serve_upload.php        # Authenticated file delivery
│
├── partials/               # Shared HTML fragments
│   ├── layout.php          # layout_open() / layout_close()
│   ├── header.php          # Top bar: brand, bell, user info
│   ├── sidebar.php         # Role-aware navigation
│   ├── footer.php
│   └── error_page.php
│
├── assets/
│   ├── css/                # tokens.css, base.css, components.css
│   ├── js/                 # validate.js, photo-upload.js, notifications.js
│   └── uploads/            # User-uploaded files (Deny-from-all via .htaccess)
│
└── db/
    ├── schema.sql           # Full table definitions (idempotent — drops on re-run)
    ├── seed.sql             # Demo data
    ├── expire_items.php     # Daily CLI expiry job
    ├── sync_its.php         # Nightly CLI ITS roster sync
    ├── hash_passwords.php   # bcrypt hash utility
    ├── migrations/          # One-off SQL files to apply to existing databases
    └── README.md            # Database setup details
```

---

## Architecture

All HTTP requests hit `index.php`. It:

1. Resolves `?p=<token>` from the query string.
2. Looks up the token in `lib/routes.php` (any unlisted token → 404).
3. Enforces authentication and the required role.
4. Includes the matching `pages/<file>.php`.

Page files are not web-accessible directly. `config.php`, `lib/`, `db/`, and `partials/` live outside any rewrite rule that would serve them publicly. `assets/uploads/` is protected by a Deny-from-all `.htaccess`; uploaded files are served only through `serve_upload.php` which enforces per-attachment ownership checks.

---

## Expiry job

```sh
# Preview what would be expired (no writes):
php db/expire_items.php --dry

# Run normally:
php db/expire_items.php
```

Schedule via Windows Task Scheduler at noon, Mon–Sat — see `db/expire_items.php` for the `schtasks` command. The holding period (default: 365 days) is configurable from **Admin → Settings**.

---

## ITS integration

The system can pull a roster of authoritative student / staff / faculty records from FEU's Integrated Tertiary System (ITS) and cache them locally in `its_users`. The cache feeds the admin directory page (`?p=admin.its`) and is the source of identity used for ID verification at release time.

Configure the endpoint, auth mode, and credentials under the `its` key in `config.php` (see `config.example.php`). A bundled mock — `?p=api.its_mock` — serves a static JSON payload for local development; point `its.endpoints.*` at it when working without a real ITS server.

```sh
# One-time manual sync (admin UI button does the same thing):
php db/sync_its.php

# Schedule nightly at 02:00:
schtasks /Create /SC DAILY /TN "LFMS Sync ITS" /TR "php C:\xampp\htdocs\feulib\db\sync_its.php" /ST 02:00
```

The local `its_users` table is populated by the first sync. If you imported `db/schema.sql` from scratch the table already exists; if you upgraded an older database, apply `db/migrations/2026_its_users.sql` first.

---

## Running the matching tests

```sh
php lib/matching_test.php
```

The tests cover the scoring algorithm in `lib/matching.php` — category, color, location, date, and description weights — and verify that the combined score respects the threshold setting.

---

## Development notes

See `DEVELOPMENT.md` for conventions on adding pages, using the DB helpers, audit logging, and the CSRF / flash / redirect patterns.
