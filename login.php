<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect('/dashboard.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = strtolower(trim(post('email')));
    $password = post('password');
    $remember = !empty($_POST['remember']);
    $ip       = clientIp();

    if (!checkRateLimit($ip . ':login', 'login', 5, 900)) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
       $stmt = db()->prepare("SELECT id, name, email, password, status, plan, profile_completed FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'suspended') {
                $error = 'Your account has been suspended. Contact support@kweek.ng.';
            } else {
                clearRateLimit($ip . ':login', 'login');

                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                session_regenerate_id(true);

              if ($remember) {
    $token = bin2hex(random_bytes(32));
    db()->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]);
    setcookie('kweek_remember', $token, time() + (86400 * 30), '/', '', true, true);
}

if (empty($user['profile_completed'])) {
    redirect('/onboarding.php');
}

$redirect = clean(get('redirect', '/dashboard.php'));
if (!str_starts_with($redirect, '/')) $redirect = '/dashboard.php';
redirect($redirect);
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Kweek | Get paid instantly, stay organized</title>
<meta name="description" content="Sign in to your Kweek account to manage payment links, track instant payments, and issue verified receipts.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="https://kweek.ng/login.php">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kweek.ng/login.php">
<meta property="og:title" content="Sign in — Kweek">
<meta property="og:description" content="Sign in to manage your Kweek payment links and verified receipts.">
<meta property="og:image" content="https://kweek.ng/assets/img/kweek-og-banner-1200x630.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="en_NG">
<meta property="og:site_name" content="Kweek">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Sign in — Kweek">
<meta name="twitter:description" content="Sign in to manage your Kweek payment links and verified receipts.">
<meta name="twitter:image" content="https://kweek.ng/assets/img/kweek-og-banner-1200x630.png">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/kweek-icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/kweek-icon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="/assets/img/kweek-icon-192.png">
<meta name="theme-color" content="#0F0E1C">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>

<?php include __DIR__ . '/partials/preloader.php'; ?>

<div class="kw-canvas"></div>

<div class="kw-shell">
  <a href="/index.php" class="kw-brand">
    <img src="/assets/img/kweek-lockup-dark.png" alt="Kweek">
  </a>

  <div class="kw-eyebrow">Welcome back</div>
  <h1 class="kw-headline">Your orders, your money, <span>one tap away.</span></h1>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">

      <div class="kw-form-header">
        <div>
          <h2>Sign in</h2>
          <p>New to Kweek? <a href="/register.php">Create a free account</a></p>
        </div>
        <span class="kw-stamp"><i class="ti ti-shield-check"></i> secure</span>
      </div>

     <?php if ($error): ?>
<div class="kw-alert"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
<?php endif; ?>
<?php if ($f = getFlash()): ?>
<div class="kw-alert"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($f['message']) ?></span></div>
<?php endif; ?>
      
      <a href="/google-auth.php"  data-loading-text="Redirecting..." style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:12px 16px;border:1.5px solid #E4E3EE;border-radius:12px;background:#fff;color:#0F0E1C;font-size:14px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .15s" onmouseover="this.style.background='#F9F9FB'" onmouseout="this.style.background='#fff'">
  <svg width="18" height="18" viewBox="0 0 48 48">
    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.5z"/>
    <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4c-7.7 0-14.3 4.3-17.7 10.7z"/>
    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.1-11.3-7.6l-6.5 5C9.6 39.6 16.3 44 24 44z"/>
    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.2 5.6l6.2 5.2C40.9 36.4 44 30.9 44 24c0-1.3-.1-2.7-.4-3.5z"/>
  </svg>
  Continue with Google
</a>

<div style="display:flex;align-items:center;gap:12px;margin:0 0 22px;color:#9896B0;font-size:12px;font-family:Arial,Helvetica,sans-serif">
  <div style="flex:1;height:1px;background:#F0EFF6"></div>
  or sign in with email
  <div style="flex:1;height:1px;background:#F0EFF6"></div>
</div>

      <form method="POST" id="login-form" novalidate>
        <?= csrfField() ?>

        <div class="kw-group">
          <label class="kw-label">Email address</label>
          <div class="kw-input-wrap">
            <input type="email" name="email" id="email" class="kw-input" placeholder="you@email.com" value="<?= htmlspecialchars(post('email')) ?>" required autofocus>
            <i class="ti ti-circle-check kw-status" id="email-status"></i>
          </div>
          <div class="kw-msg" id="email-msg"></div>
        </div>

        <div class="kw-group">
          <label class="kw-label">Password</label>
          <div class="kw-input-wrap">
            <input type="password" name="password" id="pw" class="kw-input" placeholder="Your password" required>
            <button type="button" class="kw-toggle" onclick="kwTogglePw('pw', this)"><i class="ti ti-eye"></i></button>
          </div>
        </div>

        <div class="kw-row-split">
          <label class="kw-checkbox">
            <input type="checkbox" name="remember" value="1">
            <span>Remember me for 30 days</span>
          </label>
          <a href="/forgot-password.php" class="kw-link">Forgot password?</a>
        </div>

        <button type="submit" class="kw-btn" id="submit-btn" data-loading-text="Signing in...">
  <i class="ti ti-login"></i> Sign in to Kweek
</button>
      </form>
    </div>
  </div>

  <div class="kw-below">Trouble signing in? <a href="mailto:support@kweek.ng">Talk to support</a></div>
</div>

<script>
function kwTogglePw(id, btn){
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.innerHTML = `<i class="ti ti-${isText ? 'eye' : 'eye-off'}"></i>`;
}

const emailInput = document.getElementById('email');
const emailStatus = document.getElementById('email-status');
const emailMsg = document.getElementById('email-msg');
const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

emailInput.addEventListener('input', () => {
  const val = emailInput.value.trim();
  if (val.length === 0) {
    emailInput.classList.remove('is-valid','is-invalid');
    emailStatus.classList.remove('show-valid','show-invalid');
    emailMsg.textContent = ''; emailMsg.className = 'kw-msg';
    return;
  }
  if (emailRe.test(val)) {
    emailInput.classList.add('is-valid'); emailInput.classList.remove('is-invalid');
    emailStatus.className = 'ti ti-circle-check kw-status show-valid';
    emailMsg.textContent = ''; emailMsg.className = 'kw-msg';
  } else {
    emailInput.classList.add('is-invalid'); emailInput.classList.remove('is-valid');
    emailStatus.className = 'ti ti-alert-circle kw-status show-invalid';
    emailMsg.textContent = 'Enter a valid email address'; emailMsg.className = 'kw-msg err';
  }
});

</script>

<script src="/assets/js/kw-forms.js"></script>
</body>
</html>