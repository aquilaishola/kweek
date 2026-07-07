<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect('/dashboard.php');

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = strtolower(trim(post('email')));

    if (!checkRateLimit(clientIp(), 'forgot', 3, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = db()->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            db()->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")
               ->execute([$token, $expires, $user['id']]);

            $resetLink = SITE_URL . "/reset-password.php?token=" . $token;
            $mailSent  = sendPasswordResetEmail($email, $user['name'], $resetLink);

            if (!$mailSent) {
                error_log("Kweek: failed to send reset email to $email");
            }
        }

        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — Kweek</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--p50:#F0EFFE;--p100:#DCD9FC;--p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p700:#3D33B8;--n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;--danger:#DC2626;--danger-bg:#FEE2E2;--success:#16A34A;--success-bg:#DCFCE7;--font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px}
body{font-family:var(--font-body);background:var(--n900);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:20px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:30px;font-weight:800;color:var(--n0);letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:44px;height:44px;border-radius:50%;border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.box{background:var(--n0);border-radius:var(--r-xl);padding:40px;width:100%;max-width:420px}
.box-logo{font-family:var(--font-display);font-weight:800;font-size:22px;color:var(--n900);text-decoration:none;letter-spacing:-.5px;display:block;margin-bottom:28px}
.box-logo .acc{color:var(--p600)}
.box h2{font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px;margin-bottom:8px}
.box p{font-size:14px;color:var(--n500);line-height:1.6;margin-bottom:24px}
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.form-label{font-size:13px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:12px 14px 12px 42px;outline:none;transition:all .2s;width:100%}
.form-input:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 4px var(--p50)}
.form-input::placeholder{color:var(--n400)}
.inp-ic{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--n400);pointer-events:none}
.error-box{background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:13px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--danger)}
.success-box{background:var(--success-bg);border:1px solid #BBF7D0;border-radius:var(--r-xl);padding:28px;text-align:center}
.success-ic{width:56px;height:56px;border-radius:50%;background:#DCFCE7;display:flex;align-items:center;justify-content:center;color:var(--success);font-size:26px;margin:0 auto 16px}
.success-box h3{font-family:var(--font-display);font-size:18px;font-weight:700;color:var(--n900);margin-bottom:8px}
.success-box p{font-size:14px;color:var(--n600);line-height:1.6}
.btn-submit{width:100%;padding:13px;background:var(--p600);color:var(--n0);border:none;border-radius:var(--r-md);font-family:var(--font-body);font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s}
.btn-submit:hover{background:var(--p700)}
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;font-size:13px;color:var(--n500);text-decoration:none;font-weight:500}
.back-link:hover{color:var(--p600)}
</style>
</head>
<body>
<div id="preloader"><div class="pre-logo">Kw<span class="acc">ee</span>k</div><div class="pre-ring"></div></div>
<div class="box">
  <a href="/index.html" class="box-logo">Kw<span class="acc">ee</span>k</a>
  <?php if ($sent): ?>
  <div class="success-box">
    <div class="success-ic"><i class="ti ti-mail-check"></i></div>
    <h3>Check your inbox</h3>
    <p>If an account exists for that email, we've sent a password reset link. Check your spam folder too.</p>
  </div>
  <?php else: ?>
  <h2>Forgot your password?</h2>
  <p>Enter your email address and we'll send you a link to reset your password.</p>
  <?php if ($error): ?>
  <div class="error-box"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <div class="form-group">
      <label class="form-label">Email address</label>
      <div class="inp-wrap">
        <i class="ti ti-mail inp-ic"></i>
        <input type="email" name="email" class="form-input" placeholder="you@email.com"
               value="<?= htmlspecialchars(post('email')) ?>" required autofocus>
      </div>
    </div>
    <button type="submit" class="btn-submit"><i class="ti ti-send"></i> Send reset link</button>
  </form>
  <?php endif; ?>
  <a href="/login.php" class="back-link"><i class="ti ti-arrow-left"></i> Back to sign in</a>
</div>
<script>window.addEventListener('load',()=>setTimeout(()=>document.getElementById('preloader').classList.add('hide'),800))</script>
</body>
</html>