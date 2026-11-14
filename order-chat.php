<?php
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');
$db = db();

function respondError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Mode is inferred from whichever identifying param actually showed up,
// rather than trusting a separate 'mode' flag to survive the trip —
// order_id (numeric, merchant/logged-in) vs order_ref (string, customer/
// no login). This can't silently fail the way a dropped query param can.
$orderIdParam  = post('order_id') ?: get('order_id');
$orderRefParam = post('order_ref') ?: get('order_ref');

// ── MERCHANT SIDE ─────────────────────────────────────────────
if (!empty($orderIdParam)) {
    if (!isLoggedIn()) respondError('Not authorized', 401);
    $user    = currentUser();
    $orderId = (int) $orderIdParam;

    $stmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $user['id']]);
    if (!$stmt->fetch()) respondError('Order not found', 404);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send') {
        verifyCsrf();
        $msg = trim(post('message'));
        if ($msg === '') respondError('Message cannot be empty');
        if (mb_strlen($msg) > 1000) respondError('Message is too long');

        $db->prepare("INSERT INTO order_messages (order_id, sender_type, message, is_read_by_merchant) VALUES (?, 'merchant', ?, 1)")
           ->execute([$orderId, clean($msg)]);

        echo json_encode(['success' => true]);
        exit;
    }

    $afterId = (int) get('after_id', 0);
    $stmt = $db->prepare("SELECT id, sender_type, message, created_at FROM order_messages WHERE order_id = ? AND id > ? ORDER BY id ASC");
    $stmt->execute([$orderId, $afterId]);
    $messages = $stmt->fetchAll();

    $db->prepare("UPDATE order_messages SET is_read_by_merchant = 1 WHERE order_id = ?")->execute([$orderId]);

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// ── CUSTOMER SIDE ─────────────────────────────────────────────
if (!empty($orderRefParam)) {
    $orderRef = clean($orderRefParam);

    $stmt = $db->prepare("SELECT id FROM orders WHERE order_ref = ? LIMIT 1");
    $stmt->execute([$orderRef]);
    $order = $stmt->fetch();
    if (!$order) respondError('Order not found', 404);
    $orderId = (int) $order['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send') {
        if (!checkRateLimit(clientIp(), 'order_chat_' . $orderId, 30, 3600)) {
            respondError('Too many messages sent. Please slow down.', 429);
        }
        $msg = trim(post('message'));
        if ($msg === '') respondError('Message cannot be empty');
        if (mb_strlen($msg) > 1000) respondError('Message is too long');

        $db->prepare("INSERT INTO order_messages (order_id, sender_type, message, is_read_by_customer) VALUES (?, 'customer', ?, 1)")
           ->execute([$orderId, clean($msg)]);

        echo json_encode(['success' => true]);
        exit;
    }

    $afterId = (int) get('after_id', 0);
    $stmt = $db->prepare("SELECT id, sender_type, message, created_at FROM order_messages WHERE order_id = ? AND id > ? ORDER BY id ASC");
    $stmt->execute([$orderId, $afterId]);
    $messages = $stmt->fetchAll();

    $db->prepare("UPDATE order_messages SET is_read_by_customer = 1 WHERE order_id = ?")->execute([$orderId]);

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

respondError('Invalid mode');