USE tamasbau;

SET @db_name := DATABASE();

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'status'
    ),
    "ALTER TABLE quotes MODIFY COLUMN status ENUM('new', 'in_progress', 'answered', 'closed') NOT NULL DEFAULT 'new'",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'admin_reply'
    ),
    'SELECT 1',
    'ALTER TABLE quotes ADD COLUMN admin_reply TEXT NULL AFTER status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'replied_at'
    ),
    'SELECT 1',
    'ALTER TABLE quotes ADD COLUMN replied_at DATETIME NULL AFTER admin_reply'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND INDEX_NAME = 'idx_quotes_status_created'
    ),
    'SELECT 1',
    'ALTER TABLE quotes ADD INDEX idx_quotes_status_created (status, created_at)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND INDEX_NAME = 'idx_quotes_email'
    ),
    'SELECT 1',
    'ALTER TABLE quotes ADD INDEX idx_quotes_email (email)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'quotes' AND INDEX_NAME = 'idx_quotes_replied_at'
    ),
    'SELECT 1',
    'ALTER TABLE quotes ADD INDEX idx_quotes_replied_at (replied_at)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS quote_replies (
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
