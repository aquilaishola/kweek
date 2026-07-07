<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
requireLogin();

header('Content-Type: application/json');

$user = currentUser();
$uid  = $user['id'];
$db   = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$plan  = clean(post('plan'));   
$cycle = clean(post('cycle'));  

$prices = [
    'starter'  => ['monthly' => PRICE_STARTER_MONTHLY,  'annual' => PRICE_STARTER_ANNUAL],
    'pro'      => ['monthly' => PRICE_PRO_MONTHLY,      'annual' => PRICE_PRO_ANNUAL],
    'business' => ['monthly' => PRICE_BUSINESS_MONTHLY, 'annual' => PRICE_BUSINESS_ANNUAL],
];

if (!isset($prices[$plan][$cycle])) {
    echo json_encode(['error' => 'Invalid plan or billing cycle.']);
    exit;
}

$planRank = ['free' => 0, 'starter' => 1, 'pro' => 2, 'business' => 3];

if ($planRank[$user['plan']] >= $planRank[$plan]) {
    echo json_encode(['error' => 'You are already on this plan or higher.']);
    exit;
}

$amount = $prices[$plan][$cycle];
$ref    = 'SUB-' . strtoupper(bin2hex(random_bytes(6)));

try {

    $db->prepare("
        INSERT INTO subscriptions (user_id, plan, billing_cycle, amount, reference, status, starts_at, ends_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ")->execute([$uid, $plan, $cycle, $amount, $ref]);

    $nomba  = new Nomba();
    $result = $nomba->createCheckout([
        'amount'        => $amount,
        'orderRef'      => $ref,
        'customerEmail' => $user['email'],
        'customerPhone' => $user['phone'] ?? '',
        'description'   => ucfirst($plan) . ' plan — ' . $cycle,
        'redirectUrl'   => SITE_URL . '/settings?tab=billing&upgraded=1',
    ]);

    $checkoutUrl = $result['data']['checkoutLink']
        ?? $result['data']['paymentLink']
        ?? $result['data']['url']
        ?? '';

    if (empty($checkoutUrl)) {
        error_log('Subscription checkout response: ' . json_encode($result));
        echo json_encode(['error' => 'Payment gateway is temporarily unavailable. Please try again.']);
        exit;
    }

    echo json_encode(['success' => true, 'checkoutUrl' => $checkoutUrl]);

} catch (Throwable $e) {
    error_log('upgrade.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'Something went wrong. Please try again.']);
}