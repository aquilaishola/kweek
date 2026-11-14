<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('view_orders');

$pageTitle  = 'Orders';
$activePage = 'orders';
$db         = db();

// ── stats ──────────────────────────────────────────────────────
$totalOrders   = (int) $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$ordersToday   = (int) $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$pendingOrders = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending_confirmation'")->fetchColumn();
$paidToday     = (float) $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status IN ('paid','completed') AND DATE(paid_at) = CURDATE()")->fetchColumn();

// ── list ───────────────────────────────────────────────────────
$statusOptions = ['pending_confirmation','confirmed','pending_payment','verifying','paid','completed','cancelled'];
$statusFilter = in_array(get('status'), $statusOptions, true) ? get('status') : '';
$search = trim((string) get('q'));

$where = ['1=1']; $params = [];
if ($statusFilter !== '') { $where[] = 'o.status = ?'; $params[] = $statusFilter; }
if ($search !== '') { $where[] = '(o.order_ref LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR u.name LIKE ? OR pl.title LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%", "%$search%"); }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id JOIN payment_links pl ON pl.id = o.link_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT o.*, u.name AS merchant_name, pl.title AS link_title
    FROM orders o JOIN users u ON u.id = o.user_id JOIN payment_links pl ON pl.id = o.link_id
    WHERE $whereSql ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statusColors = [
    'pending_confirmation' => 'warning', 'confirmed' => 'purple', 'pending_payment' => 'warning',
    'verifying' => 'warning', 'paid' => 'success', 'completed' => 'success', 'cancelled' => 'danger',
];

function ordersUrl(array $overrides = []): string {
    $q = array_filter(array_merge(['status'=>get('status'),'q'=>get('q')], $overrides), fn($v)=>$v!=='' && $v!==null);
    return '/admin/orders.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-shopping-cart"></i> Total Orders</div><div class="stat-card-value"><?= number_format($totalOrders) ?></div><div class="stat-card-sub">+<?= $ordersToday ?> today</div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-clock"></i> Awaiting Confirmation</div><div class="stat-card-value"><?= number_format($pendingOrders) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-cash"></i> Paid Today</div><div class="stat-card-value"><?= formatNaira($paidToday) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-calendar"></i> Orders Today</div><div class="stat-card-value"><?= number_format($ordersToday) ?></div></div>
</div>

<div class="filter-bar">
  <a href="<?= ordersUrl(['status'=>'']) ?>" class="filter-tab <?= $statusFilter===''?'active':'' ?>">All</a>
  <?php foreach ($statusOptions as $s): ?>
  <a href="<?= ordersUrl(['status'=>$s]) ?>" class="filter-tab <?= $statusFilter===$s?'active':'' ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></a>
  <?php endforeach; ?>
  <form method="GET" class="search-input-wrap">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search ref, customer, merchant...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state"><div class="empty-title">No orders found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Order</th><th>Merchant</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $o): ?>
    <tr>
      <td>
        <div style="font-family:var(--font-mono,monospace);font-size:11px;color:var(--muted)"><?= htmlspecialchars($o['order_ref']) ?></div>
        <div style="font-size:12px;color:var(--muted-2)"><?= htmlspecialchars($o['link_title']) ?></div>
      </td>
      <td style="font-size:13px;font-weight:600;color:var(--ink)"><?= htmlspecialchars($o['merchant_name']) ?></td>
      <td>
        <div class="td-bold"><?= htmlspecialchars($o['customer_name']) ?></div>
        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($o['customer_phone']) ?></div>
      </td>
      <td class="td-bold"><?= formatNaira((float)$o['total_amount']) ?></td>
      <td><span class="badge badge-<?= $statusColors[$o['status']] ?? 'neutral' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($o['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= ordersUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= ordersUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
