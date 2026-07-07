<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';

// ── SESSION ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ── AUTH HELPERS ─────────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND status != 'suspended' LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        if ($user && $user['plan'] !== 'free') {
            $subStmt = db()->prepare("SELECT * FROM subscriptions WHERE user_id=? AND status='active' ORDER BY ends_at DESC LIMIT 1");
            $subStmt->execute([$user['id']]);
            $sub = $subStmt->fetch();
            if (!$sub || strtotime($sub['ends_at']) < time()) {
                db()->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$user['id']]);
                if ($sub) db()->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
                $user['plan'] = 'free';
            }
        }
    }
    return $user;
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /login.php');
    exit;
}

// ── CSRF ─────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid CSRF token']));
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// ── RATE LIMITING ────────────────────────────────────────────
function checkRateLimit(string $identifier, string $action, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    $db = db();
    $stmt = $db->prepare("SELECT attempts, blocked_until FROM rate_limits WHERE identifier = ? AND action = ?");
    $stmt->execute([$identifier, $action]);
    $row = $stmt->fetch();

    if ($row) {
        if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
            return false; // still blocked
        }
        if ($row['attempts'] >= $maxAttempts) {
            $blockedUntil = date('Y-m-d H:i:s', time() + $windowSeconds);
            $db->prepare("UPDATE rate_limits SET blocked_until = ?, attempts = attempts + 1, updated_at = NOW() WHERE identifier = ? AND action = ?")
               ->execute([$blockedUntil, $identifier, $action]);
            return false;
        }
        $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, updated_at = NOW() WHERE identifier = ? AND action = ?")
           ->execute([$identifier, $action]);
    } else {
        $db->prepare("INSERT INTO rate_limits (identifier, action, attempts) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1, updated_at = NOW()")
           ->execute([$identifier, $action]);
    }
    return true;
}

function clearRateLimit(string $identifier, string $action): void {
    db()->prepare("DELETE FROM rate_limits WHERE identifier = ? AND action = ?")->execute([$identifier, $action]);
}

// ── INPUT HELPERS ────────────────────────────────────────────
function clean(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function post(string $key, mixed $default = ''): mixed {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get(string $key, mixed $default = ''): mixed {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url, int $code = 302): never {
    header("Location: $url", true, $code);
    exit;
}

function isAjax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ── MONEY HELPERS ────────────────────────────────────────────
function formatNaira(float $amount): string {
    return '₦' . number_format($amount, 2);
}

function calcFee(float $amount, string $plan): float {
    $rate = match($plan) {
        'starter'  => FEE_STARTER / 100,
        'pro'      => FEE_PRO / 100,
        'business' => FEE_BUSINESS / 100,
        default    => FEE_FREE / 100,
    };
    return round($amount * $rate, 2);
}

function monthlyCapFor(string $plan): float {
    return match($plan) {
        'starter'  => CAP_STARTER,
        'pro'      => CAP_PRO,
        'business' => CAP_BUSINESS,
        default    => CAP_FREE,
    };
}

function withdrawalFeeFor(string $plan): float {
    return match($plan) {
        'starter'  => WITHDRAWAL_FEE_STARTER,
        'pro'      => WITHDRAWAL_FEE_PRO,
        'business' => WITHDRAWAL_FEE_BUSINESS,
        default    => WITHDRAWAL_FEE_FREE,
    };
}

function linkLimitFor(string $plan): int {
    return match($plan) {
        'starter'  => LINK_LIMIT_STARTER,
        'pro'      => LINK_LIMIT_PRO,
        'business' => LINK_LIMIT_BUSINESS,
        default    => LINK_LIMIT_FREE,
    };
}

function checkMonthlyCap(int $userId, float $newAmount): bool {
    $user = db()->prepare("SELECT plan, monthly_volume, monthly_reset FROM users WHERE id = ?");
    $user->execute([$userId]);
    $u = $user->fetch();
    if (!$u) return false;

    // Reset monthly volume if month has changed
    $thisMonth = date('Y-m-01');
    if ($u['monthly_reset'] !== $thisMonth) {
        db()->prepare("UPDATE users SET monthly_volume = 0, monthly_reset = ? WHERE id = ?")
            ->execute([$thisMonth, $userId]);
        $u['monthly_volume'] = 0;
    }

    $cap = monthlyCapFor($u['plan']);
    return ($u['monthly_volume'] + $newAmount) <= $cap;
}

// ── REFERENCE GENERATORS ─────────────────────────────────────
function generateRef(string $prefix = 'KWK'): string {
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(6)));
}

function generateOrderRef(): string {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function generateReceiptCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'TXN-';
    for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
    $code .= '-';
    for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
    return $code;
}

function generateSlug(string $name): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
    $slug = trim($slug, '-');
    $base = $slug;
    $i = 1;
    while (true) {
        $stmt = db()->prepare("SELECT id FROM payment_links WHERE slug = ?");
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function notify(int $userId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null, bool $email = true): void {
    db()->prepare("INSERT INTO notifications (user_id, type, title, message, icon, link) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $type, $title, $message, $icon, $link]);

    if ($email) {
        $stmt = db()->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && !empty($user['email'])) {
            try {
                sendNotificationEmail($user, $title, $message, $link);
            } catch (Throwable $e) {
                error_log('notify() email send failed: ' . $e->getMessage());
            }
        }
    }
}

function unreadNotifCount(int $userId): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function creditWallet(int $userId, float $amount, float $fee, string $description, string $reference, ?int $orderId = null, ?string $nombaRef = null, bool $manageTransaction = true): bool {
    $db = db();
    if ($manageTransaction) $db->beginTransaction();
    try {
        // Lock row
        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $before = (float)$stmt->fetchColumn();
        $net    = $amount - $fee;
        $after  = $before + $net;

        // Update balance
        $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, total_earned = total_earned + ?, monthly_volume = monthly_volume + ?, updated_at = NOW() WHERE id = ?")
           ->execute([$net, $net, $amount, $userId]);

        // Log transaction
        $db->prepare("INSERT INTO transactions (user_id, order_id, type, amount, fee, net_amount, balance_before, balance_after, description, reference, nomba_ref, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'success')")
           ->execute([$userId, $orderId, 'credit', $amount, $fee, $net, $before, $after, $description, $reference, $nombaRef]);

        if ($manageTransaction) $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($manageTransaction) {
            $db->rollBack();
            error_log("creditWallet error: " . $e->getMessage());
            return false;
        }

        throw $e;
    }
}

function debitWallet(
    int $userId,
    float $amount,
    string $description,
    string $reference,
    ?int $orderId = null,
    ?string $nombaRef = null,
    bool $manageTransaction = true
): bool {
    $db = db();

    if ($manageTransaction) {
        $db->beginTransaction();
    }

    try {
        // Lock user row
        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $before = (float)$stmt->fetchColumn();

        if ($before < $amount) {
            if ($manageTransaction) {
                $db->rollBack();
                return false;
            }

            throw new Exception('Insufficient wallet balance.');
        }

        $after = $before - $amount;

        // Update balance
        $db->prepare("
            UPDATE users
            SET wallet_balance = wallet_balance - ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$amount, $userId]);

        // Log transaction
        $db->prepare("
            INSERT INTO transactions (
                user_id,
                order_id,
                type,
                amount,
                fee,
                net_amount,
                balance_before,
                balance_after,
                description,
                reference,
                nomba_ref,
                status
            ) VALUES (
                ?, ?, 'debit', ?, 0, ?, ?, ?, ?, ?, ?, 'success'
            )
        ")->execute([
            $userId,
            $orderId,
            $amount,
            $amount,
            $before,
            $after,
            $description,
            $reference,
            $nombaRef
        ]);

        if ($manageTransaction) {
            $db->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($manageTransaction) {
            $db->rollBack();
            error_log("debitWallet error: " . $e->getMessage());
            return false;
        }

        // Caller owns the transaction
        throw $e;
    }
}

// ── FLASH MESSAGES ───────────────────────────────────────────
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flashHtml(): string {
    $f = getFlash();
    if (!$f) return '';

    $icons = [
        'success' => 'circle-check',
        'error'   => 'alert-circle',
        'info'    => 'info-circle',
        'warning' => 'alert-triangle'
    ];

    $icon = $icons[$f['type']] ?? 'bell';

    return '
    <div id="flash-wrap">
        <div class="flash flash-' . htmlspecialchars($f['type']) . '">
            <i class="ti ti-' . $icon . '"></i>
            <span>' . htmlspecialchars($f['message']) . '</span>
        </div>
    </div>';
}

// ── IP HELPER ────────────────────────────────────────────────
function clientIp(): string {
    foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            if (filter_var(trim($ip), FILTER_VALIDATE_IP)) return trim($ip);
        }
    }
    return '0.0.0.0';
}

// ── DATE HELPERS ─────────────────────────────────────────────
function timeAgo(string $datetime): string {
    $time = time() - strtotime($datetime);
    if ($time < 60)      return 'just now';
    if ($time < 3600)    return (int)($time/60) . ' mins ago';
    if ($time < 86400)   return (int)($time/3600) . ' hrs ago';
    if ($time < 604800)  return (int)($time/86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

function nigeriaTime(string $datetime = 'now'): string {
    $dt = new DateTime($datetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Africa/Lagos'));
    return $dt->format('M j, Y g:i A');
}

function feeLabelFor(string $plan): float {
    return match($plan) {
        'starter'  => FEE_STARTER,
        'pro'      => FEE_PRO,
        'business' => FEE_BUSINESS,
        default    => FEE_FREE,
    };
}