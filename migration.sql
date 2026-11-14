-- ============================================================
-- Kweek feature migration
-- Adds: custom success/thank-you pages, per-link logo branding,
--       item photos + "view details", and live chat.
--
-- Run this ONCE via phpMyAdmin (or Adminer) in cPanel before
-- uploading the new PHP files. Safe to run on a copy of your
-- database first if you want to check it.
-- ============================================================

ALTER TABLE payment_links
  ADD COLUMN logo_url            VARCHAR(255) NULL     AFTER accent_color,
  ADD COLUMN success_title       VARCHAR(150) NULL     AFTER logo_url,
  ADD COLUMN success_message     TEXT NULL             AFTER success_title,
  ADD COLUMN success_buttons     TEXT NULL             AFTER success_message,   -- JSON: [{"label":"...","url":"..."}]
  ADD COLUMN show_receipt_button TINYINT(1) NOT NULL DEFAULT 1 AFTER success_buttons,
  ADD COLUMN chat_enabled        TINYINT(1) NOT NULL DEFAULT 1 AFTER show_receipt_button;

ALTER TABLE link_items
  ADD COLUMN image_url VARCHAR(255) NULL AFTER description;

-- Live chat threads. Scoped to (link_id, customer_token) so a buyer
-- can chat before they've even placed an order — the token is a
-- random value stored in a cookie on their first message.
CREATE TABLE IF NOT EXISTS link_chats (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  link_id           INT UNSIGNED NOT NULL,
  merchant_id       INT UNSIGNED NOT NULL,
  customer_token    VARCHAR(40)  NOT NULL,
  customer_name     VARCHAR(120) NULL,
  sender_type       ENUM('merchant','customer') NOT NULL,
  message           TEXT NOT NULL,
  read_by_merchant  TINYINT(1) NOT NULL DEFAULT 0,
  read_by_customer  TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_link_customer (link_id, customer_token),
  INDEX idx_merchant (merchant_id, read_by_merchant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upload folders (create these via cPanel File Manager, chmod 755):
--   /public_html/uploads/logos/
--   /public_html/uploads/items/
-- includes/upload_helpers.php writes into these.
