<?php
// admin/orders.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Orders';
$activePage = 'orders';
$db         = db();

$statusF = in_array(get('status'), ['pending_confirmation','confirmed','pending_payment','paid','completed','declined','cancelled']) ? get('status') : '';
$search  = clean(get('q'));
$page    = max(1,(int)get('page',1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$where  = ['1=1'];
$params = [];
if ($statusF) { $where[] = "o.status=?"; $params[] = $statusF; }
if ($search)  { $where[] = "(o.customer_name LIKE ? OR o.order_ref LIKE ? OR u.name LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
$wSQL = implode(' AND ', $where);

$cStmt = $db->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id=u.id WHERE $wSQL");
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("
    SELECT o.*, u.name as merchant_name, pl.title as link_title
    FROM orders o
    JOIN users u ON o.user_id=u.id
    JOIN payment_links pl ON o.link_id=pl.id
    WHERE $wSQL ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totalPaid = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status IN ('paid','completed')")->fetchColumn();
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending_confirmation'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:20px">
  <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Orders</h1>
  <p style="font-size:13px;color:var(--n500);margin-top:2px">All orders placed across every merchant on the platform</p>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-shopping-cart"></i></div>
    <div class="stat-label">Total Orders</div>
    <div class="stat-value" style="font-size:20px"><?= number_format($total) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--success-bg);color:var(--success)"><i class="ti ti-check"></i></div>
    <div class="stat-label">Total Order Value</div>
    <div class="stat-value" style="font-size:20px"><?= formatNaira($totalPaid) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i class="ti ti-clock"></i></div>
    <div class="stat-label">Awaiting Confirmation</div>
    <div class="stat-value" style="font-size:20px"><?= $pendingCount ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px;padding:12px 14px">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:180px;position:relative">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:14px"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Customer, ref, or merchant..." style="width:100%;font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px 7px 28px;outline:none;background:var(--n50)">
    </div>
    <select name="status" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px;background:var(--n50)">
      <option value="">All Statuses</option>
      <?php foreach (['pending_confirmation'=>'Pending Confirm','confirmed'=>'Confirmed','pending_payment'=>'Awaiting Payment','paid'=>'Paid','completed'=>'Completed','declined'=>'Declined','cancelled'=>'Cancelled'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= $statusF===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-filter"></i> Filter</button>
    <a href="/admin/orders.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($orders)): ?>
  <div style="text-align:center;padding:40px;color:var(--n500)">No orders found.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Ref</th><th>Merchant</th><th>Customer</th><th>Link</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o):
      $sm = ['pending_confirmation'=>'warning','confirmed'=>'purple','pending_payment'=>'warning','paid'=>'success','completed'=>'success','declined'=>'danger','cancelled'=>'neutral'];
      $sc = $sm[$o['status']] ?? 'neutral';
    ?>
    <tr>
      <td style="font-family:monospace;font-size:11px;color:var(--n500)"><?= htmlspecialchars($o['order_ref']) ?></td>
      <td class="td-bold"><?= htmlspecialchars($o['merchant_name']) ?></td>
      <td>
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($o['customer_name']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($o['customer_phone']) ?></div>
      </td>
      <td style="font-size:12px;color:var(--p600)"><?= htmlspecialchars($o['link_title']) ?></td>
      <td class="td-bold"><?= formatNaira($o['total_amount']) ?></td>
      <td><span class="badge badge-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
      <td style="font-size:11px;color:var(--n500);white-space:nowrap"><?= timeAgo($o['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&status=<?= $statusF ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><a href="?page=<?= $i ?>&status=<?= $statusF ?>&q=<?= urlencode($search) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&status=<?= $statusF ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>