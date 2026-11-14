<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();

if (!empty($user['profile_completed'])) {
    redirect('/dashboard.php');
}

$errors     = [];
$needsPhone = empty($user['phone']);

$businessTypes = [
    'fashion_beauty'   => 'Fashion & Beauty',
    'food_beverage'    => 'Food & Beverage',
    'electronics'      => 'Electronics & Gadgets',
    'digital'          => 'Digital Products / Services',
    'health_wellness'  => 'Health & Wellness',
    'home_living'      => 'Home & Living',
    'education'        => 'Education / Coaching',
    'events'           => 'Events & Entertainment',
    'other'            => 'Other',
];

$referralSources = [
    'instagram'  => 'Instagram',
    'tiktok'     => 'TikTok',
    'twitter'    => 'Twitter / X',
    'facebook'   => 'Facebook',
    'whatsapp'   => 'WhatsApp status/group',
    'referral'   => 'A friend or colleague',
    'google'     => 'Google search',
    'other'      => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name           = clean(post('name'));
    $phone          = $needsPhone ? preg_replace('/\D/', '', post('phone')) : $user['phone'];
    $businessType   = post('business_type');
    $referralSource = post('referral_source');

    if (empty($name) || strlen($name) < 2) {
        $errors[] = 'Enter your full name.';
    }
    if ($needsPhone) {
        if (strlen($phone) < 10 || strlen($phone) > 11) {
    $errors[] = 'Enter a valid Nigerian phone number.';
} else {
            $stmt = db()->prepare("SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1");
            $stmt->execute([$phone, $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'This phone number is already linked to another account.';
            }
        }
    }
    if (!array_key_exists($businessType, $businessTypes)) {
        $errors[] = 'Select your business type.';
    }
    if (!array_key_exists($referralSource, $referralSources)) {
        $errors[] = 'Let us know how you heard about us.';
    }

  if (empty($errors)) {
    $stmt = db()->prepare(
        "UPDATE users SET name = ?, phone = ?, business_type = ?, referral_source = ?, profile_completed = 1
         WHERE id = ? AND profile_completed = 0"
    );
    $stmt->execute([$name, $phone, $businessType, $referralSource, $user['id']]);

    if ($stmt->rowCount() === 0) {
        flash('info', 'Your account is already set up.');
        redirect('/dashboard.php');
    }

    $_SESSION['user_name'] = $name;
    flash('success', "You're all set! Welcome to Kweek.");
    redirect('/dashboard.php');
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finish setting up — Kweek</title>
<meta name="robots" content="noindex, nofollow">
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

  <div class="kw-eyebrow">Almost there</div>
  <h1 class="kw-headline">Just a couple more <span>details.</span></h1>

  <div class="kw-card">
    <div class="kw-perf"></div>
    <div class="kw-card-body">

      <div class="kw-form-header">
        <div>
          <h2>Finish setting up</h2>
          <p>This helps us tailor Kweek to your business.</p>
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

      <form method="POST" novalidate>
        <?= csrfField() ?>

        <div class="kw-group">
          <label class="kw-label">Full name</label>
          <div class="kw-input-wrap">
            <input type="text" name="name" class="kw-input" placeholder="Temi Adeyemi"
              value="<?= htmlspecialchars(post('name') ?: $user['name']) ?>" required>
          </div>
           <small class="kw-hint" style="display:flex;align-items:center;gap:5px;margin-top:4px;font-size:12px;color:#8A899B;">
    <i class="ti ti-info-circle" style="font-size:13px;"></i>
    Use the real name on your government ID 
  </small>
        </div>

        <?php if ($needsPhone): ?>
        <div class="kw-group">
          <label class="kw-label">Phone number</label>
          <div class="kw-input-wrap">
            <input type="tel" name="phone" class="kw-input" placeholder="08012345678"
              value="<?= htmlspecialchars(post('phone')) ?>" required>
          </div>
        </div>
        <?php endif; ?>

        <div class="kw-group">
          <label class="kw-label">What type of business do you run?</label>
          <select name="business_type" class="kw-input" required>
            <option value="">Select one</option>
            <?php foreach ($businessTypes as $val => $label): ?>
            <option value="<?= $val ?>" <?= post('business_type') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="kw-group">
          <label class="kw-label">How did you hear about Kweek?</label>
          <select name="referral_source" class="kw-input" required>
            <option value="">Select one</option>
            <?php foreach ($referralSources as $val => $label): ?>
            <option value="<?= $val ?>" <?= post('referral_source') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="kw-btn" style="margin-top:22px" data-loading-text="Finishing setup...">
          <i class="ti ti-check"></i> Finish setup
        </button>
      </form>
    </div>
  </div>

  <div class="kw-below">Questions? <a href="mailto:support@kweek.ng">Talk to support</a></div>
</div>
<script src="/assets/js/kw-forms.js"></script>
</body>
</html>