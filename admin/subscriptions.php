<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
$admin = currentAdmin();
if (!adminCan($admin, 'view_subscriptions') && !adminCan($admin, 'manage_subscriptions')) {
    http_response_code(403);
    $pageTitle = 'Subscriptions'; $activePage = 'subscriptions';
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/403.php';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$canManage = adminCan($admin, 'manage_subscriptions');

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    verifyCsrf();
    if (post('action') === 'cancel_subscription') {
        $id = (int) post('sub_id');
        $stmt = $db->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $sub = $stmt->fetch();
        if ($sub) {
            $db->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE users SET plan = 'free' WHERE id = ?")->execute([$sub['user_id']]);
            if (function_exists('enforceLinkLimit')) enforceLinkLimit((int) $sub['user_id']);
            notify((int)$sub['user_id'], 'plan', 'Subscription Cancelled', 'Your ' . ucfirst($sub['plan']) . ' subscription was cancelled by an admin. You are now on the Free plan.', 'alert-triangle', '/settings.php?tab=billing');
            flash('success', 'Subscription cancelled.');
        }
        redirect('/admin/subscriptions.php');
    }
}

$statusFilter = in_array(get('status'), ['pending','active','cancelled','expired','trial']) ? get('status') : '';
$search = trim((string) get('q'));

$where = ['1=1']; $params = [];
if ($statusFilter !== '') { $where[] = 's.status = ?'; $params[] = $statusFilter; }
if ($search !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id = s.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT s.*, u.name AS user_name, u.email AS user_email
    FROM subscriptions s JOIN users u ON u.id = s.user_id
    WHERE $whereSql ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function subUrl(array $overrides = []): string {
    $q = array_filter(array_merge(['status'=>get('status'),'q'=>get('q')], $overrides), fn($v)=>$v!=='' && $v!==null);
    return '/admin/subscriptions.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
  <a href="<?= subUrl(['status'=>'']) ?>" class="filter-tab <?= $statusFilter===''?'active':'' ?>">All</a>
  <?php foreach (['trial','active','pending','cancelled','expired'] as $s): ?>
  <a href="<?= subUrl(['status'=>$s]) ?>" class="filter-tab <?= $statusFilter===$s?'active':'' ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
  <form method="GET" class="search-input-wrap">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search merchant...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state" style="padding:48px 20px"><div class="empty-title">No subscriptions found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Merchant</th><th>Plan</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Period</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
    <tr>
      <td><div class="td-bold"><?= htmlspecialchars($s['user_name']) ?></div><div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($s['user_email']) ?></div></td>
      <td style="text-transform:capitalize"><?= htmlspecialchars($s['plan']) ?></td>
      <td style="text-transform:capitalize"><?= htmlspecialchars($s['billing_cycle']) ?></td>
      <td class="td-bold"><?= formatNaira((float)$s['amount']) ?></td>
      <td><span class="badge badge-<?= in_array($s['status'],['active','trial'])?'success':($s['status']==='cancelled'?'danger':'neutral') ?>"><?= ucfirst($s['status']) ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= date('M j, Y', strtotime($s['starts_at'])) ?> – <?= date('M j, Y', strtotime($s['ends_at'])) ?></td>
      <?php if ($canManage): ?>
      <td>
        <?php if (in_array($s['status'], ['active','trial'], true)): ?>
        <form method="POST" onsubmit="return confirm('Cancel this subscription? The merchant drops to Free immediately.')">
          <?= csrfField() ?><input type="hidden" name="action" value="cancel_subscription"><input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger-bg)">Cancel</button>
        </form>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= subUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= subUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
