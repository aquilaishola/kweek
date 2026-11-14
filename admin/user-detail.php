<?php
require_once __DIR__ . '/includes/admin_functions.php';
require_once __DIR__ . '/../includes/integration.php';
requireAdminLogin();
$admin = currentAdmin();
if (!adminCan($admin, 'manage_users') && !adminCan($admin, 'view_users')) {
    http_response_code(403);
    $pageTitle = 'User'; $activePage = 'users';
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/403.php';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$canManage = adminCan($admin, 'manage_users');

$db = db();
$userId = (int) get('id');
if (!$userId) redirect('/admin/users.php');

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND owner_user_id IS NULL LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) redirect('/admin/users.php');

$pageTitle  = $user['name'];
$activePage = 'users';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden');
    }
    verifyCsrf();
    $action = post('action');

    if ($action === 'toggle_status') {
        $newStatus = $user['status'] === 'suspended' ? 'active' : 'suspended';
        $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
        flash('success', $newStatus === 'suspended' ? 'Account suspended.' : 'Account reactivated.');
        redirect('/admin/user-detail.php?id=' . $userId);
    }

    elseif ($action === 'change_plan') {
    $newPlan = post('plan');
    if (!in_array($newPlan, ['free','starter','pro','business'], true)) {
        $error = 'Invalid plan.';
    } else {
        try {
            kwApplyPlanChange($db, $userId, $newPlan);   
            notify($userId, 'plan_changed', 'Your plan was updated', 'Your plan is now ' . ucfirst($newPlan) . '.', 'crown', '/settings.php?tab=billing');
            flash('success', 'Plan changed to ' . ucfirst($newPlan) . '.');
            redirect('/admin/user-detail.php?id=' . $userId);
        } catch (Throwable $e) {
            error_log('change_plan: ' . $e->getMessage());
            $error = 'Could not change plan: ' . $e->getMessage();
        }
    }
}

    elseif ($action === 'adjust_wallet') {
        $amount = abs((float) post('amount'));
        $mode   = post('mode'); // credit | debit
        $reason = clean(post('reason'));

        if ($amount <= 0) {
            $error = 'Enter an amount greater than zero.';
        } elseif (strlen($reason) < 3) {
            $error = 'Give a reason for the audit trail.';
        } else {
            $ref = generateRef('ADJ');
            $ok = $mode === 'debit'
                ? debitWallet($userId, $amount, 'Admin adjustment: ' . $reason, $ref)
                : creditWallet($userId, $amount, 0, 'Admin adjustment: ' . $reason, $ref);

            if ($ok) {
                notify($userId, 'wallet_adjustment', 'Wallet Adjusted', ($mode === 'debit' ? '-' : '+') . formatNaira($amount) . ' — ' . $reason, 'wallet', '/transactions.php', false);
                flash('success', 'Wallet ' . $mode . 'ed by ' . formatNaira($amount) . '.');
            } else {
                flash('error', 'Adjustment failed — check the merchant has sufficient balance for a debit.');
            }
            redirect('/admin/user-detail.php?id=' . $userId);
        }
    }

    elseif ($action === 'unlock_payout') {
        $db->prepare("UPDATE users SET payout_locked = 0, payout_locked_reason = NULL WHERE id = ?")->execute([$userId]);
        flash('success', 'Payouts unlocked.');
        redirect('/admin/user-detail.php?id=' . $userId);
    }

    elseif ($action === 'lock_payout') {
        $reason = clean(post('lock_reason')) ?: 'Locked by admin';
        $db->prepare("UPDATE users SET payout_locked = 1, payout_locked_reason = ? WHERE id = ?")->execute([$reason, $userId]);
        flash('info', 'Payouts locked for this account.');
        redirect('/admin/user-detail.php?id=' . $userId);
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1"); $stmt->execute([$userId]); $user = $stmt->fetch();
}

$stmt = $db->prepare("SELECT COUNT(*) c, SUM(status='active') active_c FROM payment_links WHERE user_id = ?");
$stmt->execute([$userId]);
$linkStats = $stmt->fetch();

$orderCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$orderCount->execute([$userId]);
$orderCount = (int) $orderCount->fetchColumn();

$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
$stmt->execute([$userId]);
$recentTx = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
$stmt->execute([$userId]);
$recentWd = $stmt->fetchAll();

$stmt = $db->prepare("SELECT status FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId]);
$subStatus = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT status FROM user_kyc WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$kycStatus = $stmt->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<a href="/admin/users.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back to users</a>

<?php if ($error): ?>
<div style="display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:var(--r-lg);margin-bottom:20px;font-size:13px;background:var(--danger-bg);border:1px solid rgba(220,38,38,.2);color:var(--danger)">
  <i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="detail-grid" style="display:flex;flex-direction:column;gap:20px">

<div style="display:flex;flex-direction:column;gap:20px">

  <div class="card">
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--purple-600);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;flex-shrink:0"><?= strtoupper(substr($user['name'],0,2)) ?></div>
      <div>
        <div style="font-size:17px;font-weight:700;color:var(--ink)"><?= htmlspecialchars($user['name']) ?></div>
        <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($user['email']) ?> <?= $user['phone'] ? '· '.htmlspecialchars($user['phone']) : '' ?></div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
        <span class="badge badge-<?= $user['status']==='active'?'success':($user['status']==='suspended'?'danger':'warning') ?>"><?= ucfirst($user['status']) ?></span>
        <span class="badge badge-purple" style="text-transform:capitalize"><?= htmlspecialchars($user['plan']) ?></span>
      </div>
    </div>
    <div class="stat-mini-grid">
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:12px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:var(--ink)"><?= formatNaira((float)$user['wallet_balance']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Wallet</div>
      </div>
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:12px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:var(--ink)"><?= formatNaira((float)$user['total_earned']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Lifetime Earned</div>
      </div>
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:12px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:var(--ink)"><?= (int)($linkStats['active_c'] ?? 0) ?>/<?= (int)($linkStats['c'] ?? 0) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Active Links</div>
      </div>
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:12px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:var(--ink)"><?= $orderCount ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Orders</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:12px">Verification</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <span class="badge badge-<?= $user['bvn_verified']?'success':'neutral' ?>">Identity: <?= $user['bvn_verified']?'Verified':'Unverified' ?></span>
      <span class="badge badge-<?= $user['payout_verified']?'success':'neutral' ?>">Payout: <?= $user['payout_verified']?'Verified':'Unverified' ?></span>
      <?php if ($user['payout_locked']): ?><span class="badge badge-danger">Payouts Locked</span><?php endif; ?>
      <?php if ($kycStatus): ?><span class="badge badge-neutral">KYC: <?= ucfirst($kycStatus) ?></span><?php endif; ?>
    </div>
    <?php if ($user['payout_verified']): ?>
    <div style="font-size:13px;color:var(--muted);margin-bottom:10px">
      <?= htmlspecialchars($user['payout_bank_name']) ?> · <?= htmlspecialchars($user['payout_account_number']) ?> · <?= htmlspecialchars($user['payout_account_name']) ?>
    </div>
    <?php endif; ?>
    <a href="/admin/kyc-review.php?user_id=<?= $userId ?>" style="font-size:12.5px;font-weight:600;color:var(--purple-600);text-decoration:none">View KYC submission →</a>
  </div>

  <div class="table-wrap">
    <div class="table-head"><span class="table-title">Recent Transactions</span></div>
    <?php if (empty($recentTx)): ?>
    <div class="empty-state" style="padding:28px 20px"><div class="empty-title">No transactions yet</div></div>
    <?php else: ?>
    <table>
      <thead><tr><th>Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($recentTx as $t): ?>
      <tr>
        <td style="text-transform:capitalize"><?= htmlspecialchars($t['type']) ?></td>
        <td class="td-bold" style="color:<?= $t['type']==='debit'?'var(--danger)':'var(--success)' ?>"><?= $t['type']==='debit'?'-':'+' ?><?= formatNaira((float)$t['amount']) ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($t['description']) ?></td>
        <td><span class="badge badge-<?= $t['status']==='success'?'success':($t['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($t['status']) ?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($t['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <div class="table-head"><span class="table-title">Recent Withdrawals</span></div>
    <?php if (empty($recentWd)): ?>
    <div class="empty-state" style="padding:28px 20px"><div class="empty-title">No withdrawals yet</div></div>
    <?php else: ?>
    <table>
      <thead><tr><th>Amount</th><th>Bank</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($recentWd as $w): ?>
      <tr>
        <td class="td-bold"><?= formatNaira((float)$w['net_amount']) ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($w['bank_name']) ?> · <?= htmlspecialchars($w['account_number']) ?></td>
        <td><span class="badge badge-<?= $w['status']==='success'?'success':($w['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($w['status']) ?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($w['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<?php if ($canManage): ?>
<div style="display:flex;flex-direction:column;gap:20px">

  <div class="card">
    <div class="card-title" style="margin-bottom:12px">Account</div>
    <form method="POST" style="margin-bottom:12px">
      <?= csrfField() ?><input type="hidden" name="action" value="toggle_status">
      <button type="submit" class="btn btn-outline btn-md" style="width:100%;justify-content:center<?= $user['status']!=='suspended' ? ';color:var(--danger);border-color:var(--danger-bg)' : '' ?>"
        onclick="return confirm('<?= $user['status']==='suspended' ? 'Reactivate' : 'Suspend' ?> this account?')">
        <i class="ti ti-<?= $user['status']==='suspended'?'player-play':'player-pause' ?>"></i>
        <?= $user['status']==='suspended' ? 'Reactivate Account' : 'Suspend Account' ?>
      </button>
    </form>

    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="change_plan">
      <label class="form-label">Plan</label>
      <select name="plan" class="form-select" style="margin-bottom:8px">
        <?php foreach (['free','starter','pro','business'] as $p): ?>
        <option value="<?= $p ?>" <?= $user['plan']===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline btn-md" style="width:100%;justify-content:center">Change Plan</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:12px">Adjust Wallet</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="adjust_wallet">
      <div class="form-group">
        <label class="form-label">Amount (₦)</label>
        <input type="number" name="amount" min="1" step="1" class="form-input" required>
      </div>
      <div class="form-group">
        <label class="form-label">Direction</label>
        <select name="mode" class="form-select">
          <option value="credit">Credit (add funds)</option>
          <option value="debit">Debit (remove funds)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-input" placeholder="e.g. Refund for order #1234" required>
      </div>
      <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center" onclick="return confirm('Apply this wallet adjustment?')">Apply Adjustment</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:12px">Payout Lock</div>
    <?php if ($user['payout_locked']): ?>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="unlock_payout">
      <button type="submit" class="btn btn-outline btn-md" style="width:100%;justify-content:center">Unlock Payouts</button>
    </form>
    <?php else: ?>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="lock_payout">
      <input type="text" name="lock_reason" class="form-input" placeholder="Reason (optional)" style="margin-bottom:8px">
      <button type="submit" class="btn btn-outline btn-md" style="width:100%;justify-content:center;color:var(--danger);border-color:var(--danger-bg)">Lock Payouts</button>
    </form>
    <?php endif; ?>
  </div>

</div>
<?php endif; ?>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
