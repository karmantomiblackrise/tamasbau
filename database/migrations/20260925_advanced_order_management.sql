SET @role_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'
  LIMIT 1
);
SET @sql := IF(@role_type IS NULL OR LOCATE('superadmin', @role_type) = 0, 'ALTER TABLE users MODIFY COLUMN role ENUM(''user'', ''admin'', ''superadmin'') NOT NULL DEFAULT ''user''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'tracking_number'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(120) NULL AFTER payment_status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'tracking_url'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE orders ADD COLUMN tracking_url VARCHAR(1000) NULL AFTER tracking_number', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'stock_reverted'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE orders ADD COLUMN stock_reverted TINYINT(1) NOT NULL DEFAULT 0 AFTER tracking_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE orders
SET status = CASE
  WHEN LOWER(TRIM(status)) = 'fizetésre vár' THEN 'payment_pending'
  WHEN LOWER(TRIM(status)) = 'feldolgozás alatt' THEN 'processing'
  WHEN LOWER(TRIM(status)) = 'teljesítve' THEN 'completed'
  WHEN LOWER(TRIM(status)) = 'lemondva' THEN 'cancelled'
  ELSE status
END;

UPDATE orders
SET status = 'processing'
WHERE LOWER(TRIM(status)) NOT IN ('new', 'payment_pending', 'processing', 'packing', 'shipped', 'completed', 'cancelled', 'refunded');

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_orders_status_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_status_created ON orders (status, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_orders_user_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_user_created ON orders (user_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


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

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND column_name = 'from_status'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE order_status_logs ADD COLUMN from_status VARCHAR(60) NOT NULL AFTER order_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND column_name = 'to_status'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE order_status_logs ADD COLUMN to_status VARCHAR(60) NOT NULL AFTER from_status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND column_name = 'changed_by_user_id'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE order_status_logs ADD COLUMN changed_by_user_id INT UNSIGNED NULL AFTER to_status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND column_name = 'note'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE order_status_logs ADD COLUMN note VARCHAR(500) NULL AFTER changed_by_user_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND index_name = 'idx_order_status_logs_order_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_order_status_logs_order_created ON order_status_logs (order_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND constraint_name = 'fk_order_status_logs_order' AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE order_status_logs ADD CONSTRAINT fk_order_status_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'order_status_logs' AND constraint_name = 'fk_order_status_logs_user' AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE order_status_logs ADD CONSTRAINT fk_order_status_logs_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND column_name = 'movement_type'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE stock_movements ADD COLUMN movement_type ENUM(''reserve'', ''release'', ''manual_adjustment'') NOT NULL AFTER order_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND column_name = 'qty'
);
SET @sql := IF(@column_exists = 0, 'ALTER TABLE stock_movements ADD COLUMN qty INT NOT NULL AFTER movement_type', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND index_name = 'idx_stock_movements_product_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_stock_movements_product_created ON stock_movements (product_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND constraint_name = 'fk_stock_movements_product' AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE stock_movements ADD CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND constraint_name = 'fk_stock_movements_order' AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE stock_movements ADD CONSTRAINT fk_stock_movements_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND index_name = 'idx_stock_movements_order_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_stock_movements_order_created ON stock_movements (order_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
