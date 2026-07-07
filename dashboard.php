<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$user       = currentUser();
$db         = db();
$uid        = $user['id'];

// ── STATS ─────────────────────────────────────────────────────
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

$balance    = (float)$user['wallet_balance'];
$totalEarned = (float)$user['total_earned'];

$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=? AND type='credit' AND DATE(created_at)=?");
$stmt->execute([$uid, $today]); $todayRevenue = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=? AND type='credit' AND created_at>=?");
$stmt->execute([$uid, $monthStart]); $monthRevenue = (float)$stmt->fetchColumn();

$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t',  strtotime('-1 month'));
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=? AND type='credit' AND created_at BETWEEN ? AND ?");
$stmt->execute([$uid, $lastMonthStart, $lastMonthEnd . ' 23:59:59']); $lastMonthRevenue = (float)$stmt->fetchColumn();
$revenueChange = $lastMonthRevenue > 0 ? (($monthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='paid' AND created_at>=?");
$stmt->execute([$uid, $monthStart]); $monthOrders = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='pending_confirmation'");
$stmt->execute([$uid]); $pendingOrders = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status='active'");
$stmt->execute([$uid]); $activeLinks = (int)$stmt->fetchColumn();

// ── RECENT ORDERS ─────────────────────────────────────────────
$stmt = $db->prepare("SELECT o.*, pl.title as link_title, pl.slug FROM orders o JOIN payment_links pl ON o.link_id = pl.id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 10");
$stmt->execute([$uid]); $recentOrders = $stmt->fetchAll();

// ── RECENT TRANSACTIONS ───────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$uid]); $recentTxns = $stmt->fetchAll();

// ── TOP LINKS ─────────────────────────────────────────────────
$stmt = $db->prepare("SELECT title, slug, total_orders, total_revenue FROM payment_links WHERE user_id=? ORDER BY total_revenue DESC LIMIT 5");
$stmt->execute([$uid]); $topLinks = $stmt->fetchAll();

// ── CHART DATA (last 7 days) ──────────────────────────────────
$chartLabels = []; $chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($date));
    $stmt = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=? AND type='credit' AND DATE(created_at)=?");
    $stmt->execute([$uid, $date]);
    $chartData[] = (float)$stmt->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── dashboard layout ───────────────────────────── */
.db-grid {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 20px;
  align-items: start;
  /* prevent children from blowing past grid boundary */
  min-width: 0;
}
/* min-width:0 on EVERY grid/flex child — the key fix for overflow */
.db-left, .db-right, .db-grid > * { min-width: 0; }
.db-left  { display: flex; flex-direction: column; gap: 20px; }
.db-right { display: flex; flex-direction: column; gap: 20px; }

/* cards must never overflow their column */
.db-left .card,
.db-left .table-wrap,
.db-right .card { overflow: hidden; max-width: 100%; }

/* ── chart fixed height ─────────────────────────── */
.chart-wrap {
  position: relative;
  height: 200px;
  width: 100%;
  /* canvas must be clipped to this box */
  overflow: hidden;
}
.chart-wrap canvas {
  max-width: 100% !important;
  max-height: 100% !important;
}

/* ── orders table responsive ────────────────────── */
.orders-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
.orders-table-wrap table { min-width: 480px; }

/* hide less-critical columns on small screens */
@media (max-width: 600px) {
  .col-link, .col-time { display: none; }
  .orders-table-wrap table { min-width: 0; width: 100%; }
}

/* ── responsive breakpoints ─────────────────────── */
@media (max-width: 960px) {
  .db-grid { grid-template-columns: 1fr; }
  .stat-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 380px) {
  .stat-grid { grid-template-columns: 1fr !important; }
}

/* ── quick action buttons ───────────────────────── */
.qa-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 11px 16px;
  border-radius: var(--r-md);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  border: 1.5px solid var(--n200);
  background: var(--n0);
  color: var(--n700);
  transition: all .2s;
  width: 100%;
}
.qa-btn:hover { border-color: var(--p300); color: var(--p600); background: var(--p50); }
.qa-btn.primary { background: var(--p600); border-color: var(--p600); color: #fff; }
.qa-btn.primary:hover { background: var(--p700); }
.qa-badge {
  margin-left: auto;
  background: var(--danger);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: var(--r-full);
}

/* ── top-link row ───────────────────────────────── */
.top-link-row { display: flex; align-items: center; gap: 10px; }
.top-link-num {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--p50); display: flex; align-items: center;
  justify-content: center; font-size: 11px; font-weight: 800;
  color: var(--p600); flex-shrink: 0;
}
.top-link-info { flex: 1; min-width: 0; }
.top-link-title { font-size: 13px; font-weight: 600; color: var(--n900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-link-sub   { font-size: 11px; color: var(--n500); }
.top-link-rev   { font-size: 13px; font-weight: 700; color: var(--n900); white-space: nowrap; }

/* ── txn row ────────────────────────────────────── */
.txn-row { display: flex; align-items: center; gap: 10px; }
.txn-icon {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.txn-icon.credit { background: var(--success-bg); color: var(--success); }
.txn-icon.debit  { background: var(--danger-bg);  color: var(--danger); }
.txn-desc { font-size: 13px; font-weight: 600; color: var(--n900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txn-time { font-size: 11px; color: var(--n500); }
.txn-amt  { font-size: 13px; font-weight: 700; white-space: nowrap; }
.txn-amt.credit { color: var(--success); }
.txn-amt.debit  { color: var(--danger); }
</style>

<!-- STAT CARDS -->
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-wallet"></i></div>
    <div class="stat-label">Wallet Balance</div>
    <div class="stat-value"><?= formatNaira($balance) ?></div>
    <a href="/withdraw.php" style="font-size:12px;color:var(--p600);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px">
      Withdraw <i class="ti ti-arrow-right" style="font-size:13px"></i>
    </a>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-trending-up"></i></div>
    <div class="stat-label">This Month</div>
    <div class="stat-value"><?= formatNaira($monthRevenue) ?></div>
    <div class="stat-change <?= $revenueChange >= 0 ? 'up' : 'down' ?>">
      <i class="ti ti-arrow-<?= $revenueChange >= 0 ? 'up' : 'down' ?>-right"></i>
      <?= abs(round($revenueChange, 1)) ?>% vs last month
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-shopping-cart"></i></div>
    <div class="stat-label">Orders This Month</div>
    <div class="stat-value"><?= number_format($monthOrders) ?></div>
    <?php if ($pendingOrders > 0): ?>
    <a href="/orders.php?status=pending_confirmation" style="font-size:12px;color:var(--warning);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px">
      <i class="ti ti-clock" style="font-size:13px"></i> <?= $pendingOrders ?> pending
    </a>
    <?php else: ?>
    <div class="stat-change up"><i class="ti ti-check"></i> All confirmed</div>
    <?php endif; ?>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-link"></i></div>
    <div class="stat-label">Active Links</div>
    <div class="stat-value"><?= $activeLinks ?></div>
    <a href="/create-link.php" style="font-size:12px;color:var(--p600);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px">
      <i class="ti ti-plus" style="font-size:13px"></i> New link
    </a>
  </div>
</div>

<!-- MAIN GRID -->
<div class="db-grid">

  <!-- LEFT COL -->
  <div class="db-left">

    <!-- REVENUE CHART -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <div>
          <div class="card-title">Revenue Overview</div>
          <div class="card-sub">Last 7 days</div>
        </div>
        <div style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900)"><?= formatNaira(array_sum($chartData)) ?></div>
      </div>
      <!-- fixed-height wrapper prevents unbounded growth -->
      <div class="chart-wrap">
        <canvas id="revenueChart"></canvas>
      </div>
    </div>

    <!-- RECENT ORDERS -->
    <div class="table-wrap">
      <div class="table-head">
        <span class="table-title">Recent Orders</span>
        <a href="/orders.php" class="btn btn-outline btn-sm">View all <i class="ti ti-arrow-right"></i></a>
      </div>
      <?php if (empty($recentOrders)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="ti ti-shopping-cart"></i></div>
        <div class="empty-title">No orders yet</div>
        <div class="empty-sub">Share your payment link and orders will appear here.</div>
        <a href="/create-link.php" class="btn btn-purple btn-md"><i class="ti ti-link"></i> Create payment link</a>
      </div>
      <?php else: ?>
      <div class="orders-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Customer</th>
              <th class="col-link">Link</th>
              <th>Amount</th>
              <th>Status</th>
              <th class="col-time">Time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $order):
              $statusMap = [
                'pending_confirmation' => ['warning','clock','Pending'],
                'confirmed'            => ['purple','check','Confirmed'],
                'paid'                 => ['success','circle-check','Paid'],
                'completed'            => ['success','checks','Completed'],
                'declined'             => ['danger','x','Declined'],
                'cancelled'            => ['neutral','minus','Cancelled'],
              ];
              [$sc,$si,$sl] = $statusMap[$order['status']] ?? ['neutral','dot','Unknown'];
            ?>
            <tr>
              <td>
                <div class="td-bold"><?= htmlspecialchars($order['customer_name']) ?></div>
                <div style="font-size:12px;color:var(--n400)"><?= htmlspecialchars($order['customer_phone']) ?></div>
              </td>
              <td class="col-link">
                <a href="/pay.php?slug=<?= htmlspecialchars($order['slug']) ?>" target="_blank"
                   style="color:var(--p600);text-decoration:none;font-size:13px;font-weight:500">
                  <?= htmlspecialchars($order['link_title']) ?> <i class="ti ti-external-link" style="font-size:11px"></i>
                </a>
              </td>
              <td class="td-bold"><?= formatNaira($order['total_amount']) ?></td>
              <td><span class="badge badge-<?= $sc ?>"><i class="ti ti-<?= $si ?>"></i><?= $sl ?></span></td>
              <td class="col-time" style="color:var(--n500);font-size:12px"><?= timeAgo($order['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /left -->

  <!-- RIGHT COL -->
  <div class="db-right">

    <!-- QUICK ACTIONS -->
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">Quick Actions</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a href="/create-link.php" class="qa-btn primary"><i class="ti ti-link"></i> Create Payment Link</a>
        <a href="/withdraw.php"    class="qa-btn"><i class="ti ti-cash"></i> Withdraw Funds</a>
        <a href="/orders.php?status=pending_confirmation" class="qa-btn">
          <i class="ti ti-clock-check"></i> Confirm Orders
          <?php if ($pendingOrders > 0): ?>
          <span class="qa-badge"><?= $pendingOrders ?></span>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <!-- TOP LINKS -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div class="card-title">Top Links</div>
        <a href="/payment-links.php" style="font-size:12px;color:var(--p600);text-decoration:none;font-weight:600">View all</a>
      </div>
      <?php if (empty($topLinks)): ?>
      <div style="text-align:center;padding:20px 0;color:var(--n500);font-size:13px">
        No links yet. <a href="/create-link.php" style="color:var(--p600);font-weight:600">Create one →</a>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($topLinks as $i => $link): ?>
        <div class="top-link-row">
          <div class="top-link-num"><?= $i+1 ?></div>
          <div class="top-link-info">
            <div class="top-link-title" title="<?= htmlspecialchars($link['title']) ?>"><?= htmlspecialchars($link['title']) ?></div>
            <div class="top-link-sub"><?= $link['total_orders'] ?> orders</div>
          </div>
          <div class="top-link-rev"><?= formatNaira($link['total_revenue']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php /* RECENT TRANSACTIONS
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div class="card-title">Recent Transactions</div>
        <a href="/transactions.php" style="font-size:12px;color:var(--p600);text-decoration:none;font-weight:600">View all</a>
      </div>
      <?php if (empty($recentTxns)): ?>
      <div style="text-align:center;padding:20px 0;color:var(--n500);font-size:13px">No transactions yet.</div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($recentTxns as $txn):
          $isCredit = $txn['type'] === 'credit';
        ?>
        <div class="txn-row">
          <div class="txn-icon credit-or-debit">...</div>
          ...
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    */ ?>

  </div><!-- /right -->

</div><!-- /db-grid -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('revenueChart');
if (ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [{
        label: 'Revenue (₦)',
        data: <?= json_encode($chartData) ?>,
        backgroundColor: 'rgba(79,67,212,0.15)',
        borderColor: '#4F43D4',
        borderWidth: 2,
        borderRadius: 8,
        borderSkipped: false,
        hoverBackgroundColor: 'rgba(79,67,212,0.3)',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,   
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: c => '₦' + c.parsed.y.toLocaleString('en-NG', { minimumFractionDigits: 2 })
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#9896B0', font: { family: 'Inter', size: 12 } }
        },
        y: {
          grid: { color: '#F2F1F6' },
          ticks: {
            color: '#9896B0',
            font: { family: 'Inter', size: 12 },
            callback: v => '₦' + (v / 1000).toFixed(0) + 'k'
          }
        }
      }
    }
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>