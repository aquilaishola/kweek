<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/integration.php';
require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',     
        'secure'   => $isHttps, 
        'httponly' => true,
        'samesite' => 'Lax',   
    ]);

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
    $current = currentUser();
    $currentPage = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $currentPage = rtrim($currentPage, '/');
    if (str_ends_with($currentPage, '.php')) {
        $currentPage = substr($currentPage, 0, -4);
    }

    if (empty($current['email_verified_at'])) {
        if ($currentPage !== '/verify-email-notice') {
            redirect('/verify-email-notice.php');
        }
        return; 
    }

    if (empty($current['profile_completed']) && $currentPage !== '/onboarding') {
        redirect('/onboarding.php');
    }
}

function sessionUser(): ?array {
    if (!isLoggedIn()) return null;
    static $viewer = null;
    if ($viewer === null) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND status != 'suspended' LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $viewer = $stmt->fetch() ?: null;
    }
    if ($viewer && (empty($viewer['last_seen_at']) || strtotime($viewer['last_seen_at']) < time() - 60)) {
    db()->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?")->execute([$viewer['id']]);
    $viewer['last_seen_at'] = date('Y-m-d H:i:s');
}
    return $viewer;
}

function currentUser(): ?array {
    $viewer = sessionUser();
    if (!$viewer) return null;

    static $account = null;
    if ($account !== null) return $account;

    if (empty($viewer['owner_user_id'])) {
        $account = $viewer;
    } else {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND status != 'suspended' LIMIT 1");
        $stmt->execute([$viewer['owner_user_id']]);
        $account = $stmt->fetch() ?: null;
    }

    if ($account && empty($account['owner_user_id']) && $account['plan'] !== 'free') {
        $subStmt = db()->prepare("SELECT * FROM subscriptions WHERE user_id=? AND status='active' ORDER BY ends_at DESC LIMIT 1");
        $subStmt->execute([$account['id']]);
        $sub = $subStmt->fetch();
       if (!$sub || strtotime($sub['ends_at']) < time()) {
    db()->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$account['id']]);
    if ($sub) db()->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
    enforceLinkLimitOnDowngrade($account['id'], 'free');  
    $account['plan'] = 'free';
}
    }

    // Safety net — enforces the limit regardless of *how* the account ended
    // up over it (the block above only catches the exact moment a
    // subscription is detected expiring in this request; a manual plan
    // edit, an admin action, or any other path that sets `plan` directly
    // would otherwise leave excess links active forever).
    if ($account && empty($account['owner_user_id'])) {
        $limit = linkLimitFor($account['plan']);
        if ($limit !== PHP_INT_MAX) {
            $cntStmt = db()->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status='active'");
            $cntStmt->execute([$account['id']]);
            if ((int) $cntStmt->fetchColumn() > $limit) {
                enforceLinkLimitOnDowngrade($account['id'], $account['plan']);
            }
        }
    }

    return $account;
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

// ── TEAM / MULTI-USER ACCOUNTS ──────────────────────────────
// A "team member" is a normal row in `users` with owner_user_id pointing
// at the account they help manage, and team_role set. Login is unchanged
// (they authenticate against their own row like anyone else).
//
// currentUser() already does the heavy lifting: it transparently
// resolves a team member's session to their owner's row, so any
// existing page doing `$user = currentUser(); $uid = $user['id'];`
// automatically scopes to the right account with no changes needed.
//
// These helpers are only for the few spots that need to know about the
// person logged in specifically, rather than the account: identity
// (name/avatar), personal settings (name/phone/password), and
// permission checks. Pass them sessionUser(), not currentUser().

function isTeamMember(array $sessionUser): bool {
    return !empty($sessionUser['owner_user_id']);
}

function accountOwnerId(array $sessionUser): int {
    return !empty($sessionUser['owner_user_id']) ? (int)$sessionUser['owner_user_id'] : (int)$sessionUser['id'];
}

function teamRole(array $sessionUser): string {
    return empty($sessionUser['owner_user_id']) ? 'owner' : ($sessionUser['team_role'] ?: 'viewer');
}

const TEAM_CAPABILITIES = [
    'admin'   => ['manage_links', 'manage_orders', 'manage_team', 'api_webhooks', 'view_transactions', 'view_analytics'],
    'manager' => ['manage_links', 'manage_orders', 'view_transactions'],
    'viewer'  => ['view_transactions'],
];

function can(array $sessionUser, string $capability): bool {
    $role = teamRole($sessionUser);
    if ($role === 'owner') return true;
    return in_array($capability, TEAM_CAPABILITIES[$role] ?? [], true);
}

// Use at the top of a page/action that needs more than "logged in", e.g.
//   requireLogin();
//   requireCapability('manage_team');
// Deliberately no 'billing' / 'withdraw' / 'close_account' entries exist
// in the matrix above, so requireCapability() for those only ever passes
// for the actual owner. Checks the logged-in PERSON's role, not the
// account, so this must run before/alongside currentUser() rather than
// relying on it.
function requireCapability(string $capability): void {
    $viewer = sessionUser();
    if (!$viewer || !can($viewer, $capability)) {
        flash('error', "You don't have permission to do that.");
        redirect('/dashboard.php');
    }
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
            return false; // still within an active block
        }

        if ($row['blocked_until'] && strtotime($row['blocked_until']) <= time()) {
            // block has expired — start a fresh window instead of compounding
            $db->prepare("UPDATE rate_limits SET attempts = 1, blocked_until = NULL, updated_at = NOW() WHERE identifier = ? AND action = ?")
               ->execute([$identifier, $action]);
            return true;
        }

        if ($row['attempts'] >= $maxAttempts) {
            $blockedUntil = date('Y-m-d H:i:s', time() + $windowSeconds);
            $db->prepare("UPDATE rate_limits SET blocked_until = ? WHERE identifier = ? AND action = ?")
               ->execute([$blockedUntil, $identifier, $action]);
            return false;
        }

        $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, updated_at = NOW() WHERE identifier = ? AND action = ?")
           ->execute([$identifier, $action]);
    } else {
        $db->prepare("INSERT INTO rate_limits (identifier, action, attempts) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE attempts = 1, blocked_until = NULL, updated_at = NOW()")
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

function enforceLinkLimitOnDowngrade(int $userId, string $newPlan): void {
    $limit = linkLimitFor($newPlan);
    if ($limit === PHP_INT_MAX) return;

    $db = db();
    $stmt = $db->prepare("
        SELECT id FROM payment_links
        WHERE user_id = ? AND status = 'active'
        ORDER BY total_revenue DESC, total_orders DESC, created_at DESC
    ");
    $stmt->execute([$userId]);
    $activeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($activeIds) <= $limit) return;

    $toPause = array_slice($activeIds, $limit);
    $placeholders = implode(',', array_fill(0, count($toPause), '?'));
    $db->prepare("UPDATE payment_links SET status = 'paused' WHERE id IN ($placeholders)")
       ->execute($toPause);

    notify(
        $userId,
        'plan_downgraded',
        'Plan Downgraded',
        count($toPause) . ' of your payment links ' . (count($toPause) === 1 ? 'was' : 'were') . ' automatically paused because your new plan only allows ' . $limit . ' active link' . ($limit === 1 ? '' : 's') . '. They are not deleted — upgrade anytime to reactivate them.',
        'alert-triangle',
        '/payment-links.php?status=paused'
    );
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
    
    sendPushNotification($userId, $title, $message, $link, $icon);
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
        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $before = (float)$stmt->fetchColumn();
        $net    = $amount - $fee;
        $after  = $before + $net;

        $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, total_earned = total_earned + ?, monthly_volume = monthly_volume + ?, updated_at = NOW() WHERE id = ?")
           ->execute([$net, $net, $amount, $userId]);

        $db->prepare("INSERT INTO transactions (user_id, order_id, type, amount, fee, net_amount, balance_before, balance_after, description, reference, nomba_ref, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'success')")
           ->execute([$userId, $orderId, 'credit', $amount, $fee, $net, $before, $after, $description, $reference, $nombaRef]);

        if ($manageTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($manageTransaction) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("creditWallet error: " . $e->getMessage());
            return false;
        }
        throw $e;
    }

    // Outside the try: a webhook problem can never undo or fail a committed credit.
    // Only fire here if the caller owns no transaction (otherwise it isn't committed yet).
    if ($orderId && $manageTransaction && function_exists('kwQueueOrderWebhook')) {
        try { kwQueueOrderWebhook((int)$orderId); }
        catch (Throwable $e) { error_log('kwQueueOrderWebhook failed: ' . $e->getMessage()); }
    }
    return true;
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

function maskEmail(string $email): string {
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    $len = strlen($local);

    if ($len <= 2) {
        $maskedLocal = str_repeat('*', $len);
    } else {
        $maskedLocal = $local[0] . str_repeat('*', $len - 2) . $local[$len - 1];
    }

    return $maskedLocal . '@' . $domain;
}

function normalizeNameTokens(string $name): array {
    $name = strtolower($name);
    $name = preg_replace('/[^a-z\s]/', '', $name);
    return array_values(array_filter(explode(' ', trim($name))));
}

/**
 * Used to gate where real money gets sent (payout-account name vs. the
 * name on file from KYC), so this needs to be strict: every token in
 * $nameA (the authoritative/shorter name — normally the KYC name) must
 * have a close match somewhere in $nameB (normally the account name).
 * $nameB is allowed extra tokens (e.g. a middle name the KYC name didn't
 * include) — that's fine and not treated as a mismatch. What's NOT fine
 * is $nameA having a token (e.g. a first name) that finds nothing to
 * match in $nameB — previously this only required matching *half* of
 * $nameA's tokens, which meant sharing just a surname was enough to pass
 * for a completely different person.
 */
function namesReasonablyMatch(string $nameA, string $nameB): bool {
    $tokensA = normalizeNameTokens($nameA);
    $tokensB = normalizeNameTokens($nameB);
    if (empty($tokensA) || empty($tokensB)) return false;
 
    $matches = 0;
    foreach ($tokensA as $a) {
        foreach ($tokensB as $b) {
            similar_text($a, $b, $pct);
            if ($a === $b || $pct > 85) {
                $matches++;
                break;
            }
        }
    }
 
    return $matches === count($tokensA);
}

function normalizeName(string $name): string {
    $name = strtoupper(trim($name));
    $name = str_replace(['-', "'", '.'], ' ', $name); // hyphenated/apostrophe names split into tokens
    $name = preg_replace('/[^A-Z\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function tokenizeName(string $name): array {
    return array_values(array_filter(explode(' ', normalizeName($name))));
}

/**
 * Fuzzy match: every token in the account name must have a close match
 * somewhere among the BVN tokens (order-independent, typo-tolerant).
 * BVN can have extra tokens (e.g. a middle name) the account doesn't list —
 * that's fine and not treated as a mismatch.
 */
function namesFuzzyMatch(string $accountFirst, string $accountLast, string $bvnFirst, string $bvnMiddle, string $bvnLast): array {
    $accountTokens = array_merge(tokenizeName($accountFirst), tokenizeName($accountLast));
    $bvnTokens     = array_merge(tokenizeName($bvnFirst), tokenizeName($bvnMiddle), tokenizeName($bvnLast));

    if (empty($accountTokens) || empty($bvnTokens)) {
        return ['match' => false, 'reason' => 'empty_tokens'];
    }

    $unmatched = [];
    foreach ($accountTokens as $tok) {
        $threshold = strlen($tok) <= 4 ? 1 : 2; // shorter names tolerate less typo distance
        $best = PHP_INT_MAX;
        foreach ($bvnTokens as $bvnTok) {
            $best = min($best, levenshtein($tok, $bvnTok));
        }
        if ($best > $threshold) {
            $unmatched[] = $tok;
        }
    }

    return [
        'match'  => empty($unmatched),
        'reason' => empty($unmatched) ? 'ok' : ('unmatched: ' . implode(',', $unmatched)),
    ];
}

/**
 * StroWallet's lookup endpoint may not return the same date format the
 * verify endpoint expects. Try known formats first; fall back to strtotime
 * only as a last resort (ambiguous for D/M vs M/D slash dates).
 */
function normalizeBvnDob(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    $formats = [
        'd-m-Y', 'Y-m-d', 'd/m/Y', 'Y/m/d',
        'd-m-Y H:i:s', 'Y-m-d H:i:s',
        'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP',
        'd M Y', 'd F Y',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt !== false) {
            $errors = DateTime::getLastErrors();
            if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $dt->format('d-m-Y');
            }
        }
    }

    $ts = strtotime($raw);
    return $ts !== false ? date('d-m-Y', $ts) : null;
}

const KYC_MAX_ATTEMPTS = 5;

function kycAttemptsRemaining(array $user): int
{
    return max(0, KYC_MAX_ATTEMPTS - (int) $user['kyc_attempts']);
}

function recordKycAttempt(PDO $db, int $uid, bool $passed, ?float $namePct, ?string $message, ?string $bvn): void
{
    $db->prepare("UPDATE users SET kyc_attempts = kyc_attempts + 1 WHERE id = ?")->execute([$uid]);

    $db->prepare("INSERT INTO kyc_attempts_log (user_id, bvn_last4, passed, name_match_pct, message) VALUES (?,?,?,?,?)")
       ->execute([$uid, $bvn ? substr($bvn, -4) : null, $passed ? 1 : 0, $namePct, $message]);

    $stmt = $db->prepare("SELECT kyc_attempts FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $attempts = (int) $stmt->fetchColumn();

    if (!$passed && $attempts >= KYC_MAX_ATTEMPTS) {
        $db->prepare("UPDATE users SET payout_locked = 1, payout_locked_reason = ? WHERE id = ?")
           ->execute(['Exceeded ' . KYC_MAX_ATTEMPTS . ' BVN verification attempts', $uid]);
    }
}

function generateOtp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}