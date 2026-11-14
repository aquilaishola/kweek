<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Customers';
$activePage = 'customers';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$search = clean(get('q'));
$sort   = in_array(get('sort'), ['spend','orders','recent']) ? get('sort') : 'spend';
$page   = max(1, (int)get('page', 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$having = '';
$havingParams = [];
if ($search) {
    $having = "HAVING customer_phone LIKE ? OR MAX(customer_name) LIKE ?";
    $havingParams = ["%$search%", "%$search%"];
}

$sortMap = [
    'spend'  => 'lifetime_spend DESC',
    'orders' => 'order_count DESC',
    'recent' => 'last_order_at DESC',
];
$orderBySQL = $sortMap[$sort];

$baseSelect = "SELECT
        customer_phone,
        MAX(customer_name) as customer_name,
        MAX(customer_email) as customer_email,
        COUNT(*) as order_count,
        COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) as lifetime_spend,
        MIN(created_at) as first_order_at,
        MAX(created_at) as last_order_at
    FROM orders
    WHERE user_id = ?
    GROUP BY customer_phone
    $having";

// total count (wrap as subquery since GROUP BY + HAVING)
$countStmt = $db->prepare("SELECT COUNT(*) FROM ($baseSelect) as sub");
$countStmt->execute(array_merge([$uid], $havingParams));
$total = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$stmt = $db->prepare("$baseSelect ORDER BY $orderBySQL LIMIT $perPage OFFSET $offset");
$stmt->execute(array_merge([$uid], $havingParams));
$customers = $stmt->fetchAll();

// Summary stats (unfiltered — whole account)
$summaryStmt = $db->prepare("SELECT
        COUNT(DISTINCT customer_phone) as total_customers,
        COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) as total_ltv,
        (SELECT COUNT(*) FROM (SELECT customer_phone FROM orders WHERE user_id=? GROUP BY customer_phone HAVING COUNT(*) > 1) rep) as repeat_customers
    FROM orders WHERE user_id=?");
$summaryStmt->execute([$uid, $uid]);
$summary = $summaryStmt->fetch();
$avgLtv = $summary['total_customers'] > 0 ? $summary['total_ltv'] / $summary['total_customers'] : 0;

// ── Render the table + pagination once, into a buffer. Used as-is for a
// normal page load, and returned raw for an ajax=1 request so the search
// box can swap it in live without a full reload.
$isAjax = isset($_GET['ajax']);

ob_start();
if (empty($customers)):
?>
<div class="empty-state">
  <div class="empty-icon"><i class="ti ti-users"></i></div>
  <div class="empty-title">No customers found</div>
  <div class="empty-sub"><?= $search ? 'Try a different search term.' : 'Once orders come in, your customers will show up here.' ?></div>
</div>
<?php
else:
?>
<table>
  <thead>
    <tr><th>Customer</th><th>Orders</th><th>Lifetime Spend</th><th>First Order</th><th>Last Order</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($customers as $c):
    $initials = strtoupper(substr(trim($c['customer_name'] ?: '?'), 0, 1));
    $isRepeat = $c['order_count'] > 1;
  ?>
  <tr>
    <td>
      <div class="cu-name-row">
        <div class="cu-avatar"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="td-bold"><?= htmlspecialchars($c['customer_name'] ?: 'Unknown') ?><?php if ($isRepeat): ?><span class="cu-badge-repeat">Repeat</span><?php endif; ?></div>
          <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($c['customer_phone']) ?></div>
        </div>
      </div>
    </td>
    <td class="td-bold"><?= $c['order_count'] ?></td>
    <td class="td-bold"><?= formatNaira($c['lifetime_spend']) ?></td>
    <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($c['first_order_at']) ?></td>
    <td style="font-size:12px;color:var(--muted)"><?= timeAgo($c['last_order_at']) ?></td>
    <td>
      <button class="btn btn-outline btn-sm" onclick="viewCustomer('<?= htmlspecialchars(addslashes($c['customer_phone'])) ?>','<?= htmlspecialchars(addslashes($c['customer_name'] ?: 'Unknown')) ?>')">
        <i class="ti ti-eye"></i> Orders
      </button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> customers</span>
  <div class="pag-btns">
    <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
    <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
    <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
endif;
$resultsHtml = ob_get_clean();

if ($isAjax) {
    header('Content-Type: text/html; charset=utf-8');
    echo $resultsHtml;
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<style>
.cu-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.cu-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px;margin:0}
.cu-header p{font-size:14px;color:var(--muted);margin:2px 0 0}

.cu-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.cu-stat-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:16px;min-width:0}
.cu-stat-label{font-size:12px;color:var(--muted);margin-bottom:4px}
.cu-stat-value{font-family:var(--font-display);font-size:19px;font-weight:800;color:var(--ink);overflow-wrap:anywhere;word-break:break-word}

.cu-filters{display:flex;align-items:flex-end;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.cu-search-wrap{position:relative;flex:1;min-width:200px}
.cu-sort{font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--ink);border:1.5px solid var(--line);border-radius:var(--r-md);padding:9px 30px 9px 12px;background:var(--paper);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23726E85' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center}

.cu-avatar{width:36px;height:36px;border-radius:50%;background:var(--purple-50);color:var(--purple-600);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;font-family:var(--font-display);flex-shrink:0}
.cu-name-row{display:flex;align-items:center;gap:10px}

.cu-badge-repeat{background:var(--purple-50);color:var(--purple-600);font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:var(--r-full);margin-left:6px}

.cu-loading{opacity:.5;pointer-events:none;transition:opacity .15s}

.cu-spinner{width:28px;height:28px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--purple-600);animation:cu-spin .7s linear infinite}
@keyframes cu-spin{to{transform:rotate(360deg)}}
</style>

<div class="cu-header">
  <div>
    <h1>Customers</h1>
    <p>Everyone who's paid you, and what they're worth over time.</p>
  </div>
</div>

<div class="cu-stats">
  <div class="cu-stat-card">
    <div class="cu-stat-label">Total Customers</div>
    <div class="cu-stat-value"><?= number_format($summary['total_customers']) ?></div>
  </div>
  <div class="cu-stat-card">
    <div class="cu-stat-label">Repeat Customers</div>
    <div class="cu-stat-value"><?= number_format($summary['repeat_customers']) ?></div>
  </div>
  <div class="cu-stat-card">
    <div class="cu-stat-label">Avg. Lifetime Value</div>
    <div class="cu-stat-value"><?= formatNaira($avgLtv) ?></div>
  </div>
</div>

<div class="cu-filters">
  <div class="cu-search-wrap">
    <div class="form-input-wrap">
      <i class="ti ti-search inp-icon"></i>
      <input type="text" id="cu-search-input" class="form-input" placeholder="Search by name or phone..." value="<?= htmlspecialchars($search) ?>"
        oninput="cuLiveSearch(this.value)">
    </div>
  </div>
  <select class="cu-sort" onchange="cuChangeSort(this.value)">
    <option value="spend"  <?= $sort==='spend'?'selected':'' ?>>Highest lifetime spend</option>
    <option value="orders" <?= $sort==='orders'?'selected':'' ?>>Most orders</option>
    <option value="recent" <?= $sort==='recent'?'selected':'' ?>>Most recent order</option>
  </select>
</div>

<div class="table-wrap" id="cu-results"><?= $resultsHtml ?></div>

<!-- CUSTOMER ORDER HISTORY MODAL -->
<div class="modal" id="customer-modal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <div class="modal-title" id="customer-modal-title">Order History</div>
      <button class="modal-close" onclick="closeModal('customer-modal')"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body" id="customer-modal-body">
      <div style="display:flex;align-items:center;justify-content:center;padding:30px"><div class="cu-spinner"></div></div>
    </div>
  </div>
</div>

<script>
// ── LIVE SEARCH — filters in place as you type, no page reload ────────
let cuSearchTimer;
function cuLiveSearch(val) {
  clearTimeout(cuSearchTimer);
  cuSearchTimer = setTimeout(() => runCuSearch(val), 300);
}

function runCuSearch(val) {
  const url = new URL(window.location);
  url.searchParams.set('q', val);
  url.searchParams.set('page', 1);
  window.history.replaceState({}, '', url);

  const fetchUrl = new URL(url);
  fetchUrl.searchParams.set('ajax', '1');

  const results = document.getElementById('cu-results');
  results.classList.add('cu-loading');

  fetch(fetchUrl)
    .then(r => r.text())
    .then(html => {
      results.innerHTML = html;
      results.classList.remove('cu-loading');
    })
    .catch(() => {
      results.classList.remove('cu-loading');
      showToast('error', 'Search failed', 'Could not load results.');
    });
}

function cuChangeSort(val) {
  const url = new URL(window.location);
  url.searchParams.set('sort', val);
  url.searchParams.set('page', 1);
  window.location = url;
}

function viewCustomer(phone, name) {
  document.getElementById('customer-modal-title').textContent = name + "'s Orders";
  const body = document.getElementById('customer-modal-body');
  body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:30px"><div class="cu-spinner"></div></div>';
  openModal('customer-modal');

  fetch('/customer-orders.php?phone=' + encodeURIComponent(phone))
    .then(r => r.json())
    .then(data => {
      if (!data.orders || !data.orders.length) {
        body.innerHTML = '<p style="font-size:13px;color:var(--muted);text-align:center;padding:20px">No orders found.</p>';
        return;
      }
      body.innerHTML = data.orders.map(o => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono)">${o.order_ref}</div>
            <div style="font-size:11.5px;color:var(--muted)">${o.link_title} · ${o.date}</div>
          </div>
          <div style="text-align:right">
            <div style="font-size:13px;font-weight:700;color:var(--ink)">₦${parseFloat(o.total_amount).toLocaleString()}</div>
            <span class="badge badge-${o.status_color}" style="margin-top:2px">${o.status_label}</span>
          </div>
        </div>
      `).join('');
    })
    .catch(() => {
      body.innerHTML = '<p style="font-size:13px;color:var(--danger);text-align:center;padding:20px">Could not load order history.</p>';
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
