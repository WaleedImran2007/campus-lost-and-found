-- =====================================================================
-- Campus Lost & Found System  -  database schema
-- Import in phpMyAdmin (Import tab) or:  mysql -u root < database/schema.sql
-- Needs MySQL 8.0.16+ / MariaDB 10.2.1+ (current XAMPP) for CHECK constraints.
-- Foreign keys have no ON DELETE action = RESTRICT: rows are never
-- hard-deleted (users are suspended/deactivated, items are marked removed).
-- =====================================================================
CREATE DATABASE IF NOT EXISTS campus_lost_found
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campus_lost_found;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS rate_limits, audit_logs, reports, notifications, messages,
  handovers, private_item_evidence, claims, item_images, items,
  campus_locations, categories, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id        VARCHAR(20)  NULL UNIQUE,              -- roll no, e.g. bcsf24m035
  full_name         VARCHAR(100) NOT NULL,
  email             VARCHAR(150) NOT NULL UNIQUE,          -- must end with @pucit.edu.pk
  password_hash     VARCHAR(255) NOT NULL,
  phone             VARCHAR(20)  NULL,
  role              ENUM('student','custodian','admin') NOT NULL DEFAULT 'student',
  account_status    ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
  suspended_until   DATETIME     NULL,                     -- NULL + suspended = open-ended
  suspension_reason VARCHAR(255) NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(60) NOT NULL UNIQUE,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE campus_locations (
  location_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_name VARCHAR(80) NOT NULL UNIQUE,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE items (
  item_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id    INT UNSIGNED NOT NULL,
  report_type    ENUM('lost','found') NOT NULL,
  title          VARCHAR(120) NOT NULL,
  category_id    INT UNSIGNED NOT NULL,
  location_id    INT UNSIGNED NOT NULL,
  description    TEXT NOT NULL,                            -- public text only
  incident_date  DATE NOT NULL,
  item_status    ENUM('open','claim_pending','ready_for_handover','recovered','closed','removed')
                 NOT NULL DEFAULT 'open',
  is_high_value  TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reporter_id) REFERENCES users(user_id),
  FOREIGN KEY (category_id) REFERENCES categories(category_id),
  FOREIGN KEY (location_id) REFERENCES campus_locations(location_id),
  INDEX idx_status_type_date (item_status, report_type, incident_date),
  INDEX idx_cat_loc (category_id, location_id),
  INDEX idx_reporter (reporter_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE item_images (
  image_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id    INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,                        -- random stored file name
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB;

CREATE TABLE claims (
  claim_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id           INT UNSIGNED NOT NULL,
  claimant_id       INT UNSIGNED NOT NULL,
  ownership_details TEXT NOT NULL,                         -- private statement
  claim_status      ENUM('pending','under_review','approved','rejected','withdrawn')
                    NOT NULL DEFAULT 'pending',
  reviewed_by       INT UNSIGNED NULL,
  review_reason     TEXT NULL,
  reviewed_at       DATETIME NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id)     REFERENCES items(item_id),
  FOREIGN KEY (claimant_id) REFERENCES users(user_id),
  FOREIGN KEY (reviewed_by) REFERENCES users(user_id),
  UNIQUE KEY uq_one_claim_per_user (item_id, claimant_id),  -- BR-4
  INDEX idx_item_claim_status (item_id, claim_status),
  CONSTRAINT chk_reviewer_not_claimant CHECK (reviewed_by IS NULL OR reviewed_by <> claimant_id) -- BR-6
) ENGINE=InnoDB;

CREATE TABLE private_item_evidence (
  evidence_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id             INT UNSIGNED NOT NULL,
  claim_id            INT UNSIGNED NULL,
  evidence_type       ENUM('item_detail','claim_proof') NOT NULL,
  submitted_by        INT UNSIGNED NOT NULL,
  private_description TEXT NULL,
  file_reference      VARCHAR(255) NULL,                   -- file kept OUTSIDE the web root
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id)      REFERENCES items(item_id),
  FOREIGN KEY (claim_id)     REFERENCES claims(claim_id),
  FOREIGN KEY (submitted_by) REFERENCES users(user_id),
  CONSTRAINT chk_evidence_shape CHECK (
    (evidence_type = 'item_detail' AND claim_id IS NULL) OR
    (evidence_type = 'claim_proof' AND claim_id IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE handovers (
  handover_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_id        INT UNSIGNED NOT NULL UNIQUE,            -- BR-8: one handover per claim
  custodian_id    INT UNSIGNED NOT NULL,
  handover_status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  scheduled_at    DATETIME NOT NULL,
  completed_at    DATETIME NULL,
  notes           VARCHAR(255) NULL,                       -- ID-check notes
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (claim_id)     REFERENCES claims(claim_id),
  FOREIGN KEY (custodian_id) REFERENCES users(user_id),
  CONSTRAINT chk_completed_time CHECK ((handover_status = 'completed') = (completed_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE messages (
  message_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id        INT UNSIGNED NOT NULL,
  sender_id      INT UNSIGNED NOT NULL,
  recipient_id   INT UNSIGNED NOT NULL,
  message_text   TEXT NOT NULL,
  message_status ENUM('sent','read','removed') NOT NULL DEFAULT 'sent',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id)      REFERENCES items(item_id),
  FOREIGN KEY (sender_id)    REFERENCES users(user_id),
  FOREIGN KEY (recipient_id) REFERENCES users(user_id),
  CONSTRAINT chk_not_self CHECK (sender_id <> recipient_id),
  INDEX idx_item_time (item_id, created_at),
  INDEX idx_recipient_status (recipient_id, message_status)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  notification_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  related_item_id   INT UNSIGNED NULL,
  notification_type ENUM('claim_submitted','claim_approved','claim_rejected','new_message',
                         'handover_scheduled','item_recovered','report_update') NOT NULL,
  notification_text VARCHAR(255) NOT NULL,
  is_read           TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)         REFERENCES users(user_id),
  FOREIGN KEY (related_item_id) REFERENCES items(item_id),
  INDEX idx_user_read (user_id, is_read, created_at)
) ENGINE=InnoDB;

CREATE TABLE reports (
  report_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id      INT UNSIGNED NOT NULL,
  reported_user_id INT UNSIGNED NULL,
  item_id          INT UNSIGNED NULL,
  message_id       INT UNSIGNED NULL,
  reason           ENUM('spam','fake_listing','harassment','inappropriate','other') NOT NULL,
  details          TEXT NULL,
  report_status    ENUM('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
  moderator_id     INT UNSIGNED NULL,
  resolution_notes TEXT NULL,
  resolved_at      DATETIME NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reporter_id)      REFERENCES users(user_id),
  FOREIGN KEY (reported_user_id) REFERENCES users(user_id),
  FOREIGN KEY (item_id)          REFERENCES items(item_id),
  FOREIGN KEY (message_id)       REFERENCES messages(message_id),
  FOREIGN KEY (moderator_id)     REFERENCES users(user_id),
  CONSTRAINT chk_report_target CHECK (reported_user_id IS NOT NULL OR item_id IS NOT NULL OR message_id IS NOT NULL),
  INDEX idx_report_status (report_status, created_at)
) ENGINE=InnoDB;

-- Append-only history. Target = type + id with NO foreign key.
CREATE TABLE audit_logs (
  log_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT UNSIGNED NULL,                           -- NULL = system action
  action      VARCHAR(50) NOT NULL,
  target_type ENUM('user','item','claim','handover','report','message','evidence') NOT NULL,
  target_id   INT UNSIGNED NOT NULL,
  details     TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(user_id),
  INDEX idx_target (target_type, target_id),
  INDEX idx_actor_time (actor_id, created_at)
) ENGINE=InnoDB;

-- Throttle counters. Works for guests / unknown emails (no user row needed).
CREATE TABLE rate_limits (
  limit_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  limit_key    VARCHAR(100) NOT NULL,                      -- e.g. login:ip:1.2.3.4
  window_start DATETIME NOT NULL,
  hit_count    INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_key_window (limit_key, window_start)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Lookup data + default staff accounts
-- ---------------------------------------------------------------------
INSERT INTO categories (category_name) VALUES
 ('Electronics'),('Wallet / Cards'),('ID / Student Card'),('Keys'),('Bags'),
 ('Books / Notes'),('Clothing'),('Water Bottle / Lunch Box'),('Jewellery / Watch'),('Other');

INSERT INTO campus_locations (location_name) VALUES
 ('Library'),('Cafeteria'),('Auditorium'),('CS Department'),('Computer Labs'),
 ('Classrooms Block'),('Parking'),('Mosque / Prayer Area'),('Sports Ground'),
 ('Admin Block'),('Main Gate'),('Other / Not sure');

-- Passwords:  admin = Admin@123   custodian = Custodian@123   (CHANGE THEM!)
INSERT INTO users (student_id, full_name, email, password_hash, role) VALUES
 (NULL,'System Admin','admin@pucit.edu.pk','$2y$10$S6/pKhn33gY3O8dCmiYtruF6FqEN/pY.WhWXHHUduZpkE.eBzfyv.','admin'),
 (NULL,'Lost & Found Custodian','custodian@pucit.edu.pk','$2y$10$UIBBpM0CXhTuv.V.UpdsROeGL8C/nuXFLIHoZKB5RZRqJJ8HXxOdy','custodian');
