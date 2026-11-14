<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
requireLogin();

$pageTitle  = 'KYC Verification';
$activePage = 'kyc';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

const KYC_UPLOAD_DIR = __DIR__ . '/../kyc_secure';

$kycStmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id = ? LIMIT 1");
$kycStmt->execute([$uid]);
$kyc = $kycStmt->fetch();

$status = $user['bvn_verified'] ? 'verified' : ($kyc['status'] ?? null);
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status !== 'verified') {
    verifyCsrf();

    if (!checkRateLimit((string) $uid, 'kyc_submit', 3, 3600)) {
        $error = 'Too many submissions. Please wait an hour before trying again.';
    } else {
        $fullName     = clean(post('full_name'));
        $dob          = post('dob');
        $bankCode     = clean(post('bank_code'));
        $accountNum   = preg_replace('/\D/', '', post('account_number'));
        $accountName  = clean(post('account_name'));

        $errors = [];
        if (strlen($fullName) < 3) $errors[] = 'Enter your full legal name as it appears on your ID.';
        if (empty($dob) || !strtotime($dob)) $errors[] = 'Enter a valid date of birth.';
        if (strlen($accountNum) !== 10) $errors[] = 'Enter a valid 10-digit account number.';
        if (empty($bankCode)) $errors[] = 'Select your bank.';
        if (empty($accountName)) $errors[] = 'Verify your account name before submitting.';

        $ninFile  = $_FILES['nin_document'] ?? null;
        $poaFile  = $_FILES['proof_of_address'] ?? null;
        $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        foreach (['nin_document' => $ninFile, 'proof_of_address' => $poaFile] as $field => $file) {
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = ($field === 'nin_document' ? 'Upload your NIN document.' : 'Upload a proof of address.');
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'There was a problem uploading one of your files. Please try again.';
                continue;
            }
            if ($file['size'] > $maxSize) {
                $errors[] = 'Each file must be under 5MB.';
                continue;
            }
            $mime = mime_content_type($file['tmp_name']);
            if (!in_array($mime, $allowedTypes, true)) {
                $errors[] = 'Files must be a JPG, PNG, or PDF.';
            }
        }

        if (empty($errors)) {
            try {
                if (!is_dir(KYC_UPLOAD_DIR)) {
                    mkdir(KYC_UPLOAD_DIR, 0700, true);
                }

                $ext = function (array $file): string {
                    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
                    return $map[mime_content_type($file['tmp_name'])] ?? 'bin';
                };

                $ninName = 'nin_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext($ninFile);
                $poaName = 'poa_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext($poaFile);

                move_uploaded_file($ninFile['tmp_name'], KYC_UPLOAD_DIR . '/' . $ninName);
                move_uploaded_file($poaFile['tmp_name'], KYC_UPLOAD_DIR . '/' . $poaName);

                // Wipe any previous rejected submission's files so we don't
                // accumulate stale documents for the same user.
                if (!empty($kyc['nin_document_path']) && file_exists(KYC_UPLOAD_DIR . '/' . $kyc['nin_document_path'])) {
                    @unlink(KYC_UPLOAD_DIR . '/' . $kyc['nin_document_path']);
                }
                if (!empty($kyc['proof_of_address_path']) && file_exists(KYC_UPLOAD_DIR . '/' . $kyc['proof_of_address_path'])) {
                    @unlink(KYC_UPLOAD_DIR . '/' . $kyc['proof_of_address_path']);
                }

                $db->prepare("
                    INSERT INTO user_kyc (user_id, full_name, dob, nin_document_path, proof_of_address_path, submitted_at, status)
                    VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
                    ON DUPLICATE KEY UPDATE
                        full_name=VALUES(full_name), dob=VALUES(dob),
                        nin_document_path=VALUES(nin_document_path),
                        proof_of_address_path=VALUES(proof_of_address_path),
                        submitted_at=NOW(), status='pending',
                        reviewed_by=NULL, reviewed_at=NULL, rejection_reason=NULL
                ")->execute([$uid, $fullName, date('Y-m-d', strtotime($dob)), $ninName, $poaName]);

                $db->prepare("
                    UPDATE users SET
                        payout_bank_code=?, payout_bank_name=?, payout_account_number=?, payout_account_name=?
                    WHERE id=?
                ")->execute([$bankCode, $accountName ? $bankCode : null, $accountNum, $accountName, $uid]);
                // bank_name column intentionally set from the resolved
                // account name flow below, not user-typed input.

                notify($uid, 'kyc_submitted', 'Verification Submitted', 'Your documents have been submitted for review. This usually takes 1–2 business days.', 'clock', '/kyc.php');

                flash('success', 'Your documents have been submitted. We\'ll review them within 1–2 business days.');
                redirect('/kyc.php');
            } catch (Throwable $e) {
                error_log('KYC manual submission error: ' . $e->getMessage());
                $error = 'Something went wrong saving your submission. Please try again.';
            }
        } else {
            $error = implode(' ', $errors);
        }
    }
}

// Bank list for the payout-details step (same source withdraw.php uses).
$nomba = new Nomba();
$banksResult = $nomba->getBanks();
$banks = $banksResult['data'] ?? [];
$normalizedBanks = [];
$seen = [];
foreach ($banks as $b) {
    $code = trim($b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '');
    $name = trim($b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? '');
    if ($code === '' || isset($seen[$code])) continue;
    $seen[$code] = true;
    $normalizedBanks[] = ['bankCode' => $code, 'bankName' => rtrim($name, '.')];
}
usort($normalizedBanks, fn($a, $b) => strcmp($a['bankName'], $b['bankName']));

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:600px">
  <div style="margin-bottom:24px">
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px">Identity Verification</h1>
    <p style="font-size:14px;color:var(--muted);margin-top:4px">Verify your identity to unlock withdrawals.</p>
  </div>

  <?php if ($error): ?>
  <div class="notice-box danger"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($status === 'verified'): ?>
  <div class="card" style="text-align:center;padding:40px 24px">
    <div class="kyc-result-icon success"><i class="ti ti-shield-check"></i></div>
    <h2 style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--ink);margin-bottom:8px">Identity Verified</h2>
    <p style="font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:24px">You're verified and can withdraw funds without limits.</p>
    <a href="/withdraw.php" class="btn btn-purple btn-md"><i class="ti ti-cash"></i> Withdraw Funds</a>
  </div>

  <?php elseif ($status === 'pending'): ?>
  <div class="card" style="text-align:center;padding:40px 24px">
    <div class="kyc-result-icon purple"><i class="ti ti-clock"></i></div>
    <h2 style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--ink);margin-bottom:8px">Under Review</h2>
    <p style="font-size:14px;color:var(--muted);line-height:1.7">
      Your documents were submitted on <strong><?= nigeriaTime($kyc['submitted_at']) ?></strong> and are being reviewed. This usually takes 1–2 business days — we'll notify you the moment it's done.
    </p>
  </div>

  <?php else: ?>

  <?php if ($status === 'failed'): ?>
  <div class="notice-box danger" style="margin-bottom:20px">
    <i class="ti ti-alert-circle"></i>
    <span>Your last submission was declined<?= !empty($kyc['rejection_reason']) ? ': ' . htmlspecialchars($kyc['rejection_reason']) : '.' ?> Please review and resubmit below.</span>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Submit Your Documents</div>
    <div class="card-sub" style="margin-bottom:20px">OUR TEAM WILL REVIEW YOUR SUBMISSION.</div>

    <form method="POST" enctype="multipart/form-data" id="kyc-form">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-label">Full Legal Name <span style="color:var(--danger)">*</span></label>
        <input type="text" name="full_name" class="form-input" placeholder="As it appears on your ID" value="<?= htmlspecialchars($kyc['full_name'] ?? $user['name']) ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Date of Birth <span style="color:var(--danger)">*</span></label>
        <input type="date" name="dob" class="form-input" value="<?= htmlspecialchars($kyc['dob'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">NIN Document <span style="color:var(--danger)">*</span></label>
        <input type="file" name="nin_document" class="form-input" accept=".jpg,.jpeg,.png,.pdf" required>
        <div class="form-hint">A clear photo or scan of your National ID slip/card. JPG, PNG, or PDF, under 5MB.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Proof of Address <span style="color:var(--danger)">*</span></label>
        <input type="file" name="proof_of_address" class="form-input" accept=".jpg,.jpeg,.png,.pdf" required>
        <div class="form-hint">A recent utility bill not older than 3 months, bank statement, or tenancy agreement with your name and address. JPG, PNG, or PDF, under 5MB.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Bank <span style="color:var(--danger)">*</span></label>
        <select name="bank_code" id="kyc-bank-select" class="form-select" onchange="onKycBankChange()" required>
          <option value="">— Select bank —</option>
          <?php foreach ($normalizedBanks as $bank): ?>
          <option value="<?= htmlspecialchars($bank['bankCode']) ?>" data-name="<?= htmlspecialchars($bank['bankName']) ?>">
            <?= htmlspecialchars($bank['bankName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Account Number <span style="color:var(--danger)">*</span></label>
        <div style="display:flex;gap:8px">
          <input type="text" name="account_number" id="kyc-acc-number" class="form-input" placeholder="10-digit account number" maxlength="10" inputmode="numeric" style="flex:1"
            oninput="if(this.value.replace(/\D/g,'').length===10) verifyKycAccount()">
          <button type="button" id="kyc-verify-btn" onclick="verifyKycAccount()" class="btn btn-outline btn-md"><i class="ti ti-search"></i> Verify</button>
        </div>
      </div>

      <input type="hidden" name="account_name" id="kyc-hidden-acc-name" value="">
      <div class="acc-verified" id="kyc-acc-verified-box" style="display:none">
        <i class="ti ti-circle-check"></i>
        <span class="acc-verified-name" id="kyc-acc-verified-name"></span>
      </div>

      <button type="submit" class="btn btn-purple btn-lg" style="width:100%;justify-content:center;margin-top:8px" id="kyc-submit-btn">
        <i class="ti ti-upload"></i> Submit for Review
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<style>
.kyc-result-icon{ width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:32px; margin:0 auto 16px; }
.kyc-result-icon.success{ background:var(--success-bg); color:var(--success); }
.kyc-result-icon.purple{ background:var(--purple-50); color:var(--purple-600); border:1px solid var(--purple-100); }
.notice-box{ display:flex; align-items:flex-start; gap:10px; padding:13px 16px; border-radius:var(--r-lg); margin-bottom:20px; font-size:13px; line-height:1.5; }
.notice-box i{ font-size:16px; flex-shrink:0; margin-top:1px; }
.notice-box.danger{ background:var(--danger-bg); border:1px solid rgba(220,38,38,.2); color:var(--danger); }
.acc-verified{ display:flex; align-items:center; gap:8px; background:var(--success-bg); border:1px solid rgba(30,158,90,.25); border-radius:var(--r-lg); padding:12px 16px; margin-bottom:16px; }
.acc-verified i{ font-size:18px; color:var(--success); flex-shrink:0; }
.acc-verified-name{ font-size:14px; font-weight:700; color:var(--success); }
</style>

<script>
const KYC_CSRF = '<?= csrfToken() ?>';

async function verifyKycAccount() {
  const accNum = document.getElementById('kyc-acc-number').value.replace(/\D/g,'');
  const bankCode = document.getElementById('kyc-bank-select').value;
  if (accNum.length !== 10 || !bankCode) return;

  const btn = document.getElementById('kyc-verify-btn');
  btn.disabled = true;
  btn.innerHTML = '<div style="width:14px;height:14px;border-radius:50%;border:2px solid var(--line);border-top-color:var(--purple-600);animation:spin .7s linear infinite;display:inline-block"></div>';

  try {
    const fd = new FormData();
    fd.append('action', 'resolve_account');
    fd.append('account_number', accNum);
    fd.append('bank_code', bankCode);
    fd.append('csrf_token', KYC_CSRF);

    const res = await fetch('/withdraw', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.name) {
      document.getElementById('kyc-hidden-acc-name').value = data.name;
      document.getElementById('kyc-acc-verified-name').textContent = data.name;
      document.getElementById('kyc-acc-verified-box').style.display = 'flex';
      showToast('success', 'Account verified', data.name);
    } else {
      document.getElementById('kyc-hidden-acc-name').value = '';
      document.getElementById('kyc-acc-verified-box').style.display = 'none';
      showToast('error', 'Not verified', data.error || 'Could not verify account.');
    }
  } catch (e) {
    showToast('error', 'Error', 'Network error. Try again.');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="ti ti-search"></i> Verify';
}

function onKycBankChange() {
  document.getElementById('kyc-hidden-acc-name').value = '';
  document.getElementById('kyc-acc-verified-box').style.display = 'none';
}

document.getElementById('kyc-form')?.addEventListener('submit', function(e) {
  if (!document.getElementById('kyc-hidden-acc-name').value.trim()) {
    e.preventDefault();
    showToast('error', 'Verify account', 'Please verify your account name before submitting.');
    return;
  }
  const btn = document.getElementById('kyc-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Submitting...';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>