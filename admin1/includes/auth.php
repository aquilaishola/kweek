<?php
// admin/includes/auth.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function currentAdmin(): ?array {
    if (!isAdminLoggedIn()) return null;
    static $admin = null;
    if ($admin === null) {
        $stmt = db()->prepare("SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin;
}

function adminLogout(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    session_regenerate_id(true);
    header('Location: /admin/login.php');
    exit;
}
