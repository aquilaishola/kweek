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

            $db->prepare("INSERT INTO users (name, email, phone, password, plan, status, email_token) VALUES (?, ?, ?, ?, ?, 'pending', ?)")
               ->execute([$name, $email, $phone, $hash, $plan, $token]);

            $userId = (int)$db->lastInsertId();

            if ($plan !== 'free') {
                $trialEnd = date('Y-m-d H:i:s', strtotime('+7 days'));
                $db->prepare("UPDATE users SET trial_ends_at = ?, plan_expires_at = ?, status = 'active' WHERE id = ?")
                   ->execute([$trialEnd, $trialEnd, $userId]);
                $db->prepare("INSERT INTO subscriptions (user_id, plan, billing_cycle, amount, status, starts_at, ends_at) VALUES (?, ?, 'monthly', ?, 'trial', NOW(), ?)")
                   ->execute([$userId, $plan, $plan === 'pro' ? PRICE_PRO_MONTHLY : PRICE_BUSINESS_MONTHLY, $trialEnd]);
            } else {
                $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);
            }

notify($userId, 'welcome', 'Welcome to Kweek!', 'Your account is ready. Create your first payment link and start getting paid.', 'heart', '/payment-links.php', false);

sendWelcomeEmail(['name' => $name, 'email' => $email]);
            $_SESSION['user_id']   = $userId;
            $_SESSION['user_name'] = $name;

            flash('success', 'Welcome to Kweek! Create your first payment link below.');
            redirect('/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create account — Kweek</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p50:#F0EFFE;--p100:#DCD9FC;--p300:#9B93F3;--p400:#7B6FEE;
  --p500:#6457E8;--p600:#4F43D4;--p700:#3D33B8;--p800:#2E2690;--p900:#1C1660;
  --n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;
  --n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --danger:#DC2626;--danger-bg:#FEE2E2;--success:#16A34A;--success-bg:#DCFCE7;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n900);min-height:100vh;display:flex;-webkit-font-smoothing:antialiased}

#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;align-items:center;justify-content:center;gap:20px;flex-direction:column;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:30px;font-weight:800;color:var(--n0);letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:44px;height:44px;border-radius:50%;border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.auth-wrap{display:flex;width:100%;min-height:100vh}
/* Left: fixed 44%, capped at 480px so it never eats more than its share */
.auth-left{flex:0 0 44%;max-width:480px;background:var(--n900);padding:48px 5%;display:flex;flex-direction:column;position:relative;overflow:hidden;min-height:100vh}
/* Right: takes the remaining space, scrollable for tall forms */
.auth-right{flex:1;background:var(--n0);display:flex;flex-direction:column;overflow-y:auto;min-width:0}

.auth-left::before{content:'';position:absolute;top:-150px;left:-100px;width:500px;height:500px;background:radial-gradient(circle,rgba(100,87,232,.18) 0%,transparent 70%);pointer-events:none}
.auth-left::after{content:'';position:absolute;bottom:-100px;right:-50px;width:350px;height:350px;background:radial-gradient(circle,rgba(61,51,184,.12) 0%,transparent 70%);pointer-events:none}
.left-inner{position:relative;z-index:1;max-width:440px;display:flex;flex-direction:column;height:100%}
.left-logo{font-family:var(--font-display);font-weight:800;font-size:24px;color:var(--n0);text-decoration:none;letter-spacing:-.5px;margin-bottom:auto;display:block}
.left-logo .acc{color:var(--p400)}
.left-quote-wrap{margin:auto 0}
.left-h{font-family:var(--font-display);font-size:clamp(24px,3.2vw,38px);font-weight:800;color:var(--n0);letter-spacing:-1px;line-height:1.15;margin-bottom:16px}
.left-h .pur{color:var(--p400)}
.left-p{font-size:15px;color:var(--n400);line-height:1.7;margin-bottom:32px}
.feature-list{display:flex;flex-direction:column;gap:16px}
.feat-item{display:flex;align-items:flex-start;gap:12px}
.feat-ic{width:36px;height:36px;border-radius:var(--r-md);background:rgba(100,87,232,.15);border:1px solid rgba(100,87,232,.25);display:flex;align-items:center;justify-content:center;color:var(--p300);font-size:17px;flex-shrink:0}
.feat-text h4{font-size:14px;font-weight:700;color:var(--n0);margin-bottom:2px}
.feat-text p{font-size:13px;color:var(--n500);line-height:1.5}
.left-bottom{margin-top:auto;padding-top:40px;font-size:12px;color:var(--n600)}

/* right-side inner wrapper — caps width, centers content */
.auth-right-inner{width:100%;max-width:440px;margin:0 auto;padding:40px 24px;display:flex;flex-direction:column;justify-content:center;min-height:100vh}

/* logo on right side */
.right-logo{font-family:var(--font-display);font-weight:800;font-size:20px;color:var(--n900);text-decoration:none;letter-spacing:-.5px;display:block;margin-bottom:28px}
.right-logo .acc{color:var(--p600)}

.auth-form-header{margin-bottom:28px}
.auth-form-header h2{font-family:var(--font-display);font-size:24px;font-weight:800;color:var(--n900);letter-spacing:-.5px;margin-bottom:6px}
.auth-form-header p{font-size:14px;color:var(--n500)}
.auth-form-header p a{color:var(--p600);font-weight:600;text-decoration:none}
.auth-form-header p a:hover{text-decoration:underline}

.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.form-label{font-size:13px;font-weight:600;color:var(--n600)}
.form-input,.form-select{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:12px 14px;outline:none;transition:all .2s;width:100%}
.form-input:focus,.form-select:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 4px var(--p50)}
.form-input.error{border-color:var(--danger)}
.form-input::placeholder{color:var(--n400)}
.inp-wrap{position:relative}
.inp-wrap .form-input{padding-left:42px}
.inp-wrap .inp-ic{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--n400);pointer-events:none}
.inp-wrap .inp-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--n400);font-size:18px;padding:4px}
.inp-wrap .inp-toggle:hover{color:var(--n700)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-hint{font-size:12px;color:var(--n500);margin-top:4px}

.plan-select-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.plan-opt{border:1.5px solid var(--n200);border-radius:var(--r-lg);padding:14px 12px;cursor:pointer;transition:all .2s;text-align:center;position:relative}
.plan-opt:hover{border-color:var(--p300)}
.plan-opt input[type=radio]{position:absolute;opacity:0;pointer-events:none}
.plan-opt.selected{border-color:var(--p600);background:var(--p50)}
.plan-opt-name{font-size:12px;font-weight:700;color:var(--n600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.plan-opt.selected .plan-opt-name{color:var(--p700)}
.plan-opt-price{font-family:var(--font-display);font-size:16px;font-weight:800;color:var(--n900)}
.plan-opt.selected .plan-opt-price{color:var(--p800)}
.plan-opt-tag{font-size:10px;color:var(--n400);margin-top:2px}
.plan-opt.selected .plan-opt-tag{color:var(--p500)}
.plan-popular-tag{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--p600);color:var(--n0);font-size:10px;font-weight:700;padding:3px 10px;border-radius:var(--r-full);white-space:nowrap}

.errors-box{background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:14px 16px;margin-bottom:20px}
.errors-box p{font-size:13px;color:var(--danger);display:flex;align-items:flex-start;gap:6px;line-height:1.5}
.errors-box p+p{margin-top:6px}
.errors-box i{font-size:15px;flex-shrink:0;margin-top:1px}

.btn-submit{width:100%;padding:14px;background:var(--p600);color:var(--n0);border:none;border-radius:var(--r-md);font-family:var(--font-body);font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-top:4px}
.btn-submit:hover{background:var(--p700);transform:translateY(-1px);box-shadow:0 8px 20px rgba(100,87,232,.3)}
.btn-submit:active{transform:translateY(0)}

.terms-note{font-size:12px;color:var(--n400);text-align:center;margin-top:16px;line-height:1.6}
.terms-note a{color:var(--p600);text-decoration:none}
.terms-note a:hover{text-decoration:underline}

.strength-bar{height:4px;border-radius:var(--r-full);background:var(--n200);margin-top:6px;overflow:hidden}
.strength-fill{height:100%;border-radius:var(--r-full);transition:width .3s,background .3s;width:0}

@media(max-width:900px){
  .auth-left{display:none}
  .auth-right{width:100%;min-height:100vh}
  .auth-right-inner{min-height:100vh;padding:48px 24px}
}
@media(max-width:480px){.form-row{grid-template-columns:1fr}.plan-select-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
</div>

<div class="auth-wrap">
  <!-- LEFT -->
  <div class="auth-left">
    <div class="left-inner">
      <a href="/index.php" class="left-logo">Kw<span class="acc">ee</span>k</a>
      <div class="left-quote-wrap">
        <h1 class="left-h">Start getting paid<br>the <span class="pur">smart way</span></h1>
        <p class="left-p">Join thousands of Nigerian merchants who replaced manual bank transfers with Kweek payment links.</p>
        <div class="feature-list">
          <div class="feat-item">
            <div class="feat-ic"><i class="ti ti-link"></i></div>
            <div class="feat-text">
              <h4>Payment link in 30 seconds</h4>
              <p>Share on WhatsApp and Instagram. Customers pay instantly.</p>
            </div>
          </div>
          <div class="feat-item">
            <div class="feat-ic"><i class="ti ti-shield-check"></i></div>
            <div class="feat-text">
              <h4>Verified receipts, zero fraud</h4>
              <p>Every payment gets a unique receipt link. No more fake alerts.</p>
            </div>
          </div>
          <div class="feat-item">
            <div class="feat-ic"><i class="ti ti-building-bank"></i></div>
            <div class="feat-text">
              <h4>Withdraw to any bank instantly</h4>
              <p>GTB, Access, Opay, Kuda — your money, your account, your timing.</p>
            </div>
          </div>
        </div>
      </div>
      <div class="left-bottom">© 2026 Kweek Technologies Ltd · Lagos, Nigeria</div>
    </div>
  </div>

  <!-- RIGHT -->
  <div class="auth-right">
    <div class="auth-right-inner">
      <a href="/index.php" class="right-logo">Kw<span class="acc">ee</span>k</a>

      <div class="auth-form-header">
        <h2>Create your account</h2>
        <p>Already have an account? <a href="/login.php">Sign in</a></p>
      </div>

      <?php if (!empty($errors)): ?>
      <div class="errors-box">
        <?php foreach ($errors as $e): ?>
          <p><i class="ti ti-alert-circle"></i><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="reg-form" novalidate>
        <?= csrfField() ?>

       <!-- <div class="form-group">
          <label class="form-label">Choose a plan</label>
          <div class="plan-select-grid">
            <label class="plan-opt <?= (post('plan','free')==='free')?'selected':'' ?>" onclick="selectPlan(this,'free')">
              <input type="radio" name="plan" value="free" <?= post('plan','free')==='free'?'checked':'' ?>>
              <div class="plan-opt-name">Free</div>
              <div class="plan-opt-price">₦0</div>
              <div class="plan-opt-tag">Forever free</div>
            </label>
            <label class="plan-opt <?= (post('plan')==='pro')?'selected':'' ?>" onclick="selectPlan(this,'pro')" style="position:relative">
              <div class="plan-popular-tag">Popular</div>
              <input type="radio" name="plan" value="pro" <?= post('plan')==='pro'?'checked':'' ?>>
              <div class="plan-opt-name">Pro</div>
              <div class="plan-opt-price">₦6,500</div>
              <div class="plan-opt-tag">7-day free trial</div>
            </label>
            <label class="plan-opt <?= (post('plan')==='business')?'selected':'' ?>" onclick="selectPlan(this,'business')">
              <input type="radio" name="plan" value="business" <?= post('plan')==='business'?'checked':'' ?>>
              <div class="plan-opt-name">Business</div>
              <div class="plan-opt-price">₦15k</div>
              <div class="plan-opt-tag">7-day free trial</div>
            </label>
          </div>
        </div> -->

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full name</label>
            <div class="inp-wrap">
              <i class="ti ti-user inp-ic"></i>
              <input type="text" name="name" class="form-input" placeholder="Temi Adeyemi" value="<?= htmlspecialchars(post('name')) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Phone number</label>
            <div class="inp-wrap">
              <i class="ti ti-phone inp-ic"></i>
              <input type="tel" name="phone" class="form-input" placeholder="08012345678" value="<?= htmlspecialchars(post('phone')) ?>" required>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email address</label>
          <div class="inp-wrap">
            <i class="ti ti-mail inp-ic"></i>
            <input type="email" name="email" class="form-input" placeholder="you@email.com" value="<?= htmlspecialchars(post('email')) ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password</label>
            <div class="inp-wrap">
              <i class="ti ti-lock inp-ic"></i>
              <input type="password" name="password" id="pw" class="form-input" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)">
              <button type="button" class="inp-toggle" onclick="togglePw('pw',this)"><i class="ti ti-eye"></i></button>
            </div>
            <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm password</label>
            <div class="inp-wrap">
              <i class="ti ti-lock-check inp-ic"></i>
              <input type="password" name="confirm_password" id="pw2" class="form-input" placeholder="Repeat password" required>
              <button type="button" class="inp-toggle" onclick="togglePw('pw2',this)"><i class="ti ti-eye"></i></button>
            </div>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">
          <i class="ti ti-user-plus"></i>
          Create my account
        </button>

        <p class="terms-note">By creating an account you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>. We comply with CBN regulations and AML/KYC requirements.</p>
      </form>
    </div>
  </div>
</div>

<script>
window.addEventListener('load', () => setTimeout(() => document.getElementById('preloader').classList.add('hide'), 800));

function selectPlan(el, plan) {
  document.querySelectorAll('.plan-opt').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  el.querySelector('input').checked = true;
}

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.innerHTML = `<i class="ti ti-${isText ? 'eye' : 'eye-off'}"></i>`;
}

function checkStrength(val) {
  let score = 0;
  if (val.length >= 8) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const fill = document.getElementById('strength-fill');
  const colors = ['','#DC2626','#D97706','#16A34A','#4F43D4'];
  const widths = ['0%','25%','50%','75%','100%'];
  fill.style.width = widths[score];
  fill.style.background = colors[score];
}

document.getElementById('reg-form').addEventListener('submit', function(e) {
  const btn = document.getElementById('submit-btn');
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Creating account...';
  btn.disabled = true;
});
</script>
</body>
</html>