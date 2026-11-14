<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect('/dashboard.php');

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name     = clean(post('name'));
    $email    = strtolower(trim(post('email')));
    $phone    = preg_replace('/\D/', '', post('phone'));
    $password = post('password');
    $confirm  = post('confirm_password');
    $plan     = in_array(post('plan'), ['free','pro','business']) ? post('plan') : 'free';

    if (!checkRateLimit(clientIp(), 'register', 10, 3600)) {
        $errors[] = 'Too many registration attempts. Please try again in an hour.';
    }

    if (empty($name) || strlen($name) < 2)           $errors[] = 'Enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Enter a valid email address.';
    if (strlen($phone) < 10)                          $errors[] = 'Enter a valid Nigerian phone number.';
    if (strlen($password) < 8)                        $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                        $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $db = db();

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email or phone already exists.';
        } else {
            $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $token = bin2hex(random_bytes(32));

           $db->prepare("INSERT INTO users (name, email, phone, password, plan, status, email_token, profile_completed) VALUES (?, ?, ?, ?, ?, 'pending', ?, 0)")
   ->execute([$name, $email, $phone, $hash, $plan, $token]);

            $userId = (int)$db->lastInsertId();

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;

if ($plan !== 'free') {
    $trialEnd = date('Y-m-d H:i:s', strtotime('+7 days'));
    $db->prepare("UPDATE users SET trial_ends_at = ?, plan_expires_at = ?, status = 'active' WHERE id = ?")
       ->execute([$trialEnd, $trialEnd, $userId]);
    $db->prepare("INSERT INTO subscriptions (user_id, plan, billing_cycle, amount, status, starts_at, ends_at) VALUES (?, ?, 'monthly', ?, 'trial', NOW(), ?)")
       ->execute([$userId, $plan, $plan === 'pro' ? PRICE_PRO_MONTHLY : PRICE_BUSINESS_MONTHLY, $trialEnd]);
} else {
    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);
}

$verifyLink = rtrim(SITE_URL, '/') . '/verify-email.php?token=' . $token;
sendVerificationEmail(['name' => $name, 'email' => $email], $verifyLink);

flash('success', 'Welcome to Kweek! Please verify your email to continue.');
redirect('/verify-email-notice.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create your account — Kweek | Get paid instantly, stay organized</title>
<meta name="description" content="Create a free Kweek account in under 2 minutes. Build payment links, get instant payment alerts, and issue receipts your customers can't fake.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="https://kweek.ng/register.php">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kweek.ng/register.php">
<meta property="og:title" content="Create your account — Kweek">
<meta property="og:description" content="Free forever. Create a payment link in 30 seconds and get paid today.">
<meta property="og:image" content="https://kweek.ng/assets/img/kweek-og-banner-1200x630.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="en_NG">
<meta property="og:site_name" content="Kweek">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Create your account — Kweek">
<meta name="twitter:description" content="Free forever. Create a payment link in 30 seconds and get paid today.">
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

  <div class="kw-eyebrow">Free forever</div>
  <h1 class="kw-headline">Your first payment link is <span>two minutes away.</span></h1>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">

      <div class="kw-form-header">
        <div>
          <h2>Create your account</h2>
          <p>Already on Kweek? <a href="/login.php">Sign in</a></p>
        </div>
        <span class="kw-stamp"><i class="ti ti-shield-check"></i> secure</span>
      </div>

      <?php if (!empty($errors)): ?>
      <div class="kw-alert">
        <i class="ti ti-alert-circle"></i>
        <span>
          <?php foreach ($errors as $i => $e): ?>
            <?= htmlspecialchars($e) ?><?= $i < count($errors) - 1 ? '<br>' : '' ?>
          <?php endforeach; ?>
        </span>
      </div>
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

      <form method="POST" id="reg-form" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="plan" value="free">

        <div class="kw-row2">
<div class="kw-group">
  <label class="kw-label">Full name</label>
  <div class="kw-input-wrap">
    <input type="text" name="name" id="name" class="kw-input" placeholder="Temi Adeyemi" value="<?= htmlspecialchars(post('name')) ?>" required>
    <i class="ti ti-circle-check kw-status" id="name-status"></i>
  </div>
  <div class="kw-msg" id="name-msg"></div>
  <small class="kw-hint" style="display:flex;align-items:center;gap:5px;margin-top:4px;font-size:12px;color:#8A899B;">
    <i class="ti ti-info-circle" style="font-size:13px;"></i>
    Use the real name on your government ID 
  </small>
</div>
          <div class="kw-group">
            <label class="kw-label">Phone number</label>
            <div class="kw-input-wrap">
              <input type="tel" name="phone" id="phone" class="kw-input" placeholder="08012345678" value="<?= htmlspecialchars(post('phone')) ?>" required>
              <i class="ti ti-circle-check kw-status" id="phone-status"></i>
            </div>
            <div class="kw-msg" id="phone-msg"></div>
          </div>
        </div>

        <div class="kw-group">
          <label class="kw-label">Email address</label>
          <div class="kw-input-wrap">
            <input type="email" name="email" id="email" class="kw-input" placeholder="you@email.com" value="<?= htmlspecialchars(post('email')) ?>" required>
            <i class="ti ti-circle-check kw-status" id="email-status"></i>
          </div>
          <div class="kw-msg" id="email-msg"></div>
        </div>

        <div class="kw-row2">
          <div class="kw-group">
            <label class="kw-label">Password</label>
            <div class="kw-input-wrap">
              <input type="password" name="password" id="pw" class="kw-input" placeholder="Min. 8 characters" required>
              <button type="button" class="kw-toggle" onclick="kwTogglePw('pw', this)"><i class="ti ti-eye"></i></button>
            </div>
            <div class="kw-strength"><div class="kw-strength-fill" id="strength-fill"></div></div>
          </div>
          <div class="kw-group">
            <label class="kw-label">Confirm password</label>
            <div class="kw-input-wrap">
              <input type="password" name="confirm_password" id="pw2" class="kw-input" placeholder="Repeat password" required>
              <button type="button" class="kw-toggle" onclick="kwTogglePw('pw2', this)"><i class="ti ti-eye"></i></button>
            </div>
            <div class="kw-msg" id="pw2-msg"></div>
          </div>
        </div>

        <div class="kw-checklist" id="pw-checklist">
          <span class="kw-rule" id="rule-len"><i class="ti ti-circle"></i> 8+ characters</span>
          <span class="kw-rule" id="rule-upper"><i class="ti ti-circle"></i> Uppercase</span>
          <span class="kw-rule" id="rule-num"><i class="ti ti-circle"></i> Number</span>
          <span class="kw-rule" id="rule-sym"><i class="ti ti-circle"></i> Symbol</span>
        </div>

        <button type="submit" class="kw-btn" id="submit-btn" data-loading-text="Creating account..." style="margin-top:22px">
          <i class="ti ti-user-plus"></i> Create my account
        </button>

        <p class="kw-terms">By creating an account you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>. We comply with CBN regulations and AML/KYC requirements.</p>
      </form>
    </div>
  </div>

  <div class="kw-below">Need help getting set up? <a href="mailto:support@kweek.ng">Talk to support</a></div>
</div>

<script>
function kwTogglePw(id, btn){
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.innerHTML = `<i class="ti ti-${isText ? 'eye' : 'eye-off'}"></i>`;
}

function setFieldState(inputEl, statusEl, msgEl, valid, msg) {
  if (valid === null) {
    inputEl.classList.remove('is-valid','is-invalid');
    statusEl.className = 'ti kw-status';
    msgEl.textContent = ''; msgEl.className = 'kw-msg';
    return;
  }
  if (valid) {
    inputEl.classList.add('is-valid'); inputEl.classList.remove('is-invalid');
    statusEl.className = 'ti ti-circle-check kw-status show-valid';
    msgEl.textContent = msg || ''; msgEl.className = 'kw-msg ok';
  } else {
    inputEl.classList.add('is-invalid'); inputEl.classList.remove('is-valid');
    statusEl.className = 'ti ti-alert-circle kw-status show-invalid';
    msgEl.textContent = msg || ''; msgEl.className = 'kw-msg err';
  }
}

const nameInput = document.getElementById('name');
nameInput.addEventListener('input', () => {
  const val = nameInput.value.trim();
  if (!val) return setFieldState(nameInput, document.getElementById('name-status'), document.getElementById('name-msg'), null);
  const valid = val.length >= 2 && val.includes(' ');
  setFieldState(nameInput, document.getElementById('name-status'), document.getElementById('name-msg'), valid, valid ? '' : 'Enter your full name');
});

const phoneInput = document.getElementById('phone');
phoneInput.addEventListener('input', () => {
  const raw = phoneInput.value.trim();
  if (!raw) return setFieldState(phoneInput, document.getElementById('phone-status'), document.getElementById('phone-msg'), null);
  const digits = raw.replace(/\D/g,'');
  const valid = /^0\d{10}$/.test(digits) || /^234\d{10}$/.test(digits) || /^\+234\d{10}$/.test(raw.replace(/\s/g,''));
  setFieldState(phoneInput, document.getElementById('phone-status'), document.getElementById('phone-msg'), valid, valid ? '' : 'Use format 0801 234 5678');
});

const emailInput = document.getElementById('email');
const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
emailInput.addEventListener('input', () => {
  const val = emailInput.value.trim();
  if (!val) return setFieldState(emailInput, document.getElementById('email-status'), document.getElementById('email-msg'), null);
  const valid = emailRe.test(val);
  setFieldState(emailInput, document.getElementById('email-status'), document.getElementById('email-msg'), valid, valid ? '' : 'Enter a valid email address');
});

const pwInput = document.getElementById('pw');
const pw2Input = document.getElementById('pw2');
const fill = document.getElementById('strength-fill');
const rules = {
  len:   v => v.length >= 8,
  upper: v => /[A-Z]/.test(v),
  num:   v => /[0-9]/.test(v),
  sym:   v => /[^A-Za-z0-9]/.test(v)
};

function updateChecklist(val) {
  let met = 0;
  for (const key in rules) {
    const ok = rules[key](val);
    const el = document.getElementById('rule-' + key);
    el.classList.toggle('met', ok);
    el.querySelector('i').className = ok ? 'ti ti-circle-check' : 'ti ti-circle';
    if (ok) met++;
  }
  const colors = ['', '#DC2626', '#D97706', '#D97706', '#1E9E5A'];
  const widths = ['0%','25%','50%','75%','100%'];
  fill.style.width = widths[met];
  fill.style.background = colors[met];
  return met;
}

pwInput.addEventListener('input', () => {
  updateChecklist(pwInput.value);
  if (pw2Input.value) checkMatch();
});

function checkMatch() {
  const msg = document.getElementById('pw2-msg');
  if (!pw2Input.value) { pw2Input.classList.remove('is-valid','is-invalid'); msg.textContent=''; msg.className='kw-msg'; return; }
  const match = pwInput.value === pw2Input.value;
  pw2Input.classList.toggle('is-valid', match);
  pw2Input.classList.toggle('is-invalid', !match);
  msg.textContent = match ? 'Passwords match' : "Passwords don't match";
  msg.className = 'kw-msg ' + (match ? 'ok' : 'err');
}
pw2Input.addEventListener('input', checkMatch);

</script>

<script src="/assets/js/kw-forms.js"></script>
</body>
</html>