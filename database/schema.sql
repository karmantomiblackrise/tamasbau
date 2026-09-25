CREATE DATABASE IF NOT EXISTS tamasbau CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
USE tamasbau;

SET NAMES utf8mb4;

DROP TABLE IF EXISTS support_messages;
DROP TABLE IF EXISTS support_chats;
DROP TABLE IF EXISTS in_app_notifications;
DROP TABLE IF EXISTS service_ticket_history;
DROP TABLE IF EXISTS service_ticket_messages;
DROP TABLE IF EXISTS service_tickets;
DROP TABLE IF EXISTS project_files;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS work_order_checklists;
DROP TABLE IF EXISTS work_order_tasks;
DROP TABLE IF EXISTS work_orders;
DROP TABLE IF EXISTS project_timeline;
DROP TABLE IF EXISTS crm_quote_status_logs;
DROP TABLE IF EXISTS crm_quotes;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS lead_timeline;
DROP TABLE IF EXISTS leads;
DROP TABLE IF EXISTS quote_replies;
DROP TABLE IF EXISTS admin_activity_logs;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS order_status_logs;
DROP TABLE IF EXISTS gdpr_requests;
DROP TABLE IF EXISTS wishlists;
DROP TABLE IF EXISTS user_notification_preferences;
DROP TABLE IF EXISTS user_addresses;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS saved_estimates;
DROP TABLE IF EXISTS quotes;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user', 'admin', 'superadmin', 'project_manager', 'field_worker', 'service_agent', 'support_agent', 'quote_manager', 'content_manager') NOT NULL DEFAULT 'user',
  phone VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE user_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  shipping_name VARCHAR(120) NULL,
  shipping_phone VARCHAR(40) NULL,
  shipping_postal_code VARCHAR(20) NULL,
  shipping_city VARCHAR(120) NULL,
  shipping_address VARCHAR(255) NULL,
  billing_name VARCHAR(120) NULL,
  billing_tax_number VARCHAR(60) NULL,
  billing_postal_code VARCHAR(20) NULL,
  billing_city VARCHAR(120) NULL,
  billing_address VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_notification_preferences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  order_emails TINYINT(1) NOT NULL DEFAULT 1,
  quote_emails TINYINT(1) NOT NULL DEFAULT 1,
  support_emails TINYINT(1) NOT NULL DEFAULT 1,
  marketing_emails TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  price INT UNSIGNED NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  icon VARCHAR(80) NULL,
  image_url VARCHAR(1000) NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_resets_user_id (user_id),
  INDEX idx_password_resets_expires_at (expires_at)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  total INT UNSIGNED NOT NULL,
  shipping_name VARCHAR(120) NULL,
  shipping_phone VARCHAR(40) NULL,
  shipping_postal_code VARCHAR(20) NULL,
  shipping_city VARCHAR(120) NULL,
  shipping_address VARCHAR(255) NULL,
  billing_name VARCHAR(120) NULL,
  billing_tax_number VARCHAR(60) NULL,
  billing_postal_code VARCHAR(20) NULL,
  billing_city VARCHAR(120) NULL,
  billing_address VARCHAR(255) NULL,
  shipping_method VARCHAR(40) NULL,
  payment_method VARCHAR(40) NULL,
  payment_provider VARCHAR(40) NULL,
  payment_status VARCHAR(40) NULL,
  tracking_number VARCHAR(120) NULL,
  tracking_url VARCHAR(1000) NULL,
  stock_reverted TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(60) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_orders_status_created (status, created_at),
  INDEX idx_orders_user_created (user_id, created_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  qty INT UNSIGNED NOT NULL,
  unit_price INT UNSIGNED NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE order_status_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(60) NOT NULL,
  to_status VARCHAR(60) NOT NULL,
  changed_by_user_id INT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_status_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_status_logs_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_order_status_logs_order_created (order_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  movement_type ENUM('reserve', 'release', 'manual_adjustment') NOT NULL,
  qty INT NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_movements_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  INDEX idx_stock_movements_product_created (product_id, created_at),
  INDEX idx_stock_movements_order_created (order_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(190) NOT NULL,
  work_type VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new', 'in_progress', 'answered', 'closed') NOT NULL DEFAULT 'new',
  admin_reply TEXT NULL,
  replied_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quotes_status_created (status, created_at),
  INDEX idx_quotes_email (email),
  INDEX idx_quotes_replied_at (replied_at)
) ENGINE=InnoDB;

CREATE TABLE admin_activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NULL,
  target_id INT UNSIGNED NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_activity_logs_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_admin_activity_logs_created (created_at),
  INDEX idx_admin_activity_logs_event (event_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE quote_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED NOT NULL,
  reply_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quote_replies_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_quote_replies_admin_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_quote_replies_quote_created (quote_id, created_at),
  INDEX idx_quote_replies_admin_user_id (admin_user_id)
) ENGINE=InnoDB;

CREATE TABLE support_chats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_name VARCHAR(120) NULL,
  user_email VARCHAR(190) NOT NULL,
  status ENUM('new', 'open', 'resolved', 'closed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_customer_message_at TIMESTAMP NULL DEFAULT NULL,
  last_admin_message_at TIMESTAMP NULL DEFAULT NULL,
  admin_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  customer_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  INDEX idx_support_chats_status_last_message (status, last_message_at),
  INDEX idx_support_chats_email_updated (user_email, updated_at),
  INDEX idx_support_chats_unread (unread_count, last_message_at),
  INDEX idx_support_chats_admin_unread (admin_unread_count, last_message_at),
  INDEX idx_support_chats_customer_unread (customer_unread_count, last_message_at),
  INDEX idx_support_chats_deleted_at (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE support_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id INT UNSIGNED NOT NULL,
  sender_type ENUM('customer', 'admin') NOT NULL,
  sender_name VARCHAR(120) NULL,
  sender_email VARCHAR(190) NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_messages_chat FOREIGN KEY (chat_id) REFERENCES support_chats(id) ON DELETE CASCADE,
  INDEX idx_support_messages_chat_created (chat_id, created_at),
  INDEX idx_support_messages_sender_email (sender_email)
) ENGINE=InnoDB;

CREATE TABLE saved_estimates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  area INT UNSIGNED NOT NULL,
  wiring_type VARCHAR(40) NOT NULL,
  alarm_qty INT UNSIGNED NOT NULL DEFAULT 0,
  camera_qty INT UNSIGNED NOT NULL DEFAULT 0,
  intercom TINYINT(1) NOT NULL DEFAULT 0,
  total INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_estimates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wishlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wishlists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlists_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wishlist_user_product (user_id, product_id),
  INDEX idx_wishlists_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE gdpr_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  request_type ENUM('data_export', 'account_delete') NOT NULL,
  status ENUM('new', 'in_progress', 'done', 'rejected') NOT NULL DEFAULT 'new',
  consent_version VARCHAR(40) NOT NULL DEFAULT 'v1',
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gdpr_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_gdpr_requests_user_status (user_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE leads (
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

CREATE TABLE lead_timeline (
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

CREATE TABLE projects (
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

CREATE TABLE crm_quotes (
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

CREATE TABLE crm_quote_status_logs (
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

CREATE TABLE project_timeline (
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

CREATE TABLE work_orders (
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

CREATE TABLE work_order_tasks (
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

CREATE TABLE work_order_checklists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  item_text VARCHAR(255) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_work_order_checklists_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  INDEX idx_work_order_checklists_work_order (work_order_id)
) ENGINE=InnoDB;

CREATE TABLE appointments (
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

CREATE TABLE project_files (
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

CREATE TABLE service_tickets (
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

CREATE TABLE service_ticket_messages (
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

CREATE TABLE service_ticket_history (
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

CREATE TABLE in_app_notifications (
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

INSERT INTO users (name, email, password_hash, role, phone, is_active) VALUES
('Tamás Bau Admin', 'admin@tamasbau.hu', '$2y$10$MQrhuqpNXpFmNwG56je1XemeyoWzHhlMBOateSUfcq5zkr8mpKIl6', 'admin', '+36 (30) 123-4567', 1),
('Nagy Péter', 'nagy.peter@example.hu', '$2y$10$wyjWKleoJ76qtUVoSWxXbevwc9cjvdSYZWH5ILdSZpyFwx3ke3RuS', 'user', '+36 30 222 1111', 1),
('Szabó Éva', 'szabo.eva@example.hu', '$2y$10$QJwbtBoUexoM1F2BBmiJAu7bRePgEZ/RHTu4o4o3fsrBzW70MJUrq', 'user', '+36 30 333 2222', 1);

INSERT INTO user_addresses (
  user_id, shipping_name, shipping_phone, shipping_postal_code, shipping_city, shipping_address,
  billing_name, billing_tax_number, billing_postal_code, billing_city, billing_address
) VALUES
(2, 'Nagy Péter', '+36 30 222 1111', '1117', 'Budapest', 'Minta utca 12.',
 'Nagy Péter', '', '1117', 'Budapest', 'Minta utca 12.');

INSERT INTO user_notification_preferences (user_id, order_emails, quote_emails, support_emails, marketing_emails) VALUES
(2, 1, 1, 1, 0),
(3, 1, 1, 1, 0);

INSERT INTO categories (id, name, slug, parent_id) VALUES
(1, 'Riasztórendszerek', 'riasztorendszerek', NULL),
(2, 'Vezetékes riasztók', 'vezetekes-riasztok', 1),
(3, 'Vezeték nélküli riasztók', 'vezetek-nelkuli-riasztok', 1),
(4, 'Érzékelők', 'erzekelok', 1),
(5, 'Kamerák & NVR', 'kamerak-nvr', NULL),
(6, 'IP Kamerák', 'ip-kamerak', 5),
(7, 'Rögzítők', 'rogzitok', 5),
(8, 'Villanyszerelés', 'villanyszereles', NULL),
(9, 'Kismegszakítók', 'kismegszakitok', 8),
(10, 'Fi-relék', 'fi-relek', 8),
(13, 'Lakossági kismegszakítók', 'lakossagi-kismegszakitok', 9),
(14, 'Ipari kismegszakítók', 'ipari-kismegszakitok', 9),
(15, '1 fázisú Fi-relék', '1-fazisu-fi-relek', 10),
(16, '3 fázisú Fi-relék', '3-fazisu-fi-relek', 10),
(11, 'Okosotthon', 'okosotthon', NULL),
(12, 'Kaputelefonok', 'kaputelefonok', 11);

INSERT INTO products (name, category_id, price, stock, icon, image_url, description) VALUES
('Ajax Hub 2 Plus Okos Riasztóközpont', 3, 124900, 12, 'fa-shield-halved', 'https://images.unsplash.com/photo-1558002038-1055907df827?auto=format&fit=crop&w=800&q=80', 'Ethernet, Wi-Fi és dual SIM támogatású központi egység.'),
('Ajax MotionProtect Vezeték Nélküli Mozgásérzékelő', 4, 18900, 45, 'fa-sensor-on', 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=800&q=80', 'Kisállat-védett infrás mozgásérzékelő.'),
('Hikvision 4K IP Dome Kamera 30m IR', 6, 42500, 18, 'fa-video', 'https://images.unsplash.com/photo-1557324232-b8917d3c3dcb?auto=format&fit=crop&w=800&q=80', 'Acusense ember/jármű megkülönböztetés, IP67 vízálló.'),
('Schneider Electric 16A Kismegszakító (C16)', 13, 1490, 150, 'fa-bolt', 'https://images.unsplash.com/photo-1581091012184-7f4f4bcbf6b1?auto=format&fit=crop&w=800&q=80', 'B és C kioldási karakterisztikával lakossági elosztókhoz.'),
('Wi-Fi Videó Kaputelefon Beltéri Egységgel', 12, 68900, 8, 'fa-door-closed', 'https://images.unsplash.com/photo-1616627455480-8c6366f75f1a?auto=format&fit=crop&w=800&q=80', 'Mobiltelefonos kapunyitás és HD videókép.'),
('Fi-Relé (Áram-védőkapcsoló) 40A 30mA', 15, 11200, 30, 'fa-plug', 'https://images.unsplash.com/photo-1584277261846-c6a1672ed979?auto=format&fit=crop&w=800&q=80', 'Életvédelmi relé családi házak védelméhez.');

INSERT INTO orders (user_id, total, payment_method, payment_provider, payment_status, status, created_at) VALUES
(2, 143800, 'bank_transfer', 'offline', 'accepted', 'processing', '2026-03-18 10:15:00'),
(3, 42500, 'cash_on_delivery', 'offline', 'paid', 'completed', '2026-03-15 14:22:00');

INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES
(1, 1, 1, 124900),
(1, 2, 1, 18900),
(2, 3, 1, 42500);

INSERT INTO quotes (name, phone, email, work_type, message, status, admin_reply, replied_at, created_at) VALUES
('Varga Balázs', '+36 30 555 1234', 'varga.balazs@example.hu', 'Riasztó kiépítés', '120m2-es családi házhoz szeretnék komplett Ajax rendszert.', 'new', NULL, NULL, '2026-03-19 09:45:00'),
('Kiss Andrea', '+36 30 111 2233', 'kiss.andrea@example.hu', 'Kamerarendszer bővítés', 'Két új kültéri kamerát szeretnék a garázshoz és az udvarra.', 'answered', 'Köszönjük a megkeresést, 24 órán belül küldjük a részletes ajánlatot.', '2026-03-20 15:30:00', '2026-03-20 14:10:00');

INSERT INTO quote_replies (quote_id, admin_user_id, reply_message, created_at) VALUES
(2, 1, 'Köszönjük a megkeresést, 24 órán belül küldjük a részletes ajánlatot.', '2026-03-20 15:30:00');

INSERT INTO support_chats (
  id, user_name, user_email, status, created_at, updated_at, last_message_at,
  last_customer_message_at, last_admin_message_at, admin_unread_count, customer_unread_count, unread_count, deleted_at
) VALUES
(1, 'Kovács Júlia', 'kovacs.julia@example.hu', 'new', '2026-03-21 10:05:00', '2026-03-21 10:05:00', '2026-03-21 10:05:00', '2026-03-21 10:05:00', NULL, 1, 0, 1, NULL),
(2, 'Fekete András', 'fekete.andras@example.hu', 'open', '2026-03-20 16:20:00', '2026-03-20 17:05:00', '2026-03-20 17:05:00', '2026-03-20 16:20:00', '2026-03-20 17:05:00', 0, 1, 0, NULL);

INSERT INTO support_messages (chat_id, sender_type, sender_name, sender_email, message, created_at) VALUES
(1, 'customer', 'Kovács Júlia', 'kovacs.julia@example.hu', 'Jó estét! A lakásomban időnként lever a biztosíték, tudnának visszahívni?', '2026-03-21 10:05:00'),
(2, 'customer', 'Fekete András', 'fekete.andras@example.hu', 'Szeretnék egy kisebb kamerarendszer bővítést egyeztetni.', '2026-03-20 16:20:00'),
(2, 'admin', 'Tamás Bau Admin', 'admin@tamasbau.hu', 'Köszönjük az üzenetet! Holnap délelőtt felvesszük Önnel a kapcsolatot.', '2026-03-20 17:05:00');

INSERT INTO saved_estimates (user_id, area, wiring_type, alarm_qty, camera_qty, intercom, total, created_at) VALUES
(2, 70, 'full', 1, 2, 0, 1190000, '2026-03-20 11:30:00');

INSERT INTO wishlists (user_id, product_id, created_at) VALUES
(2, 3, '2026-03-22 08:00:00');
