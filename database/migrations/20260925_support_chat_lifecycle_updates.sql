USE tamasbau;

ALTER TABLE support_chats
  MODIFY COLUMN status ENUM('new', 'open', 'resolved', 'closed') NOT NULL DEFAULT 'new',
  ADD COLUMN last_customer_message_at TIMESTAMP NULL DEFAULT NULL AFTER last_message_at,
  ADD COLUMN last_admin_message_at TIMESTAMP NULL DEFAULT NULL AFTER last_customer_message_at,
  ADD COLUMN admin_unread_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_admin_message_at,
  ADD COLUMN customer_unread_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER admin_unread_count,
  ADD COLUMN deleted_at DATETIME NULL AFTER unread_count,
  ADD INDEX idx_support_chats_admin_unread (admin_unread_count, last_message_at),
  ADD INDEX idx_support_chats_customer_unread (customer_unread_count, last_message_at),
  ADD INDEX idx_support_chats_deleted_at (deleted_at);

UPDATE support_chats
SET
  admin_unread_count = unread_count,
  customer_unread_count = 0,
  last_customer_message_at = (
    SELECT MAX(sm.created_at)
    FROM support_messages sm
    WHERE sm.chat_id = support_chats.id AND sm.sender_type = 'customer'
  ),
  last_admin_message_at = (
    SELECT MAX(sm.created_at)
    FROM support_messages sm
    WHERE sm.chat_id = support_chats.id AND sm.sender_type = 'admin'
  )
WHERE 1 = 1;
