<?php

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function savePushSubscription(int $userId, array $sub, ?string $userAgent = null): bool
{
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh   = $sub['keys']['p256dh'] ?? '';
    $auth     = $sub['keys']['auth'] ?? '';

    if (!$endpoint || !$p256dh || !$auth) {
        return false;
    }

    db()->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent)
    ")->execute([$userId, $endpoint, $p256dh, $auth, $userAgent]);

    return true;
}

/**
 * Remove a push subscription (called when the browser reports the
 * subscription has expired, or when the user disables notifications).
 */
function deletePushSubscription(string $endpoint): void
{
    db()->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
}

function sendPushNotification(int $userId, string $title, string $message, ?string $link = null, string $icon = 'bell'): void
{
    if (!class_exists(WebPush::class)) {

        return;
    }

    if (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY')) {
        error_log('sendPushNotification: VAPID keys not configured, skipping push send.');
        return;
    }

    $stmt = db()->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $subs = $stmt->fetchAll();

    if (empty($subs)) {
        return;
    }

    $webPush = new WebPush([
        'VAPID' => [
            'subject'    => defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:support@kweek.ng',
            'publicKey'  => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ]);

    $payload = json_encode([
        'title' => $title,
        'body'  => $message,
        'icon'  => '/assets/img/kweek-icon-192.png',
        'badge' => '/assets/img/kweek-icon-32.png',
        'url'   => $link ?: '/dashboard.php',
        'tag'   => $icon, // lets the browser collapse rapid duplicate notifications of the same type
    ]);

    foreach ($subs as $row) {
        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'keys'     => [
                'p256dh' => $row['p256dh'],
                'auth'   => $row['auth'],
            ],
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
            deletePushSubscription($report->getEndpoint());
        } elseif (!$report->isSuccess()) {
            error_log('Push send failed for ' . $report->getEndpoint() . ': ' . $report->getReason());
        }
    }
}
