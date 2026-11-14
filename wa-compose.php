<?php
/**
 * Manual / ad-hoc WhatsApp send — for templates that don't correspond to
 * an automatic status change (e.g. "Out for Delivery", which has no
 * matching order status in the schema), or a custom message. Builds the
 * message fresh from the order's current data, logs it as already-sent
 * (the redirect below IS the send), and redirects to wa.me.
 * POST only, opened via target="_blank".
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wa_helpers.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verifyCsrf();

$user    = currentUser();
$db      = db();
$orderId = (int) post('order_id');
$event   = post('event');
$custom  = trim((string) post('custom_text'));

$validEvents = ['order_confirmed', 'payment_received', 'out_for_delivery', 'order_completed', 'order_declined', 'custom'];
if (!in_array($event, $validEvents, true)) {
    http_response_code(400);
    exit('Invalid template.');
}

$stmt = $db->prepare("SELECT o.*, pl.title as link_title FROM orders o JOIN payment_links pl ON pl.id = o.link_id WHERE o.id = ? AND o.user_id = ? LIMIT 1");
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$message = ($event === 'custom')
    ? $custom
    : waBuildMessage($event, $order, $user);

if ($message === '') {
    http_response_code(422);
    exit('Message cannot be empty.');
}

$db->prepare("INSERT INTO wa_notifications (order_id,user_id,event_type,customer_phone,message_body,status,sent_at) VALUES (?,?,?,?,?,'sent',NOW())")
   ->execute([$orderId, $user['id'], $event, $order['customer_phone'], $message]);

header('Location: ' . waLink($order['customer_phone'], $message));
exit;
