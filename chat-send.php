<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat_helpers.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $db     = db();
    $linkId = (int)post('link_id');

    $stmt = $db->prepare("SELECT id, user_id, chat_enabled FROM payment_links WHERE id = ? LIMIT 1");
    $stmt->execute([$linkId]);
    $link = $stmt->fetch();
} catch (Throwable $e) {
    // Almost always means migration.sql hasn't been run yet (the
    // chat_enabled column or link_chats table doesn't exist).
    error_log('chat-send.php schema error — run migration.sql? ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Chat is not set up yet on this server (missing database migration).']);
    exit;
}

if (!$link || !$link['chat_enabled']) {
    http_response_code(404);
    // Only log when it looks like a genuine widget call (has a message
    // attached) — skips bots/scanners hitting the URL with nothing.
    if (post('message') !== '') {
        error_log('chat-send.php: no active/chat-enabled link matched. link_id POSTed=' . var_export(post('link_id'), true) . ' resolved int=' . $linkId);
    }
    echo json_encode(['ok' => false, 'error' => 'Chat unavailable for this link']);
    exit;
}

// ── Merchant reply (logged-in dashboard user) ──────────────────
if (post('role') === 'merchant') {
    requireLogin();
    $user = currentUser();

    if ((int)$link['user_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    verifyCsrf();
    $token = clean(post('token'));
    $id = chatSend($db, $linkId, (int)$user['id'], 'merchant', $token, null, (string)post('message'));
    if (!$id) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Message could not be saved. Check the server error log.']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Buyer message (public, rate-limited) ───────────────────────
if (!checkRateLimit(clientIp(), 'chat_send_' . $linkId, 30, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many messages — please slow down.']);
    exit;
}

// Buyers introduce themselves before chatting. The widget asks for this up
// front; we check again here so the merchant always sees who is writing.
$customerName  = clean(post('customer_name', ''));
$customerEmail = filter_var(trim((string)post('customer_email', '')), FILTER_VALIDATE_EMAIL) ?: null;

if (mb_strlen($customerName) < 2 || !$customerEmail) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please share your name and a valid email address so the merchant can reply to you.']);
    exit;
}

$customerName  = mb_substr($customerName, 0, 80);
$customerEmail = mb_substr($customerEmail, 0, 190);

$token = chatCustomerToken();
$id = chatSend($db, $linkId, (int)$link['user_id'], 'customer', $token, $customerName, (string)post('message'), $customerEmail);

if (!$id) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Message could not be saved. Check the server error log.']);
    exit;
}

notify(
    (int)$link['user_id'],
    'chat_message',
    'New chat message',
    $customerName . ' (' . $customerEmail . ') sent you a message on your payment link',
    'message-circle',
    '/chats.php'
);

echo json_encode(['ok' => true, 'id' => $id]);