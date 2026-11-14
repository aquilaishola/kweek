<?php
require_once __DIR__ . '/../../includes/functions.php';

// ── ADMIN AUTH ───────────────────────────────────────────────
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        redirect('/admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
    $admin = currentAdmin();
    if (!$admin) {
        adminLogout();
    }
}

function currentAdmin(): ?array {
    if (!isAdminLoggedIn()) return null;
    static $admin = null;
    if ($admin === null) {
        $stmt = db()->prepare("SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: false;
    }
    return $admin ?: null;
}

function adminLogout(): void {
    unset($_SESSION['admin_id']);
    redirect('/admin/login.php');
}

// ── CAPABILITIES ─────────────────────────────────────────────
// Deliberately explicit per role rather than a deny-list — a role only
// gets what's listed here, nothing implicit.
const ADMIN_CAPABILITIES = [
    'super_admin' => [
        'view_dashboard', 'manage_users', 'manage_kyc', 'manage_withdrawals',
        'view_transactions', 'manage_subscriptions', 'manage_admins',
        'manage_links', 'view_orders', 'manage_settings',
    ],
    'support' => [
        'view_dashboard', 'manage_users', 'manage_kyc', 'view_transactions', 'view_subscriptions',
        'manage_links', 'view_orders',
    ],
    'finance' => [
        'view_dashboard', 'view_users', 'manage_withdrawals', 'view_transactions', 'manage_subscriptions',
        'view_links', 'view_orders',
    ],
];

function adminCan(array $admin, string $capability): bool {
    if ($admin['role'] === 'super_admin') return true;
    return in_array($capability, ADMIN_CAPABILITIES[$admin['role']] ?? [], true);
}

/** True if the admin has ANY of the given capabilities — for pages two roles can both open, just with different rights inside (e.g. links: support can manage, finance can only view). */
function adminCanAny(array $admin, array $capabilities): bool {
    foreach ($capabilities as $cap) {
        if (adminCan($admin, $cap)) return true;
    }
    return false;
}

/** Call at the top of any admin page that needs more than "logged in". */
function requireAdminCapability(string $capability): void {
    $admin = currentAdmin();
    if (!$admin || !adminCan($admin, $capability)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

function requireAdminCapabilityAny(array $capabilities): void {
    $admin = currentAdmin();
    if (!$admin || !adminCanAny($admin, $capabilities)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

const ADMIN_ONLINE_THRESHOLD_MINUTES = 5;

function usersHaveLastSeenColumn(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $col = db()->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'")->fetch();
            $has = (bool) $col;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

// ── MISC HELPERS ─────────────────────────────────────────────
function adminRoleLabel(string $role): string {
    return match ($role) {
        'super_admin' => 'Super Admin',
        'finance'     => 'Finance',
        default       => 'Support',
    };
}

function adminInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

function kycStorageDir(): string {
    return '/home/schedwav/kyc_secure';
}

function resolveKycDocumentPath(?string $stored): ?string {
    if (!$stored) return null;
    $base = kycStorageDir();

    $candidate = str_starts_with($stored, $base) ? $stored : $base . '/' . ltrim($stored, '/');
    $real = realpath($candidate);
    $realBase = realpath($base);
    if (!$real || !$realBase || !str_starts_with($real, $realBase)) {
        return null;
    }
    return $real;
}
