-- ============================================================
-- KWEEK DATABASE SCHEMA
-- Run this in phpMyAdmin or MySQL CLI
-- Database: kweek_db
-- ============================================================

CREATE DATABASE IF NOT EXISTS kweek_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kweek_db;

-- ── USERS (merchants) ────────────────────────────────────────
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(180) NOT NULL UNIQUE,
    phone           VARCHAR(20) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    plan            ENUM('free','pro','business') DEFAULT 'free',
    status          ENUM('active','suspended','pending') DEFAULT 'pending',
    email_verified  TINYINT(1) DEFAULT 0,
    bvn_verified    TINYINT(1) DEFAULT 0,
    avatar          VARCHAR(255) DEFAULT NULL,

    -- Nomba virtual account
    nomba_account_id        VARCHAR(120) DEFAULT NULL,
    nomba_account_number    VARCHAR(20)  DEFAULT NULL,
    nomba_bank_name         VARCHAR(80)  DEFAULT NULL,

    -- Withdrawal bank details
    bank_name       VARCHAR(80)  DEFAULT NULL,
    bank_code       VARCHAR(10)  DEFAULT NULL,
    account_number  VARCHAR(20)  DEFAULT NULL,
    account_name    VARCHAR(120) DEFAULT NULL,

    -- Wallet
    wallet_balance  DECIMAL(15,2) DEFAULT 0.00,
    total_earned    DECIMAL(15,2) DEFAULT 0.00,

    -- Plan billing
    plan_expires_at TIMESTAMP NULL DEFAULT NULL,
    trial_ends_at   TIMESTAMP NULL DEFAULT NULL,

    -- Monthly transaction tracking (for caps)
    monthly_volume  DECIMAL(15,2) DEFAULT 0.00,
    monthly_reset   DATE DEFAULT NULL,

    -- Tokens
    email_token     VARCHAR(100) DEFAULT NULL,
    reset_token     VARCHAR(100) DEFAULT NULL,
    reset_expires   TIMESTAMP NULL DEFAULT NULL,

    remember_token  VARCHAR(100) DEFAULT NULL,

    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_plan (plan),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ── USER KYC ────────────────────────────────────────────────
CREATE TABLE user_kyc (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    bvn_hash    VARCHAR(255) DEFAULT NULL,  -- SHA-256 hashed BVN
    bvn_last4   VARCHAR(4)   DEFAULT NULL,
    full_name   VARCHAR(120) DEFAULT NULL,
    dob         DATE         DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    status      ENUM('pending','verified','failed') DEFAULT 'pending',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_kyc (user_id)
) ENGINE=InnoDB;

-- ── PAYMENT LINKS ────────────────────────────────────────────
CREATE TABLE payment_links (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    title           VARCHAR(160) NOT NULL,
    slug            VARCHAR(80) NOT NULL UNIQUE,
    description     TEXT DEFAULT NULL,
    category        VARCHAR(60) DEFAULT NULL,

    -- Type
    link_type       ENUM('simple','menu','installment','group') DEFAULT 'simple',

    -- Pricing
    amount          DECIMAL(15,2) DEFAULT NULL,  -- NULL = customer enters amount
    min_amount      DECIMAL(15,2) DEFAULT NULL,
    max_amount      DECIMAL(15,2) DEFAULT NULL,

    -- Order confirmation flow
    require_confirmation TINYINT(1) DEFAULT 0,

    -- Redirect after payment
    redirect_url    VARCHAR(500) DEFAULT NULL,

    -- Custom fields (JSON array of field configs)
    custom_fields   JSON DEFAULT NULL,

    -- Delivery zones (JSON)
    delivery_zones  JSON DEFAULT NULL,

    -- Installment config (JSON)
    installment_config JSON DEFAULT NULL,

    -- Status
    status          ENUM('active','paused','archived') DEFAULT 'active',
    is_expired      TINYINT(1) DEFAULT 0,
    expires_at      TIMESTAMP NULL DEFAULT NULL,

    -- Branding
    cover_image     VARCHAR(255) DEFAULT NULL,
    accent_color    VARCHAR(7)   DEFAULT '#4F43D4',

    -- Stats (cached)
    total_orders    INT UNSIGNED DEFAULT 0,
    total_revenue   DECIMAL(15,2) DEFAULT 0.00,
    view_count      INT UNSIGNED DEFAULT 0,

    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_slug (slug),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ── LINK ITEMS (menu items per payment link) ─────────────────
CREATE TABLE link_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(160) NOT NULL,
    description     VARCHAR(300) DEFAULT NULL,
    price           DECIMAL(15,2) NOT NULL,
    image           VARCHAR(255) DEFAULT NULL,
    is_available    TINYINT(1) DEFAULT 1,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (link_id) REFERENCES payment_links(id) ON DELETE CASCADE,
    INDEX idx_link_id (link_id)
) ENGINE=InnoDB;

-- ── ORDERS ───────────────────────────────────────────────────
CREATE TABLE orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,  -- merchant
    order_ref       VARCHAR(40) NOT NULL UNIQUE,

    -- Customer info
    customer_name   VARCHAR(120) NOT NULL,
    customer_email  VARCHAR(180) DEFAULT NULL,
    customer_phone  VARCHAR(20)  NOT NULL,

    -- Delivery
    delivery_zone   VARCHAR(80)  DEFAULT NULL,
    delivery_fee    DECIMAL(15,2) DEFAULT 0.00,
    delivery_address TEXT DEFAULT NULL,

    -- Amounts
    subtotal        DECIMAL(15,2) NOT NULL,
    total_amount    DECIMAL(15,2) NOT NULL,

    -- Items ordered (JSON snapshot)
    items           JSON DEFAULT NULL,

    -- Custom fields response (JSON)
    custom_fields   JSON DEFAULT NULL,

    -- Status flow
    status          ENUM('pending_confirmation','confirmed','pending_payment','paid','processing','completed','declined','refunded','cancelled') DEFAULT 'pending_confirmation',

    -- Confirmation
    confirmed_at    TIMESTAMP NULL DEFAULT NULL,
    declined_at     TIMESTAMP NULL DEFAULT NULL,
    decline_reason  VARCHAR(300) DEFAULT NULL,

    -- Merchant notes
    merchant_note   TEXT DEFAULT NULL,

    -- Nomba payment
    nomba_checkout_id   VARCHAR(120) DEFAULT NULL,
    nomba_txn_ref       VARCHAR(120) DEFAULT NULL,
    paid_at             TIMESTAMP NULL DEFAULT NULL,

    -- For group orders
    group_session_id    VARCHAR(80) DEFAULT NULL,

    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (link_id) REFERENCES payment_links(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_order_ref (order_ref),
    INDEX idx_user_id (user_id),
    INDEX idx_link_id (link_id),
    INDEX idx_status (status),
    INDEX idx_nomba_txn (nomba_txn_ref)
) ENGINE=InnoDB;

-- ── INSTALLMENTS ─────────────────────────────────────────────
CREATE TABLE installments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    installment_num TINYINT UNSIGNED NOT NULL,  -- 1, 2, 3...
    amount          DECIMAL(15,2) NOT NULL,
    due_date        DATE DEFAULT NULL,
    status          ENUM('pending','paid','overdue') DEFAULT 'pending',
    nomba_txn_ref   VARCHAR(120) DEFAULT NULL,
    paid_at         TIMESTAMP NULL DEFAULT NULL,

    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_order_id (order_id)
) ENGINE=InnoDB;

-- ── TRANSACTIONS ─────────────────────────────────────────────
CREATE TABLE transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    order_id        INT UNSIGNED DEFAULT NULL,
    type            ENUM('credit','debit','withdrawal','refund','fee') NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    fee             DECIMAL(15,2) DEFAULT 0.00,
    net_amount      DECIMAL(15,2) NOT NULL,
    balance_before  DECIMAL(15,2) NOT NULL,
    balance_after   DECIMAL(15,2) NOT NULL,
    description     VARCHAR(300) NOT NULL,
    reference       VARCHAR(120) NOT NULL UNIQUE,
    nomba_ref       VARCHAR(120) DEFAULT NULL,
    status          ENUM('pending','success','failed','reversed') DEFAULT 'pending',
    meta            JSON DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_reference (reference),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ── WITHDRAWALS ──────────────────────────────────────────────
CREATE TABLE withdrawals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    fee             DECIMAL(15,2) DEFAULT 0.00,
    net_amount      DECIMAL(15,2) NOT NULL,
    bank_name       VARCHAR(80)  NOT NULL,
    bank_code       VARCHAR(10)  NOT NULL,
    account_number  VARCHAR(20)  NOT NULL,
    account_name    VARCHAR(120) NOT NULL,
    reference       VARCHAR(120) NOT NULL UNIQUE,
    nomba_ref       VARCHAR(120) DEFAULT NULL,
    status          ENUM('pending','processing','success','failed') DEFAULT 'pending',
    failure_reason  VARCHAR(300) DEFAULT NULL,
    processed_at    TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_reference (reference)
) ENGINE=InnoDB;

-- ── RECEIPTS ─────────────────────────────────────────────────
CREATE TABLE receipts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    receipt_code    VARCHAR(40) NOT NULL UNIQUE,  -- TXN-8847-KQPL
    amount          DECIMAL(15,2) NOT NULL,
    customer_name   VARCHAR(120) NOT NULL,
    merchant_name   VARCHAR(120) NOT NULL,
    paid_at         TIMESTAMP NOT NULL,
    is_valid        TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_receipt_code (receipt_code)
) ENGINE=InnoDB;

-- ── WEBHOOKS LOG ─────────────────────────────────────────────
CREATE TABLE webhook_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type      VARCHAR(80) NOT NULL,
    payload         JSON NOT NULL,
    signature       VARCHAR(255) DEFAULT NULL,
    status          ENUM('received','processed','failed','ignored') DEFAULT 'received',
    error           TEXT DEFAULT NULL,
    processed_at    TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event (event_type),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ── NOTIFICATIONS ────────────────────────────────────────────
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(60) NOT NULL,  -- 'payment_received', 'order_confirmed', etc.
    title       VARCHAR(160) NOT NULL,
    message     TEXT NOT NULL,
    icon        VARCHAR(40) DEFAULT 'bell',
    link        VARCHAR(300) DEFAULT NULL,
    is_read     TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB;

-- ── PLAN SUBSCRIPTIONS ───────────────────────────────────────
CREATE TABLE subscriptions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    plan            ENUM('pro','business') NOT NULL,
    billing_cycle   ENUM('monthly','annual') DEFAULT 'monthly',
    amount          DECIMAL(15,2) NOT NULL,
    status          ENUM('active','cancelled','expired','trial') DEFAULT 'trial',
    starts_at       TIMESTAMP NOT NULL,
    ends_at         TIMESTAMP NOT NULL,
    cancelled_at    TIMESTAMP NULL DEFAULT NULL,
    nomba_txn_ref   VARCHAR(120) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ── ADMIN USERS ──────────────────────────────────────────────
CREATE TABLE admins (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(180) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('super_admin','support','finance') DEFAULT 'support',
    is_active   TINYINT(1) DEFAULT 1,
    last_login  TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── RATE LIMITS ──────────────────────────────────────────────
CREATE TABLE rate_limits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier  VARCHAR(120) NOT NULL,  -- IP or user_id
    action      VARCHAR(60) NOT NULL,   -- 'login', 'otp', 'payment'
    attempts    INT DEFAULT 1,
    blocked_until TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_rate (identifier, action),
    INDEX idx_identifier (identifier),
    INDEX idx_action (action)
) ENGINE=InnoDB;

-- ── SETTINGS ─────────────────────────────────────────────────
CREATE TABLE settings (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`   VARCHAR(80) NOT NULL UNIQUE,
    `value` TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default settings
INSERT INTO settings (`key`, `value`) VALUES
('site_name', 'Kweek'),
('site_url', 'https://kweek.ng'),
('support_email', 'support@kweek.ng'),
('free_tx_fee', '1.5'),
('pro_tx_fee', '0.8'),
('business_tx_fee', '0.5'),
('withdrawal_fee', '100'),
('free_monthly_cap', '200000'),
('pro_monthly_cap', '5000000'),
('maintenance_mode', '0'),
('new_signups', '1');

-- Default super admin (password: Admin@2026 — change immediately)
INSERT INTO admins (name, email, password, role) VALUES
('Kweek Admin', 'admin@kweek.ng', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin');
