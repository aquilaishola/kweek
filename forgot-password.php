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
<title>Reset your password — Kweek</title>
<meta name="description" content="Reset the password for your Kweek account.">
<meta name="robots" content="noindex, nofollow">
<link rel="canonical" href="https://kweek.ng/forgot-password.php">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kweek.ng/forgot-password.php">
<meta property="og:title" content="Reset your password — Kweek">
<meta property="og:description" content="Reset the password for your Kweek account.">
<meta property="og:image" content="https://kweek.ng/assets/img/kweek-og-banner-1200x630.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="en_NG">
<meta property="og:site_name" content="Kweek">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Reset your password — Kweek">
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

  <?php if (!$sent): ?>
    <div class="kw-eyebrow">Account recovery</div>
    <h1 class="kw-headline">Locked out happens. <span>Let's fix it.</span></h1>
  <?php endif; ?>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">

      <?php if ($sent): ?>
        <div class="kw-success-block">
          <div class="kw-success-ic"><i class="ti ti-mail-check"></i></div>
          <h3>Check your inbox</h3>
          <p>If an account exists for that email, we've sent a password reset link. Check your spam folder too.</p>
        </div>
      <?php else: ?>
        <div class="kw-form-header">
          <div>
            <h2>Forgot your password?</h2>
            <p>Enter your email and we'll send a reset link.</p>
          </div>
          <span class="kw-stamp"><i class="ti ti-shield-check"></i> secure</span>
        </div>

        <?php if ($error): ?>
        <div class="kw-alert"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" id="forgot-form" novalidate>
          <?= csrfField() ?>
          <div class="kw-group">
            <label class="kw-label">Email address</label>
            <div class="kw-input-wrap">
              <input type="email" name="email" id="email" class="kw-input" placeholder="you@email.com" value="<?= htmlspecialchars(post('email')) ?>" required autofocus>
              <i class="ti ti-circle-check kw-status" id="email-status"></i>
            </div>
            <div class="kw-msg" id="email-msg"></div>
          </div>
          <button type="submit" class="kw-btn" data-loading-text="Sending..." id="submit-btn">
            <i class="ti ti-send"></i> Send reset link
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="kw-below"><a href="/login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a></div>
</div>

<script>
const emailInput = document.getElementById('email');
if (emailInput) {
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
}
</script>
<script src="/assets/js/kw-forms.js"></script>
</body>
</html>