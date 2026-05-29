-- -----------------------------------------------------------------------------
-- Migration: add backup_log table (Backup Status feature).
--
-- Apply against an existing lfms database. Re-running schema.sql from scratch
-- is the alternative and already includes this table.
--
-- Usage (phpMyAdmin):
--   1. Select the lfms database.
--   2. Import this file.
--
-- Usage (CLI):
--   mysql -u root -p lfms < db/migrations/2026_backup_log.sql
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS backup_log (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  actor_account_id  INT UNSIGNED  NULL,
  file_size_bytes   INT UNSIGNED  NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_backup_log_created (created_at),
  CONSTRAINT fk_backup_log_actor
    FOREIGN KEY (actor_account_id) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
