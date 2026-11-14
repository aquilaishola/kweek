<?php

function chatCustomerToken(): string {
    if (!empty($_COOKIE['kweek_chat_token'])) {
        return $_COOKIE['kweek_chat_token'];
    }
    $token = bin2hex(random_bytes(16));
    setcookie('kweek_chat_token', $token, time() + 60 * 60 * 24 * 30, '/');
    $_COOKIE['kweek_chat_token'] = $token;
    return $token;
}

/**
 * Saves one chat message. Buyers introduce themselves with a name and
 * email, which are stored with each of their messages so the merchant can
 * see who they are talking to (and reach out by email if needed).
 * Merchant replies simply leave both blank.
 */
function chatSend(PDO $db, int $linkId, int $merchantId, string $senderType, string $customerToken, ?string $customerName, string $message, ?string $customerEmail = null): int {
    $message = trim($message);
    if ($message === '') return 0;
    $message = mb_substr($message, 0, 1000);

    try {
        $stmt = $db->prepare("INSERT INTO link_chats (link_id, merchant_id, customer_token, customer_name, customer_email, sender_type, message, read_by_merchant, read_by_customer) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $linkId,
            $merchantId,
            $customerToken,
            $customerName,
            $customerEmail,
            $senderType,
            $message,
            $senderType === 'merchant' ? 1 : 0,
            $senderType === 'customer' ? 1 : 0,
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // Most common cause: migration.sql hasn't been run yet, so the
        // link_chats table (or a column on it, such as customer_email)
        // doesn't exist — that throws here rather than failing quietly.
        // Logged, not thrown, so the endpoint can still return clean JSON
        // instead of a 500 HTML error page that the widget's fetch()
        // can't parse.
        error_log('chatSend() failed — is migration.sql applied? ' . $e->getMessage());
        return 0;
    }
}

function chatFetch(PDO $db, int $linkId, string $customerToken, int $afterId = 0): array {
    $stmt = $db->prepare("SELECT id, sender_type, customer_name, message, created_at FROM link_chats WHERE link_id = ? AND customer_token = ? AND id > ? ORDER BY id ASC LIMIT 100");
    $stmt->execute([$linkId, $customerToken, $afterId]);
    return $stmt->fetchAll();
}

/**
 * One row per (link, customer) thread for the merchant's inbox —
 * last message + unread count, newest first.
 *
 * The visitor's name and email come from their most recent message that
 * carries them. (Previously the name was read from the very last message,
 * which showed "Anonymous visitor" whenever the merchant had replied last.)
 */
function chatThreadsForMerchant(PDO $db, int $merchantId): array {
    $stmt = $db->prepare("
        SELECT lc.customer_token, lc.link_id, pl.title AS link_title,
               MAX(lc.id) AS last_id,
               SUM(CASE WHEN lc.sender_type='customer' AND lc.read_by_merchant=0 THEN 1 ELSE 0 END) AS unread
        FROM link_chats lc
        JOIN payment_links pl ON pl.id = lc.link_id
        WHERE lc.merchant_id = ?
        GROUP BY lc.customer_token, lc.link_id, pl.title
        ORDER BY last_id DESC
    ");
    $stmt->execute([$merchantId]);
    $rows = $stmt->fetchAll();

    $msgStmt = $db->prepare("SELECT message, sender_type, created_at FROM link_chats WHERE id = ?");
    $whoStmt = $db->prepare("
        SELECT customer_name, customer_email
        FROM link_chats
        WHERE link_id = ? AND customer_token = ? AND sender_type = 'customer'
          AND customer_name IS NOT NULL AND customer_name <> ''
        ORDER BY id DESC
        LIMIT 1
    ");

    foreach ($rows as &$r) {
        $msgStmt->execute([$r['last_id']]);
        $last = $msgStmt->fetch();
        $r['last_message'] = $last['message'] ?? '';
        $r['last_sender']  = $last['sender_type'] ?? '';
        $r['last_time']    = $last['created_at'] ?? null;

        $whoStmt->execute([$r['link_id'], $r['customer_token']]);
        $who = $whoStmt->fetch();
        $r['customer_name']  = $who['customer_name']  ?? 'Anonymous visitor';
        $r['customer_email'] = $who['customer_email'] ?? '';
    }
    unset($r);
    return $rows;
}

function chatMarkRead(PDO $db, int $linkId, string $customerToken, string $readerType): void {
    $col            = $readerType === 'merchant' ? 'read_by_merchant' : 'read_by_customer';
    $senderOpposite = $readerType === 'merchant' ? 'customer' : 'merchant';
    $stmt = $db->prepare("UPDATE link_chats SET $col = 1 WHERE link_id = ? AND customer_token = ? AND sender_type = ?");
    $stmt->execute([$linkId, $customerToken, $senderOpposite]);
}

function chatUnreadCountForMerchant(PDO $db, int $merchantId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM link_chats WHERE merchant_id = ? AND sender_type = 'customer' AND read_by_merchant = 0");
    $stmt->execute([$merchantId]);
    return (int)$stmt->fetchColumn();
}