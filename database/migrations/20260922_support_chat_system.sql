USE tamasbau;

CREATE TABLE IF NOT EXISTS support_chats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_name VARCHAR(120) NULL,
  user_email VARCHAR(190) NOT NULL,
  status ENUM('new', 'open', 'resolved') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_support_chats_status_last_message (status, last_message_at),
  INDEX idx_support_chats_email_updated (user_email, updated_at),
  INDEX idx_support_chats_unread (unread_count, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS support_messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
