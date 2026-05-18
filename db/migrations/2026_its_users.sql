-- -----------------------------------------------------------------------------
-- Migration: add its_users table (ITS User Data Integration feature).
--
-- Apply this against an existing lfms database that was created from a
-- pre-feature schema.sql. Re-running schema.sql from scratch is the
-- alternative and will include this table.
--
-- Usage (phpMyAdmin):
--   1. Select the lfms database.
--   2. Import this file.
--
-- Usage (CLI):
--   mysql -u root -p lfms < db/migrations/2026_its_users.sql
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS its_users (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  its_id                   VARCHAR(50)        NOT NULL,
  full_name                VARCHAR(150)       NOT NULL,
  email                    VARCHAR(255)       NULL,
  role                     ENUM('student','staff','faculty') NOT NULL,
  status                   ENUM('active','inactive') NOT NULL DEFAULT 'active',
  raw_json                 JSON               NULL,
  last_synced_at           DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_its_id (its_id),
  KEY idx_its_role     (role, status),
  KEY idx_its_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
