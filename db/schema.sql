-- =============================================================================
-- FEU LFMS — Database Schema
-- MySQL 5.7+ / MariaDB 10.4+ (XAMPP default)
-- Charset: utf8mb4 (handles Filipino names, emoji-safe descriptions)
-- Engine:  InnoDB (FK + transactions)
--
-- IMPORT ORDER:
--   1. CREATE DATABASE lfms ...   (see db/README.md)
--   2. USE lfms;
--   3. SOURCE schema.sql
--   4. SOURCE seed.sql
--
-- Active sessions are handled by PHP's built-in session mechanism;
-- no auth_sessions table exists. Login events are recorded in audit_logs.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS release_logs;
DROP TABLE IF EXISTS claim_tickets;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS found_reports;
DROP TABLE IF EXISTS lost_reports;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS storage_locations;
DROP TABLE IF EXISTS accounts;

SET FOREIGN_KEY_CHECKS = 1;


-- -----------------------------------------------------------------------------
-- accounts — single table for User / Staff / Admin identities.
-- Role-specific columns are nullable (user_type only matters when role='user').
-- -----------------------------------------------------------------------------
CREATE TABLE accounts (
  id              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  role            ENUM('user','staff','admin') NOT NULL,
  user_type       ENUM('student','faculty')    NULL,            -- only when role='user'
  full_name       VARCHAR(150)       NOT NULL,
  id_number       VARCHAR(50)        NOT NULL,                  -- student / employee number
  email           VARCHAR(255)       NOT NULL,
  password_hash   VARCHAR(255)       NOT NULL,                  -- bcrypt (60 chars + headroom)
  is_active       TINYINT(1)         NOT NULL DEFAULT 1,
  created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_email     (email),
  UNIQUE KEY uq_accounts_id_number (id_number),
  KEY idx_accounts_role            (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- storage_locations — physical bins/shelves where Found items are kept.
-- -----------------------------------------------------------------------------
CREATE TABLE storage_locations (
  id              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)        NOT NULL,                  -- e.g., "BIN-A-1"
  description     VARCHAR(255)       NOT NULL,
  is_active       TINYINT(1)         NOT NULL DEFAULT 1,
  created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_storage_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- settings — admin-configurable key/value store
-- (holding period, match weights, notification rules, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE settings (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  key_name                 VARCHAR(100)       NOT NULL,
  value                    TEXT               NOT NULL,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  updated_by_account_id    INT UNSIGNED       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (key_name),
  CONSTRAINT fk_settings_updated_by
    FOREIGN KEY (updated_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- lost_reports — User-filed reports of items they have lost.
-- ref_number format: LFMS-YYYY-NNNNN  (generated in PHP on insert)
-- -----------------------------------------------------------------------------
CREATE TABLE lost_reports (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  ref_number               VARCHAR(30)        NOT NULL,
  reporter_account_id      INT UNSIGNED       NOT NULL,
  category                 VARCHAR(50)        NOT NULL,         -- bag, phone, book, ID, etc.
  color                    VARCHAR(50)        NOT NULL,
  brand                    VARCHAR(100)       NULL,
  description              TEXT               NOT NULL,
  last_seen_location       VARCHAR(255)       NOT NULL,
  date_lost                DATE               NOT NULL,
  status                   ENUM('open','matched','claimed','released','expired')
                                              NOT NULL DEFAULT 'open',
  is_suspicious            TINYINT(1)         NOT NULL DEFAULT 0,
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lost_ref (ref_number),
  KEY idx_lost_reporter  (reporter_account_id),
  KEY idx_lost_status    (status),
  KEY idx_lost_category  (category),
  CONSTRAINT fk_lost_reporter
    FOREIGN KEY (reporter_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- found_reports — Staff-logged items physically turned in.
-- ref_number format: LFMS-YYYY-F-NNNNN
-- -----------------------------------------------------------------------------
CREATE TABLE found_reports (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  ref_number               VARCHAR(30)        NOT NULL,
  finder_account_id        INT UNSIGNED       NOT NULL,         -- staff who logged it
  category                 VARCHAR(50)        NOT NULL,
  color                    VARCHAR(50)        NOT NULL,
  brand                    VARCHAR(100)       NULL,
  description              TEXT               NOT NULL,
  storage_location_id      INT UNSIGNED       NOT NULL,
  date_found               DATE               NOT NULL,
  status                   ENUM('open','matched','claimed','released','expired','donated')
                                              NOT NULL DEFAULT 'open',
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_found_ref (ref_number),
  KEY idx_found_finder    (finder_account_id),
  KEY idx_found_storage   (storage_location_id),
  KEY idx_found_status    (status),
  KEY idx_found_category  (category),
  CONSTRAINT fk_found_finder
    FOREIGN KEY (finder_account_id)   REFERENCES accounts(id)          ON DELETE RESTRICT,
  CONSTRAINT fk_found_storage
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- attachments — polymorphic uploads (photos, ID proofs, signatures, selfies).
-- attachable_type narrows down which other table the row points at.
-- -----------------------------------------------------------------------------
CREATE TABLE attachments (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  attachable_type          ENUM('lost_report','found_report','claim_ticket','release_log')
                                              NOT NULL,
  attachable_id            INT UNSIGNED       NOT NULL,
  purpose                  ENUM('photo','id_proof','signature','selfie')
                                              NOT NULL,
  filename                 VARCHAR(255)       NOT NULL,         -- original upload name
  stored_path              VARCHAR(500)       NOT NULL,         -- relative path under assets/uploads/
  mime_type                VARCHAR(100)       NOT NULL,
  size_bytes               INT UNSIGNED       NOT NULL,
  uploaded_by_account_id   INT UNSIGNED       NOT NULL,
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attach_target   (attachable_type, attachable_id),
  KEY idx_attach_uploader (uploaded_by_account_id),
  CONSTRAINT fk_attach_uploader
    FOREIGN KEY (uploaded_by_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- matches — system-generated pairings between a Lost Report and a Found Item.
-- factors_json holds the per-factor score breakdown for the tooltip UI.
-- -----------------------------------------------------------------------------
CREATE TABLE matches (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  lost_report_id           INT UNSIGNED       NOT NULL,
  found_report_id          INT UNSIGNED       NOT NULL,
  score                    TINYINT UNSIGNED   NOT NULL,         -- 0..100
  factors_json             JSON               NOT NULL,         -- {category:30, color:20, ...}
  status                   ENUM('pending','approved','rejected','needs_info')
                                              NOT NULL DEFAULT 'pending',
  is_suspicious            TINYINT(1)         NOT NULL DEFAULT 0,
  reviewed_by_account_id   INT UNSIGNED       NULL,
  reviewed_at              DATETIME           NULL,
  review_notes             TEXT               NULL,
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_pair    (lost_report_id, found_report_id),
  KEY idx_match_status        (status),
  KEY idx_match_lost          (lost_report_id),
  KEY idx_match_found         (found_report_id),
  KEY idx_match_reviewer      (reviewed_by_account_id),
  CONSTRAINT fk_match_lost
    FOREIGN KEY (lost_report_id)         REFERENCES lost_reports(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_match_found
    FOREIGN KEY (found_report_id)        REFERENCES found_reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_match_reviewer
    FOREIGN KEY (reviewed_by_account_id) REFERENCES accounts(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- claim_tickets — exactly one per approved Match.
-- ref_number format: LFMS-YYYY-C-NNNNN
-- -----------------------------------------------------------------------------
CREATE TABLE claim_tickets (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  ref_number               VARCHAR(30)        NOT NULL,
  match_id                 INT UNSIGNED       NOT NULL,
  claimant_account_id      INT UNSIGNED       NOT NULL,
  status                   ENUM('pending_user_action','pending_verification','released','rejected')
                                              NOT NULL DEFAULT 'pending_user_action',
  submitted_at             DATETIME           NULL,             -- set when user uploads ID + confirms
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_claim_ref   (ref_number),
  UNIQUE KEY uq_claim_match (match_id),                         -- one claim per match
  KEY idx_claim_claimant    (claimant_account_id),
  KEY idx_claim_status      (status),
  CONSTRAINT fk_claim_match
    FOREIGN KEY (match_id)            REFERENCES matches(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_claim_claimant
    FOREIGN KEY (claimant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- release_logs — immutable record of an item being physically released.
-- Exactly one per claim. No updated_at — once written, never changes.
-- Signature + selfie are mandatory and stored as attachments.
-- -----------------------------------------------------------------------------
CREATE TABLE release_logs (
  id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  claim_id                    INT UNSIGNED    NOT NULL,
  released_by_account_id      INT UNSIGNED    NOT NULL,         -- staff
  released_to_account_id      INT UNSIGNED    NOT NULL,         -- claimant
  signature_attachment_id     INT UNSIGNED    NOT NULL,
  selfie_attachment_id        INT UNSIGNED    NOT NULL,
  released_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes                       TEXT            NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_release_claim (claim_id),
  KEY idx_release_by          (released_by_account_id),
  KEY idx_release_to          (released_to_account_id),
  CONSTRAINT fk_release_claim
    FOREIGN KEY (claim_id)                REFERENCES claim_tickets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_release_by
    FOREIGN KEY (released_by_account_id)  REFERENCES accounts(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_release_to
    FOREIGN KEY (released_to_account_id)  REFERENCES accounts(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_release_signature
    FOREIGN KEY (signature_attachment_id) REFERENCES attachments(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_release_selfie
    FOREIGN KEY (selfie_attachment_id)    REFERENCES attachments(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- notifications — in-app notification queue. Polled every 60s by the bell.
-- payload_json holds type-specific data; the consumer JS reads link_url.
-- -----------------------------------------------------------------------------
CREATE TABLE notifications (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  recipient_account_id     INT UNSIGNED       NOT NULL,
  type                     VARCHAR(50)        NOT NULL,         -- match.approved, claim.released, etc.
  title                    VARCHAR(255)       NOT NULL,
  body                     TEXT               NOT NULL,
  link_url                 VARCHAR(500)       NULL,
  payload_json             JSON               NULL,
  is_read                  TINYINT(1)         NOT NULL DEFAULT 0,
  read_at                  DATETIME           NULL,
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_recipient_unread (recipient_account_id, is_read),
  KEY idx_notif_created          (created_at),
  CONSTRAINT fk_notif_recipient
    FOREIGN KEY (recipient_account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- audit_logs — append-only history of every state-mutating action.
-- diff_json holds a snapshot of before/after for state changes.
-- actor_account_id is nullable for system-generated events (expiry job, etc.).
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  actor_account_id         INT UNSIGNED       NULL,
  action                   VARCHAR(100)       NOT NULL,         -- "lost_report.create", "match.approve", etc.
  target_type              VARCHAR(50)        NOT NULL,
  target_id                INT UNSIGNED       NOT NULL,
  diff_json                JSON               NULL,
  ip_address               VARCHAR(45)        NULL,             -- supports IPv6
  created_at               DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor   (actor_account_id),
  KEY idx_audit_target  (target_type, target_id),
  KEY idx_audit_action  (action),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor
    FOREIGN KEY (actor_account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- reports — metadata for admin-generated reports (parameters + who/when).
-- Actual report data is computed on demand, not stored.
-- -----------------------------------------------------------------------------
CREATE TABLE reports (
  id                       INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  generated_by_account_id  INT UNSIGNED       NOT NULL,
  report_type              VARCHAR(50)        NOT NULL,         -- "operational_summary", "match_effectiveness", etc.
  parameters_json          JSON               NOT NULL,
  generated_at             DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_generator (generated_by_account_id),
  KEY idx_reports_type      (report_type),
  CONSTRAINT fk_reports_generator
    FOREIGN KEY (generated_by_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
