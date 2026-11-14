<?php
/**
 * Sends a previously-queued automatic WhatsApp notification (see
 * wa_helpers.php / orders.php's confirm/complete/decline actions).
 * POST only, opened via target="_blank" so the merchant's orders.php
 * tab stays where it was.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wa_helpers.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verifyCsrf();

$user = currentUser();
$db   = db();
$id   = (int) post('id');

$stmt = $db->prepare("SELECT * FROM wa_notifications WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user['id']]);
$notif = $stmt->fetch();

if (!$notif) {
    http_response_code(404);
    exit('Notification not found.');
}

if ($notif['status'] === 'pending') {
    $db->prepare("UPDATE wa_notifications SET status='sent', sent_at=NOW() WHERE id=?")->execute([$id]);
}

header('Location: ' . waLink($notif['customer_phone'], $notif['message_body']));
exit;
