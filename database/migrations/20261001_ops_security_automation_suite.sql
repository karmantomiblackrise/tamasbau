-- =====================================================================
-- 2026-10-01: Üzemeltetés, biztonság és automatizálás csomag
-- Előfeltétel: 20260925_platform_expansion_suite.sql
-- Idempotens: többször is futtatható (phpMyAdmin → Import vagy SQL fül).
-- =====================================================================

-- ---------- A) Mobil PWA offline szinkron ----------
ALTER TABLE work_order_sync_operations
  MODIFY operation_status ENUM('pending', 'applied', 'conflict', 'failed', 'resolved', 'discarded') NOT NULL DEFAULT 'pending';
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE work_order_sync_operations ADD COLUMN resolution VARCHAR(20) NULL AFTER conflict_snapshot', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_order_sync_operations' AND COLUMN_NAME = 'resolution');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE work_order_sync_operations ADD COLUMN resolved_at DATETIME NULL AFTER resolution', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_order_sync_operations' AND COLUMN_NAME = 'resolved_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE work_order_sync_operations ADD COLUMN resolved_by_user_id INT UNSIGNED NULL AFTER resolved_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_order_sync_operations' AND COLUMN_NAME = 'resolved_by_user_id');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE work_order_sync_operations ADD COLUMN error_message VARCHAR(500) NULL AFTER resolved_by_user_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_order_sync_operations' AND COLUMN_NAME = 'error_message');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE work_order_sync_operations ADD INDEX idx_work_order_sync_status (operation_status, created_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_order_sync_operations' AND INDEX_NAME = 'idx_work_order_sync_status');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;

CREATE TABLE IF NOT EXISTS work_order_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  author_user_id INT UNSIGNED NULL,
  note TEXT NOT NULL,
  source ENUM('mobile', 'admin') NOT NULL DEFAULT 'mobile',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_work_order_notes_wo (work_order_id, created_at),
  CONSTRAINT fk_work_order_notes_wo FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_order_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_time_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  minutes INT UNSIGNED NOT NULL,
  started_at DATETIME NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_work_order_time_logs_wo (work_order_id, created_at),
  CONSTRAINT fk_work_order_time_logs_wo FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_order_time_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- B) Digitális aláírás ----------
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN document_hash CHAR(64) NULL AFTER document_version', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'document_hash');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN document_snapshot MEDIUMTEXT NULL AFTER document_hash', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'document_snapshot');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN signer_role VARCHAR(40) NULL AFTER signer_email', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'signer_role');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN revoked_by_user_id INT UNSIGNED NULL AFTER revoked_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'revoked_by_user_id');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN email_status VARCHAR(20) NULL AFTER revoked_by_user_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'email_status');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD COLUMN email_error VARCHAR(500) NULL AFTER email_status', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND COLUMN_NAME = 'email_error');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE digital_signatures ADD INDEX idx_digital_signatures_hash (document_type, document_id, document_hash)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_signatures' AND INDEX_NAME = 'idx_digital_signatures_hash');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;

-- ---------- D) Workflow job sor ----------
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE workflow_jobs ADD COLUMN next_attempt_at DATETIME NULL AFTER max_retries', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_jobs' AND COLUMN_NAME = 'next_attempt_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE workflow_jobs ADD COLUMN started_at DATETIME NULL AFTER next_attempt_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_jobs' AND COLUMN_NAME = 'started_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE workflow_jobs ADD COLUMN result_json TEXT NULL AFTER last_error', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_jobs' AND COLUMN_NAME = 'result_json');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE workflow_jobs ADD INDEX idx_workflow_jobs_status_next (status, next_attempt_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_jobs' AND INDEX_NAME = 'idx_workflow_jobs_status_next');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;

INSERT INTO workflow_rules (rule_key, title, is_enabled, config_json)
VALUES
  ('service_sla_monitor', 'Szerviz SLA figyelés és eszkaláció', 1, JSON_OBJECT('warn_before_hours', 4, 'escalate_after_hours', 48)),
  ('invoice_prepare', 'Számla-előkészítés rendelés/projekt záráskor', 1, JSON_OBJECT('auto_send', false)),
  ('negative_review_followup', 'Negatív értékelés belső follow-up', 1, JSON_OBJECT('threshold', 3))
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- ---------- E) 2FA replay védelem ----------
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE user_totp_settings ADD COLUMN last_used_step BIGINT NULL AFTER last_verified_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_totp_settings' AND COLUMN_NAME = 'last_used_step');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE user_login_events ADD INDEX idx_user_login_events_risk (risk_level, created_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_login_events' AND INDEX_NAME = 'idx_user_login_events_risk');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;

-- ---------- G) Szerviz SLA ----------
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD COLUMN sla_warned_at DATETIME NULL AFTER sla_due_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND COLUMN_NAME = 'sla_warned_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD COLUMN sla_breached_at DATETIME NULL AFTER sla_warned_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND COLUMN_NAME = 'sla_breached_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD COLUMN emergency_alerted_at DATETIME NULL AFTER sla_breached_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND COLUMN_NAME = 'emergency_alerted_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD COLUMN escalated_at DATETIME NULL AFTER emergency_alerted_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND COLUMN_NAME = 'escalated_at');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD COLUMN escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER escalated_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND COLUMN_NAME = 'escalation_level');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE service_tickets ADD INDEX idx_service_tickets_status_sla (status, sla_due_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_tickets' AND INDEX_NAME = 'idx_service_tickets_status_sla');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;

-- ---------- H) Számlázó adapter ----------
CREATE TABLE IF NOT EXISTS invoice_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type ENUM('order', 'project') NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'none',
  mode VARCHAR(20) NOT NULL DEFAULT 'sandbox',
  status ENUM('draft', 'pending', 'sent', 'sandbox', 'manual_required', 'manual_done', 'cancelled', 'failed') NOT NULL DEFAULT 'draft',
  customer_name VARCHAR(180) NOT NULL,
  customer_email VARCHAR(190) NULL,
  buyer_tax_number VARCHAR(40) NULL,
  billing_address VARCHAR(255) NULL,
  total_cents BIGINT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'HUF',
  items_json MEDIUMTEXT NULL,
  external_id VARCHAR(120) NULL,
  error_message VARCHAR(500) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  manual_reference VARCHAR(120) NULL,
  processed_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_invoice_drafts_source (source_type, source_id),
  INDEX idx_invoice_drafts_status (status, created_at),
  CONSTRAINT fk_invoice_drafts_user FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- I) Ügyfélértékelés ----------
CREATE TABLE IF NOT EXISTS review_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type ENUM('project', 'work_order', 'service_ticket') NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  recipient_email VARCHAR(190) NOT NULL,
  recipient_name VARCHAR(120) NULL,
  token_hash CHAR(64) NOT NULL,
  status ENUM('pending', 'sent', 'send_failed', 'completed', 'expired') NOT NULL DEFAULT 'pending',
  email_error VARCHAR(500) NULL,
  expires_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  completed_at DATETIME NULL,
  review_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_review_requests_token (token_hash),
  UNIQUE KEY uniq_review_requests_source (source_type, source_id),
  INDEX idx_review_requests_status (status, created_at),
  CONSTRAINT fk_review_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customer_reviews ADD COLUMN follow_up_status VARCHAR(20) NULL AFTER public_visible', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_reviews' AND COLUMN_NAME = 'follow_up_status');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customer_reviews ADD COLUMN moderation_note VARCHAR(500) NULL AFTER follow_up_status', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_reviews' AND COLUMN_NAME = 'moderation_note');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customer_reviews ADD COLUMN review_request_id INT UNSIGNED NULL AFTER moderation_note', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_reviews' AND COLUMN_NAME = 'review_request_id');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customer_reviews ADD COLUMN public_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER review_request_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_reviews' AND COLUMN_NAME = 'public_consent');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
SET @tb_sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customer_reviews ADD INDEX idx_customer_reviews_follow_up (follow_up_status, created_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_reviews' AND INDEX_NAME = 'idx_customer_reviews_follow_up');
PREPARE tb_stmt FROM @tb_sql; EXECUTE tb_stmt; DEALLOCATE PREPARE tb_stmt;
