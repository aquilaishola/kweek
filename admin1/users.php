<?php
$pageTitle = 'Merchants';
$activePage = 'users';
require_once __DIR__ . '/includes/layout-top.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle_status') {
    verifyCsrf();
    $uid = (int)post('user_id');
    $stmt = $db->prepare("SELECT status FROM users WHERE id=? AND owner_user_id IS NULL");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if ($row) {
        $new = $row['status'] === 'suspended' ? 'active' : 'suspended';
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $uid]);
        flash('success', 'Merchant status updated to ' . $new . '.');
    }
    redirect('/admin/users.php' . (post('redirect_qs') ? '?' . post('redirect_qs') : ''));
}

$search = trim(get('q'));
$planFilter   = get('plan');
$statusFilter = get('status');
$page  = max(1, (int)get('page', 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["owner_user_id IS NULL"];
$params = [];
if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($planFilter && in_array($planFilter, ['free','starter','pro','business'], true)) {
    $where[] = "plan = ?"; $params[] = $planFilter;
}
if ($statusFilter && in_array($statusFilter, ['active','suspended','pending'], true)) {
    $where[] = "status = ?"; $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("SELECT id,name,email,phone,plan,status,wallet_balance,total_earned,bvn_verified,created_at FROM users WHERE $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$merchants = $stmt->fetchAll();

$qs = http_build_query(array_filter(['q'=>$search,'plan'=>$planFilter,'status'=>$statusFilter]));
?>

<div class="filter-bar">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
    <input type="text" name="q" class="filter-input" placeholder="Search name, email, phone" value="<?= htmlspecialchars($search) ?>" style="min-width:220px">
    <select name="plan" class="filter-select">
      <option value="">All plans</option>
      <?php foreach (['free','starter','pro','business'] as $p): ?>
      <option value="<?= $p ?>" <?= $planFilter === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="filter-select">
      <option value="">All statuses</option>
      <?php foreach (['active','suspended','pending'] as $s): ?>
      <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-search"></i> Filter</button>
    <?php if ($search || $planFilter || $statusFilter): ?>
    <a href="/admin/users.php" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>Merchant</th><th>Plan</th><th>Wallet</th><th>Total Earned</th><th>BVN</th><th>Status</th><th>Joined</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($merchants)): ?>
      <tr class="empty-row"><td colspan="8">No merchants match this filter.</td></tr>
      <?php else: foreach ($merchants as $m):
        $sMap = ['active'=>'success','suspended'=>'danger','pending'=>'warning'];
      ?>
      <tr>
        <td>
          <div class="td-bold"><?= htmlspecialchars($m['name']) ?></div>
          <div class="td-muted"><?= htmlspecialchars($m['email']) ?></div>
        </td>
        <td><span class="badge badge-purple"><?= ucfirst($m['plan']) ?></span></td>
        <td class="mono"><?= formatNaira($m['wallet_balance']) ?></td>
        <td class="mono"><?= formatNaira($m['total_earned']) ?></td>
        <td><?php if ($m['bvn_verified']): ?><i class="ti ti-circle-check" style="color:var(--success)"></i><?php else: ?><i class="ti ti-circle-x" style="color:var(--n400)"></i><?php endif; ?></td>
        <td><span class="badge badge-<?= $sMap[$m['status']] ?? 'neutral' ?>"><?= ucfirst($m['status']) ?></span></td>
        <td class="td-muted"><?= date('M j, Y', strtotime($m['created_at'])) ?></td>
        <td style="display:flex;gap:6px">
          <a href="/admin/user-view.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-eye"></i></a>
          <form method="POST" onsubmit="return confirm('<?= $m['status'] === 'suspended' ? 'Reactivate' : 'Suspend' ?> this merchant?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
            <input type="hidden" name="redirect_qs" value="<?= htmlspecialchars($qs) ?>">
            <button type="submit" class="btn btn-sm <?= $m['status'] === 'suspended' ? 'btn-success' : 'btn-danger' ?>">
              <i class="ti ti-<?= $m['status'] === 'suspended' ? 'player-play' : 'ban' ?>"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <a href="?<?= $qs ? $qs . '&' : '' ?>page=<?= $p ?>" class="page-link <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>