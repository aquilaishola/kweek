<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'KYC Verification';
$activePage = 'kyc';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$step  = 'start'; // start | otp | done
$error = '';

// Already verified
if ($user['bvn_verified']) {
    $step = 'done';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 'done') {
    verifyCsrf();
    $action = post('action');

    // ── STEP 1: Submit BVN ────────────────────────────────────
    if ($action === 'submit_bvn') {
        $bvn = preg_replace('/\D/', '', post('bvn'));

        if (!checkRateLimit(clientIp(), 'bvn', 5, 3600)) {
            $error = 'Too many attempts. Try again in an hour.';
        } elseif (strlen($bvn) !== 11) {
            $error = 'BVN must be exactly 11 digits.';
        } else {
            // In production: call StroWallet or a BVN API here
            // For hackathon demo we simulate OTP being sent
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['kyc_bvn']     = hash('sha256', $bvn); // store hashed
            $_SESSION['kyc_bvn_raw'] = $bvn; // temp for verification
            $_SESSION['kyc_otp']     = $otp;
            $_SESSION['kyc_otp_exp'] = time() + 300; // 5 min
            // TODO: send OTP to user's phone via SMS
            // For demo: show OTP on screen (remove in production)
            $_SESSION['kyc_demo_otp'] = $otp;
            $step = 'otp';
        }
    }

    // ── STEP 2: Verify OTP ────────────────────────────────────
    elseif ($action === 'verify_otp') {
        $enteredOtp = preg_replace('/\D/', '', post('otp'));

        if (empty($_SESSION['kyc_otp'])) {
            $error = 'Session expired. Please start over.';
            $step  = 'start';
        } elseif (time() > ($_SESSION['kyc_otp_exp'] ?? 0)) {
            $error = 'OTP expired. Please start over.';
            $step  = 'start';
            unset($_SESSION['kyc_otp'], $_SESSION['kyc_bvn']);
        } elseif (!hash_equals($_SESSION['kyc_otp'], $enteredOtp)) {
            $error = 'Incorrect OTP. Please try again.';
            $step  = 'otp';
        } else {
            // OTP correct — mark BVN verified
            $bvnHash = $_SESSION['kyc_bvn'] ?? hash('sha256', $_SESSION['kyc_bvn_raw'] ?? '');
            $bvnLast4 = substr($_SESSION['kyc_bvn_raw'] ?? '', -4);

            $db->prepare("UPDATE users SET bvn_verified = 1 WHERE id = ?")->execute([$uid]);

            // Upsert KYC record
            $db->prepare("INSERT INTO user_kyc (user_id, bvn_hash, bvn_last4, status, verified_at)
                          VALUES (?, ?, ?, 'verified', NOW())
                          ON DUPLICATE KEY UPDATE bvn_hash=VALUES(bvn_hash), bvn_last4=VALUES(bvn_last4), status='verified', verified_at=NOW()")
               ->execute([$uid, $bvnHash, $bvnLast4]);

            unset($_SESSION['kyc_otp'], $_SESSION['kyc_bvn'], $_SESSION['kyc_bvn_raw'], $_SESSION['kyc_otp_exp'], $_SESSION['kyc_demo_otp']);

            notify($uid, 'kyc_verified', 'BVN Verified!', 'Your identity has been verified. You can now withdraw funds without limits.', 'shield-check', '/withdraw.php');

            flash('success', 'BVN verified successfully! You can now withdraw funds.');
            redirect('/kyc.php');
        }
    }

    if (!empty($_SESSION['kyc_otp']) && $step !== 'otp' && empty($error)) {
        $step = 'otp';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:560px">
  <div style="margin-bottom:24px">
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px">KYC Verification</h1>
    <p style="font-size:14px;color:var(--n500);margin-top:4px">Verify your identity to unlock withdrawals and higher transaction limits.</p>
  </div>

  <!-- PROGRESS STEPS -->
  <div style="display:flex;align-items:center;gap:0;margin-bottom:28px">
    <?php
    $steps = [
      ['Enter BVN',    'shield'],
      ['Verify OTP',   'message-check'],
      ['Verified',     'circle-check'],
    ];
    $currentStep = $step === 'start' ? 0 : ($step === 'otp' ? 1 : 2);
    foreach ($steps as $i => [$label, $icon]):
      $done    = $i < $currentStep;
      $active  = $i === $currentStep;
      $future  = $i > $currentStep;
    ?>
    <div style="display:flex;align-items:center;gap:0;flex:<?= $i < count($steps)-1 ? '1' : '0' ?>">
      <div style="display:flex;flex-direction:column;align-items:center;gap:6px;flex-shrink:0">
        <div style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;
          background:<?= $done?'var(--success)':($active?'var(--p600)':'var(--n200)') ?>;
          color:<?= ($done||$active)?'#fff':'var(--n500)' ?>">
          <i class="ti ti-<?= $done?'check':$icon ?>"></i>
        </div>
        <span style="font-size:11px;font-weight:600;color:<?= $active?'var(--p600)':($done?'var(--success)':'var(--n400)') ?>;white-space:nowrap"><?= $label ?></span>
      </div>
      <?php if ($i < count($steps)-1): ?>
      <div style="flex:1;height:2px;background:<?= $done?'var(--success)':'var(--n200)' ?>;margin:0 8px;margin-bottom:22px"></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?>
  <div style="background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--danger)">
    <i class="ti ti-alert-circle" style="font-size:16px;flex-shrink:0"></i><?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <!-- ── DONE STATE ─────────────────────────────────────────── -->
  <?php if ($step === 'done'): ?>
  <div class="card" style="text-align:center;padding:40px 24px">
    <div style="width:72px;height:72px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:32px;margin:0 auto 16px">
      <i class="ti ti-shield-check"></i>
    </div>
    <h2 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:8px">Identity Verified</h2>
    <p style="font-size:14px;color:var(--n500);line-height:1.7;margin-bottom:24px">
      Your BVN has been verified. You can now withdraw funds and enjoy higher transaction limits.
    </p>
    <?php
    $kyc = $db->prepare("SELECT * FROM user_kyc WHERE user_id=?");
    $kyc->execute([$uid]);
    $kycRow = $kyc->fetch();
    ?>
    <?php if ($kycRow): ?>
    <div style="background:var(--n50);border-radius:var(--r-lg);padding:16px;text-align:left;margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px"><span style="color:var(--n500)">BVN (last 4)</span><strong>****<?= htmlspecialchars($kycRow['bvn_last4'] ?? '????') ?></strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px"><span style="color:var(--n500)">Verified at</span><strong><?= nigeriaTime($kycRow['verified_at']) ?></strong></div>
    </div>
    <?php endif; ?>
    <a href="/withdraw.php" class="btn btn-purple btn-md"><i class="ti ti-cash"></i> Withdraw Funds</a>
  </div>

  <!-- ── OTP STEP ───────────────────────────────────────────── -->
  <?php elseif ($step === 'otp' || !empty($_SESSION['kyc_otp'])): ?>
  <div class="card">
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--p50);display:flex;align-items:center;justify-content:center;color:var(--p600);font-size:24px;margin:0 auto 14px;border:1px solid var(--p100)">
        <i class="ti ti-message-check"></i>
      </div>
      <div class="card-title">Enter OTP</div>
      <div class="card-sub">We've sent a 6-digit code to your registered phone number.</div>
      <?php if (!empty($_SESSION['kyc_demo_otp'])): ?>
      <div style="margin-top:10px;background:var(--warning-bg);border:1px solid #FDE68A;border-radius:var(--r-md);padding:10px 14px;font-size:13px;color:var(--warning);font-weight:600">
        <i class="ti ti-info-circle"></i> Demo mode — OTP: <strong><?= $_SESSION['kyc_demo_otp'] ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="verify_otp">
      <div class="form-group">
        <label class="form-label">6-digit OTP</label>
        <input type="text" name="otp" class="form-input" placeholder="000000" maxlength="6"
          style="font-size:24px;font-weight:700;letter-spacing:8px;text-align:center;font-family:var(--font-display)"
          inputmode="numeric" pattern="[0-9]{6}" autofocus required>
      </div>
      <button type="submit" class="btn btn-purple btn-lg" style="width:100%;justify-content:center;margin-top:4px">
        <i class="ti ti-check"></i> Verify OTP
      </button>
    </form>
    <div style="text-align:center;margin-top:16px">
      <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="start">
        <button type="submit" style="background:none;border:none;color:var(--p600);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font-body)">
          <i class="ti ti-refresh"></i> Start over
        </button>
      </form>
    </div>
  </div>

  <!-- ── START STEP ─────────────────────────────────────────── -->
  <?php else: ?>
  <div class="card">
    <div style="margin-bottom:20px">
      <div class="card-title">Enter your BVN</div>
      <div class="card-sub" style="margin-top:4px">Your Bank Verification Number (BVN) is an 11-digit number. You can find it by dialing <strong>*565*0#</strong> on your phone.</div>
    </div>

    <!-- WHY BVN -->
    <div style="background:var(--p50);border:1px solid var(--p100);border-radius:var(--r-lg);padding:14px 16px;margin-bottom:20px">
      <div style="font-size:13px;font-weight:700;color:var(--p800);margin-bottom:8px;display:flex;align-items:center;gap:6px"><i class="ti ti-lock" style="font-size:15px"></i> Why do we need your BVN?</div>
      <ul style="list-style:none;display:flex;flex-direction:column;gap:6px">
        <?php foreach (['Prevent fraud and protect your account','Enable instant fund withdrawals','Comply with CBN regulations','Increase your monthly transaction limit'] as $item): ?>
        <li style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--p700)">
          <i class="ti ti-check" style="color:var(--p500);flex-shrink:0"></i><?= $item ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <div style="font-size:12px;color:var(--p500);margin-top:10px;padding-top:10px;border-top:1px solid var(--p100)">
        <i class="ti ti-shield-lock"></i> Your BVN is hashed and encrypted. We never store the raw number.
      </div>
    </div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="submit_bvn">
      <div class="form-group">
        <label class="form-label">BVN <span style="color:var(--danger)">*</span></label>
        <div class="form-input-wrap">
          <i class="ti ti-id-badge inp-icon"></i>
          <input type="text" name="bvn" class="form-input" placeholder="22234567890"
            maxlength="11" inputmode="numeric" pattern="[0-9]{11}" required autofocus
            style="letter-spacing:2px;font-size:16px;font-weight:600">
        </div>
        <div class="form-hint">Dial *565*0# to get your BVN from your bank</div>
      </div>
      <button type="submit" class="btn btn-purple btn-lg" style="width:100%;justify-content:center">
        <i class="ti ti-shield-check"></i> Verify My Identity
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<style>
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}
.form-input-wrap .form-input{padding-left:38px}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
