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
        $stmt = db()->prepare("SELECT id, name, email, password, status, plan FROM users WHERE email = ? LIMIT 1");
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

                $redirect = clean(get('redirect', '/dashboard.php'));
                // Sanitize redirect
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
<title>Sign in — Kweek</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p50:#F0EFFE;--p100:#DCD9FC;--p400:#7B6FEE;--p500:#6457E8;
  --p600:#4F43D4;--p700:#3D33B8;--p800:#2E2690;--p900:#1C1660;
  --n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;
  --n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --danger:#DC2626;--danger-bg:#FEE2E2;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n900);min-height:100vh;display:flex;-webkit-font-smoothing:antialiased}
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:20px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:30px;font-weight:800;color:var(--n0);letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:44px;height:44px;border-radius:50%;border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.auth-wrap{display:flex;width:100%;min-height:100vh}
.auth-left{flex:0 0 44%;max-width:480px;background:var(--n900);padding:48px 5%;display:flex;flex-direction:column;position:relative;overflow:hidden;min-height:100vh}
.auth-left::before{content:'';position:absolute;top:-150px;left:-100px;width:500px;height:500px;background:radial-gradient(circle,rgba(100,87,232,.18) 0%,transparent 70%);pointer-events:none}
.auth-right{flex:1;background:var(--n0);display:flex;flex-direction:column;padding:48px 40px;justify-content:center;min-width:0}
.left-inner{position:relative;z-index:1;max-width:440px;display:flex;flex-direction:column;height:100%}
.left-logo{font-family:var(--font-display);font-weight:800;font-size:24px;color:var(--n0);text-decoration:none;letter-spacing:-.5px;margin-bottom:auto;display:block}
.left-logo .acc{color:var(--p400)}
.left-quote-wrap{margin:auto 0}
.left-quote{font-family:var(--font-display);font-size:clamp(22px,3vw,36px);font-weight:800;color:var(--n0);letter-spacing:-1px;line-height:1.15;margin-bottom:24px}
.left-quote .pur{color:var(--p400)}
.testi-block{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:var(--r-xl);padding:22px}
.testi-text{font-size:14px;color:var(--n400);line-height:1.7;margin-bottom:16px;font-style:italic}
.testi-auth{display:flex;align-items:center;gap:10px}
.testi-av{width:36px;height:36px;border-radius:50%;background:var(--p800);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--p200);font-family:var(--font-display)}
.testi-info .name{font-size:13px;font-weight:700;color:var(--n0)}
.testi-info .role{font-size:12px;color:var(--n500);margin-top:1px}
.left-bottom{margin-top:40px;font-size:12px;color:var(--n600)}

/* right side branding */
.right-logo{font-family:var(--font-display);font-weight:800;font-size:20px;color:var(--n900);text-decoration:none;letter-spacing:-.5px;display:block;margin-bottom:32px}
.right-logo .acc{color:var(--p600)}

.form-header{margin-bottom:32px}
.form-header h2{font-family:var(--font-display);font-size:26px;font-weight:800;color:var(--n900);letter-spacing:-.5px;margin-bottom:6px}
.form-header p{font-size:14px;color:var(--n500)}
.form-header p a{color:var(--p600);font-weight:600;text-decoration:none}

.error-box{background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:13px 16px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--danger)}
.error-box i{font-size:16px;flex-shrink:0}

.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.form-label{font-size:13px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:12px 14px 12px 42px;outline:none;transition:all .2s;width:100%}
.form-input:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 4px var(--p50)}
.form-input::placeholder{color:var(--n400)}
.inp-ic{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--n400);pointer-events:none}
.inp-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--n400);font-size:18px;padding:4px}
.inp-toggle:hover{color:var(--n700)}

.form-row-split{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.checkbox-wrap{display:flex;align-items:center;gap:8px;cursor:pointer}
.checkbox-wrap input{width:16px;height:16px;accent-color:var(--p600);cursor:pointer}
.checkbox-wrap span{font-size:13px;color:var(--n600)}
.forgot-link{font-size:13px;color:var(--p600);text-decoration:none;font-weight:600}
.forgot-link:hover{text-decoration:underline}

.btn-submit{width:100%;padding:14px;background:var(--p600);color:var(--n0);border:none;border-radius:var(--r-md);font-family:var(--font-body);font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s}
.btn-submit:hover{background:var(--p700);transform:translateY(-1px);box-shadow:0 8px 20px rgba(100,87,232,.3)}
.btn-submit:active{transform:translateY(0)}
.btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none;box-shadow:none}

.signup-cta{text-align:center;margin-top:24px;font-size:13px;color:var(--n500)}
.signup-cta a{color:var(--p600);font-weight:700;text-decoration:none}
.signup-cta a:hover{text-decoration:underline}

/* cap form width so it doesn't stretch on very wide screens */
.auth-right-inner{width:100%;max-width:400px;margin:0 auto}

@media(max-width:900px){
  .auth-left{display:none}
  .auth-right{width:100%;min-height:100vh;padding:40px 24px;justify-content:flex-start;padding-top:48px}
}
</style>
</head>
<body>
<div id="preloader"><div class="pre-logo">Kw<span class="acc">ee</span>k</div><div class="pre-ring"></div></div>

<div class="auth-wrap">
  <div class="auth-left">
    <div class="left-inner">
      <a href="/index.php" class="left-logo">Kw<span class="acc">ee</span>k</a>
      <div class="left-quote-wrap">
        <h2 class="left-quote">The payment OS<br>for merchants who<br><span class="pur">sell on WhatsApp.</span></h2>
        <div class="testi-block">
          <p class="testi-text">"I stopped losing customers the day I switched to Kweek. My payment confirmation used to take 20 minutes. Now it's instant."</p>
          <div class="testi-auth">
            <div class="testi-av">FK</div>
            <div class="testi-info">
              <div class="name">Fatima K.</div>
              <div class="role">Fashion vendor, Kano</div>
            </div>
          </div>
        </div>
      </div>
      <div class="left-bottom">© 2026 Kweek Technologies Ltd · Lagos, Nigeria</div>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-right-inner">
      <a href="/index.php" class="right-logo">Kw<span class="acc">ee</span>k</a>

      <div class="form-header">
        <h2>Welcome back</h2>
        <p>Don't have an account? <a href="/register.php">Sign up free</a></p>
      </div>

      <?php if ($error): ?>
      <div class="error-box">
        <i class="ti ti-alert-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="login-form">
        <?= csrfField() ?>
        <div class="form-group">
          <label class="form-label">Email address</label>
          <div class="inp-wrap">
            <i class="ti ti-mail inp-ic"></i>
            <input type="email" name="email" class="form-input" placeholder="you@email.com" value="<?= htmlspecialchars(post('email')) ?>" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="inp-wrap">
            <i class="ti ti-lock inp-ic"></i>
            <input type="password" name="password" id="pw" class="form-input" placeholder="Your password" required>
            <button type="button" class="inp-toggle" onclick="togglePw()"><i class="ti ti-eye"></i></button>
          </div>
        </div>
        <div class="form-row-split">
          <label class="checkbox-wrap">
            <input type="checkbox" name="remember" value="1">
            <span>Remember me for 30 days</span>
          </label>
          <a href="/forgot-password.php" class="forgot-link">Forgot password?</a>
        </div>
        <button type="submit" class="btn-submit" id="submit-btn">
          <i class="ti ti-login"></i> Sign in to Kweek
        </button>
      </form>

      <div class="signup-cta">
        New to Kweek? <a href="/register.php">Create a free account →</a>
      </div>
    </div>
  </div>
</div>

<script>
window.addEventListener('load', () => setTimeout(() => document.getElementById('preloader').classList.add('hide'), 800));
function togglePw() {
  const inp = document.getElementById('pw');
  const btn = document.querySelector('.inp-toggle i');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.className = `ti ti-${inp.type === 'password' ? 'eye' : 'eye-off'}`;
}
document.getElementById('login-form').addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Signing in...';
  btn.disabled = true;
});
</script>
</body>
</html>