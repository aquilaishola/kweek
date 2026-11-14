<?php
/**
 * Internal JSON endpoint for the Customers page order-history modal.
 * Session-authenticated (dashboard login), NOT part of the public API —
 * scoped strictly to the logged-in merchant's own orders for one phone number.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
header('Content-Type: application/json');

$user  = currentUser();
$uid   = $user['id'];
$db    = db();
$phone = clean(get('phone', ''));

if (!$phone) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'phone is required']);
    exit;
}

$stmt = $db->prepare("SELECT o.order_ref, o.total_amount, o.status, o.created_at, pl.title as link_title
                       FROM orders o JOIN payment_links pl ON pl.id = o.link_id
                       WHERE o.user_id = ? AND o.customer_phone = ?
                       ORDER BY o.created_at DESC LIMIT 50");
$stmt->execute([$uid, $phone]);
$rows = $stmt->fetchAll();

$statusMap = [
    'pending_confirmation' => ['warning', 'Pending Confirm'],
    'confirmed'            => ['purple',  'Confirmed'],
    'pending_payment'      => ['warning', 'Awaiting Payment'],
    'paid'                 => ['success', 'Paid'],
    'processing'           => ['warning', 'Processing'],
    'completed'            => ['success', 'Completed'],
    'declined'             => ['danger',  'Declined'],
    'refunded'             => ['neutral', 'Refunded'],
    'cancelled'            => ['neutral', 'Cancelled'],
];

$orders = array_map(function ($o) use ($statusMap) {
    [$color, $label] = $statusMap[$o['status']] ?? ['neutral', ucfirst($o['status'])];
    return [
        'order_ref'     => $o['order_ref'],
        'link_title'    => $o['link_title'],
        'total_amount'  => $o['total_amount'],
        'status_color'  => $color,
        'status_label'  => $label,
        'date'          => function_exists('nigeriaTime') ? nigeriaTime($o['created_at']) : $o['created_at'],
    ];
}, $rows);

echo json_encode(['orders' => $orders]);
