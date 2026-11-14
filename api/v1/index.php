<?php
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function apiRespond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function apiError(string $message, int $code = 400): void {
    apiRespond(['error' => true, 'message' => $message], $code);
}

// ── Auth: Authorization: Bearer <api_key> ──────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!$authHeader || !preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    apiError('Missing or invalid Authorization header. Use: Authorization: Bearer <api_key>', 401);
}
$plainKey = trim($m[1]);
$keyHash  = hash('sha256', $plainKey);

$db = db();
$keyStmt = $db->prepare("SELECT ak.id as key_id, ak.user_id, u.plan, u.status as user_status
                          FROM api_keys ak JOIN users u ON u.id = ak.user_id
                          WHERE ak.key_hash = ? AND ak.revoked_at IS NULL LIMIT 1");
$keyStmt->execute([$keyHash]);
$apiKey = $keyStmt->fetch();

if (!$apiKey) {
    apiError('Invalid or revoked API key.', 401);
}
if ($apiKey['plan'] !== 'business') {
    apiError('API access requires the Business plan.', 403);
}
if ($apiKey['user_status'] !== 'active') {
    apiError('This account is not active.', 403);
}
$userId = (int)$apiKey['user_id'];

try {
    $db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$apiKey['key_id']]);
} catch (Throwable $e) { /* best-effort, never block the request on this */ }

// ── Rate limiting — reuses the existing rate_limits table:
// 120 requests / rolling 60s window per API key. ────────────────────
(function () use ($db, $apiKey) {
    $identifier = 'apikey_' . $apiKey['key_id'];
    $action     = 'api_request';
    $windowSecs = 60;
    $maxAttempts = 120;

    $stmt = $db->prepare("SELECT * FROM rate_limits WHERE identifier=? AND action=?");
    $stmt->execute([$identifier, $action]);
    $rl = $stmt->fetch();

    if (!$rl) {
        $db->prepare("INSERT INTO rate_limits (identifier,action,attempts) VALUES (?,?,1)")->execute([$identifier, $action]);
        return;
    }

    if (!empty($rl['blocked_until']) && strtotime($rl['blocked_until']) > time()) {
        apiError('Rate limit exceeded. Try again shortly.', 429);
    }

    if (time() - strtotime($rl['updated_at']) > $windowSecs) {
        $db->prepare("UPDATE rate_limits SET attempts=1, blocked_until=NULL WHERE id=?")->execute([$rl['id']]);
        return;
    }

    $newAttempts = (int)$rl['attempts'] + 1;
    if ($newAttempts > $maxAttempts) {
        $db->prepare("UPDATE rate_limits SET attempts=?, blocked_until=DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE id=?")
           ->execute([$newAttempts, $rl['id']]);
        apiError('Rate limit exceeded. Try again shortly.', 429);
    }
    $db->prepare("UPDATE rate_limits SET attempts=? WHERE id=?")->execute([$newAttempts, $rl['id']]);
})();

if (!empty($_GET['route'])) {
    $path = trim($_GET['route'], '/');
} else {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    $path = preg_replace('#^/api/v1#', '', $requestPath);
    $path = trim($path, '/');
}
$segments = $path === '' ? [] : explode('/', $path);
$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;
$method   = $_SERVER['REQUEST_METHOD'];

function apiPaginationParams(): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    return [$page, $perPage, ($page - 1) * $perPage];
}

// GET /me
if ($resource === 'me' && $method === 'GET') {
    $stmt = $db->prepare("SELECT name, email, plan, wallet_balance, total_earned, bvn_verified FROM users WHERE id=?");
    $stmt->execute([$userId]);
    apiRespond(['data' => $stmt->fetch()]);
}

// GET /orders, GET /orders/{order_ref}
if ($resource === 'orders' && $method === 'GET') {
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM orders WHERE user_id=? AND order_ref=? LIMIT 1");
        $stmt->execute([$userId, $id]);
        $order = $stmt->fetch();
        if (!$order) apiError('Order not found.', 404);
        $order['items']         = $order['items'] ? json_decode($order['items'], true) : null;
        $order['custom_fields'] = $order['custom_fields'] ? json_decode($order['custom_fields'], true) : null;
        apiRespond(['data' => $order]);
    }
    [$page, $perPage, $offset] = apiPaginationParams();
    $status = $_GET['status'] ?? '';
    $where  = ['user_id=?'];
    $params = [$userId];
    if ($status) { $where[] = 'status=?'; $params[] = $status; }
    $whereSQL = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT order_ref, customer_name, customer_phone, customer_email, total_amount, status, created_at, paid_at
                           FROM orders WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    apiRespond([
        'data' => $stmt->fetchAll(),
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1,$perPage))],
    ]);
}

// GET /links, GET /links/{id_or_slug}, POST /links
if ($resource === 'links') {
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("SELECT * FROM payment_links WHERE user_id=? AND (id=? OR slug=?) LIMIT 1");
        $stmt->execute([$userId, is_numeric($id) ? $id : 0, $id]);
        $link = $stmt->fetch();
        if (!$link) apiError('Payment link not found.', 404);
        apiRespond(['data' => $link]);
    }
    if ($method === 'GET' && !$id) {
        $stmt = $db->prepare("SELECT id, title, slug, link_type, amount, status, total_orders, total_revenue, view_count, created_at
                               FROM payment_links WHERE user_id=? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        apiRespond(['data' => $stmt->fetchAll()]);
    }
    if ($method === 'POST' && !$id) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $title  = trim($input['title'] ?? '');
        $amount = isset($input['amount']) ? (float)$input['amount'] : null;

        if ($title === '') apiError('title is required.', 422);

        $limit = function_exists('linkLimitFor') ? linkLimitFor($apiKey['plan']) : PHP_INT_MAX;
        $countStmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status IN ('active','paused')");
        $countStmt->execute([$userId]);
        if ((int)$countStmt->fetchColumn() >= $limit) {
            apiError("Plan limit of $limit payment links reached.", 403);
        }

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')) . '-' . strtolower(bin2hex(random_bytes(3)));
        $db->prepare("INSERT INTO payment_links (user_id, title, slug, amount, status) VALUES (?,?,?,?,'active')")
           ->execute([$userId, $title, $slug, $amount]);

        $stmt = $db->prepare("SELECT * FROM payment_links WHERE id=?");
        $stmt->execute([$db->lastInsertId()]);
        apiRespond(['data' => $stmt->fetch()], 201);
    }
}

// GET /transactions
if ($resource === 'transactions' && $method === 'GET') {
    [$page, $perPage, $offset] = apiPaginationParams();
    $stmt = $db->prepare("SELECT reference, type, amount, fee, net_amount, balance_after, status, description, created_at
                           FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute([$userId]);
    apiRespond(['data' => $stmt->fetchAll()]);
}

apiError('Unknown endpoint: ' . $method . ' /' . $path, 404);
