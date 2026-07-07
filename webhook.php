<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/mailer.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawBody = file_get_contents('php://input');

$signature = $_SERVER['HTTP_NOMBA_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_NOMBA_TIMESTAMP'] ?? '';

$db = db();
$logId = null;
try {
    $db->prepare("INSERT INTO webhook_logs (event_type, payload, signature, status) VALUES ('raw', ?, ?, 'received')")
       ->execute([$rawBody, $signature]);
    $logId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log("Webhook log error: " . $e->getMessage());
}

if (defined('NOMBA_WEBHOOK_SECRET') && NOMBA_WEBHOOK_SECRET && $signature) {
    if (!Nomba::verifyWebhookSignature($rawBody, $signature, $timestamp)) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Invalid signature' WHERE id=?")->execute([$logId]);
        http_response_code(401);
        exit('Invalid signature');
    }
} elseif ($logId) {
    error_log('Webhook: signature verification skipped — NOMBA_WEBHOOK_SECRET not configured yet');
}

$payload = json_decode($rawBody, true);
if (!$payload) {
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Invalid JSON' WHERE id=?")->execute([$logId]);
    http_response_code(400);
    exit('Bad request');
}

$eventType = $payload['event_type'] ?? $payload['event'] ?? $payload['eventType'] ?? 'unknown';

if ($logId) $db->prepare("UPDATE webhook_logs SET event_type=? WHERE id=?")->execute([$eventType, $logId]);

try {
switch ($eventType) {
    case 'payment_success':
    case 'checkout.order.paid':
    case 'payment.success':
    case 'transfer.credit':
        $data      = $payload['data'] ?? $payload;
        $orderData = $data['order'] ?? [];
        $checkRef  = $orderData['orderReference'] ?? $data['orderReference'] ?? $data['reference'] ?? '';

        if (str_starts_with($checkRef, 'SUB-')) {
            handleSubscriptionSuccess($payload, $db, $logId);
        } else {
            handlePaymentSuccess($payload, $db, $logId);
        }
        break;

    case 'payment_failed':
    case 'checkout.order.failed':
    case 'payment.failed':
        handlePaymentFailed($payload, $db, $logId);
        break;

    case 'payout_success':
        handlePayoutSuccess($payload, $db, $logId);
        break;

    case 'payout_failed':
    case 'payout_refund':
        handlePayoutFailedOrRefunded($payload, $db, $logId);
        break;

    default:
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored' WHERE id=?")->execute([$logId]);
        break;
}

    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (Throwable $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error=? WHERE id=?")->execute([$e->getMessage(), $logId]);
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}

// ── PAYOUT SUCCESS HANDLER ─────────────────────────────────────
function handlePayoutSuccess(array $payload, PDO $db, ?int $logId): void {
    $data = $payload['data'] ?? $payload;
    $txn  = $data['transaction'] ?? [];
    $ref  = $txn['merchantTxRef'] ?? $data['merchantTxRef'] ?? '';

    if (empty($ref)) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Missing merchantTxRef' WHERE id=?")->execute([$logId]);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM withdrawals WHERE reference=? LIMIT 1");
    $stmt->execute([$ref]);
    $wd = $stmt->fetch();

    if (!$wd) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Withdrawal not found' WHERE id=?")->execute([$logId]);
        return;
    }

    if ($wd['status'] === 'success') {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored', error='Already processed' WHERE id=?")->execute([$logId]);
        return;
    }

    $db->prepare("UPDATE withdrawals SET status='success', processed_at=NOW() WHERE id=?")->execute([$wd['id']]);

    notify($wd['user_id'], 'withdrawal', 'Withdrawal Successful', formatNaira($wd['net_amount']) . ' has landed in ' . $wd['bank_name'] . '.', 'circle-check', '/transactions.php');

    if ($logId) $db->prepare("UPDATE webhook_logs SET status='processed', processed_at=NOW() WHERE id=?")->execute([$logId]);
}

function handlePayoutFailedOrRefunded(array $payload, PDO $db, ?int $logId): void {
    $data = $payload['data'] ?? $payload;
    $txn  = $data['transaction'] ?? [];
    $ref  = $txn['merchantTxRef'] ?? $data['merchantTxRef'] ?? '';

    if (empty($ref)) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Missing merchantTxRef' WHERE id=?")->execute([$logId]);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM withdrawals WHERE reference=? LIMIT 1");
    $stmt->execute([$ref]);
    $wd = $stmt->fetch();

    if (!$wd) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Withdrawal not found' WHERE id=?")->execute([$logId]);
        return;
    }

    if (in_array($wd['status'], ['failed', 'refunded'])) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored', error='Already processed' WHERE id=?")->execute([$logId]);
        return;
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE withdrawals SET status='failed', processed_at=NOW() WHERE id=?")->execute([$wd['id']]);

        creditWallet(
            $wd['user_id'],
            $wd['amount'],
            0,
            'Refund for failed withdrawal ' . $wd['reference'],
            generateRef('RFD'),
            null,
            $ref,
            false
        );

        $db->commit();

        notify($wd['user_id'], 'withdrawal', 'Withdrawal Failed — Refunded', formatNaira($wd['amount']) . ' has been returned to your wallet.', 'alert-circle', '/transactions.php');

        if ($logId) $db->prepare("UPDATE webhook_logs SET status='processed', processed_at=NOW() WHERE id=?")->execute([$logId]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── SUBSCRIPTION PAYMENT HANDLER ──────────────────────────────
function handleSubscriptionSuccess(array $payload, PDO $db, ?int $logId): void {
    $data      = $payload['data'] ?? $payload;
    $orderData = $data['order'] ?? [];
    $ref       = $orderData['orderReference'] ?? $data['orderReference'] ?? $data['reference'] ?? '';

    if (empty($ref)) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Missing orderReference' WHERE id=?")->execute([$logId]);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE reference=? LIMIT 1");
    $stmt->execute([$ref]);
    $sub = $stmt->fetch();

    if (!$sub) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Subscription not found' WHERE id=?")->execute([$logId]);
        return;
    }

    if ($sub['status'] === 'active') {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored', error='Already processed' WHERE id=?")->execute([$logId]);
        return;
    }

    try {
        $nomba  = new Nomba();
        $verify = $nomba->verifyTransaction($ref, 'orderReference');
        if (strtoupper($verify['data']['status'] ?? '') !== 'SUCCESS') {
            if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='API verification failed' WHERE id=?")->execute([$logId]);
            return;
        }
    } catch (Throwable $e) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error=? WHERE id=?")->execute(['Verification error: ' . $e->getMessage(), $logId]);
        return;
    }

    $durationDays = $sub['billing_cycle'] === 'annual' ? 365 : 30;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE subscriptions SET status='active', starts_at=NOW(), ends_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")
           ->execute([$durationDays, $sub['id']]);

        $db->prepare("UPDATE users SET plan=? WHERE id=?")
           ->execute([$sub['plan'], $sub['user_id']]);

        $db->commit();

        notify($sub['user_id'], 'plan_upgraded', 'Plan Upgraded!', 'You are now on the ' . ucfirst($sub['plan']) . ' plan.', 'circle-check', '/settings.php?tab=billing');

        if ($logId) $db->prepare("UPDATE webhook_logs SET status='processed', processed_at=NOW() WHERE id=?")->execute([$logId]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── PAYMENT SUCCESS HANDLER ───────────────────────────────────
function handlePaymentSuccess(array $payload, PDO $db, ?int $logId): void {
    $data = $payload['data'] ?? $payload;

    // Actual Nomba structure nests these under data.order and data.transaction
    $orderData = $data['order'] ?? [];
    $txnData   = $data['transaction'] ?? [];

    $orderRef = $orderData['orderReference'] ?? $data['orderReference'] ?? $data['reference'] ?? '';
    $txnRef   = $txnData['transactionId'] ?? $data['transactionReference'] ?? $data['reference'] ?? '';

    // Amount is already in naira (not kobo) in this payload structure
    $amount   = (float)($orderData['amount'] ?? $txnData['transactionAmount'] ?? $data['amount'] ?? 0);
    $currency = $orderData['currency'] ?? $data['currency'] ?? 'NGN';

    if (empty($orderRef)) {
        error_log('Webhook: could not extract orderReference from payload: ' . json_encode($payload));
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Missing orderReference' WHERE id=?")->execute([$logId]);
        return;
    }

    // Find order
    $stmt = $db->prepare("SELECT o.*, u.plan, u.id as merchant_id FROM orders o JOIN users u ON o.user_id=u.id WHERE o.order_ref=? LIMIT 1");
    $stmt->execute([$orderRef]);
    $order = $stmt->fetch();

    if (!$order) {
        error_log("Webhook: Order not found for ref $orderRef");
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Order not found' WHERE id=?")->execute([$logId]);
        return;
    }

    // Idempotency — don't process twice
    if (in_array($order['status'], ['paid','completed'])) {
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='ignored', error='Already processed' WHERE id=?")->execute([$logId]);
        return;
    }

    // Verify amount matches
    $expectedAmount = (float)$order['total_amount'];
    if (abs($amount - $expectedAmount) > 1) { // 1 naira tolerance
        error_log("Webhook: Amount mismatch for $orderRef. Expected $expectedAmount, got $amount");
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='Amount mismatch' WHERE id=?")->execute([$logId]);
        return;
    }

    try {
        $nomba  = new Nomba();
        $verify = $nomba->verifyTransaction($orderRef, 'orderReference');
        $status = $verify['data']['status'] ?? '';

        if (strtoupper($status) !== 'SUCCESS') {
            error_log("Webhook: API verification did not confirm success for $orderRef. Got: " . json_encode($verify));
            if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='API verification failed' WHERE id=?")->execute([$logId]);
            return;
        }
    } catch (Throwable $e) {
        error_log("Webhook: API verification call failed for $orderRef: " . $e->getMessage());
        if ($logId) $db->prepare("UPDATE webhook_logs SET status='failed', error='API verification error: " . $e->getMessage() . "' WHERE id=?")->execute([$logId]);
        return;
    }

    $db->beginTransaction();
    try {
        // Mark order as paid
        $db->prepare("UPDATE orders SET status='paid', nomba_txn_ref=?, paid_at=NOW() WHERE id=?")
           ->execute([$txnRef, $order['id']]);

        // Update payment link stats
        $db->prepare("UPDATE payment_links SET total_revenue=total_revenue+? WHERE id=?")
           ->execute([$amount, $order['link_id']]);

        // Calculate fee
        $fee = calcFee($amount, $order['plan']);

        // Credit merchant wallet
        $ref = generateRef('TXN');
        creditWallet(
            $order['merchant_id'],
            $amount,
            $fee,
            "Payment from {$order['customer_name']} via {$order['order_ref']}",
            $ref,
            $order['id'],
            $txnRef, 
            false
        );

        // Generate receipt
        $receiptCode = generateReceiptCode();
        $db->prepare("INSERT INTO receipts (order_id, receipt_code, amount, customer_name, merchant_name, paid_at) SELECT o.id, ?, o.total_amount, o.customer_name, pl.title, NOW() FROM orders o JOIN payment_links pl ON o.link_id=pl.id WHERE o.id=?")
           ->execute([$receiptCode, $order['id']]);

        $db->commit();

       // Notify merchant (in-app)
        notify(
            $order['merchant_id'],
            'payment_received',
            'Payment Received!',
            formatNaira($amount) . ' from ' . $order['customer_name'] . ' · ' . $order['order_ref'],
            'circle-check',
            '/orders.php',
            false
        );
 
        // Fetch merchant user row for email
        $merchantStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $merchantStmt->execute([$order['merchant_id']]);
        $merchantUser = $merchantStmt->fetch();
 
        // Fetch link title for emails
        $linkStmt = $db->prepare("SELECT title FROM payment_links WHERE id = ? LIMIT 1");
        $linkStmt->execute([$order['link_id']]);
        $linkTitle = $linkStmt->fetchColumn() ?: 'Payment Link';
 
        // Email merchant — payment received notification
        if ($merchantUser) {
            sendPaymentReceivedEmail($merchantUser, $order, $linkTitle);
        }
 
        $orderForEmail = [
            'order_ref'      => $order['order_ref'],
            'customer_name'  => $order['customer_name'],
            'customer_email' => $order['customer_email'] ?? null,
            'total_amount'   => $order['total_amount'],
            'status'         => 'paid',
        ];
        if (!empty($order['customer_email'])) {
            sendOrderConfirmationEmail(
                $orderForEmail,
                $merchantUser['name'] ?? 'Your merchant',
                $linkTitle
            );
        }

        if ($logId) $db->prepare("UPDATE webhook_logs SET status='processed', processed_at=NOW() WHERE id=?")->execute([$logId]);

    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── PAYMENT FAILED HANDLER ────────────────────────────────────
function handlePaymentFailed(array $payload, PDO $db, ?int $logId): void {
    $data      = $payload['data'] ?? $payload;
    $orderData = $data['order'] ?? [];
    $orderRef  = $orderData['orderReference'] ?? $data['orderReference'] ?? $data['reference'] ?? '';

    if (empty($orderRef)) return;

    $db->prepare("UPDATE orders SET status='cancelled' WHERE order_ref=? AND status IN ('pending_payment','confirmed')")
       ->execute([$orderRef]);

    if ($logId) $db->prepare("UPDATE webhook_logs SET status='processed', processed_at=NOW() WHERE id=?")->execute([$logId]);
}