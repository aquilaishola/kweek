<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'schedkweek');
define('DB_USER', 'sch_kweek');        
define('DB_PASS', 'Aquil10');             
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL',   'https://d.schedwave.com');
define('SITE_NAME',  'Kweek');
define('NOMBA_TEST_CLIENT_ID',  '706d-41052f8631');
define('NOMBA_TEST_PRIVATE_KEY','k8UobYkfjcHs8RH0YISBB4OMqJsaafG+U8fWETu9YZ96bNXE+DelCDuMPw==');
define('NOMBA_LIVE_CLIENT_ID',  'e5edbbc15');
define('NOMBA_LIVE_PRIVATE_KEY','8/doSBs01sXyZ1AmovtZUXlmrxie+xnEF2tR4q79t0IFufMD1d4JrkT8g==');
define('NOMBA_ACCOUNT_ID',      'f6628023');
define('NOMBA_SUB_ACCOUNT_ID',  'dcafb608532084');
define('NOMBA_ENV',             'live'); 
define('NOMBA_BASE_URL',        'https://api.nomba.com/v1');
define('NOMBA_WEBHOOK_SECRET', 'Nombon2026');

// SMTP
define('SMTP_HOST',   'mve.com');   
define('SMTP_USER',   'kweee.com');  
define('SMTP_PASS',   'Aqu0'); 
define('SMTP_SECURE', 'tls');    
define('SMTP_PORT',   587);                         
 
// From address shown to recipients
define('MAIL_FROM_ADDRESS', 'noreply@yourdomain.com');
define('MAIL_FROM_NAME',    'Kweek');

// Transaction fees per plan
define('FEE_FREE',     1.5);
define('FEE_PRO',      0.8);
define('FEE_BUSINESS', 0.5);

// Monthly caps
define('CAP_FREE',     200000);
define('CAP_PRO',      5000000);
define('CAP_BUSINESS', PHP_INT_MAX);

// Withdrawal fee (flat naira)
define('WITHDRAWAL_FEE', 100);

// Plan prices (naira)
define('PRICE_PRO_MONTHLY',      6500);
define('PRICE_PRO_ANNUAL',       65000);
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