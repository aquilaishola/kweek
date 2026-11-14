<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/nomba.php';
require_once __DIR__ . '/mailer.php';

function finalizeOrderPayment(string $orderRef): array {
    $db = db();

    $stmt = $db->prepare("SELECT o.*, u.plan, u.id as merchant_id FROM orders o JOIN users u ON o.user_id=u.id WHERE o.order_ref = ? LIMIT 1");
    $stmt->execute([$orderRef]);
    $order = $stmt->fetch();

    if (!$order) {
        return ['ok' => false, 'reason' => 'order_not_found'];
    }

    if (in_array($order['status'], ['paid', 'completed'], true)) {
        return ['ok' => true, 'reason' => 'already_paid', 'order' => $order];
    }

    $originalStatus = $order['status'];

    // Atomically claim the order so a concurrent webhook call and a page
    // reload can't both pass the check above and double-credit the wallet.
    $claim = $db->prepare("UPDATE orders SET status='verifying' WHERE order_ref=? AND status=?");
    $claim->execute([$orderRef, $originalStatus]);
    if ($claim->rowCount() === 0) {
        $recheck = $db->prepare("SELECT status FROM orders WHERE order_ref = ? LIMIT 1");
        $recheck->execute([$orderRef]);
        $currentStatus = $recheck->fetchColumn();
        return in_array($currentStatus, ['paid', 'completed'], true)
            ? ['ok' => true, 'reason' => 'already_paid']
            : ['ok' => false, 'reason' => 'processing_in_progress'];
    }

    try {
        $nomba  = new Nomba();
        $verify = $nomba->verifyTransaction($orderRef, 'orderReference');
    } catch (Throwable $e) {
        error_log("finalizeOrderPayment: checkout-status call failed for $orderRef: " . $e->getMessage());
        $db->prepare("UPDATE orders SET status=? WHERE order_ref=?")->execute([$originalStatus, $orderRef]);
        return ['ok' => false, 'reason' => 'verify_error'];
    }

    if (strtoupper($verify['data']['status'] ?? '') !== 'SUCCESS') {
        $db->prepare("UPDATE orders SET status=? WHERE order_ref=?")->execute([$originalStatus, $orderRef]);
        return ['ok' => false, 'reason' => 'not_paid', 'verify' => $verify];
    }

    // ── CURRENCY ──────────────────────────────────────────────────
    // total_amount is in the order's own currency (₦, $ or GH₵). Merchant wallets
    // are always in naira, so a USD order is credited at the naira value that
    // was fixed when the order was placed (orders.ngn_amount), not at today's
    // rate. Older orders have no currency stored and are plain naira.
    $currency   = linkCurrency($order['currency'] ?? 'NGN');
    $isForeign  = $currency !== 'NGN';
    $orderTotal = (float) $order['total_amount'];
    $ngnAmount  = $isForeign ? (float) ($order['ngn_amount'] ?? 0) : $orderTotal;

    // If the gateway tells us which currency it charged, it must be the one we quoted.
    $paidCurrency = strtoupper((string) ($verify['data']['currency'] ?? ''));
    if ($paidCurrency !== '' && $paidCurrency !== $currency) {
        error_log("finalizeOrderPayment: currency mismatch for $orderRef. Expected $currency, got $paidCurrency");
        $db->prepare("UPDATE orders SET status=? WHERE order_ref=?")->execute([$originalStatus, $orderRef]);
        return ['ok' => false, 'reason' => 'currency_mismatch'];
    }

    // Naira allows a ₦1 tolerance; dollars and cedis need to match to the cent.
    $amount    = (float) ($verify['data']['amount'] ?? $orderTotal);
    $tolerance = $isForeign ? 0.005 : 1;
    if (abs($amount - $orderTotal) > $tolerance) {
        error_log("finalizeOrderPayment: amount mismatch for $orderRef. Expected {$order['total_amount']} $currency, got $amount");
        $db->prepare("UPDATE orders SET status=? WHERE order_ref=?")->execute([$originalStatus, $orderRef]);
        return ['ok' => false, 'reason' => 'amount_mismatch'];
    }

    // A USD order without a locked naira amount can't be credited safely.
    // We stop here and leave the order retryable rather than guess a figure.
    if ($ngnAmount <= 0) {
        error_log("finalizeOrderPayment: no naira amount stored for $currency order $orderRef; not crediting.");
        $db->prepare("UPDATE orders SET status=? WHERE order_ref=?")->execute([$originalStatus, $orderRef]);
        return ['ok' => false, 'reason' => 'missing_ngn_amount'];
    }

    $txnRef = $verify['data']['paymentId'] ?? $orderRef;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE orders SET status='paid', nomba_txn_ref=?, paid_at=NOW() WHERE id=?")
           ->execute([$txnRef, $order['id']]);

        // Link revenue stays in naira so dashboards keep adding up.
        $db->prepare("UPDATE payment_links SET total_revenue=total_revenue+? WHERE id=?")
           ->execute([$ngnAmount, $order['link_id']]);

        // Fees are worked out on the naira amount actually being credited.
        $fee = calcFee($ngnAmount, $order['plan']);
        $ref = generateRef('TXN');

        $description = "Payment from {$order['customer_name']} via {$order['order_ref']}";
        if ($isForeign) {
            $description .= ' (' . formatMoney($orderTotal, $currency) . ' at ₦' . number_format((float) $order['exchange_rate'], 2) . '/' . currencySymbol($currency) . '1)';
        }

        creditWallet(
            $order['merchant_id'],
            $ngnAmount,
            $fee,
            $description,
            $ref,
            $order['id'],
            $txnRef,
            false
        );

        $receiptCode = generateReceiptCode();
        $db->prepare("INSERT INTO receipts (order_id, receipt_code, amount, customer_name, merchant_name, paid_at) SELECT o.id, ?, o.total_amount, o.customer_name, pl.title, NOW() FROM orders o JOIN payment_links pl ON o.link_id=pl.id WHERE o.id=?")
           ->execute([$receiptCode, $order['id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    notify(
        $order['merchant_id'],
        'payment_received',
        'Payment Received!',
        ($isForeign
            ? formatMoney($orderTotal, $currency) . ' (' . formatNaira($ngnAmount) . ' before fees)'
            : formatNaira($orderTotal))
            . ' from ' . $order['customer_name'] . ' — ' . $order['order_ref'],
        'circle-check',
        '/orders.php',
        false
    );

    $merchantStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $merchantStmt->execute([$order['merchant_id']]);
    $merchantUser = $merchantStmt->fetch();

    $linkStmt = $db->prepare("SELECT title FROM payment_links WHERE id = ? LIMIT 1");
    $linkStmt->execute([$order['link_id']]);
    $linkTitle = $linkStmt->fetchColumn() ?: 'Payment Link';

    if ($merchantUser) {
        // The merchant's email talks about what lands in their wallet, so for
        // dollar orders it shows the naira credit.
        $merchantEmailOrder = $order;
        if ($isForeign) $merchantEmailOrder['total_amount'] = $ngnAmount;
        sendPaymentReceivedEmail($merchantUser, $merchantEmailOrder, $linkTitle);
    }

    // The buyer's email shows the amount in the currency they paid in
    // (mailer.php formats it from 'currency'), so dollar and cedi buyers get
    // it too.
    if (!empty($order['customer_email'])) {
        sendOrderConfirmationEmail(
            [
                'order_ref'      => $order['order_ref'],
                'customer_name'  => $order['customer_name'],
                'customer_email' => $order['customer_email'],
                'total_amount'   => $order['total_amount'],
                'currency'       => $currency,
                'status'         => 'paid',
            ],
            $merchantUser['name'] ?? 'Your merchant',
            $linkTitle
        );
    }

    $order['status'] = 'paid';
    return ['ok' => true, 'reason' => 'finalized', 'order' => $order];
}

function markOrderFailed(string $orderRef): void {
    db()->prepare("UPDATE orders SET status='cancelled' WHERE order_ref=? AND status IN ('pending_payment','confirmed')")
        ->execute([$orderRef]);
}

/**
 * Same idea for subscription upgrades, keyed by the subscriptions table's
 * own `reference` column rather than an order_ref.
 */
function finalizeSubscriptionPayment(string $reference): array {
    $db = db();

    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE reference = ? LIMIT 1");
    $stmt->execute([$reference]);
    $sub = $stmt->fetch();

    if (!$sub) {
        return ['ok' => false, 'reason' => 'subscription_not_found'];
    }

    if ($sub['status'] === 'active') {
        return ['ok' => true, 'reason' => 'already_active', 'subscription' => $sub];
    }

    if (empty($sub['nomba_checkout_id'])) {
        return ['ok' => false, 'reason' => 'missing_checkout_id'];
    }

    try {
        $nomba  = new Nomba();
        $verify = $nomba->verifyTransaction($sub['nomba_checkout_id'], 'paymentId');
    } catch (Throwable $e) {
        error_log("finalizeSubscriptionPayment: checkout-status call failed for $reference: " . $e->getMessage());
        return ['ok' => false, 'reason' => 'verify_error'];
    }

    if (strtoupper($verify['data']['status'] ?? '') !== 'SUCCESS') {
        return ['ok' => false, 'reason' => 'not_paid', 'verify' => $verify];
    }

    $durationDays = $sub['billing_cycle'] === 'annual' ? 365 : 30;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE subscriptions SET status='active', starts_at=NOW(), ends_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")
           ->execute([$durationDays, $sub['id']]);

        $db->prepare("UPDATE users SET plan=? WHERE id=?")
           ->execute([$sub['plan'], $sub['user_id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    notify($sub['user_id'], 'plan_upgraded', 'Plan Upgraded!', 'You are now on the ' . ucfirst($sub['plan']) . ' plan.', 'circle-check', '/settings.php?tab=billing');

    $sub['status'] = 'active';
    return ['ok' => true, 'reason' => 'finalized', 'subscription' => $sub];
}

/**
 * Handles payout.created / payout.paid / payout.failed events. Bachs keys
 * these by data.reference (our own withdrawal reference), not checkout_id.
 *
 * Rules this enforces:
 *  - success and failed are final. A late or duplicate event never moves a
 *    finished withdrawal backwards (e.g. payout.created arriving after
 *    payout.paid) and never refunds one that was paid.
 *  - Every status change is an atomic "claim" (UPDATE ... WHERE status is
 *    still open), so two deliveries of the same event racing each other
 *    can't both act. In particular a failed payout is refunded exactly once.
 *  - The failed-payout refund and its status flip commit together. If the
 *    refund throws, nothing is marked failed and the exception propagates,
 *    so the webhook returns an error and Bachs retries the delivery.
 *
 * nomba_ref is filled with the Bachs payout id (data.id) if it is still
 * empty, and never overwritten once set.
 */
function handlePayoutEvent(string $type, array $data, PDO $db): array {
    $reference = (string) ($data['reference'] ?? '');
    if ($reference === '') {
        return ['ok' => false, 'reason' => 'missing_reference'];
    }

    $stmt = $db->prepare("SELECT * FROM withdrawals WHERE reference = ? LIMIT 1");
    $stmt->execute([$reference]);
    $withdrawal = $stmt->fetch();

    if (!$withdrawal) {
        return ['ok' => false, 'reason' => 'withdrawal_not_found'];
    }

    // Already final: ignore, but say so loudly if the event disagrees with what we recorded.
    if (in_array($withdrawal['status'], ['success', 'failed'], true)) {
        $incoming = match ($type) {
            'payout.paid'   => 'success',
            'payout.failed' => 'failed',
            default         => null,
        };
        if ($incoming !== null && $incoming !== $withdrawal['status']) {
            error_log("handlePayoutEvent: $type for $reference but it is already '{$withdrawal['status']}'. Ignored, check it manually.");
        }
        return ['ok' => true, 'reason' => 'already_' . $withdrawal['status']];
    }

    $payoutId = isset($data['id']) ? (string) $data['id'] : null;

    // ── payout.paid ───────────────────────────────────────────────
    if ($type === 'payout.paid') {
        $claim = $db->prepare("UPDATE withdrawals SET status='success', nomba_ref=COALESCE(nomba_ref, ?), processed_at=NOW() WHERE id=? AND status IN ('pending','processing')");
        $claim->execute([$payoutId, $withdrawal['id']]);
        if ($claim->rowCount() === 0) {
            return ['ok' => true, 'reason' => 'already_handled'];
        }

        notify($withdrawal['user_id'], 'withdrawal', 'Withdrawal Successful',
            formatNaira($withdrawal['net_amount']) . ' has been sent to ' . $withdrawal['bank_name'] . '.',
            'circle-check', '/transactions.php');

        return ['ok' => true, 'reason' => 'finalized'];
    }

    // ── payout.failed ─────────────────────────────────────────────
    if ($type === 'payout.failed') {
        $db->beginTransaction();
        try {
            $claim = $db->prepare("UPDATE withdrawals SET status='failed', nomba_ref=COALESCE(nomba_ref, ?), processed_at=NOW() WHERE id=? AND status IN ('pending','processing')");
            $claim->execute([$payoutId, $withdrawal['id']]);
            if ($claim->rowCount() === 0) {
                $db->rollBack();
                return ['ok' => true, 'reason' => 'already_handled'];
            }

            // Refund the wallet since the payout never reached the destination.
            $credited = creditWallet(
                $withdrawal['user_id'],
                $withdrawal['amount'],
                0,
                "Refund for failed withdrawal {$withdrawal['reference']}",
                generateRef('RFD'),
                null,
                $reference,
                false   // we already own the transaction
            );
            if ($credited === false) {
                throw new RuntimeException('creditWallet refused the refund');
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        notify($withdrawal['user_id'], 'withdrawal', 'Withdrawal Failed',
            formatNaira($withdrawal['amount']) . ' withdrawal to ' . $withdrawal['bank_name'] . ' failed and has been refunded to your wallet.',
            'alert-circle', '/transactions.php');

        return ['ok' => true, 'reason' => 'finalized'];
    }

    // ── payout.created and anything else: only ever moves pending -> processing ──
    $db->prepare("UPDATE withdrawals SET status='processing', nomba_ref=COALESCE(nomba_ref, ?) WHERE id=? AND status='pending'")
       ->execute([$payoutId, $withdrawal['id']]);

    return ['ok' => true, 'reason' => 'processing'];
}