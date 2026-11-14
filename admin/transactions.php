<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('view_transactions');

$pageTitle  = 'Transactions';
$activePage = 'transactions';
$db         = db();

// ── stats ──────────────────────────────────────────────────────
$txToday      = (int) $db->query("SELECT COUNT(*) FROM transactions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$volumeToday  = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success' AND DATE(created_at) = CURDATE()")->fetchColumn();
$volumeMonth  = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
$failedCount  = (int) $db->query("SELECT COUNT(*) FROM transactions WHERE status='failed'")->fetchColumn();

$typeFilter = in_array(get('type'), ['credit','debit','withdrawal','refund','fee']) ? get('type') : '';
$search     = trim((string) get('q'));

$where = ['1=1']; $params = [];
if ($typeFilter !== '') { $where[] = 't.type = ?'; $params[] = $typeFilter; }
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR t.reference LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id = t.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT t.*, u.name AS user_name, u.email AS user_email
    FROM transactions t JOIN users u ON u.id = t.user_id
    WHERE $whereSql ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function txUrl(array $overrides = []): string {
    $q = array_filter(array_merge(['type'=>get('type'),'q'=>get('q')], $overrides), fn($v)=>$v!=='' && $v!==null);
    return '/admin/transactions.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-receipt"></i> Transactions Today</div><div class="stat-card-value"><?= number_format($txToday) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-cash"></i> Volume Today</div><div class="stat-card-value"><?= formatNaira($volumeToday) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-calendar"></i> Volume This Month</div><div class="stat-card-value"><?= formatNaira($volumeMonth) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-alert-triangle"></i> Failed (All Time)</div><div class="stat-card-value"><?= number_format($failedCount) ?></div></div>
</div>

<div class="filter-bar">
  <a href="<?= txUrl(['type'=>'']) ?>" class="filter-tab <?= $typeFilter===''?'active':'' ?>">All</a>
  <?php foreach (['credit','debit','withdrawal','refund','fee'] as $t): ?>
  <a href="<?= txUrl(['type'=>$t]) ?>" class="filter-tab <?= $typeFilter===$t?'active':'' ?>"><?= ucfirst($t) ?></a>
  <?php endforeach; ?>
  <form method="GET" class="search-input-wrap">
    <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search merchant or reference...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state" style="padding:48px 20px"><div class="empty-title">No transactions found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Merchant</th><th>Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
    <tr>
      <td><div class="td-bold"><?= htmlspecialchars($t['user_name']) ?></div><div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($t['user_email']) ?></div></td>
      <td style="text-transform:capitalize"><?= htmlspecialchars($t['type']) ?></td>
      <td class="td-bold" style="color:<?= in_array($t['type'],['debit','withdrawal','fee'])?'var(--danger)':'var(--success)' ?>">
        <?= in_array($t['type'],['debit','withdrawal','fee'])?'-':'+' ?><?= formatNaira((float)$t['amount']) ?>
      </td>
      <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($t['description']) ?></td>
      <td><span class="badge badge-<?= $t['status']==='success'?'success':($t['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($t['status']) ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($t['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= txUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= txUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
