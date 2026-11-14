<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL',   'https://example.com');
define('SITE_NAME',  'Kweek');

define('BACHS_MODE',           'sandbox'); // 'sandbox' or 'live'
define('BACHS_SECRET_KEY',     'your_bachs_secret_key');
define('BACHS_BASE_URL',       BACHS_MODE === 'live' ? 'https://api.bachs.io' : 'https://sandbox-api.bachs.io');
define('BACHS_WEBHOOK_SECRET', 'your_bachs_webhook_secret');

// SMTP
define('SMTP_HOST',   'mail.example.com');
define('SMTP_USER',   'no-reply@example.com');
define('SMTP_PASS',   'your_smtp_password');
define('SMTP_SECURE', 'tls');
define('SMTP_PORT',   587);

// Google sign-in
define('GOOGLE_CLIENT_ID',     'your_google_client_id');
define('GOOGLE_CLIENT_SECRET', 'your_google_client_secret');
define('GOOGLE_REDIRECT_URI',  rtrim(SITE_URL, '/') . '/google-callback.php');

// PWA push notifications
define('VAPID_PUBLIC_KEY',  'your_vapid_public_key');
define('VAPID_PRIVATE_KEY', 'your_vapid_private_key');
define('VAPID_SUBJECT',     'mailto:you@example.com');

define('STROWALLET_KYC_PUBLIC_KEY', 'your_strowallet_public_key');
define('STROWALLET_KYC_MODE',       'sandbox');

define('PAYVESSEL_API_KEY',    'your_payvessel_api_key');
define('PAYVESSEL_API_SECRET', 'your_payvessel_api_secret');
define('PAYVESSEL_SANDBOX',    true);

// From address shown to recipients
define('MAIL_FROM_ADDRESS', 'no-reply@ek.ng');
define('MAIL_FROM_NAME',    'Kweek');

// Transaction fees per plan (%)
define('FEE_FREE',     2.3);
define('FEE_STARTER',  2.0);
define('FEE_PRO',      1.8);
define('FEE_BUSINESS', 1.6);

// Monthly transaction volume caps (₦)
define('CAP_FREE',     200000);
define('CAP_STARTER',  750000);
define('CAP_PRO',      5000000);
define('CAP_BUSINESS', 1000000000);

// Withdrawal fee per plan (flat ₦ — 0 means free withdrawals)
define('WITHDRAWAL_FEE_FREE',     100);
define('WITHDRAWAL_FEE_STARTER',  80);
define('WITHDRAWAL_FEE_PRO',      70);
define('WITHDRAWAL_FEE_BUSINESS', 60);

// Active payment link limit per plan (PHP_INT_MAX = unlimited)
define('LINK_LIMIT_FREE',     1);
define('LINK_LIMIT_STARTER',  10);
define('LINK_LIMIT_PRO',      50);
define('LINK_LIMIT_BUSINESS', PHP_INT_MAX);

// Plan prices (₦)
define('PRICE_STARTER_MONTHLY',  5000);
define('PRICE_STARTER_ANNUAL',   50000);
define('PRICE_PRO_MONTHLY',      10000);
define('PRICE_PRO_ANNUAL',       100000);
define('PRICE_BUSINESS_MONTHLY', 15000);
define('PRICE_BUSINESS_ANNUAL',  150000);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}