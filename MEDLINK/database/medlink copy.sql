-- MediLink / MediClear database schema
-- Import: In phpMyAdmin, select your database first (e.g. medlink or xath_medlink), then Import this file.

-- ----------------------------
-- Users (students + clinic staff)
-- ----------------------------
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_code VARCHAR(50) NOT NULL,                 -- Student ID / Staff ID (what you store in $_SESSION['user_id'])
  role ENUM('student','clinic') NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  date_of_birth DATE NULL,
  course VARCHAR(120) NULL,
  year_level VARCHAR(50) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_user_code (user_code),
  KEY idx_users_role (role)
) ENGINE=InnoDB;

-- ----------------------------
-- Medical certification requests
-- ----------------------------
CREATE TABLE IF NOT EXISTS medical_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_user_id BIGINT UNSIGNED NOT NULL,
  illness VARCHAR(120) NOT NULL,
  symptoms TEXT NOT NULL,
  illness_date DATE NOT NULL,
  contact_number VARCHAR(30) NULL,
  additional_notes TEXT NULL,

  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',

  submitted_date DATE NOT NULL,
  approved_date DATE NULL,
  rejected_date DATE NULL,
  valid_until DATE NULL,
  rejection_reason TEXT NULL,

  reviewed_by BIGINT UNSIGNED NULL,               -- clinic staff user id
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_requests_student (student_user_id),
  KEY idx_requests_status (status),
  KEY idx_requests_submitted (submitted_date),
  CONSTRAINT fk_requests_student
    FOREIGN KEY (student_user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_requests_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Optional: supporting documents (your UI allows upload; store metadata here)
CREATE TABLE IF NOT EXISTS medical_request_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_docs_request (request_id),
  CONSTRAINT fk_docs_request
    FOREIGN KEY (request_id) REFERENCES medical_requests(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ----------------------------
-- Notifications (in-app alerts)
-- ----------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,                -- users.id
  title VARCHAR(140) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ----------------------------
-- Medical certificates (issued when Approved)
-- ----------------------------
CREATE TABLE IF NOT EXISTS medical_certificates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,             -- medical_requests.id
  certificate_code VARCHAR(40) NOT NULL,           -- e.g. MC-2026-000001
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by BIGINT UNSIGNED NULL,                  -- users.id (clinic)
  PRIMARY KEY (id),
  UNIQUE KEY uq_cert_request (request_id),
  UNIQUE KEY uq_cert_code (certificate_code),
  KEY idx_cert_issued_at (issued_at),
  CONSTRAINT fk_cert_request
    FOREIGN KEY (request_id) REFERENCES medical_requests(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cert_issued_by
    FOREIGN KEY (issued_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Helpful view: request_code like "REQ-001" without storing it
CREATE OR REPLACE VIEW v_medical_requests AS
SELECT
  mr.*,
  CONCAT('REQ-', LPAD(mr.id, 3, '0')) AS request_code
FROM medical_requests mr;

-- ----------------------------
-- Seed: default clinic admin account
-- Login on index.php with:
--   Staff ID: admin
--   Password: admin
-- IMPORTANT: change this password after first login.
-- ----------------------------
INSERT INTO users (user_code, role, full_name, email, password_hash)
VALUES ('admin', 'clinic', 'Administrator', 'admin@usls.edu.ph', '$2y$10$rQA3B/x8Tm8/uLoF1LVy6ePKeje763YeuzIElT7JWqUKxz3rOxp4y')
ON DUPLICATE KEY UPDATE
  role = VALUES(role),
  full_name = VALUES(full_name),
  email = VALUES(email),
  password_hash = VALUES(password_hash);

-- If users table already exists without date_of_birth, run:
-- ALTER TABLE users ADD COLUMN date_of_birth DATE NULL AFTER email;

