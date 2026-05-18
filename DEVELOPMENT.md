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

Action strings follow `<resource>.<verb>` convention. Existing verbs:
`create`, `update`, `delete`, `expire`, `approve`, `reject`, `needs_info`,
`system_reject_expired`, `login`, `logout`, `update_name`, `change_password`.

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
    ['holding_period_days', '30']
);

$days = (int) (q_value("SELECT value FROM settings WHERE key_name = 'holding_period_days'") ?? 30);
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
