CREATE TABLE IF NOT EXISTS user_addresses (
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

CREATE TABLE IF NOT EXISTS user_notification_preferences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  order_emails TINYINT(1) NOT NULL DEFAULT 1,
  quote_emails TINYINT(1) NOT NULL DEFAULT 1,
  support_emails TINYINT(1) NOT NULL DEFAULT 1,
  marketing_emails TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wishlists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlists_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wishlist_user_product (user_id, product_id),
  INDEX idx_wishlists_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gdpr_requests (
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

CREATE TABLE IF NOT EXISTS admin_activity_logs (
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

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS shipping_name VARCHAR(120) NULL AFTER total,
  ADD COLUMN IF NOT EXISTS shipping_phone VARCHAR(40) NULL AFTER shipping_name,
  ADD COLUMN IF NOT EXISTS shipping_postal_code VARCHAR(20) NULL AFTER shipping_phone,
  ADD COLUMN IF NOT EXISTS shipping_city VARCHAR(120) NULL AFTER shipping_postal_code,
  ADD COLUMN IF NOT EXISTS shipping_address VARCHAR(255) NULL AFTER shipping_city,
  ADD COLUMN IF NOT EXISTS billing_name VARCHAR(120) NULL AFTER shipping_address,
  ADD COLUMN IF NOT EXISTS billing_tax_number VARCHAR(60) NULL AFTER billing_name,
  ADD COLUMN IF NOT EXISTS billing_postal_code VARCHAR(20) NULL AFTER billing_tax_number,
  ADD COLUMN IF NOT EXISTS billing_city VARCHAR(120) NULL AFTER billing_postal_code,
  ADD COLUMN IF NOT EXISTS billing_address VARCHAR(255) NULL AFTER billing_city,
  ADD COLUMN IF NOT EXISTS shipping_method VARCHAR(40) NULL AFTER billing_address,
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(40) NULL AFTER shipping_method,
  ADD COLUMN IF NOT EXISTS payment_provider VARCHAR(40) NULL AFTER payment_method,
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(40) NULL AFTER payment_provider;
