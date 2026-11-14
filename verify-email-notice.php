<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();

if (!empty($user['email_verified_at'])) {
    redirect('/onboarding.php');
}

$resent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (checkRateLimit(clientIp(), 'resend_verify', 3, 3600)) {
        $stmt = db()->prepare("SELECT email_token FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (empty($row['email_token'])) {
            $newToken = bin2hex(random_bytes(32));
            db()->prepare("UPDATE users SET email_token = ? WHERE id = ?")->execute([$newToken, $user['id']]);
            $row['email_token'] = $newToken;
        }

        $verifyLink = rtrim(SITE_URL, '/') . '/verify-email.php?token=' . $row['email_token'];
        sendVerificationEmail(['name' => $user['name'], 'email' => $user['email']], $verifyLink);
        $resent = true;
    } else {
        $errors[] = 'Please wait before requesting another email.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify your email — Kweek</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>

<div class="kw-canvas"></div>

<div class="kw-shell">
  <a href="/index.php" class="kw-brand">
    <img src="/assets/img/kweek-lockup-dark.png" alt="Kweek">
  </a>

  <div class="kw-eyebrow">One last step</div>
  <h1 class="kw-headline">Confirm your <span>email address.</span></h1>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">
      <div class="kw-form-header">
        <div>
          <h2>Check your inbox</h2>
          <p>We sent a link to <strong><?= htmlspecialchars($user['email']) ?></strong></p>
        </div>
      </div>

      <?php if ($resent): ?>
      <div class="kw-alert" style="background:#ECFDF3;border-color:#A9E6C4;color:#166534">
        <i class="ti ti-circle-check"></i>
        <span>Verification email resent. Check your inbox.</span>
      </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
      <div class="kw-alert">
        <i class="ti ti-alert-circle"></i>
        <span><?= htmlspecialchars($errors[0]) ?></span>
      </div>
      <?php endif; ?>

      <p style="font-size:13px;color:#6E6C88;line-height:1.7;margin:0 0 18px">
        Click the link in the email to activate your account and continue setting up.
        Didn't get it? Check spam, or request another below.
      </p>

      <form method="POST">
        <?= csrfField() ?>
        <button type="submit" class="kw-btn">
          <i class="ti ti-mail"></i> Resend verification email
        </button>
      </form>
    </div>
  </div>

  <div class="kw-below"><a href="/logout.php">Sign out</a></div>
</div>

</body>
</html>