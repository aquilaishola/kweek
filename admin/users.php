<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
if (!adminCan(currentAdmin(), 'manage_users') && !adminCan(currentAdmin(), 'view_users')) {
    http_response_code(403);
    $pageTitle = 'Users'; $activePage = 'users';
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/403.php';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle  = 'Users';
$activePage = 'users';
$db         = db();

// ── stats ──────────────────────────────────────────────────────
$totalUsers     = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL")->fetchColumn();
$newToday       = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND DATE(created_at) = CURDATE()")->fetchColumn();
$suspendedCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND status = 'suspended'")->fetchColumn();
$onlineNow      = null;
if (usersHaveLastSeenColumn()) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND last_seen_at >= NOW() - INTERVAL ? MINUTE");
    $stmt->execute([ADMIN_ONLINE_THRESHOLD_MINUTES]);
    $onlineNow = (int) $stmt->fetchColumn();
}

$search      = trim((string) get('q'));
$planFilter  = in_array(get('plan'), ['free','starter','pro','business']) ? get('plan') : '';
$statusFilter = in_array(get('status'), ['active','suspended','pending']) ? get('status') : '';

$where = ["owner_user_id IS NULL"];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($planFilter !== '') { $where[] = "plan = ?"; $params[] = $planFilter; }
if ($statusFilter !== '') { $where[] = "status = ?"; $params[] = $statusFilter; }

$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$lastSeenSelect = usersHaveLastSeenColumn() ? ',last_seen_at' : '';
$stmt = $db->prepare("SELECT id,name,email,phone,plan,status,bvn_verified,payout_verified,payout_locked,wallet_balance,created_at$lastSeenSelect FROM users WHERE $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function usersUrl(array $overrides = []): string {
    $q = array_merge(['q'=>get('q'),'plan'=>get('plan'),'status'=>get('status')], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return '/admin/users.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-users-group"></i> Total Merchants</div><div class="stat-card-value"><?= number_format($totalUsers) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-user-plus"></i> New Today</div><div class="stat-card-value"><?= number_format($newToday) ?></div></div>
  <div class="stat-card">
    <div class="stat-card-label"><i class="ti ti-antenna-bars-5"></i> Online Now</div>
    <?php if ($onlineNow === null): ?>
      <div class="stat-card-sub" style="margin-top:0">Requires <code>last_seen_at</code> — see README</div>
    <?php else: ?>
      <div class="stat-card-value"><span class="online-dot"></span><?= number_format($onlineNow) ?></div>
      <div class="stat-card-sub">Active in the last <?= ADMIN_ONLINE_THRESHOLD_MINUTES ?> min</div>
    <?php endif; ?>
  </div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-user-off"></i> Suspended</div><div class="stat-card-value"><?= number_format($suspendedCount) ?></div></div>
</div>

<div class="filter-bar">
  <a href="<?= usersUrl(['status'=>'']) ?>" class="filter-tab <?= $statusFilter===''?'active':'' ?>">All</a>
  <a href="<?= usersUrl(['status'=>'active']) ?>" class="filter-tab <?= $statusFilter==='active'?'active':'' ?>">Active</a>
  <a href="<?= usersUrl(['status'=>'suspended']) ?>" class="filter-tab <?= $statusFilter==='suspended'?'active':'' ?>">Suspended</a>
  <a href="<?= usersUrl(['status'=>'pending']) ?>" class="filter-tab <?= $statusFilter==='pending'?'active':'' ?>">Pending</a>

  <select onchange="location.href=this.value" class="form-select" style="max-width:150px">
    <option value="<?= usersUrl(['plan'=>'']) ?>" <?= $planFilter===''?'selected':'' ?>>All plans</option>
    <?php foreach (['free','starter','pro','business'] as $p): ?>
    <option value="<?= usersUrl(['plan'=>$p]) ?>" <?= $planFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
    <?php endforeach; ?>
  </select>

  <form method="GET" class="search-input-wrap">
    <?php if ($planFilter) : ?><input type="hidden" name="plan" value="<?= htmlspecialchars($planFilter) ?>"><?php endif; ?>
    <?php if ($statusFilter) : ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, phone...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state" style="padding:48px 20px"><div class="empty-title">No merchants found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Merchant</th><th>Plan</th><th>Status</th><th>KYC</th><th>Wallet</th><th>Joined</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
    <tr>
      <td>
        <div class="td-bold">
          <?php if (!empty($u['last_seen_at']) && strtotime($u['last_seen_at']) >= strtotime('-' . ADMIN_ONLINE_THRESHOLD_MINUTES . ' minutes')): ?><span class="online-dot" title="Online"></span><?php endif; ?>
          <?= htmlspecialchars($u['name']) ?>
        </div>
        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></div>
      </td>
      <td style="text-transform:capitalize"><?= htmlspecialchars($u['plan']) ?></td>
      <td><span class="badge badge-<?= $u['status']==='active'?'success':($u['status']==='suspended'?'danger':'warning') ?>"><?= ucfirst($u['status']) ?></span></td>
      <td>
        <?php if ($u['payout_locked']): ?><span class="badge badge-danger">Locked</span>
        <?php elseif ($u['bvn_verified'] && $u['payout_verified']): ?><span class="badge badge-success">Verified</span>
        <?php else: ?><span class="badge badge-neutral">Unverified</span><?php endif; ?>
      </td>
      <td class="td-bold"><?= formatNaira((float)$u['wallet_balance']) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($u['created_at']) ?></td>
      <td style="text-align:right"><a href="/admin/user-detail.php?id=<?= $u['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= usersUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= usersUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
