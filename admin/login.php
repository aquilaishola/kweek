<?php
require_once __DIR__ . '/includes/admin_functions.php';

if (isAdminLoggedIn()) {
    redirect('/admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = strtolower(trim(post('email')));
    $password = post('password');

    if (!checkRateLimit(clientIp(), 'admin_login', 8, 900) || !checkRateLimit($email, 'admin_login', 8, 900)) {
        $error = 'Too many attempts. Try again in 15 minutes.';
    } else {
        $stmt = db()->prepare("SELECT * FROM admins WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password'])) {
            $error = 'Invalid email or password.';
        } elseif (!$admin['is_active']) {
            $error = 'This admin account has been deactivated.';
        } else {
            clearRateLimit(clientIp(), 'admin_login');
            clearRateLimit($email, 'admin_login');

            $_SESSION['admin_id'] = $admin['id'];
            db()->prepare("UPDATE admins SET last_login = NOW(), last_seen_at = NOW() WHERE id = ?")->execute([$admin['id']]);

            $redirect = $_GET['redirect'] ?? '/admin/dashboard.php';
            redirect(str_starts_with($redirect, '/admin') ? $redirect : '/admin/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — Kweek</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/kweek-icon-32.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
body{ background:#0F0E1C; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.login-card{ width:100%; max-width:380px; background:var(--paper); border-radius:var(--r-lg); padding:32px; box-shadow:0 30px 80px -20px rgba(0,0,0,.5); }
.login-brand{ text-align:center; margin-bottom:24px; }
.login-brand-name{ font-family:var(--font-display,inherit); font-size:24px; font-weight:800; color:var(--ink); letter-spacing:-.5px; }
.login-brand-sub{ font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--purple-600); margin-top:2px; }
.login-error{ display:flex; align-items:center; gap:8px; background:var(--danger-bg); color:var(--danger); border:1px solid rgba(220,38,38,.2); border-radius:var(--r-lg); padding:12px 14px; font-size:13px; margin-bottom:16px; }

/* ── this page doesn't include admin/includes/header.php, so it needs
   its own copy of the shared form styling rather than inheriting it ── */
.form-group{ display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
.form-label{ font-size:12.5px; font-weight:600; color:var(--ink); letter-spacing:.2px; }
.form-input-wrap{ position:relative; }
.form-input-wrap .inp-icon{ position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px; color:var(--muted-2); pointer-events:none; }
.form-input-wrap .form-input{ padding-left:38px; }
.form-input, .form-select, .form-textarea{
  width:100%; box-sizing:border-box; font-family:var(--font-body); font-size:14px; color:var(--ink);
  background:var(--surface); border:1.5px solid transparent; border-bottom:1.5px solid var(--line);
  border-radius:var(--r-sm); padding:11px 14px; outline:none; transition:all .15s;
}
.form-input::placeholder, .form-textarea::placeholder{ color:var(--muted-2); }
.form-input:focus, .form-select:focus, .form-textarea:focus{
  background:var(--paper); border-color:var(--purple-500); box-shadow:0 0 0 4px var(--purple-50);
}
.form-input:disabled, .form-select:disabled, .form-textarea:disabled{ opacity:.6; cursor:not-allowed; }
.form-hint{ font-size:11.5px; color:var(--muted); margin-top:2px; }
</style>
</head>
<body>
<div class="login-card">
  <div class="login-brand">
    <div class="login-brand-name">Kw<span style="color:var(--purple-500)">ee</span>k</div>
    <div class="login-brand-sub">Admin Panel</div>
  </div>

  <?php if ($error): ?>
  <div class="login-error"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <form method="POST">
    <?= csrfField() ?>
    <div class="form-group">
      <label class="form-label">Email</label>
      <div class="form-input-wrap">
        <i class="ti ti-mail inp-icon"></i>
        <input type="email" name="email" class="form-input" required autofocus autocomplete="username">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="form-input-wrap">
        <i class="ti ti-lock inp-icon"></i>
        <input type="password" name="password" class="form-input" required autocomplete="current-password">
      </div>
    </div>
    <button type="submit" class="btn btn-purple btn-lg" style="width:100%;justify-content:center;margin-top:6px">
      <i class="ti ti-login-2"></i> Sign in
    </button>
  </form>
</div>
</body>
</html>
