ALTER TABLE users
  MODIFY COLUMN role ENUM('user', 'admin', 'superadmin', 'project_manager', 'field_worker', 'service_agent', 'support_agent', 'quote_manager', 'content_manager') NOT NULL DEFAULT 'user';

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  quote_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  company VARCHAR(160) NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'manual',
  address VARCHAR(255) NULL,
  note TEXT NULL,
  status ENUM('new', 'contacted', 'qualified', 'quote_sent', 'won', 'lost', 'archived') NOT NULL DEFAULT 'new',
  assigned_to_user_id INT UNSIGNED NULL,
  next_follow_up_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_leads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_leads_status_created (status, created_at),
  INDEX idx_leads_followup (next_follow_up_at),
  INDEX idx_leads_assigned_status (assigned_to_user_id, status),
  INDEX idx_leads_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lead_timeline (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  event_note TEXT NULL,
  metadata_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lead_timeline_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lead_timeline_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_lead_timeline_lead_created (lead_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  lead_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  address VARCHAR(255) NULL,
  project_type VARCHAR(80) NULL,
  description TEXT NULL,
  budget_cents INT UNSIGNED NULL,
  planned_start_date DATE NULL,
  planned_end_date DATE NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  status ENUM('draft', 'survey_scheduled', 'quoted', 'approved', 'scheduled', 'in_progress', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  internal_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_projects_status_created (status, created_at),
  INDEX idx_projects_assigned_status (assigned_to_user_id, status),
  INDEX idx_projects_planned_dates (planned_start_date, planned_end_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NULL,
  amount_cents INT UNSIGNED NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'HUF',
  status ENUM('draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'cancelled') NOT NULL DEFAULT 'draft',
  next_action_at DATETIME NULL,
  next_action_note VARCHAR(500) NULL,
  followup_template VARCHAR(120) NULL,
  valid_until DATE NULL,
  print_payload TEXT NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_quotes_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_quotes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_quotes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_quotes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_crm_quotes_status_created (status, created_at),
  INDEX idx_crm_quotes_next_action (next_action_at),
  INDEX idx_crm_quotes_relations (lead_id, user_id, project_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_quote_status_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crm_quote_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(40) NOT NULL,
  to_status VARCHAR(40) NOT NULL,
  changed_by_user_id INT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_quote_status_logs_quote FOREIGN KEY (crm_quote_id) REFERENCES crm_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_quote_status_logs_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_crm_quote_status_logs_quote_created (crm_quote_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS project_timeline (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  event_type ENUM('created', 'quote', 'status_change', 'task', 'photo', 'document', 'work_order', 'note') NOT NULL DEFAULT 'note',
  event_note TEXT NULL,
  related_type VARCHAR(40) NULL,
  related_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_timeline_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_timeline_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_project_timeline_project_created (project_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  location VARCHAR(255) NULL,
  work_type VARCHAR(80) NULL,
  description TEXT NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  status ENUM('todo', 'in_progress', 'blocked', 'done', 'cancelled') NOT NULL DEFAULT 'todo',
  priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  planned_minutes INT UNSIGNED NULL,
  actual_minutes INT UNSIGNED NULL,
  due_at DATETIME NULL,
  handover_ready TINYINT(1) NOT NULL DEFAULT 0,
  client_signature_name VARCHAR(120) NULL,
  closed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_work_orders_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_work_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_work_orders_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_work_orders_status_priority (status, priority),
  INDEX idx_work_orders_due_at (due_at),
  INDEX idx_work_orders_assigned (assigned_to_user_id),
  INDEX idx_work_orders_project (project_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  status ENUM('todo', 'in_progress', 'blocked', 'done', 'cancelled') NOT NULL DEFAULT 'todo',
  priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  due_at DATETIME NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_work_order_tasks_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_order_tasks_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_work_order_tasks_order_status (work_order_id, status),
  INDEX idx_work_order_tasks_due_at (due_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_checklists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  item_text VARCHAR(255) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_work_order_checklists_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  INDEX idx_work_order_checklists_work_order (work_order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_type ENUM('site_survey', 'troubleshooting', 'installation', 'maintenance', 'emergency') NOT NULL,
  status ENUM('requested', 'confirmed', 'rescheduled', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'requested',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  lead_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  title VARCHAR(160) NULL,
  note TEXT NULL,
  location VARCHAR(255) NULL,
  reminder_sent_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_appointments_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_appointments_time (starts_at, ends_at),
  INDEX idx_appointments_assigned_time (assigned_to_user_id, starts_at, ends_at),
  INDEX idx_appointments_status (status),
  INDEX idx_appointments_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS project_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  category ENUM('before', 'during', 'after', 'issue', 'document') NOT NULL DEFAULT 'document',
  original_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  title VARCHAR(180) NULL,
  description TEXT NULL,
  uploaded_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_project_files_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_project_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_project_files_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_project_files_project_category (project_id, category),
  INDEX idx_project_files_work_order_category (work_order_id, category),
  INDEX idx_project_files_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  subject VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(255) NULL,
  priority ENUM('low', 'normal', 'high', 'emergency') NOT NULL DEFAULT 'normal',
  status ENUM('open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer', 'resolved', 'closed', 'rejected') NOT NULL DEFAULT 'open',
  warranty_active TINYINT(1) NOT NULL DEFAULT 0,
  warranty_start DATE NULL,
  warranty_end DATE NULL,
  installation_reference VARCHAR(190) NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  sla_due_at DATETIME NULL,
  follow_up_at DATETIME NULL,
  reopened_until DATETIME NULL,
  closed_at DATETIME NULL,
  created_by_customer TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_tickets_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_tickets_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_tickets_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_service_tickets_status_priority (status, priority),
  INDEX idx_service_tickets_sla (sla_due_at, follow_up_at),
  INDEX idx_service_tickets_user (user_id),
  INDEX idx_service_tickets_assigned (assigned_to_user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_ticket_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  author_user_id INT UNSIGNED NULL,
  author_type ENUM('customer', 'admin') NOT NULL,
  message TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES service_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_ticket_messages_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_service_ticket_messages_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_ticket_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  from_value VARCHAR(80) NULL,
  to_value VARCHAR(80) NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_ticket_history_ticket FOREIGN KEY (ticket_id) REFERENCES service_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_ticket_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_service_ticket_history_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS in_app_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  audience ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  title VARCHAR(160) NOT NULL,
  message VARCHAR(1000) NULL,
  link_url VARCHAR(500) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  CONSTRAINT fk_in_app_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_in_app_notifications_audience_created (audience, created_at),
  INDEX idx_in_app_notifications_user_read (user_id, is_read, created_at)
) ENGINE=InnoDB;
