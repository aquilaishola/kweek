<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat_helpers.php';
header('Content-Type: application/json');

try {
    $db      = db();
    $linkId  = (int)get('link_id');
    $afterId = (int)get('after', 0);
    $role    = get('role') === 'merchant' ? 'merchant' : 'customer';

    if ($role === 'merchant') {
        requireLogin();
        $user  = currentUser();
        $token = clean(get('token'));

        $check = $db->prepare("SELECT id FROM payment_links WHERE id = ? AND user_id = ? LIMIT 1");
        $check->execute([$linkId, $user['id']]);
        if (!$check->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }

        chatMarkRead($db, $linkId, $token, 'merchant');
    } else {
        $token = chatCustomerToken();
        chatMarkRead($db, $linkId, $token, 'customer');
    }

    $messages = chatFetch($db, $linkId, $token, $afterId);
    echo json_encode(['ok' => true, 'messages' => $messages]);
} catch (Throwable $e) {
    // Same likely cause as chat-send.php: migration.sql not applied yet,
    // so link_chats doesn't exist. Return real JSON either way so the
    // widget's fetch().then(r => r.json()) doesn't choke on an HTML
    // error page.
    error_log('chat-poll.php schema error — run migration.sql? ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Chat is not set up yet on this server (missing database migration).']);
}
