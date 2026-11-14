<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (isAdminLoggedIn()) { header('Location: /admin/'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = strtolower(trim(post('email')));
    $password = post('password');
    $ip       = clientIp();

    if (!checkRateLimit($ip . ':admin_login', 'admin_login', 5, 900)) {
        $error = 'Too many failed attempts. Wait 15 minutes.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Enter your email and password.';
    } else {
        $stmt = db()->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            clearRateLimit($ip . ':admin_login', 'admin_login');
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_role'] = $admin['role'];
            session_regenerate_id(true);
            db()->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
            header('Location: /admin/');
            exit;
        } else {
            $error = 'Invalid credentials.';
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p700:#3D33B8;--p50:#F0EFFE;--n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;--danger:#DC2626;--danger-bg:#FEE2E2;--font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px}
body{font-family:var(--font-body);background:var(--n900);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:18px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:26px;font-weight:800;color:#fff;letter-spacing:-.5px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:38px;height:38px;border-radius:50%;border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.box{background:var(--n0);border-radius:var(--r-xl);padding:40px;width:100%;max-width:400px}
.box-logo{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:28px}
.logo-text{font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px}
.logo-text .acc{color:var(--p600)}
.admin-pill{font-size:10px;font-weight:700;background:var(--p50);color:var(--p700);padding:3px 10px;border-radius:var(--r-full);letter-spacing:.5px;text-transform:uppercase}
.box h2{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:6px;letter-spacing:-.3px;text-align:center}
.box p{font-size:13px;color:var(--n500);text-align:center;margin-bottom:24px}
.error-box{background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:11px 14px;margin-bottom:16px;display:flex;align-items:center;gap:7px;font-size:13px;color:var(--danger)}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-label{font-size:12px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px 11px 40px;outline:none;transition:all .2s;width:100%}
.form-input:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 3px var(--p50)}
.form-input::placeholder{color:var(--n400)}
.inp-ic{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}
.btn-submit{width:100%;padding:13px;background:var(--p600);color:#fff;border:none;border-radius:var(--r-md);font-family:var(--font-body);font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .2s;margin-top:4px}
.btn-submit:hover{background:var(--p700)}
.back-link{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:18px;font-size:12px;color:var(--n500);text-decoration:none}
.back-link:hover{color:var(--p600)}
</style>
</head>
<body>
<div id="preloader"><div class="pre-logo">Kw<span class="acc">ee</span>k</div><div class="pre-ring"></div></div>
<div class="box">
  <div class="box-logo">
    <span class="logo-text">Kw<span class="acc">ee</span>k</span>
    <span class="admin-pill">Admin</span>
  </div>
  <h2>Admin Sign In</h2>
  <p>Restricted access — authorized personnel only</p>
  <?php if ($error): ?>
  <div class="error-box"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <div class="form-group">
      <label class="form-label">Admin Email</label>
      <div class="inp-wrap">
        <i class="ti ti-mail inp-ic"></i>
        <input type="email" name="email" class="form-input" placeholder="admin@kweek.ng" value="<?= htmlspecialchars(post('email')) ?>" required autofocus>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="inp-wrap">
        <i class="ti ti-lock inp-ic"></i>
        <input type="password" name="password" class="form-input" placeholder="Your password" required>
      </div>
    </div>
    <button type="submit" class="btn-submit"><i class="ti ti-shield-lock"></i> Sign in to Admin</button>
  </form>
  <a href="/" class="back-link"><i class="ti ti-arrow-left"></i> Back to Kweek</a>
</div>
<script>window.addEventListener('load',()=>setTimeout(()=>document.getElementById('preloader').classList.add('hide'),700))</script>
</body>
</html>
