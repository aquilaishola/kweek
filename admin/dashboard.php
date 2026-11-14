<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$admin      = currentAdmin();
$db         = db();

$totalUsers      = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL")->fetchColumn();
$activeUsers     = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND status = 'active'")->fetchColumn();
$suspendedUsers  = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND status = 'suspended'")->fetchColumn();
$newUsersWeek    = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();

$totalWalletLiability = (float) $db->query("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE owner_user_id IS NULL")->fetchColumn();

$planCounts = $db->query("SELECT plan, COUNT(*) c FROM users WHERE owner_user_id IS NULL GROUP BY plan")->fetchAll(PDO::FETCH_KEY_PAIR);

$pendingKyc  = adminCan($admin, 'manage_kyc') ? (int) $db->query("SELECT COUNT(*) FROM user_kyc WHERE status = 'pending'")->fetchColumn() : null;
$verifiedKyc = adminCan($admin, 'manage_kyc') ? (int) $db->query("SELECT COUNT(*) FROM user_kyc WHERE status = 'verified'")->fetchColumn() : null;

$pendingWithdrawals = null;
$withdrawalVolumeMonth = null;
if (adminCan($admin, 'manage_withdrawals')) {
    $pendingWithdrawals = (int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE status IN ('pending','processing')")->fetchColumn();
    $withdrawalVolumeMonth = (float) $db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status = 'success' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
}

$txVolumeMonth = null;
if (adminCan($admin, 'view_transactions')) {
    $txVolumeMonth = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
}

$activeSubs = null;
$mrr = null;
if (adminCan($admin, 'view_subscriptions') || adminCan($admin, 'manage_subscriptions')) {
    $activeSubs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
    $mrr = (float) $db->query("
        SELECT COALESCE(SUM(CASE WHEN billing_cycle='annual' THEN amount/12 ELSE amount END),0)
        FROM subscriptions WHERE status = 'active'
    ")->fetchColumn();
}

$recentUsers = $db->query("SELECT id,name,email,plan,status,created_at FROM users WHERE owner_user_id IS NULL ORDER BY created_at DESC LIMIT 6")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-users-group"></i> Merchants</div>
    <div class="stat-card-value"><?= number_format($totalUsers) ?></div>
    <div class="stat-card-sub"><?= number_format($activeUsers) ?> active · <?= number_format($suspendedUsers) ?> suspended · +<?= $newUsersWeek ?> this week</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-wallet"></i> Wallet Liability</div>
    <div class="stat-card-value"><?= formatNaira($totalWalletLiability) ?></div>
    <div class="stat-card-sub">Total sitting in merchant wallets</div>
  </div>
  <?php if ($pendingKyc !== null): ?>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-id-badge"></i> KYC Queue</div>
    <div class="stat-card-value"><?= number_format($pendingKyc) ?></div>
    <div class="stat-card-sub"><?= number_format($verifiedKyc) ?> verified total</div>
  </div>
  <?php endif; ?>
  <?php if ($pendingWithdrawals !== null): ?>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-cash"></i> Withdrawals Pending</div>
    <div class="stat-card-value"><?= number_format($pendingWithdrawals) ?></div>
    <div class="stat-card-sub"><?= formatNaira($withdrawalVolumeMonth) ?> paid out this month</div>
  </div>
  <?php endif; ?>
  <?php if ($txVolumeMonth !== null): ?>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-receipt"></i> Volume This Month</div>
    <div class="stat-card-value"><?= formatNaira($txVolumeMonth) ?></div>
    <div class="stat-card-sub">Total credited to merchants</div>
  </div>
  <?php endif; ?>
  <?php if ($activeSubs !== null): ?>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-crown"></i> Active Subscriptions</div>
    <div class="stat-card-value"><?= number_format($activeSubs) ?></div>
    <div class="stat-card-sub">~<?= formatNaira($mrr) ?> MRR</div>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr;gap:20px">

  <?php if (!empty($planCounts)): ?>
  <div class="card">
    <div class="card-title" style="margin-bottom:16px">Merchants by Plan</div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
      <?php foreach (['free','starter','pro','business'] as $p): ?>
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:14px;text-align:center">
        <div style="font-family:var(--font-display,inherit);font-size:20px;font-weight:800;color:var(--ink)"><?= number_format($planCounts[$p] ?? 0) ?></div>
        <div style="font-size:12px;color:var(--muted);text-transform:capitalize;margin-top:2px"><?= $p ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <div class="table-head">
      <span class="table-title">Newest Merchants</span>
      <?php if (adminCan($admin, 'manage_users') || adminCan($admin, 'view_users')): ?>
      <a href="/admin/users.php" style="font-size:12.5px;font-weight:600;color:var(--purple-600);text-decoration:none">View all →</a>
      <?php endif; ?>
    </div>
    <?php if (empty($recentUsers)): ?>
      <div class="empty-state" style="padding:32px 20px"><div class="empty-title">No merchants yet</div></div>
    <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Plan</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach ($recentUsers as $u): ?>
      <tr>
        <td class="td-bold"><a href="/admin/user-detail.php?id=<?= $u['id'] ?>" style="color:var(--ink);text-decoration:none"><?= htmlspecialchars($u['name']) ?></a></td>
        <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></td>
        <td style="text-transform:capitalize"><?= htmlspecialchars($u['plan']) ?></td>
        <td><span class="badge badge-<?= $u['status']==='active'?'success':($u['status']==='suspended'?'danger':'warning') ?>"><?= ucfirst($u['status']) ?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($u['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
