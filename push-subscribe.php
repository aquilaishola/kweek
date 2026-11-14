<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/push_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? post('action');

if ($action === 'key') {
    if (!defined('VAPID_PUBLIC_KEY')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Push notifications are not configured on this server yet.']);
        exit;
    }
    echo json_encode(['ok' => true, 'key' => VAPID_PUBLIC_KEY]);
    exit;
}

requireLogin();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$raw = json_decode(file_get_contents('php://input'), true);
$subscription = $raw['subscription'] ?? null;

if ($action === 'subscribe') {
    if (!$subscription || empty($subscription['endpoint'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing subscription payload']);
        exit;
    }

    $ok = savePushSubscription(
        (int)$user['id'],
        $subscription,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    );

    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = $raw['endpoint'] ?? ($subscription['endpoint'] ?? '');
    if ($endpoint) {
        deletePushSubscription($endpoint);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
