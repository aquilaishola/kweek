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

            // Invalidate any existing sessions for this user
            // (in production you'd use a session table; here we just mark done)

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
<title>Reset Password — Kweek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p50:#F0EFFE;--p100:#DCD9FC;--p400:#7B6FEE;--p500:#6457E8;
  --p600:#4F43D4;--p700:#3D33B8;--p900:#1C1660;
  --n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;
  --n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#16A34A;--success-bg:#DCFCE7;
  --danger:#DC2626;--danger-bg:#FEE2E2;--danger-border:#FECACA;
  --warning:#D97706;--warning-bg:#FEF3C7;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
}
html{scroll-behavior:smooth}
body{
  font-family:var(--font-body);background:var(--n900);
  min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:24px;-webkit-font-smoothing:antialiased;
}

/* PRELOADER */
#preloader{
  position:fixed;inset:0;background:var(--n900);z-index:9999;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;
  transition:opacity .5s ease,visibility .5s ease;
}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:28px;font-weight:800;color:#fff;letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{
  width:42px;height:42px;border-radius:50%;
  border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);
  animation:spin .8s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* BOX */
.box{
  background:var(--n0);border-radius:var(--r-xl);padding:40px;
  width:100%;max-width:420px;
  box-shadow:0 24px 64px rgba(0,0,0,.3);
}

/* LOGO */
.box-logo{
  display:flex;align-items:center;justify-content:center;
  gap:8px;margin-bottom:28px;
}
.logo-text{
  font-family:var(--font-display);font-size:22px;font-weight:800;
  color:var(--n900);text-decoration:none;letter-spacing:-.5px;
}
.logo-text .acc{color:var(--p600)}
.security-pill{
  font-size:10px;font-weight:700;background:var(--p50);color:var(--p700);
  padding:3px 10px;border-radius:var(--r-full);letter-spacing:.5px;text-transform:uppercase;
}

/* HEADINGS */
.box h2{
  font-family:var(--font-display);font-size:22px;font-weight:800;
  color:var(--n900);margin-bottom:6px;letter-spacing:-.4px;text-align:center;
}
.box-sub{font-size:13px;color:var(--n500);text-align:center;margin-bottom:24px;line-height:1.6}
.box-sub strong{color:var(--n800)}

/* MESSAGES */
.error-box{
  background:var(--danger-bg);border:1px solid var(--danger-border);
  border-radius:var(--r-lg);padding:12px 14px;margin-bottom:18px;
  display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--danger);
}
.error-box i{font-size:16px;flex-shrink:0;margin-top:1px}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.form-label{font-size:13px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input{
  font-family:var(--font-body);font-size:14px;color:var(--n800);
  background:var(--n50);border:1.5px solid var(--n200);
  border-radius:var(--r-md);padding:12px 44px 12px 40px;
  outline:none;transition:all .2s;width:100%;
}
.form-input:focus{
  background:var(--n0);border-color:var(--p500);
  box-shadow:0 0 0 4px var(--p50);
}
.form-input::placeholder{color:var(--n400)}
.inp-icon{
  position:absolute;left:12px;top:50%;transform:translateY(-50%);
  font-size:18px;color:var(--n400);pointer-events:none;
}
.inp-toggle{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;color:var(--n400);
  font-size:17px;padding:4px;transition:color .2s;
}
.inp-toggle:hover{color:var(--n700)}

/* STRENGTH BAR */
.strength-wrap{margin-top:6px}
.strength-track{
  height:4px;background:var(--n200);border-radius:var(--r-full);overflow:hidden;
}
.strength-fill{
  height:100%;border-radius:var(--r-full);width:0%;
  transition:width .35s ease,background .35s ease;
}
.strength-label{
  font-size:11px;font-weight:600;margin-top:4px;
  transition:color .35s ease;
}

/* REQUIREMENTS */
.req-list{
  list-style:none;display:flex;flex-direction:column;gap:5px;
  margin-bottom:20px;padding:12px 14px;
  background:var(--n50);border-radius:var(--r-md);border:1px solid var(--n200);
}
.req-item{
  display:flex;align-items:center;gap:7px;
  font-size:12px;color:var(--n500);transition:color .2s;
}
.req-item i{font-size:14px;color:var(--n300);transition:color .2s;flex-shrink:0}
.req-item.met{color:var(--success)}
.req-item.met i{color:var(--success)}

/* SUBMIT BUTTON */
.btn-submit{
  width:100%;padding:14px;background:var(--p600);color:#fff;
  border:none;border-radius:var(--r-md);font-family:var(--font-body);
  font-size:15px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  transition:all .2s;margin-top:4px;
}
.btn-submit:hover:not(:disabled){background:var(--p700);transform:translateY(-1px);box-shadow:0 8px 20px rgba(100,87,232,.3)}
.btn-submit:active:not(:disabled){transform:translateY(0)}
.btn-submit:disabled{opacity:.5;cursor:not-allowed}

/* SUCCESS STATE */
.success-wrap{text-align:center;padding:8px 0}
.success-icon{
  width:72px;height:72px;border-radius:50%;background:var(--success-bg);
  display:flex;align-items:center;justify-content:center;
  color:var(--success);font-size:32px;margin:0 auto 18px;
  animation:popIn .4s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes popIn{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
.success-wrap h2{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:8px}
.success-wrap p{font-size:14px;color:var(--n500);line-height:1.7;margin-bottom:24px}

/* INVALID STATE */
.invalid-wrap{text-align:center;padding:8px 0}
.invalid-icon{
  width:72px;height:72px;border-radius:50%;background:var(--danger-bg);
  display:flex;align-items:center;justify-content:center;
  color:var(--danger);font-size:32px;margin:0 auto 18px;
}
.invalid-wrap h2{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:8px}
.invalid-wrap p{font-size:14px;color:var(--n500);line-height:1.7;margin-bottom:24px}

/* BACK LINK */
.back-link{
  display:flex;align-items:center;justify-content:center;
  gap:6px;margin-top:20px;font-size:13px;color:var(--n500);
  text-decoration:none;font-weight:500;transition:color .2s;
}
.back-link:hover{color:var(--p600)}

.btn-outline-full{
  display:flex;align-items:center;justify-content:center;gap:7px;
  width:100%;padding:13px;border-radius:var(--r-md);
  font-family:var(--font-body);font-size:14px;font-weight:600;
  color:var(--n700);background:var(--n0);border:1.5px solid var(--n200);
  cursor:pointer;text-decoration:none;transition:all .2s;
}
.btn-outline-full:hover{border-color:var(--p300);color:var(--p700);background:var(--p50)}
</style>
</head>
<body>

<!-- PRELOADER -->
<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
</div>

<div class="box">

  <!-- LOGO -->
  <div class="box-logo">
    <a href="/" class="logo-text">Kw<span class="acc">ee</span>k</a>
    <span class="security-pill">Security</span>
  </div>

  <?php if ($step === 'done'): ?>
  <!-- ── SUCCESS ─────────────────────────────────────────────── -->
  <div class="success-wrap">
    <div class="success-icon"><i class="ti ti-lock-check"></i></div>
    <h2>Password updated!</h2>
    <p>
      Your Kweek password has been changed successfully.
      You can now sign in with your new password.
    </p>
    <a href="/login.php" class="btn-submit" style="text-decoration:none">
      <i class="ti ti-login"></i> Sign in now
    </a>
    <?php if ($user): ?>
    <p style="font-size:12px;color:var(--n400);margin-top:14px;line-height:1.5">
      A confirmation has been sent to <strong><?= htmlspecialchars($user['email']) ?></strong>
    </p>
    <?php endif; ?>
  </div>

  <?php elseif ($step === 'form'): ?>
  <!-- ── RESET FORM ──────────────────────────────────────────── -->
  <h2>Set new password</h2>
  <p class="box-sub">
    Resetting password for <strong><?= htmlspecialchars($user['email']) ?></strong>
  </p>

  <?php if ($error): ?>
  <div class="error-box">
    <i class="ti ti-alert-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <form method="POST" id="reset-form" novalidate>
    <?= csrfField() ?>

    <div class="form-group">
      <label class="form-label">New Password</label>
      <div class="inp-wrap">
        <i class="ti ti-lock inp-icon"></i>
        <input
          type="password"
          name="password"
          id="pw"
          class="form-input"
          placeholder="Min. 8 characters"
          autocomplete="new-password"
          oninput="checkStrength(this.value)"
          required
        >
        <button type="button" class="inp-toggle" onclick="togglePw('pw', this)" aria-label="Toggle password visibility">
          <i class="ti ti-eye"></i>
        </button>
      </div>
      <!-- STRENGTH BAR -->
      <div class="strength-wrap">
        <div class="strength-track">
          <div class="strength-fill" id="strength-fill"></div>
        </div>
        <div class="strength-label" id="strength-label" style="color:var(--n400)">Enter a password</div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Confirm New Password</label>
      <div class="inp-wrap">
        <i class="ti ti-lock-check inp-icon"></i>
        <input
          type="password"
          name="confirm_password"
          id="pw2"
          class="form-input"
          placeholder="Repeat your new password"
          autocomplete="new-password"
          oninput="checkMatch()"
          required
        >
        <button type="button" class="inp-toggle" onclick="togglePw('pw2', this)" aria-label="Toggle confirm visibility">
          <i class="ti ti-eye"></i>
        </button>
      </div>
      <div id="match-msg" style="font-size:12px;margin-top:4px;display:none"></div>
    </div>

    <!-- REQUIREMENTS -->
    <ul class="req-list" id="req-list">
      <li class="req-item" id="req-len">
        <i class="ti ti-circle"></i> At least 8 characters
      </li>
      <li class="req-item" id="req-letter">
        <i class="ti ti-circle"></i> Contains a letter
      </li>
      <li class="req-item" id="req-num">
        <i class="ti ti-circle"></i> Contains a number
      </li>
      <li class="req-item" id="req-match">
        <i class="ti ti-circle"></i> Passwords match
      </li>
    </ul>

    <button type="submit" class="btn-submit" id="submit-btn" disabled>
      <i class="ti ti-lock"></i>
      <span id="btn-label">Set new password</span>
    </button>
  </form>

  <?php else: ?>
  <!-- ── INVALID TOKEN ───────────────────────────────────────── -->
  <div class="invalid-wrap">
    <div class="invalid-icon"><i class="ti ti-link-off"></i></div>
    <h2>Link expired or invalid</h2>
    <p>
      This password reset link has expired or has already been used.
      Reset links are valid for <strong>1 hour</strong>.
    </p>
    <a href="/forgot-password.php" class="btn-submit" style="text-decoration:none">
      <i class="ti ti-send"></i> Request new reset link
    </a>
  </div>
  <?php endif; ?>

  <a href="/login.php" class="back-link">
    <i class="ti ti-arrow-left"></i> Back to sign in
  </a>

</div>

<script>
// Preloader
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader').classList.add('hide'), 700);
});

// Toggle password visibility
function togglePw(id, btn) {
  const inp  = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type       = 'text';
    icon.className = 'ti ti-eye-off';
  } else {
    inp.type       = 'password';
    icon.className = 'ti ti-eye';
  }
}

// Password strength checker
function checkStrength(val) {
  const fill   = document.getElementById('strength-fill');
  const label  = document.getElementById('strength-label');
  const reqLen = document.getElementById('req-len');
  const reqLet = document.getElementById('req-letter');
  const reqNum = document.getElementById('req-num');

  const hasLen    = val.length >= 8;
  const hasLetter = /[A-Za-z]/.test(val);
  const hasNumber = /[0-9]/.test(val);
  const hasSpec   = /[^A-Za-z0-9]/.test(val);

  // Update requirement items
  setReq(reqLen, hasLen);
  setReq(reqLet, hasLetter);
  setReq(reqNum, hasNumber);

  // Score
  let score = [hasLen, hasLetter, hasNumber, hasSpec].filter(Boolean).length;

  const configs = [
    { w: '0%',   color: '',              text: 'Enter a password',    tc: 'var(--n400)' },
    { w: '25%',  color: 'var(--danger)', text: 'Too weak',            tc: 'var(--danger)' },
    { w: '50%',  color: 'var(--warning)',text: 'Fair',                tc: 'var(--warning)' },
    { w: '75%',  color: '#16A34A',       text: 'Good',                tc: '#16A34A' },
    { w: '100%', color: '#15803D',       text: 'Strong',              tc: '#15803D' },
  ];

  const cfg = configs[val.length === 0 ? 0 : score];
  fill.style.width      = cfg.w;
  fill.style.background = cfg.color;
  label.textContent     = cfg.text;
  label.style.color     = cfg.tc;

  checkMatch();
  updateSubmitBtn();
}

function setReq(el, met) {
  const icon = el.querySelector('i');
  el.classList.toggle('met', met);
  icon.className = met ? 'ti ti-circle-check' : 'ti ti-circle';
}

function checkMatch() {
  const pw1 = document.getElementById('pw').value;
  const pw2 = document.getElementById('pw2').value;
  const msg = document.getElementById('match-msg');
  const req = document.getElementById('req-match');

  if (!pw2) {
    msg.style.display = 'none';
    setReq(req, false);
    updateSubmitBtn();
    return;
  }

  const match = pw1 === pw2;
  msg.style.display = 'block';

  if (match) {
    msg.innerHTML  = '<span style="color:var(--success)"><i class="ti ti-circle-check" style="vertical-align:-2px"></i> Passwords match</span>';
  } else {
    msg.innerHTML  = '<span style="color:var(--danger)"><i class="ti ti-alert-circle" style="vertical-align:-2px"></i> Passwords do not match</span>';
  }

  setReq(req, match);
  updateSubmitBtn();
}

function updateSubmitBtn() {
  const pw      = document.getElementById('pw').value;
  const confirm = document.getElementById('pw2').value;
  const btn     = document.getElementById('submit-btn');

  const valid = pw.length >= 8
    && /[A-Za-z]/.test(pw)
    && /[0-9]/.test(pw)
    && pw === confirm;

  btn.disabled = !valid;
}

// Loading state on submit
document.getElementById('reset-form')?.addEventListener('submit', function() {
  const btn   = document.getElementById('submit-btn');
  const label = document.getElementById('btn-label');
  btn.disabled = true;
  label.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px">'
    + '<span style="width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.3);'
    + 'border-top-color:#fff;animation:spin .7s linear infinite;display:inline-block"></span>'
    + ' Updating password...</span>';
});
</script>

</body>
</html>
