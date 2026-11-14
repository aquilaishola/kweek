<?php

function kwIntegrationSchema(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/.integration_schema_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $have = $db->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'client_reference'     => "VARCHAR(190) NULL",
            'redirect_override'    => "VARCHAR(500) NULL",
            'signed_request'       => "TINYINT(1) NOT NULL DEFAULT 0",
            'merchant_notified_at' => "DATETIME NULL",
            'webhook_attempts'     => "TINYINT NOT NULL DEFAULT 0",
            'webhook_next_at'      => "DATETIME NULL",
        ];
        $fresh = !in_array('merchant_notified_at', $have, true);
        foreach ($add as $c => $def) {
            if (!in_array($c, $have, true)) $db->exec("ALTER TABLE `orders` ADD COLUMN `$c` $def");
        }
        // Never replay history: everything already paid counts as notified.
        if ($fresh) $db->exec("UPDATE orders SET merchant_notified_at = NOW() WHERE status IN ('paid','completed')");
        try {
            $wd = $db->query("SHOW COLUMNS FROM `webhook_deliveries`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('order_id', $wd, true)) $db->exec("ALTER TABLE `webhook_deliveries` ADD COLUMN `order_id` INT NULL");
        } catch (Throwable $e) { /* table optional */ }
        @file_put_contents($flag, date('c'));
        return $ok = true;
    } catch (Throwable $e) {
        error_log('kwIntegrationSchema: ' . $e->getMessage());
        return $ok = false;
    }
}

/* ───────────────────────── pay-link params ───────────────────────── */
function kwNormPhone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 13 && str_starts_with($d, '234')) $d = '0' . substr($d, 3);
    return $d;
}

/** HMAC the merchant computes server-side with their webhook secret. */
function kwPaySignature(string $secret, string $slug, array $p): string {
    $s = implode('|', [
        $slug, $p['amount'] ?? '', $p['reference'] ?? '', $p['email'] ?? '',
        $p['phone'] ?? '', $p['name'] ?? '', $p['redirect_url'] ?? '',
    ]);
    return hash_hmac('sha256', $s, $secret);
}

/**
 * A per-payment redirect is only honoured when it sits on the same host as the
 * redirect URL the merchant saved on the link — otherwise anyone could craft a
 * pay link that bounces buyers to a phishing site after they pay.
 */
function kwResolveRedirect(?string $override, ?string $configured): ?string {
    $configured = trim((string)$configured);
    $override   = trim((string)$override);
    if ($override !== '' && $configured !== '') {
        $a = parse_url($override); $b = parse_url($configured);
        if ($a && $b && !empty($a['host']) && !empty($b['host'])
            && in_array(strtolower($a['scheme'] ?? ''), ['http', 'https'], true)
            && strcasecmp($a['host'], $b['host']) === 0) {
            return $override;
        }
    }
    return $configured !== '' ? $configured : null;
}

function kwAppendQuery(string $url, array $q): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q);
}

/* ───────────────────────── webhooks ───────────────────────── */
function kwUrlIsSafe(string $url): bool {            // https only, no private / reserved IPs (SSRF guard)
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $host = $p['host'];
    $ip   = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Send (or retry) the payment.success webhook for one order. Safe to call repeatedly. */
function kwSendOrderWebhook(int $orderId, int $timeout = 8): bool {
    $db = db(); kwIntegrationSchema($db);
    $retryMins = [1, 5, 30, 120, 720];

    $st = $db->prepare("SELECT o.*, pl.title AS link_title, pl.slug AS link_slug, u.webhook_url, u.webhook_secret
                        FROM orders o JOIN payment_links pl ON pl.id = o.link_id JOIN users u ON u.id = o.user_id
                        WHERE o.id = ? LIMIT 1");
    $st->execute([$orderId]); $o = $st->fetch();
    if (!$o || !in_array($o['status'], ['paid', 'completed'], true) || !empty($o['merchant_notified_at'])) return false;

    if (empty($o['webhook_url'])) {                  // nothing to notify
        $db->prepare("UPDATE orders SET merchant_notified_at = NOW() WHERE id = ?")->execute([$orderId]);
        return false;
    }
    $att = (int)$o['webhook_attempts'];
    if ($att >= count($retryMins)) return false;

    // claim this attempt so two requests can't both send
    $c = $db->prepare("UPDATE orders SET webhook_attempts = webhook_attempts + 1, webhook_next_at = (NOW() + INTERVAL 10 MINUTE)
                       WHERE id = ? AND merchant_notified_at IS NULL AND webhook_attempts = ?
                         AND (webhook_next_at IS NULL OR webhook_next_at <= NOW())");
    $c->execute([$orderId, $att]);
    if ($c->rowCount() !== 1) return false;

    $secret = $o['webhook_secret'];
    if (!$secret) {
        $secret = bin2hex(random_bytes(24));
        $db->prepare("UPDATE users SET webhook_secret = ? WHERE id = ?")->execute([$secret, $o['user_id']]);
    }

    $paidAt = $o['paid_at'] ?? $o['updated_at'] ?? date('Y-m-d H:i:s');
    $payload = json_encode([
        'id'      => 'evt_' . $o['order_ref'],       // stable → lets you de-duplicate retries
        'event'   => 'payment.success',
        'sent_at' => date('c'),
        'data'    => [
            'reference'        => $o['order_ref'],
            'client_reference' => $o['client_reference'] ?? null,
            'status'           => 'paid',
            'amount'           => (float)$o['total_amount'],
            'currency'         => $o['currency'] ?: 'NGN',
            'ngn_amount'       => isset($o['ngn_amount']) ? (float)$o['ngn_amount'] : null,
            'paid_at'          => date('c', strtotime($paidAt)),
            'customer'         => ['name' => $o['customer_name'], 'email' => $o['customer_email'], 'phone' => $o['customer_phone']],
            'link'             => ['id' => (int)$o['link_id'], 'slug' => $o['link_slug'], 'title' => $o['link_title']],
            'items'            => json_decode($o['items'] ?? '[]', true) ?: [],
            'delivery'         => ['zone' => $o['delivery_zone'], 'fee' => (float)$o['delivery_fee'], 'address' => $o['delivery_address']],
            'signed_request'   => !empty($o['signed_request']),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $code = null;
    if (kwUrlIsSafe($o['webhook_url']) && function_exists('curl_init')) {
        $ch = curl_init($o['webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'User-Agent: Kweek-Webhooks/1.0',
                'X-Kweek-Event: payment.success', 'X-Kweek-Delivery: ' . ($att + 1),
                'X-Kweek-Signature: ' . hash_hmac('sha256', $payload, $secret),
            ],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        curl_close($ch);
    }
    $ok = $code !== null && $code >= 200 && $code < 300;

    if ($ok) {
        $db->prepare("UPDATE orders SET merchant_notified_at = NOW(), webhook_next_at = NULL WHERE id = ?")->execute([$orderId]);
    } else {
        $m = (int)$retryMins[min($att, count($retryMins) - 1)];
        $db->prepare("UPDATE orders SET webhook_next_at = (NOW() + INTERVAL $m MINUTE) WHERE id = ?")->execute([$orderId]);
    }
    try {
        $db->prepare("INSERT INTO webhook_deliveries (user_id,event_type,url,payload,response_code,status,order_id) VALUES (?,?,?,?,?,?,?)")
           ->execute([$o['user_id'], 'payment.success', $o['webhook_url'], $payload, $code, $ok ? 'success' : 'failed', $orderId]);
    } catch (Throwable $e) {
        $db->prepare("INSERT INTO webhook_deliveries (user_id,event_type,url,payload,response_code,status) VALUES (?,?,?,?,?,?)")
           ->execute([$o['user_id'], 'payment.success', $o['webhook_url'], $payload, $code, $ok ? 'success' : 'failed']);
    }
    return $ok;
}

/** Call this the moment an order is known to be paid. */
function kwDispatchOrderPaidWebhook(string $orderRef, int $timeout = 8): void {
    try {
        $s = db()->prepare("SELECT id FROM orders WHERE order_ref = ? LIMIT 1");
        $s->execute([$orderRef]);
        if ($id = (int)$s->fetchColumn()) kwSendOrderWebhook($id, $timeout);
    } catch (Throwable $e) { error_log('kwDispatchOrderPaidWebhook: ' . $e->getMessage()); }
}

/** Cron safety net: sends anything missed and retries failures. */
function kwSweepWebhooks(int $limit = 50): int {
    $db = db(); kwIntegrationSchema($db);
    $limit = max(1, (int)$limit);
    $ids = $db->query("SELECT id FROM orders WHERE status IN ('paid','completed') AND merchant_notified_at IS NULL
                         AND webhook_attempts < 5 AND (webhook_next_at IS NULL OR webhook_next_at <= NOW())
                         AND created_at > (NOW() - INTERVAL 14 DAY) ORDER BY id LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) kwSendOrderWebhook((int)$id, 8);
    return count($ids);
}

/* ───────────────────────── plan changes (admin) ───────────────────────── */
/** Insert using only columns that exist, filling any required leftovers. */
function kwInsertRow(PDO $db, string $table, array $values): int {
    $row = [];
    foreach ($db->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $f = $c['Field'];
        if (array_key_exists($f, $values)) { $row[$f] = $values[$f]; continue; }
        if (stripos((string)$c['Extra'], 'auto_increment') !== false) continue;
        if ($c['Null'] === 'NO' && $c['Default'] === null) {
            $t = strtolower($c['Type']);
            if (str_starts_with($t, 'enum(')) { preg_match("/enum\('([^']*)'/", $t, $m); $row[$f] = $m[1] ?? ''; }
            elseif (preg_match('/int|decimal|float|double|bit/', $t)) $row[$f] = 0;
            elseif (preg_match('/date|time/', $t)) $row[$f] = date('Y-m-d H:i:s');
            else $row[$f] = '';
        }
    }
    $db->prepare("INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")
       ->execute(array_values($row));
    return (int)$db->lastInsertId();
}

/**
 * Change a merchant's plan AND keep the subscription row in step, so the
 * sidebar chip, billing page and any expiry job all agree with users.plan.
 */
function kwApplyPlanChange(PDO $db, int $userId, string $newPlan, int $days = 30): void {
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE users SET plan = ? WHERE id = ?")->execute([$newPlan, $userId]);
        $db->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status IN ('active','trial')")->execute([$userId]);
        if ($newPlan !== 'free') {
            $now = date('Y-m-d H:i:s'); $end = date('Y-m-d H:i:s', strtotime("+$days days"));
            kwInsertRow($db, 'subscriptions', [
                'user_id' => $userId, 'plan' => $newPlan, 'plan_name' => $newPlan, 'status' => 'active',
                'started_at' => $now, 'starts_at' => $now, 'start_date' => $now,
                'ends_at' => $end, 'expires_at' => $end, 'current_period_end' => $end,
                'amount' => 0, 'billing_cycle' => 'monthly', 'source' => 'admin', 'provider' => 'admin',
                'reference' => 'ADM-' . strtoupper(bin2hex(random_bytes(4))), 'created_at' => $now,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
