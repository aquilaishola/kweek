<?php
require_once __DIR__ . '/includes/functions.php';

$token = trim(get('token'));

if (empty($token)) {
    flash('error', 'Invalid verification link.');
    redirect('/login.php');
}

$db = db();
$stmt = $db->prepare("SELECT id, email_verified_at FROM users WHERE email_token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    flash('error', 'This verification link is invalid or has already been used.');
    redirect('/login.php');
}

if (!empty($user['email_verified_at'])) {
    flash('info', 'Your email is already verified.');
} else {
    $db->prepare("UPDATE users SET email_verified_at = NOW(), email_token = NULL WHERE id = ?")
       ->execute([$user['id']]);
    flash('success', 'Email verified! You\'re all set.');
}

redirect(isLoggedIn() ? '/onboarding.php' : '/login.php');