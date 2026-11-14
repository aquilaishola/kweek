<?php
// Run every few minutes via cron: php /path/to/reconcile-payments.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/payment.php';

$db = db();

$stmt = $db->query("
    SELECT order_ref FROM orders
    WHERE status = 'pending_payment'
      AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
");

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderRef) {
    try {
        $result = finalizeOrderPayment($orderRef);
        if ($result['ok'] && $result['reason'] === 'finalized') {
            echo "Finalized $orderRef\n";
        }
    } catch (Throwable $e) {
        error_log("reconcile-payments: $orderRef failed — " . $e->getMessage());
    }
}
