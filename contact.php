<?php
require_once __DIR__ . '/includes/functions.php';

$viewer       = function_exists('sessionUser') ? sessionUser() : null;
$loggedIn     = !empty($viewer);
$user         = $loggedIn ? currentUser() : null;
$db           = db();
$supportEmail = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@kweek.ng';

$types = [
  'feedback' => ['Feedback',        'message-circle'],
  'feature'  => ['Feature request', 'bulb'],
  'bug'      => ['Report a bug',    'bug'],
  'support'  => ['Need help',       'lifebuoy'],
];

$err  = null;
$sent = isset($_GET['sent']);
$old  = ['type' => 'feedback', 'message' => '', 'name' => '', 'email' => '', 'rating' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $type   = array_key_exists(post('type'), $types) ? post('type') : 'feedback';
    $msg    = trim((string)post('message'));
    $rating = (int)post('rating'); if ($rating < 1 || $rating > 5) $rating = null;
    $name   = $loggedIn ? (string)($viewer['name'] ?? '')  : trim((string)post('name'));
    $email  = $loggedIn ? (string)($user['email'] ?? $viewer['email'] ?? '') : trim((string)post('email'));
    $email  = str_replace(["\r", "\n"], '', $email);
    $from   = substr((string)post('from'), 0, 255);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $old    = ['type' => $type, 'message' => $msg, 'name' => $name, 'email' => $email, 'rating' => (int)$rating];

    if ((string)post('website') !== '') {            // honeypot: bots only
        redirect('/contact.php?sent=1');
    } elseif (mb_strlen($msg) < 10) {
        $err = 'Please write at least a sentence so we can understand.';
    } elseif (mb_strlen($msg) > 3000) {
        $err = 'That message is a bit long. Please keep it under 3,000 characters.';
    } elseif (!$loggedIn && ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $err = 'Please enter your name and a valid email so we can reply.';
    } else {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS feedback_messages (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, name VARCHAR(120), email VARCHAR(160),
                type VARCHAR(30), rating TINYINT NULL, message TEXT, page_url VARCHAR(255) NULL,
                ip VARCHAR(45) NULL, status VARCHAR(20) DEFAULT 'new', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

            $uidv = $loggedIn ? (int)$user['id'] : null;
            $rl = $db->prepare("SELECT COUNT(*) FROM feedback_messages WHERE (ip = ? OR (user_id IS NOT NULL AND user_id = ?)) AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $rl->execute([$ip, $uidv]);
            if ((int)$rl->fetchColumn() >= 5) {
                $err = 'You have sent a few messages already. Please try again in a bit.';
            } else {
                $db->prepare("INSERT INTO feedback_messages (user_id,name,email,type,rating,message,page_url,ip) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$uidv, $name, $email, $type, $rating, $msg, $from, $ip]);
              
                redirect('/contact.php?sent=1');
            }
        } catch (Throwable $e) {
            $err = "We couldn't save that right now. Please email us at $supportEmail.";
        }
    }
}

$fromUrl = $_SERVER['HTTP_REFERER'] ?? '';

$css = <<<'CSS'
<style>
.ct{--l:#ECEAF1;--m:#6B6A7B;--p:#4F43D4;--ink:#15131F;max-width:1060px;font-family:'Space Grotesk',sans-serif;color:var(--ink)}
.ct *{box-sizing:border-box}
.ct-grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:28px;align-items:start;margin-top:8px}
.ct h1{font-size:clamp(28px,3.6vw,44px);font-weight:400;letter-spacing:-.04em;line-height:1.08;margin:6px 0 14px}
.ct-lead{color:var(--m);font-size:17px;max-width:440px}
.ct-opts{display:flex;flex-direction:column;gap:12px;margin-top:28px}
.ct-opt{display:flex;gap:14px;align-items:center;border:1px solid var(--l);border-radius:16px;padding:16px 18px;background:#fff;text-decoration:none;color:inherit;transition:border-color .2s,transform .2s,box-shadow .2s}
.ct-opt:hover{border-color:#cfcbe0;transform:translateY(-2px);box-shadow:0 14px 30px -20px rgba(21,19,31,.3)}
.ct-opt i{width:42px;height:42px;border-radius:12px;background:#F2F0FC;color:var(--p);display:flex;align-items:center;justify-content:center;font-size:21px;flex:none}
.ct-opt b{display:block;font-weight:600;font-size:15px}.ct-opt small{color:var(--m);font-size:13.5px}
.ct-card{background:#fff;border:1px solid var(--l);border-radius:22px;padding:28px}
.ct-lbl{display:block;font-size:13px;font-weight:600;margin:0 0 8px}
.ct-types{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:22px}
.ct-type{position:relative;cursor:pointer}
.ct-type input{position:absolute;opacity:0;inset:0}
.ct-type span{display:flex;align-items:center;gap:9px;border:1.5px solid var(--l);border-radius:13px;padding:12px 14px;font-size:14px;font-weight:500;transition:all .2s;background:#fff}
.ct-type span i{font-size:19px;color:var(--m);transition:color .2s}
.ct-type:hover span{border-color:#cfcbe0}
.ct-type input:checked+span{border-color:var(--p);background:#F2F0FC;color:var(--p)}
.ct-type input:checked+span i{color:var(--p)}
.ct-type input:focus-visible+span{outline:2px solid var(--p);outline-offset:2px}
.ct-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:2px;margin-bottom:22px}
.ct-stars input{position:absolute;opacity:0}
.ct-stars label{font-size:28px;color:#D9D6E4;cursor:pointer;transition:color .15s,transform .15s;line-height:1}
.ct-stars label:hover,.ct-stars label:hover~label,.ct-stars input:checked~label{color:#F2A916}
.ct-stars label:hover{transform:scale(1.15)}
.ct-f{margin-bottom:18px}
.ct-in,.ct-ta{width:100%;font:inherit;font-size:15px;border:1.5px solid var(--l);background:#F6F6F8;border-radius:13px;padding:12px 14px;outline:0;color:var(--ink);transition:border-color .2s,box-shadow .2s,background .2s}
.ct-ta{min-height:150px;resize:vertical}
.ct-in:focus,.ct-ta:focus{background:#fff;border-color:var(--p);box-shadow:0 0 0 4px #F2F0FC}
.ct-cnt{text-align:right;font-size:12px;color:var(--m);margin-top:6px}
.ct-btn{width:100%;height:50px;border:0;border-radius:13px;background:var(--p);color:#fff;font:600 15.5px 'Space Grotesk',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .2s,transform .2s,box-shadow .2s}
.ct-btn:hover{background:#3D33B8;transform:translateY(-1px);box-shadow:0 12px 24px -12px rgba(79,67,212,.7)}
.ct-btn:disabled{opacity:.7;cursor:default;transform:none}
.ct-err{display:flex;gap:10px;background:#FDECEA;color:#B42318;border:1px solid #F5C6C1;border-radius:13px;padding:12px 14px;font-size:14px;margin-bottom:18px}
.ct-ok{text-align:center;padding:30px 10px}
.ct-ok i{width:64px;height:64px;border-radius:50%;background:#E9F9F0;color:#1E9E5A;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 16px;animation:ctPop .5s cubic-bezier(.2,1.5,.4,1)}
.ct-ok h2{font-size:24px;font-weight:600;letter-spacing:-.03em;margin-bottom:8px}.ct-ok p{color:var(--m)}
.ct-ok a{display:inline-block;margin-top:18px;color:var(--p);font-weight:600;text-decoration:none}
.hp{position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden}
@keyframes ctPop{from{transform:scale(.4);opacity:0}to{transform:none;opacity:1}}
@media(max-width:860px){.ct-grid{grid-template-columns:1fr}}
@media(max-width:480px){.ct-types{grid-template-columns:1fr}.ct-card{padding:20px 18px}}
</style>
CSS;

ob_start(); ?>
<div class="ct">
  <div style="margin-bottom:6px">
    <h1>Tell us what you think.</h1>
    <p class="ct-lead">Found a bug, want a feature, or need a hand? Send it here and a real person on the Kweek team will read it.</p>
  </div>
  <div class="ct-grid">
    <div class="ct-opts" style="margin-top:0">
      <a class="ct-opt" href="mailto:<?= htmlspecialchars($supportEmail) ?>"><i class="ti ti-mail"></i><div><b>Email us</b><small><?= htmlspecialchars($supportEmail) ?></small></div></a>
      <div class="ct-opt"><i class="ti ti-clock-hour-4"></i><div><b>We reply fast</b><small>Most messages get an answer within 24 hours.</small></div></div>
      <div class="ct-opt"><i class="ti ti-shield-lock"></i><div><b>Payments issue?</b><small>Include your order ref or TXN code so we can find it quickly.</small></div></div>
    </div>

    <div class="ct-card">
    <?php if ($sent): ?>
      <div class="ct-ok">
        <i class="ti ti-check"></i>
        <h2>Thank you, message received.</h2>
        <p>We'll get back to you by email if it needs a reply.</p>
        <a href="<?= $loggedIn ? '/dashboard.php' : '/index.php' ?>">← Back to <?= $loggedIn ? 'dashboard' : 'home' ?></a>
      </div>
    <?php else: ?>
      <form method="POST" id="ct-form" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromUrl) ?>">
        <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

        <?php if ($err): ?><div class="ct-err"><i class="ti ti-alert-circle"></i><span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

        <span class="ct-lbl">What is this about?</span>
        <div class="ct-types">
          <?php foreach ($types as $k => $t): ?>
          <label class="ct-type"><input type="radio" name="type" value="<?= $k ?>" <?= $old['type'] === $k ? 'checked' : '' ?>><span><i class="ti ti-<?= $t[1] ?>"></i><?= $t[0] ?></span></label>
          <?php endforeach; ?>
        </div>

        <span class="ct-lbl">How is Kweek working for you? <span style="color:var(--m);font-weight:400">(optional)</span></span>
        <div class="ct-stars">
          <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>" <?= $old['rating'] === $i ? 'checked' : '' ?>><label for="r<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="ti ti-star-filled"></i></label>
          <?php endfor; ?>
        </div>

        <?php if (!$loggedIn): ?>
        <div class="ct-f"><label class="ct-lbl" for="ct-name">Your name</label><input class="ct-in" id="ct-name" name="name" value="<?= htmlspecialchars($old['name']) ?>" autocomplete="name"></div>
        <div class="ct-f"><label class="ct-lbl" for="ct-email">Email</label><input class="ct-in" id="ct-email" type="email" name="email" value="<?= htmlspecialchars($old['email']) ?>" autocomplete="email"></div>
        <?php endif; ?>

        <div class="ct-f">
          <label class="ct-lbl" for="ct-msg">Your message</label>
          <textarea class="ct-ta" id="ct-msg" name="message" maxlength="3000" placeholder="Tell us what happened, or what you'd love to see…"><?= htmlspecialchars($old['message']) ?></textarea>
          <div class="ct-cnt"><span id="ct-n"><?= mb_strlen($old['message']) ?></span> / 3000</div>
        </div>

        <button type="submit" class="ct-btn" id="ct-send"><i class="ti ti-send"></i> Send message</button>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>
<script>
const m=document.getElementById('ct-msg'),n=document.getElementById('ct-n');
if(m)m.addEventListener('input',()=>n.textContent=m.value.length);
const f=document.getElementById('ct-form');
if(f)f.addEventListener('submit',()=>{const b=document.getElementById('ct-send');b.disabled=true;b.innerHTML='<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> Sending…'});
</script>
<?php
$body = ob_get_clean();

if ($loggedIn) {
    $pageTitle  = 'Contact & Feedback';
    $activePage = 'contact';
    include __DIR__ . '/includes/header.php';
    echo $css . $body;
    include __DIR__ . '/includes/footer.php';
} else { ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact & Feedback — Kweek</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/kweek-icon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Space Grotesk',sans-serif;background:#fff;color:#15131F;-webkit-font-smoothing:antialiased}
.pt{display:flex;justify-content:space-between;align-items:center;max-width:1140px;margin:0 auto;padding:20px 24px}
.pt a{text-decoration:none;color:inherit;font-weight:500;font-size:14.5px}
.pt .lg{display:flex;align-items:center;gap:9px;font-weight:700;font-size:20px;letter-spacing:-.4px}
.pt .lg span{width:30px;height:30px;border-radius:8px;background:#4F43D4;display:flex;align-items:center;justify-content:center}
.pt .lg svg{width:18px;height:18px}.pw{max-width:1140px;margin:0 auto;padding:12px 24px 80px}
@keyframes spin{to{transform:rotate(360deg)}}</style>
</head><body>
<div class="pt"><a href="/index.php" class="lg"><span><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#fff"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></span>kweek</a><a href="/login.php">Sign in →</a></div>
<div class="pw"><?= $css . $body ?></div>
</body></html>
<?php }