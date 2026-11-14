<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect('/dashboard.php');

$code  = get('code');
$state = get('state');

if (empty($code) || empty($state) || !hash_equals($_SESSION['google_oauth_state'] ?? '', $state)) {
    error_log('Google OAuth state mismatch — session state: ' . ($_SESSION['google_oauth_state'] ?? '(missing)') . ', received: ' . ($state ?: '(missing)') . ', session id: ' . session_id());
    flash('error', 'Google sign-in failed — please try again.');
    redirect('/login.php');
}
unset($_SESSION['google_oauth_state']);

// ── Exchange the code for an access token ──────────────────────
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$tokenRaw = curl_exec($ch);
$tokenErr = curl_error($ch);
curl_close($ch);

$token = json_decode((string) $tokenRaw, true);
if ($tokenErr || empty($token['access_token'])) {
    error_log('Google OAuth token exchange failed: ' . ($tokenErr ?: $tokenRaw));
    flash('error', 'Google sign-in failed. Please try again.');
    redirect('/login.php');
}

// ── Fetch the profile ───────────────────────────────────────────
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_TIMEOUT        => 15,
]);
$profileRaw = curl_exec($ch);
curl_close($ch);

$profile = json_decode((string) $profileRaw, true);
if (empty($profile['email']) || empty($profile['email_verified'])) {
    error_log('Google OAuth profile fetch failed or unverified email: ' . $profileRaw);
    flash('error', 'We could not verify your Google account email.');
    redirect('/login.php');
}

$email    = strtolower(trim($profile['email']));
$name     = $profile['name'] ?? explode('@', $email)[0];
$googleId = $profile['sub'];

$db = db();

// 1. Already linked to this Google account
$stmt = $db->prepare("SELECT id, name, email, status, profile_completed FROM users WHERE google_id = ? LIMIT 1");
$stmt->execute([$googleId]);
$user = $stmt->fetch();

if (!$user) {
    // 2. Existing password account with the same email → link it
    $stmt = $db->prepare("SELECT id, name, email, status, profile_completed FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

   if ($user) {
    $db->prepare("UPDATE users SET google_id = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
       ->execute([$googleId, $user['id']]);
}  else {
  
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("INSERT INTO users (name, email, password, google_id, status, plan, profile_completed, email_verified_at) VALUES (?, ?, ?, ?, 'active', 'free', 0, NOW())")
       ->execute([$name, $email, $randomPassword, $googleId]);
    $userId = (int) $db->lastInsertId();

    notify($userId, 'welcome', 'Welcome to Kweek!', 'Your account is ready. Create your first payment link and start getting paid.', 'heart', '/payment-links.php', false);
    if (function_exists('sendWelcomeEmail')) {
        sendWelcomeEmail(['name' => $name, 'email' => $email]);
    }

    $stmt = $db->prepare("SELECT id, name, email, status, profile_completed FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}
}

if ($user['status'] === 'suspended') {
    flash('error', 'Your account has been suspended. Contact support@kweek.ng.');
    redirect('/login.php');
}

$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['name'];
session_regenerate_id(true);

redirect(empty($user['profile_completed']) ? '/onboarding.php' : '/dashboard.php');