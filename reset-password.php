<?php
require_once __DIR__ . '/includes/functions.php';

// Already logged in — no need to reset
if (isLoggedIn()) redirect('/dashboard.php');

$token = clean(get('token', ''));
$step  = 'invalid'; // invalid | form | done
$error = '';
$user  = null;

// ── VALIDATE TOKEN ────────────────────────────────────────────
if (!empty($token)) {
    $stmt = db()->prepare("
        SELECT id, name, email, reset_token, reset_expires
        FROM users
        WHERE reset_token = ?
          AND reset_expires > NOW()
          AND status != 'suspended'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $step = 'form';
    }
}

// ── HANDLE FORM SUBMIT ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'form') {
    verifyCsrf();

    if (!checkRateLimit(clientIp(), 'reset_pw', 5, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $newPassword = post('password');
        $confirm     = post('confirm_password');

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $error = 'Password must contain at least one letter and one number.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            // Update password and clear reset token
            db()->prepare("
                UPDATE users
                SET password = ?, reset_token = NULL, reset_expires = NULL, remember_token = NULL
                WHERE id = ?
            ")->execute([$hash, $user['id']]);

            clearRateLimit(clientIp(), 'reset_pw');

            $step = 'done';

            // Send confirmation email
            if (function_exists('sendNotificationEmail')) {
                sendNotificationEmail(
                    $user,
                    'Password changed',
                    'Your Kweek password was just changed. If this was not you, contact us immediately at support@kweek.ng.',
                    '/login.php'
                );
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset your password — Kweek</title>
<meta name="description" content="Set a new password for your Kweek account.">
<meta name="robots" content="noindex, nofollow">
<link rel="canonical" href="https://kweek.ng/reset-password.php">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kweek.ng/reset-password.php">
<meta property="og:title" content="Reset your password — Kweek">
<meta property="og:description" content="Set a new password for your Kweek account.">
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

  <?php if ($step === 'form'): ?>
    <div class="kw-eyebrow">Account recovery</div>
    <h1 class="kw-headline">Almost there. <span>Choose a new password.</span></h1>
  <?php endif; ?>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">

      <?php if ($step === 'done'): ?>
        <!-- ── SUCCESS ─────────────────────────────────────────── -->
        <div class="kw-success-block">
          <div class="kw-success-ic"><i class="ti ti-lock-check"></i></div>
          <h3>Password updated</h3>
          <p>Your Kweek password has been changed successfully. You can now sign in with your new password.</p>
        </div>
        <a href="/login.php" class="kw-btn" style="text-decoration:none;margin-top:6px">
          <i class="ti ti-login"></i> Sign in now
        </a>
        <?php if ($user): ?>
        <p style="font-size:11.5px;color:var(--muted-2);text-align:center;margin-top:14px;">
          A confirmation was sent to <strong><?= htmlspecialchars($user['email']) ?></strong>
        </p>
        <?php endif; ?>

      <?php elseif ($step === 'form'): ?>
        <!-- ── RESET FORM ──────────────────────────────────────── -->
        <div class="kw-form-header">
          <div>
            <h2>Set new password</h2>
            <p>For <strong><?= htmlspecialchars($user['email']) ?></strong></p>
          </div>
          <span class="kw-stamp"><i class="ti ti-shield-check"></i> secure</span>
        </div>

        <?php if ($error): ?>
        <div class="kw-alert"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" id="reset-form" novalidate>
          <?= csrfField() ?>

          <div class="kw-group">
            <label class="kw-label">New password</label>
            <div class="kw-input-wrap">
              <input type="password" name="password" id="pw" class="kw-input" placeholder="Min. 8 characters" autocomplete="new-password" required>
              <button type="button" class="kw-toggle" onclick="kwTogglePw('pw', this)"><i class="ti ti-eye"></i></button>
            </div>
            <div class="kw-strength"><div class="kw-strength-fill" id="strength-fill"></div></div>
          </div>

          <div class="kw-group">
            <label class="kw-label">Confirm new password</label>
            <div class="kw-input-wrap">
              <input type="password" name="confirm_password" id="pw2" class="kw-input" placeholder="Repeat your new password" autocomplete="new-password" required>
              <button type="button" class="kw-toggle" onclick="kwTogglePw('pw2', this)"><i class="ti ti-eye"></i></button>
            </div>
            <div class="kw-msg" id="pw2-msg"></div>
          </div>

          <div class="kw-checklist" id="pw-checklist">
            <span class="kw-rule" id="rule-len"><i class="ti ti-circle"></i> 8+ characters</span>
            <span class="kw-rule" id="rule-letter"><i class="ti ti-circle"></i> Letter</span>
            <span class="kw-rule" id="rule-num"><i class="ti ti-circle"></i> Number</span>
            <span class="kw-rule" id="rule-match"><i class="ti ti-circle"></i> Match</span>
          </div>

          <button type="submit" class="kw-btn" id="submit-btn" disabled style="margin-top:20px">
            <i class="ti ti-lock"></i> <span id="btn-label">Set new password</span>
          </button>
        </form>

      <?php else: ?>
        <!-- ── INVALID TOKEN ───────────────────────────────────── -->
        <div class="kw-success-block">
          <div class="kw-success-ic" style="background:var(--danger-bg);color:var(--danger)"><i class="ti ti-link-off"></i></div>
          <h3>Link expired or invalid</h3>
          <p>This password reset link has expired or has already been used. Reset links are valid for <strong>1 hour</strong>.</p>
        </div>
        <a href="/forgot-password.php" class="kw-btn" style="text-decoration:none;margin-top:6px">
          <i class="ti ti-send"></i> Request new reset link
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="kw-below"><a href="/login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a></div>
</div>

<script>
function kwTogglePw(id, btn){
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.innerHTML = `<i class="ti ti-${isText ? 'eye' : 'eye-off'}"></i>`;
}

const pwInput  = document.getElementById('pw');
const pw2Input = document.getElementById('pw2');

if (pwInput) {
  const fill = document.getElementById('strength-fill');
  const rules = {
    len:    v => v.length >= 8,
    letter: v => /[A-Za-z]/.test(v),
    num:    v => /[0-9]/.test(v),
  };

  function setRule(id, ok){
    const el = document.getElementById(id);
    el.classList.toggle('met', ok);
    el.querySelector('i').className = ok ? 'ti ti-circle-check' : 'ti ti-circle';
  }

  function updateAll(){
    const v = pwInput.value;
    let met = 0;
    for (const key in rules){ const ok = rules[key](v); setRule('rule-' + key, ok); if (ok) met++; }

    const match = pw2Input.value.length > 0 && pwInput.value === pw2Input.value;
    setRule('rule-match', match);

    const msg = document.getElementById('pw2-msg');
    if (!pw2Input.value) { msg.textContent = ''; msg.className = 'kw-msg'; }
    else { msg.textContent = match ? 'Passwords match' : "Passwords don't match"; msg.className = 'kw-msg ' + (match ? 'ok' : 'err'); }

    const colors = ['', '#DC2626', '#D97706', '#1E9E5A'];
    const widths = ['0%','33%','66%','100%'];
    fill.style.width = widths[met];
    fill.style.background = colors[met];

    const valid = rules.len(v) && rules.letter(v) && rules.num(v) && match;
    document.getElementById('submit-btn').disabled = !valid;
  }

  pwInput.addEventListener('input', updateAll);
  pw2Input.addEventListener('input', updateAll);

  document.getElementById('reset-form').addEventListener('submit', function(){
    const btn = document.getElementById('submit-btn');
    document.getElementById('btn-label').innerHTML = '';
    btn.innerHTML = '<div class="kw-spinner"></div> Updating password...';
    btn.disabled = true;
  });
}
</script>
</body>
</html>