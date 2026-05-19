# Development guide — FEU LFMS

This document covers conventions for contributors working on the PHP/MySQL LFMS codebase.

---

## Adding a new page

1. **Register the route** in `lib/routes.php`:

   ```php
   'my.page' => ['file' => 'my.page.php', 'roles' => ['staff', 'admin']],
   ```

   Valid roles: `user`, `staff`, `admin`. Use `'public' => true` for anonymous pages.  
   Any token not listed here returns 404 — the allow-list is the URL surface.

2. **Create the page file** at `pages/my.page.php`:

   ```php
   <?php
   declare(strict_types=1);

   layout_open('Page title');
   page_header('Page heading');
   ?>
   <!-- HTML content -->
   <?php
   layout_close();
   ```

3. **Wire the sidebar** if it should appear in navigation — edit `sidebar_items()` in `partials/layout.php`.

---

## Database helpers (`lib/db.php`)

All queries use parameterized PDO. Never build SQL by concatenating user input.

```php
q('UPDATE t SET col = ? WHERE id = ?', [$value, $id]);    // no return
q_one('SELECT * FROM t WHERE id = ?', [$id]);              // first row or null
q_all('SELECT * FROM t WHERE status = ?', ['open']);        // all rows
q_value('SELECT COUNT(*) FROM t WHERE x = ?', [$x]);       // scalar or null
db_last_id();                                               // last INSERT id (int)

db_transaction(function () use ($id) {
    q('UPDATE a SET …', […]);
    q('INSERT INTO b …', […]);
    // Throws on failure — rolled back automatically.
});
```

---

## Auth helpers (`lib/auth.php`)

```php
current_user()        // Row from accounts (no password_hash). Null if unauthenticated.
is_authenticated()    // bool
user_role()           // 'user' | 'staff' | 'admin' | null
has_role(['staff', 'admin'])  // bool — true if current user has any listed role
```

`current_user()` does **not** return `password_hash`. If you need it (e.g. verify current password), fetch the full row:

```php
$row = q_one('SELECT * FROM accounts WHERE id = ?', [$user_id]);
password_verify($input, (string) $row['password_hash']);
```

---

## CSRF protection

Every mutating form must include the hidden token and verify it on POST:

```php
// In the form:
<?= csrf_field() ?>

// At the top of the POST handler:
csrf_check();   // Throws / redirects on failure. Call before reading $_POST.
```

---

## Flash messages and redirects

```php
flash_set('success', 'Item saved.');
go(url('/index.php?p=dashboard'));  // redirect — terminates execution

back();  // redirect to the referring page

// After redirect, in the target page:
$msg = flash_get('success');   // null if not set
```

---

## Output escaping

**Always** wrap user-controlled values in `e()` when echoing into HTML:

```php
echo e($user['full_name']);           // safe
echo '<a href="' . e($url) . '">';   // safe
echo $user['full_name'];              // NEVER — XSS
```

`e()` is `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.  
Do not alias the loop variable to `$e` — it shadows the helper.

---

## File uploads (`lib/upload.php`)

```php
// Returns the new attachment ID (int). Throws RuntimeException on invalid file.
$attachment_id = upload_store($_FILES['photo'], 'lost_report', $report_id, 'photo');
```

`upload_store` validates MIME type (magic bytes, not extension), enforces the `upload.max_bytes` limit from config, and stores the file under `assets/uploads/` with an opaque filename. The directory is denied direct HTTP access via `.htaccess`; serve files through `pages/serve_upload.php`.

Allowed MIME types (configured in `config.php`): `image/jpeg`, `image/png`, `image/webp`.

---

## Audit logging (`lib/audit.php`)

Append an audit row after every state-changing operation:

```php
audit_log('found_report.create',  'found_report', $id);
audit_log('match.approve',        'match',         $match_id, [
    'status' => ['pending', 'approved'],
]);

// Third argument is target_id (int). Fourth is an optional diff array:
// [ 'field' => ['old_value', 'new_value'], … ]
```

Action strings follow `<resource>.<verb>` convention. Existing actions in the
codebase:

- `account.register`, `account.update_name`, `account.change_password`
- `attachment.create`, `attachment.delete`
- `auth.login`, `auth.logout`
- `claim.create`, `claim.submit`, `claim.release`
- `found_report.create`, `found_report.update`, `found_report.expire`
- `its.sync`, `its.sync_failed`
- `lost_report.create`, `lost_report.update`
- `match.generate`, `match.approve`, `match.reject`, `match.needs_info`, `match.system_reject_expired`
- `settings.update_match_weights`, `settings.update_holding_period`

When adding a new action, follow the same `<resource>.<verb>` shape and grep
this list before inventing a verb that already exists in a slightly different
form.

In CLI scripts (e.g. `db/expire_items.php`), `audit_log` is called with no authenticated session. Verify that the implementation handles a null actor gracefully (writes NULL to `actor_account_id`).

---

## Matching algorithm (`lib/matching.php`)

```php
// Called after a new lost report is submitted:
generate_candidates_for_lost(int $lost_report_id): void

// Called after a new found item is logged:
generate_candidates_for_found(int $found_report_id): void
```

Each function computes a weighted similarity score for every candidate pair. Weights are read from the `settings` table:

| Key | Default |
|-----|---------|
| `match_weight_category` | 40 |
| `match_weight_color` | 20 |
| `match_weight_location` | 15 |
| `match_weight_date` | 15 |
| `match_weight_description` | 10 |
| `match_threshold` | 50 |

Pairs scoring ≥ threshold are inserted into `matches` with status `pending` and their per-factor breakdown in `factors_json`. Weights must sum to 100; enforce this in `admin.settings.php` before saving.

Run the unit tests: `php lib/matching_test.php`.

---

## Page state helpers (tables + pagination)

```php
$state = table_state('prefix_', ['sort' => 'created_at', 'dir' => 'desc', 'per_page' => 25]);
// Reads ?sort, ?dir, ?page, ?per_page, ?q from $_GET. Sanitizes + applies defaults.

echo sort_link('col_name', 'Label', $state, $base_params);
echo render_pagination($total_rows, $state, '', $base_params);
```

`$base_params` is an associative array of URL params that persist across sort/page links (e.g. `['p' => 'matches', 'status' => 'pending']`).

---

## Settings table

Key/value store. Use `INSERT … ON DUPLICATE KEY UPDATE` for upserts:

```php
q(
    "INSERT INTO settings (key_name, value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE value = VALUES(value)",
    ['holding_period_days', '365']
);

$days = (int) (q_value("SELECT value FROM settings WHERE key_name = 'holding_period_days'") ?? 365);
```

---

## Reference numbers

```php
make_ref_number('lost',  $id);   // LFMS-2026-NNNNN
make_ref_number('found', $id);   // LFMS-2026-F-NNNNN
make_ref_number('claim', $id);   // LFMS-2026-C-NNNNN
```

Generated in PHP immediately after insert using `db_last_id()`. The `matches` table has **no** `ref_number` column.

---

## Status state machines

### `found_reports.status`
```
open → matched (match approved) → released
open → expired (expire_items.php)
matched → open (match rejected, no other approved match)
```

### `lost_reports.status`
```
open → matched (match approved) → released
```

### `matches.status`
```
pending → approved | rejected | needs_info
needs_info → pending (after claimant provides info)
pending | needs_info → rejected (system, on found item expiry)
```

### `claim_tickets.status`
```
pending_user_action → pending_verification (claimant uploads ID)
pending_verification → released (staff completes release)
```

---

## Notification conventions

```php
q(
    "INSERT INTO notifications
       (recipient_account_id, type, title, body, link_url, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())",
    [$user_id, 'match_approved', 'Match found!', 'Your lost item has a match.', '/index.php?p=claim.show&id=' . $claim_id]
);
```

`link_url` values are relative paths (no domain). The `api.notifications.php` endpoint converts them to absolute URLs via `url()` before returning JSON. `notifications.php` wraps them the same way when rendering the page list.

---

## CSV export (`lib/export.php`)

Hand-rolled, zero-dependency. Use for any admin export that should download as a spreadsheet-friendly CSV.

```php
csv_send('lfms-operational-2026-05.csv', function ($h) {
    csv_row($h, ['FEU Library — Lost & Found Management System']);
    csv_row($h, ['Generated at', date('Y-m-d H:i:s')]);
    csv_row($h, []);

    csv_section($h, 'Totals',
        ['Metric', 'Count'],
        [['Lost reports', 42], ['Found items', 31]]
    );
});
// csv_send sets the headers, writes a UTF-8 BOM (Excel opens accented names
// correctly), invokes the writer with an open php://output handle, and exits.
// Call it BEFORE any HTML output — the page must not have started rendering.
```

Reach for `csv_section` when an export contains multiple labelled tables; reach for raw `csv_row` when you only need a single rectangular dataset.

---

## ITS integration (`lib/its.php`)

The Integrated Tertiary System (ITS) is FEU's authoritative source for student / staff / faculty records. LFMS fetches that roster over HTTP and caches it locally in `its_users`. Authentication for LFMS itself still uses the `accounts` table — ITS is for ID verification at the counter, not login.

Public surface:

```php
its_fetch_students(): array        // Live HTTP call. Throws on failure.
its_fetch_staff():    array        // Live HTTP call. Throws on failure.
its_sync():           array        // Fetch both, upsert, return summary.
its_get_by_id($its_id):         ?array  // Local DB lookup.
its_get_student_by_id($its_id): ?array
its_get_staff_by_id($its_id):   ?array
its_last_sync_at():             ?string // MAX(last_synced_at).
```

`its_sync()` is transactional, idempotent, and soft-deactivates rows the API no longer returns. It writes one `its.sync` audit row on success or `its.sync_failed` on error — the admin UI surfaces the same summary.

**Config** (under the `its` key in `config.php`):

- `endpoints.students`, `endpoints.staff` — full URLs
- `auth_mode` — `bearer` or `api_key`
- `auth_value` — token / key
- `api_key_header` — header name when `auth_mode = api_key` (default `X-API-Key`)
- `timeout_seconds` — cURL timeout (default 10)
- `verify_ssl` — set false only for local dev against `api.its_mock`

**Bundled mock**: `pages/api.its_mock.php` serves a static JSON roster behind a configurable token. Point `its.endpoints.*` at it (e.g. `http://localhost/feulib/index.php?p=api.its_mock&kind=students`) to develop without a live ITS server.

**CLI sync**: `db/sync_its.php` calls `its_sync()` and exits non-zero on failure. Schedule nightly via Windows Task Scheduler:

```sh
schtasks /Create /SC DAILY /TN "LFMS Sync ITS" /TR "php C:\xampp\htdocs\feulib\db\sync_its.php" /ST 02:00
```

**Schema**: the `its_users` table lives in `db/schema.sql`. If you upgraded from a pre-feature database, apply `db/migrations/2026_its_users.sql` once.

---

## Scheduled tasks (Windows host)

LFMS has two recurring jobs. Both are plain `php script.php` invocations, both
exit non-zero on failure, and both write to the audit log so a missed run is
visible in the admin reports. Schedule via Windows Task Scheduler.

Adjust the path (`C:\xampp\htdocs\feulib`) to match the install location.

### Nightly ITS roster sync — `db/sync_its.php`

Pulls students + staff from ITS into `its_users`. Soft-deactivates anyone the
API no longer returns. One `its.sync` audit row per successful run,
`its.sync_failed` on error.

```sh
schtasks /Create /SC DAILY /TN "LFMS Sync ITS" ^
         /TR "php C:\xampp\htdocs\feulib\db\sync_its.php" ^
         /ST 02:00
```

### Daily expire job — `db/expire_items.php`

Marks `found_reports` past the configured holding period (default 365 days) as
`expired`, auto-rejecting any pending matches. Items in status `matched` are
left alone — staff must rule on the in-flight match first.

```sh
schtasks /Create /SC WEEKLY ^
         /D MON,TUE,WED,THU,FRI,SAT ^
         /TN "LFMS Expire Items" ^
         /TR "php C:\xampp\htdocs\feulib\db\expire_items.php" ^
         /ST 12:00
```

Mon–Sat reflects FEU library opening days; adjust if your branch differs.
Use `php db/expire_items.php --dry` from a normal shell first to preview what
the next run will touch.

### Verifying a scheduled task

```sh
schtasks /Query /TN "LFMS Sync ITS" /V /FO LIST
schtasks /Run   /TN "LFMS Sync ITS"          :: trigger immediately for a smoke test
schtasks /Delete /TN "LFMS Sync ITS" /F      :: remove
```

After a manual run, confirm the audit log picked it up:

```sql
SELECT action, created_at, payload_json
  FROM audit_logs
 WHERE action IN ('its.sync', 'its.sync_failed', 'found_report.expire')
 ORDER BY id DESC LIMIT 10;
```

---

## Production config checklist

Before pointing real traffic at this instance, walk `config.php` top-to-bottom
and flip each of these. None of them have safe production defaults — XAMPP's
defaults are tuned for local development.

- [ ] `app.env` → `'production'`
- [ ] `app.base_url` → the real HTTPS URL (e.g. `https://lfms.feu.edu.ph`)
- [ ] `app.base_path` → matches the URL path, or `''` if served at docroot
- [ ] `db.user`, `db.pass` → a dedicated MySQL account, **not** `root` with
      an empty password. Grant only `SELECT, INSERT, UPDATE, DELETE` on the
      `lfms` database. Schema changes should require a separate admin
      credential the application never sees.
- [ ] `session.cookie_secure` → `true` (requires HTTPS to be live first)
- [ ] `upload.storage_path` → on a writable disk with enough headroom; the
      `assets/uploads/.htaccess` deny rule must survive any reverse-proxy
      rewrite so files are only served via `pages/serve_upload.php`
- [ ] `its.endpoints.students`, `its.endpoints.staff` → real ITS URLs, not
      the bundled `api.its_mock`
- [ ] `its.auth_value` → real bearer token / API key, rotated off
      `dev-token-change-me-before-production`
- [ ] `its.verify_ssl` → `true` (only flip false for local dev against the
      mock)

Sanity checks once deployed:

```sh
php -r "print_r((require 'config.php')['app']);"   :: app.env = 'production' ?
curl -I https://your-host/feulib/                   :: HTTP/2 200, Strict-Transport-Security set ?
```

Try logging in over HTTP — it should redirect or fail. If a session cookie
arrives without the `Secure` flag, `cookie_secure` is still `false`.

---

## MySQL backup and restore

The application has no built-in backup feature; this is a Windows
Task-Scheduler + `mysqldump` recipe. Keep at least seven daily snapshots
plus one weekly snapshot offsite (different physical drive, ideally a
different machine).

### Daily dump

Single-transaction so the dump is consistent without locking writes. Routine
on `lfms` is well under a minute on the expected dataset size.

```sh
mysqldump --single-transaction --quick --default-character-set=utf8mb4 ^
          -u lfms_backup -p<password> ^
          --routines --triggers ^
          lfms > C:\lfms-backups\lfms-%date:~-4%-%date:~3,2%-%date:~0,2%.sql
```

Wire it into Task Scheduler:

```sh
schtasks /Create /SC DAILY /TN "LFMS Backup" ^
         /TR "C:\path\to\backup-lfms.cmd" ^
         /ST 01:00
```

Create a dedicated MySQL user with the minimum privileges needed:

```sql
CREATE USER 'lfms_backup'@'localhost' IDENTIFIED BY '<password>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON lfms.* TO 'lfms_backup'@'localhost';
FLUSH PRIVILEGES;
```

### Restore — verify the backup actually works

A backup nobody has restored is a folder of files. Once a quarter, restore
the latest dump into a scratch database and run the app against it:

```sh
mysql -u root -p -e "CREATE DATABASE lfms_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p lfms_restore_test < C:\lfms-backups\lfms-2026-05-19.sql
```

Point a throwaway `config.php` at `lfms_restore_test`, open the dashboard,
confirm counts match what the source database shows. Drop the scratch DB
when done.

### What also needs backing up

`mysqldump` covers the database, not the filesystem. Add these to your
backup set:

- `assets/uploads/` — every uploaded photo / ID scan / signature / selfie.
  Items lose their evidence if this folder is lost, even with the DB intact.
- `config.php` — small, but the only copy of your production credentials.
  Store this separately from the dumps (a password manager attachment is
  fine; a flat `config.bak` next to the SQL dump is not).
