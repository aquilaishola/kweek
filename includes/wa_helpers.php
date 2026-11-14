<?php
/**
 * WhatsApp order-status automation — message templates, phone
 * normalization, and the notification queue.
 *
 * Design: zero API cost. Nothing here calls WhatsApp's servers directly —
 * there's no Business Platform account wired up, so nothing can be sent
 * without a human clicking. What this DOES do is make sure the right
 * message, with the right order data, is one click away every time an
 * order's status changes — via wa.me deep links that open WhatsApp with
 * the message pre-filled. The merchant still hits send themselves, but
 * they never type anything.
 *
 * Two paths converge on the same wa.me redirect:
 *   - Automatic: a status change (confirm/complete/decline in orders.php)
 *     queues a row here as 'pending'. The merchant reviews queued updates
 *     and clicks "Send" → wa-send.php marks it sent and redirects.
 *   - Manual: merchant picks a template (e.g. "Out for Delivery", which
 *     has no corresponding order status) any time from an order row →
 *     wa-compose.php builds the message fresh and redirects immediately.
 */

/** Build the message body for a given event. $order needs at least:
 *  order_ref, customer_name, total_amount, items (JSON string), and
 *  optionally link_title, delivery_zone, decline_reason, _checkout_url. */
function waBuildMessage(string $event, array $order, array $merchant): string {
    $nameParts     = preg_split('/\s+/', trim($order['customer_name'] ?? ''));
    $customerFirst = $nameParts[0] !== '' ? $nameParts[0] : ($order['customer_name'] ?? 'there');

    $items = [];
    if (!empty($order['items'])) {
        $decoded = json_decode($order['items'], true);
        if (is_array($decoded)) $items = $decoded;
    }
    $itemsSummary = '';
    if ($items) {
        $parts = array_map(function ($i) {
            $name = $i['name'] ?? $i['item_name'] ?? 'Item';
            $qty  = $i['qty'] ?? 1;
            return $qty > 1 ? "$name x$qty" : $name;
        }, $items);
        $itemsSummary = implode(', ', $parts);
    }

    $total        = function_exists('formatNaira') ? formatNaira((float)($order['total_amount'] ?? 0)) : '₦' . number_format((float)($order['total_amount'] ?? 0), 2);
    $ref          = $order['order_ref'] ?? '';
    $merchantName = $merchant['name'] ?? 'us';
    $linkTitle    = $order['link_title'] ?? '';

    switch ($event) {
        case 'order_confirmed':
            $msg = "Hi $customerFirst! Your order $ref" . ($linkTitle ? " for \"$linkTitle\"" : '') . " has been confirmed by $merchantName.";
            if ($itemsSummary) $msg .= "\n\nItems: $itemsSummary";
            $msg .= "\nTotal: $total";
            if (!empty($order['_checkout_url'])) $msg .= "\n\nComplete your payment here: {$order['_checkout_url']}";
            $msg .= "\n\nWe'll keep you posted!";
            return $msg;

        case 'payment_received':
            return "Hi $customerFirst! We've received your payment of $total for order $ref. Thank you 🎉\n\nYour order is now being processed.";

        case 'out_for_delivery':
            $zone = $order['delivery_zone'] ?? '';
            $msg  = "Hi $customerFirst! Your order $ref" . ($itemsSummary ? " ($itemsSummary)" : '') . " is out for delivery" . ($zone ? " to $zone" : '') . ". It should arrive soon!";
            return $msg;

        case 'order_completed':
            return "Hi $customerFirst! Your order $ref has been completed. Thank you for shopping with $merchantName — we'd love to have you back! 🙌";

        case 'order_declined':
            $reason = $order['decline_reason'] ?? '';
            $msg = "Hi $customerFirst, unfortunately we're unable to fulfill order $ref at this time.";
            if ($reason) $msg .= "\nReason: $reason";
            $msg .= "\n\nWe're sorry for the inconvenience.";
            return $msg;

        default:
            return "Hi $customerFirst, this is an update on your order $ref from $merchantName.";
    }
}

/** Human-readable label for the pending-updates list / dropdowns. */
function waEventLabel(string $event): string {
    return [
        'order_confirmed'  => 'Order Confirmed',
        'payment_received' => 'Payment Received',
        'out_for_delivery' => 'Out for Delivery',
        'order_completed'  => 'Order Completed',
        'order_declined'   => 'Order Declined',
    ][$event] ?? ucfirst(str_replace('_', ' ', $event));
}

/** Nigerian-number-aware normalization for wa.me (needs country code, no leading 0/+). */
function waNormalizePhone(string $phone): string {
    $p = preg_replace('/\D/', '', $phone);
    if (str_starts_with($p, '0'))   return '234' . substr($p, 1);
    if (str_starts_with($p, '234')) return $p;
    if (strlen($p) === 10)          return '234' . $p;
    return $p;
}

function waLink(string $phone, string $message): string {
    return 'https://wa.me/' . waNormalizePhone($phone) . '?text=' . rawurlencode($message);
}

/** Queue an automatic notification (status starts 'pending' — merchant reviews before sending). */
function waQueue(PDO $db, int $orderId, int $userId, string $customerPhone, string $event, string $message): void {
    $db->prepare("INSERT INTO wa_notifications (order_id,user_id,event_type,customer_phone,message_body,status) VALUES (?,?,?,?,?,'pending')")
       ->execute([$orderId, $userId, $event, $customerPhone, $message]);
}
