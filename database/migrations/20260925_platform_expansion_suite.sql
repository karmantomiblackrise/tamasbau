CREATE TABLE IF NOT EXISTS work_order_sync_operations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idempotency_key VARCHAR(120) NOT NULL,
  work_order_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  operation_type VARCHAR(60) NOT NULL DEFAULT 'mobile_update',
  payload_json TEXT NULL,
  operation_status ENUM('pending', 'applied', 'conflict', 'failed') NOT NULL DEFAULT 'pending',
  conflict_snapshot TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  UNIQUE KEY uniq_work_order_sync_idempotency (idempotency_key),
  INDEX idx_work_order_sync_work_order (work_order_id, created_at),
  CONSTRAINT fk_work_order_sync_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_order_sync_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS digital_signatures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type ENUM('quote', 'project', 'work_order') NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  document_version VARCHAR(80) NOT NULL,
  signer_name VARCHAR(120) NOT NULL,
  signer_email VARCHAR(190) NOT NULL,
  declaration_text TEXT NOT NULL,
  signature_svg LONGTEXT NOT NULL,
  signer_ip_hash CHAR(64) NULL,
  ip_capture_mode ENUM('hash', 'omitted') NOT NULL DEFAULT 'hash',
  status ENUM('active', 'revoked', 'invalidated') NOT NULL DEFAULT 'active',
  revoked_reason VARCHAR(255) NULL,
  signed_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_digital_signatures_doc (document_type, document_id, status),
  INDEX idx_digital_signatures_email (signer_email, signed_at),
  CONSTRAINT fk_digital_signatures_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_cost_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  entry_type ENUM('material', 'labor', 'travel', 'external') NOT NULL,
  title VARCHAR(180) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit VARCHAR(20) NULL,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  internal_unit_cost_cents INT UNSIGNED NULL,
  planned_quantity DECIMAL(10,2) NULL,
  planned_unit_price_cents INT UNSIGNED NULL,
  travel_km DECIMAL(10,2) NULL,
  note TEXT NULL,
  billable_to_customer TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_work_order_cost_entries_project (project_id, entry_type),
  INDEX idx_work_order_cost_entries_work_order (work_order_id, entry_type),
  CONSTRAINT fk_work_order_cost_entries_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_work_order_cost_entries_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_order_cost_entries_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_key VARCHAR(80) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  config_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id INT UNSIGNED NULL,
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  trigger_type VARCHAR(80) NOT NULL,
  payload_json TEXT NULL,
  status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
  retries INT UNSIGNED NOT NULL DEFAULT 0,
  max_retries INT UNSIGNED NOT NULL DEFAULT 3,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_workflow_jobs_status_created (status, created_at),
  CONSTRAINT fk_workflow_jobs_rule FOREIGN KEY (rule_id) REFERENCES workflow_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_totp_settings (
  user_id INT UNSIGNED PRIMARY KEY,
  secret_encrypted VARCHAR(255) NOT NULL,
  secret_hint VARCHAR(16) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  enabled_at DATETIME NULL,
  last_verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_totp_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_recovery_code_hash (code_hash),
  INDEX idx_user_recovery_codes_user (user_id, is_used),
  CONSTRAINT fk_user_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip_hash CHAR(64) NULL,
  is_revoked TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  UNIQUE KEY uniq_user_sessions_token_hash (session_token_hash),
  INDEX idx_user_sessions_user (user_id, is_revoked, last_seen_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_login_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  email_attempt VARCHAR(190) NULL,
  is_success TINYINT(1) NOT NULL DEFAULT 0,
  risk_level ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  failure_reason VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_login_events_user_created (user_id, created_at),
  INDEX idx_user_login_events_email_created (email_attempt, created_at),
  CONSTRAINT fk_user_login_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  service_ticket_id INT UNSIGNED NULL,
  rating TINYINT UNSIGNED NOT NULL,
  feedback TEXT NULL,
  moderation_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  public_visible TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(60) NOT NULL DEFAULT 'portal',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  moderated_at DATETIME NULL,
  moderated_by_user_id INT UNSIGNED NULL,
  INDEX idx_customer_reviews_status_created (moderation_status, created_at),
  INDEX idx_customer_reviews_rating (rating, created_at),
  CONSTRAINT fk_customer_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_reviews_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_reviews_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_reviews_service_ticket FOREIGN KEY (service_ticket_id) REFERENCES service_tickets(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_reviews_moderator FOREIGN KEY (moderated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  partner_type ENUM('partner', 'subcontractor', 'supplier') NOT NULL DEFAULT 'partner',
  status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
  contact_name VARCHAR(120) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(40) NULL,
  service_type VARCHAR(120) NULL,
  contract_reference VARCHAR(120) NULL,
  metadata_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_partners_status_type (status, partner_type),
  INDEX idx_partners_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS partner_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  assigned_note VARCHAR(500) NULL,
  assignment_status ENUM('assigned', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'assigned',
  external_cost_cents INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id INT UNSIGNED NULL,
  INDEX idx_partner_assignments_partner (partner_id, assignment_status),
  INDEX idx_partner_assignments_project (project_id),
  INDEX idx_partner_assignments_work_order (work_order_id),
  CONSTRAINT fk_partner_assignments_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_assignments_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_assignments_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_assignments_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_timeline (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  lead_id INT UNSIGNED NULL,
  source_type VARCHAR(50) NOT NULL,
  source_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  summary VARCHAR(1000) NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json TEXT NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_communication_timeline_user_created (user_id, created_at),
  INDEX idx_communication_timeline_lead_created (lead_id, created_at),
  INDEX idx_communication_timeline_source (source_type, source_id),
  CONSTRAINT fk_communication_timeline_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_communication_timeline_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_communication_timeline_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  region_code VARCHAR(20) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teams_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS regions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_region_access (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NULL,
  team_id INT UNSIGNED NULL,
  region_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_region_access (user_id, site_id, team_id, region_id),
  CONSTRAINT fk_user_region_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_region_access_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_region_access_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_region_access_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE projects
  ADD COLUMN site_id INT UNSIGNED NULL AFTER assigned_to_user_id,
  ADD COLUMN team_id INT UNSIGNED NULL AFTER site_id,
  ADD COLUMN region_id INT UNSIGNED NULL AFTER team_id,
  ADD CONSTRAINT fk_projects_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_projects_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_projects_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

ALTER TABLE work_orders
  ADD COLUMN site_id INT UNSIGNED NULL AFTER assigned_to_user_id,
  ADD COLUMN team_id INT UNSIGNED NULL AFTER site_id,
  ADD COLUMN region_id INT UNSIGNED NULL AFTER team_id,
  ADD CONSTRAINT fk_work_orders_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_work_orders_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_work_orders_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

ALTER TABLE appointments
  ADD COLUMN site_id INT UNSIGNED NULL AFTER work_order_id,
  ADD COLUMN region_id INT UNSIGNED NULL AFTER site_id,
  ADD CONSTRAINT fk_appointments_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_appointments_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

INSERT INTO workflow_rules (rule_key, title, is_enabled, config_json)
VALUES
  ('quote_request_to_lead', 'Új ajánlatkérésből lead', 1, JSON_OBJECT('source', 'quotes')),
  ('quote_sent_followup', 'Ajánlat küldés utáni follow-up', 1, JSON_OBJECT('delay_hours', 72)),
  ('accepted_quote_to_project', 'Elfogadott ajánlatból projekt', 1, JSON_OBJECT('create_project', true)),
  ('project_closed_review_request', 'Projekt lezárás utáni értékeléskérés', 1, JSON_OBJECT('channel', 'email')),
  ('warranty_expiry_reminder', 'Garancia lejárat előtti emlékeztető', 1, JSON_OBJECT('days_before', 14)),
  ('urgent_ticket_alert', 'Sürgős ticket azonnali admin riasztás', 1, JSON_OBJECT('priority', 'emergency'))
ON DUPLICATE KEY UPDATE title = VALUES(title), is_enabled = VALUES(is_enabled), config_json = VALUES(config_json);

INSERT INTO sites (name, region_code, is_default, status)
SELECT 'Alapértelmezett telephely', 'DEFAULT', 1, 'active'
WHERE NOT EXISTS (SELECT 1 FROM sites WHERE is_default = 1);
