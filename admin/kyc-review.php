<?php
require_once __DIR__ . '/includes/admin_functions.php';
require_once __DIR__ . '/../includes/nomba.php';
requireAdminLogin();
requireAdminCapability('manage_kyc');

$admin  = currentAdmin();
$db     = db();
$userId = (int) get('user_id');

if (!$userId) redirect('/admin/kyc.php');

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND owner_user_id IS NULL LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) redirect('/admin/kyc.php');

$stmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$kyc = $stmt->fetch();

$pageTitle  = 'Review — ' . $user['name'];
$activePage = 'kyc';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kyc) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'approve' && $kyc['status'] === 'pending') {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE users SET bvn_verified = 1, payout_verified = 1, payout_verified_at = NOW(), payout_locked = 0, payout_locked_reason = NULL WHERE id = ?")
               ->execute([$userId]);
            $db->prepare("UPDATE user_kyc SET status = 'verified', verified_at = NOW(), reviewed_by = ?, reviewed_at = NOW(), rejection_reason = NULL WHERE user_id = ?")
               ->execute([$admin['id'], $userId]);
            $db->commit();

            notify($userId, 'kyc_verified', 'Identity Verified!', 'Your KYC documents have been approved. You can now withdraw funds to your linked account.', 'shield-check', '/withdraw.php');
            flash('success', 'KYC approved — ' . $user['name'] . ' can now withdraw.');
            redirect('/admin/kyc.php?status=pending');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('KYC approve error: ' . $e->getMessage());
            $error = 'Something went wrong approving this submission. Try again.';
        }
    }

    elseif ($action === 'reject' && $kyc['status'] === 'pending') {
        $reason = clean(post('reason'));
        if (strlen($reason) < 5) {
            $error = 'Give a reason so the merchant knows what to fix.';
        } else {
            $db->prepare("UPDATE user_kyc SET status = 'failed', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE user_id = ?")
               ->execute([$admin['id'], $reason, $userId]);

            notify($userId, 'kyc_rejected', 'Verification Needs Attention', 'Your KYC submission was not approved: ' . $reason, 'alert-triangle', '/kyc.php');
            flash('info', 'Submission rejected and merchant notified.');
            redirect('/admin/kyc.php?status=pending');
        }
    }

    elseif ($action === 'unlock_payout') {
        $db->prepare("UPDATE users SET payout_locked = 0, payout_locked_reason = NULL WHERE id = ?")->execute([$userId]);
        flash('success', 'Payout unlocked for ' . $user['name'] . '.');
        redirect('/admin/kyc-review.php?user_id=' . $userId);
    }

    // refresh after any handled action that didn't redirect
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1"); $stmt->execute([$userId]); $user = $stmt->fetch();
    $stmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id = ? LIMIT 1"); $stmt->execute([$userId]); $kyc = $stmt->fetch();
}

$ninDocUrl = $kyc && $kyc['nin_document_path'] ? '/admin/kyc-document.php?user_id=' . $userId . '&type=nin' : null;
$poaDocUrl = $kyc && $kyc['proof_of_address_path'] ? '/admin/kyc-document.php?user_id=' . $userId . '&type=poa' : null;

function isPdfPath(?string $path): bool {
    return $path && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

// users.payout_bank_name currently gets set to the bank CODE at submission
// time (a bug in kyc.php, not this page), so showing it as-is would display
// e.g. "058" instead of "GTBank". Resolve the real name from
// payout_bank_code against the same bank list withdraw.php/kyc.php use,
// and fall back to the raw stored value only if that lookup comes up empty.
$resolvedBankName = $user['payout_bank_name'];
if (!empty($user['payout_bank_code'])) {
    try {
        $nomba = new Nomba();
        $banksResult = $nomba->getBanks();
        foreach (($banksResult['data'] ?? []) as $b) {
            $code = trim($b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '');
            if ($code === (string) $user['payout_bank_code']) {
                $name = trim($b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? '');
                if ($name !== '') $resolvedBankName = rtrim($name, '.');
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('kyc-review bank lookup error: ' . $e->getMessage());
    }
}

include __DIR__ . '/includes/header.php';
?>

<a href="/admin/kyc.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back to queue</a>

<?php if ($error): ?>
<div class="notice-box danger" style="display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:var(--r-lg);margin-bottom:20px;font-size:13px;background:var(--danger-bg);border:1px solid rgba(220,38,38,.2);color:var(--danger)">
  <i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="detail-grid">

<div style="display:flex;flex-direction:column;gap:20px">

  <?php if (!$kyc): ?>
  <div class="card"><div class="empty-state" style="padding:32px 0"><div class="empty-title">No KYC submission yet</div><div class="empty-sub"><?= htmlspecialchars($user['name']) ?> hasn't started verification.</div></div></div>
  <?php else: ?>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Submitted Identity</div>
    <div class="card-sub" style="margin-bottom:16px">What the merchant entered when they submitted for review.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div><div style="font-size:11px;color:var(--muted-2);text-transform:uppercase;font-weight:700;margin-bottom:4px">Full Name (declared)</div><div style="font-size:14px;font-weight:600;color:var(--ink)"><?= htmlspecialchars(trim(($kyc['kyc_first_name'] ?? '') . ' ' . ($kyc['kyc_last_name'] ?? '')) ?: ($kyc['full_name'] ?? '—')) ?></div></div>
      <div><div style="font-size:11px;color:var(--muted-2);text-transform:uppercase;font-weight:700;margin-bottom:4px">Account Name</div><div style="font-size:14px;font-weight:600;color:var(--ink)"><?= htmlspecialchars($user['name']) ?></div></div>
      <?php if (!empty($kyc['dob'])): ?>
      <div><div style="font-size:11px;color:var(--muted-2);text-transform:uppercase;font-weight:700;margin-bottom:4px">Date of Birth</div><div style="font-size:14px;font-weight:600;color:var(--ink)"><?= htmlspecialchars($kyc['dob']) ?></div></div>
      <?php endif; ?>
      <div><div style="font-size:11px;color:var(--muted-2);text-transform:uppercase;font-weight:700;margin-bottom:4px">Email</div><div style="font-size:14px;font-weight:600;color:var(--ink)"><?= htmlspecialchars($user['email']) ?></div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Documents</div>
    <div class="card-sub" style="margin-bottom:16px">Compare the name and photo on the ID against the account name above.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:8px">National ID (NIN)</div>
        <?php if (!$ninDocUrl): ?>
          <div style="font-size:13px;color:var(--muted)">Not uploaded.</div>
        <?php elseif (isPdfPath($kyc['nin_document_path'])): ?>
          <a href="<?= $ninDocUrl ?>" target="_blank" class="doc-pdf-box"><i class="ti ti-file-type-pdf" style="font-size:20px;color:var(--danger)"></i> View PDF</a>
        <?php else: ?>
          <a href="<?= $ninDocUrl ?>" target="_blank" class="doc-thumb"><img src="<?= $ninDocUrl ?>" alt="NIN document"></a>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:8px">Proof of Address</div>
        <?php if (!$poaDocUrl): ?>
          <div style="font-size:13px;color:var(--muted)">Not uploaded.</div>
        <?php elseif (isPdfPath($kyc['proof_of_address_path'])): ?>
          <a href="<?= $poaDocUrl ?>" target="_blank" class="doc-pdf-box"><i class="ti ti-file-type-pdf" style="font-size:20px;color:var(--danger)"></i> View PDF</a>
        <?php else: ?>
          <a href="<?= $poaDocUrl ?>" target="_blank" class="doc-thumb"><img src="<?= $poaDocUrl ?>" alt="Proof of address document"></a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Payout Account</div>
    <div class="card-sub" style="margin-bottom:16px">Must be in the same name as the ID above before you approve.</div>
    <?php if (empty($user['payout_account_number'])): ?>
      <div style="font-size:13px;color:var(--muted)">No payout account submitted.</div>
    <?php else: ?>
    <div style="background:var(--surface);border-radius:var(--r-lg);padding:16px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px"><span style="color:var(--muted)">Bank</span><strong style="color:var(--ink)"><?= htmlspecialchars($resolvedBankName) ?></strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px"><span style="color:var(--muted)">Account Number</span><strong style="color:var(--ink)"><?= htmlspecialchars($user['payout_account_number']) ?></strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px"><span style="color:var(--muted)">Account Name</span><strong style="color:var(--ink)"><?= htmlspecialchars($user['payout_account_name']) ?></strong></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($kyc['status'] === 'failed' && $kyc['rejection_reason']): ?>
  <div class="card" style="border-color:var(--danger-bg)">
    <div class="card-title" style="color:var(--danger);margin-bottom:4px">Previous Rejection</div>
    <div style="font-size:13px;color:var(--ink)"><?= htmlspecialchars($kyc['rejection_reason']) ?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:6px"><?= $kyc['reviewed_at'] ? nigeriaTime($kyc['reviewed_at']) : '' ?></div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<div style="display:flex;flex-direction:column;gap:20px">
  <div class="card">
    <div class="card-title" style="margin-bottom:12px">Status</div>
    <?php
      $statusColor = ['pending'=>'warning','verified'=>'success','failed'=>'danger'][$kyc['status'] ?? ''] ?? 'neutral';
    ?>
    <?php if ($kyc): ?>
    <span class="badge badge-<?= $statusColor ?>" style="margin-bottom:14px;display:inline-block"><?= ucfirst($kyc['status']) ?></span>
    <?php endif; ?>

    <?php if (!empty($user['payout_locked'])): ?>
    <div style="background:var(--danger-bg);border:1px solid rgba(220,38,38,.2);border-radius:var(--r-lg);padding:12px 14px;font-size:12.5px;color:var(--danger);margin-bottom:14px">
      <strong>Payouts locked:</strong> <?= htmlspecialchars($user['payout_locked_reason'] ?: 'No reason recorded') ?>
      <form method="POST" style="margin-top:8px">
        <?= csrfField() ?><input type="hidden" name="action" value="unlock_payout">
        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;justify-content:center">Unlock Payouts</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($kyc && $kyc['status'] === 'pending'): ?>
    <form method="POST" style="margin-bottom:10px">
      <?= csrfField() ?><input type="hidden" name="action" value="approve">
      <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center" onclick="return confirm('Approve this KYC submission? The merchant will be able to withdraw immediately.')">
        <i class="ti ti-check"></i> Approve
      </button>
    </form>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="reject">
      <textarea name="reason" class="form-textarea" placeholder="Reason for rejection (shown to the merchant)" required style="margin-bottom:8px" rows="3"></textarea>
      <button type="submit" class="btn btn-outline btn-md" style="width:100%;justify-content:center;color:var(--danger);border-color:var(--danger-bg)">
        <i class="ti ti-x"></i> Reject
      </button>
    </form>
    <?php endif; ?>

    <a href="/admin/user-detail.php?id=<?= $userId ?>" style="display:block;text-align:center;margin-top:14px;font-size:12.5px;font-weight:600;color:var(--purple-600);text-decoration:none">View full merchant profile →</a>
  </div>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
