<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawBody   = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_BACHS_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_BACHS_SIGNATURE'] ?? '';

$db = db();
$logId = null;
try {
    $db->prepare("INSERT INTO webhook_logs (event_type, payload, signature, status) VALUES ('raw', ?, ?, 'received')")
       ->execute([$rawBody, $signature]);
    $logId = (int) $db->lastInsertId();
} catch (Throwable $e) {
    error_log("Webhook log error: " . $e->getMessage());
}

if (!Nomba::verifyWebhookSignature($rawBody, $signature, $timestamp)) {
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Invalid signature' WHERE id=?")->execute([$logId]);
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($rawBody, true);
if (!$event) {
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Invalid JSON' WHERE id=?")->execute([$logId]);
    http_response_code(400);
    exit('Bad request');
}

$type       = $event['type'] ?? '';
$data       = $event['data'] ?? [];
$checkoutId = (string) ($data['checkout_id'] ?? '');

if ($logId) $db->prepare("UPDATE webhook_logs SET event_type=? WHERE id=?")->execute([$type, $logId]);

if (str_starts_with($type, 'payout.')) {
    try {
        $result = handlePayoutEvent($type, $data, $db);
        if ($logId) {
            $logStatus = $result['ok'] ? 'processed' : 'failed';
            $db->prepare("UPDATE webhook_logs SET status=?, error=?, processed_at=NOW() WHERE id=?")
               ->execute([$logStatus, $result['reason'], $logId]);
        }
        http_response_code(200);
        echo json_encode(['message' => $result['reason']]);
    } catch (Throwable $e) {
        error_log("Payout webhook processing error: " . $e->getMessage());
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error=? WHERE id=?")->execute([$e->getMessage(), $logId]);
        http_response_code(500);
        echo json_encode(['message' => 'processing_failed']);
    }
    exit;
}

if (empty($checkoutId)) {
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored', error='No checkout_id on this event' WHERE id=?")->execute([$logId]);
    http_response_code(200);
    echo json_encode(['message' => 'ignored']);
    exit;
}

try {
    $orderStmt = $db->prepare("SELECT order_ref FROM orders WHERE nomba_checkout_id = ? LIMIT 1");
    $orderStmt->execute([$checkoutId]);
    $orderRef = $orderStmt->fetchColumn();

    if ($orderRef) {
        if ($type === 'collection.succeeded') {
            $result = finalizeOrderPayment($orderRef);
        } elseif (in_array($type, ['collection.failed', 'checkout.expired'], true)) {
            markOrderFailed($orderRef);
            $result = ['ok' => true, 'reason' => 'marked_failed'];
        } else {
            $result = ['ok' => true, 'reason' => 'ignored_event_type'];
        }
    } else {
        $subStmt = $db->prepare("SELECT reference FROM subscriptions WHERE nomba_checkout_id = ? LIMIT 1");
        $subStmt->execute([$checkoutId]);
        $subRef = $subStmt->fetchColumn();

        if ($subRef && $type === 'collection.succeeded') {
            $result = finalizeSubscriptionPayment($subRef);
        } elseif ($subRef && in_array($type, ['collection.failed', 'checkout.expired'], true)) {
            $db->prepare("UPDATE subscriptions SET status='expired' WHERE reference=? AND status='pending'")->execute([$subRef]);
            $result = ['ok' => true, 'reason' => 'marked_failed'];
        } else {
            $result = ['ok' => false, 'reason' => 'checkout_id_not_found'];
        }
    }

    if ($logId) {
        $logStatus = $result['ok'] ? 'processed' : 'failed';
        $db->prepare("UPDATE webhook_logs SET status=?, error=?, processed_at=NOW() WHERE id=?")
           ->execute([$logStatus, $result['reason'], $logId]);
    }

    http_response_code(200);
    echo json_encode(['message' => $result['reason']]);

} catch (Throwable $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error=? WHERE id=?")->execute([$e->getMessage(), $logId]);
    http_response_code(500);
    echo json_encode(['message' => 'processing_failed']);
}