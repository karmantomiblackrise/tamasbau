ALTER TABLE users
  MODIFY COLUMN role ENUM('user', 'admin', 'superadmin', 'webshop_manager', 'quote_manager', 'support_agent', 'accountant', 'content_manager') NOT NULL DEFAULT 'user';

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(120) NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS tracking_url VARCHAR(1000) NULL AFTER tracking_number,
  ADD COLUMN IF NOT EXISTS stock_reverted TINYINT(1) NOT NULL DEFAULT 0 AFTER tracking_url;

CREATE TABLE IF NOT EXISTS order_status_logs (
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

CREATE TABLE IF NOT EXISTS stock_movements (
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
