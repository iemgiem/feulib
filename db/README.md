# Database setup — FEU LFMS

This directory contains the MySQL schema and seed data for the Lost & Found
Management System. It targets the MySQL / MariaDB build that ships with
**XAMPP for Windows** (MariaDB 10.4 or later).

## Files

| File | Purpose |
|---|---|
| `schema.sql` | All table definitions, indexes, and foreign keys. Drops tables on import — re-running it nukes the database. |
| `seed.sql` | Demo data: 1 admin, 2 staff, 5 students/faculty, 3 storage locations, 4 lost reports, 3 found items, 1 pending match, sample audit log entries. |
| `hash_passwords.php` | CLI utility that prints a bcrypt hash for a given password. Run when you need to regenerate the seed password or change a specific account's password directly. |
| `expire_items.php` | CLI script that marks items past the holding period as `EXPIRED`. Scheduled via Windows Task Scheduler at noon Mon–Sat. Supports `--dry` flag. |

## First-time setup

### 1. Start XAMPP

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

### 2. Create the database

Open phpMyAdmin (`http://localhost/phpmyadmin`) and run:

```sql
CREATE DATABASE lfms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 3. Import the schema

In phpMyAdmin → select the `lfms` database → **Import** tab → choose
`db/schema.sql` → **Go**.

### 4. Import the seed

Same flow with `db/seed.sql`.

### 5. Configure your local credentials

Copy `config.example.php` to `config.php` at the project root and update
the database credentials. `config.php` is the only file with real
secrets; never commit it.

```php
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'lfms',
        'user'     => 'root',     // XAMPP default
        'pass'     => '',         // XAMPP default
        'charset'  => 'utf8mb4',
    ],
];
```

### 6. Verify

Open phpMyAdmin → `lfms` database → confirm all 12 tables exist and
the `accounts` table has 8 rows.

## Demo accounts

All seed accounts share the password **`password123`** (bcrypt-hashed
in `seed.sql`). Use the email column to log in.

| Email | Role | Use case |
|---|---|---|
| `admin@feu.edu.ph` | Admin | Full system access |
| `alice.reyes@feu.edu.ph` | Staff | Logs found items, validates matches |
| `bob.santos@feu.edu.ph` | Staff | Second staff account for testing assignments |
| `juan.delacruz@feu.edu.ph` | User (student) | Has a lost report + pending match |
| `maria.santos@feu.edu.ph` | User (student) | Lost an iPhone |
| `pedro.gomez@feu.edu.ph` | User (student) | Lost a calculator |
| `ana.lopez@feu.edu.ph` | User (student) | Lost a Hydro Flask |
| `luis.tan@feu.edu.ph` | User (faculty) | Faculty account, no current reports |

**Rotate the seed password before deploying.** Run:

```sh
php db/hash_passwords.php "your-new-password"
```

Copy the printed hash into `seed.sql` (replace `@pw`), then re-import,
or `UPDATE accounts SET password_hash = '<hash>'` for each row.

## Resetting the database

To wipe and rebuild from scratch:

1. Drop the database in phpMyAdmin (or `DROP DATABASE lfms;`)
2. Recreate it (step 2 above).
3. Re-run `schema.sql` then `seed.sql`.

`schema.sql` is idempotent: it drops every table before recreating
them, so running it again is safe and gives you a clean slate.

## Schema notes

- **Character set**: `utf8mb4 / utf8mb4_unicode_ci` everywhere. Filipino
  names with diacritics and emoji-in-descriptions all work.
- **Engine**: InnoDB for foreign keys and transactions.
- **Active sessions** are handled by PHP's built-in session mechanism
  (filesystem-backed). There is no `auth_sessions` table — login
  events are recorded in `audit_logs` (`action = 'auth.login'`).
- **Reference numbers** (`LFMS-2026-NNNNN`) are generated in PHP on
  insert; the auto-increment `id` provides the sequence.
- **`release_logs` is immutable** — no `updated_at` column. Once a
  release row is written, it never changes. Corrections happen via
  compensating audit-log entries.
- **JSON columns** are used for `factors_json`, `diff_json`,
  `payload_json`, and `parameters_json`. MariaDB 10.4+ supports the
  JSON type natively.
