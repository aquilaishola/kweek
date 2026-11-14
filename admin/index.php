<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db         = db();

// ── PLATFORM STATS ───────────────────────────────────────────
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$lastMonth  = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';

$totalSubRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM subscriptions WHERE status IN ('active','expired')")->fetchColumn();
$monthSubRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM subscriptions WHERE status IN ('active','expired') AND starts_at >= '$monthStart'")->fetchColumn();
$activeSubCount  = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='active'")->fetchColumn();

// Total merchants
$totalMerchants = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$newToday       = (int)$db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = '$today'")->fetchColumn();

// Total revenue (all transactions)
$totalRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success'")->fetchColumn();
$monthRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success' AND created_at >= '$monthStart'")->fetchColumn();

// Platform fees earned (from transaction fees)
$totalFees = (float)$db->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND status='success'")->fetchColumn();
$monthFees = (float)$db->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND status='success' AND created_at >= '$monthStart'")->fetchColumn();

// Total orders
$totalOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','completed')")->fetchColumn();
$monthOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','completed') AND created_at >= '$monthStart'")->fetchColumn();

// Pending withdrawals
$pendingWithdrawals = (int)$db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
$pendingWithdrawAmt = (float)$db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='pending'")->fetchColumn();

// Active links
$activeLinks = (int)$db->query("SELECT COUNT(*) FROM payment_links WHERE status='active'")->fetchColumn();

// Chart data: daily revenue last 14 days
$chartLabels = [];
$chartRevenue = [];
$chartFees    = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[]  = date('M j', strtotime($date));
    $r = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND status='success' AND DATE(created_at)='$date'")->fetchColumn();
    $f = (float)$db->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND status='success' AND DATE(created_at)='$date'")->fetchColumn();
    $chartRevenue[] = $r;
    $chartFees[]    = $f;
}

// Recent signups
$recentUsers = $db->query("SELECT id,name,email,plan,bvn_verified,status,created_at FROM users ORDER BY created_at DESC LIMIT 8")->fetchAll();

// Recent transactions
$recentTxns = $db->query("SELECT t.*, u.name as merchant_name FROM transactions t JOIN users u ON t.user_id=u.id ORDER BY t.created_at DESC LIMIT 8")->fetchAll();

// Top merchants by revenue
$topMerchants = $db->query("SELECT u.id,u.name,u.email,u.plan, COALESCE(SUM(t.net_amount),0) as rev, COUNT(t.id) as txn_count FROM users u LEFT JOIN transactions t ON u.id=t.user_id AND t.type='credit' AND t.status='success' GROUP BY u.id ORDER BY rev DESC LIMIT 5")->fetchAll();

// Plan distribution
$planDist = $db->query("SELECT plan, COUNT(*) as cnt FROM users GROUP BY plan")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── admin dashboard responsive layout ──────────────────────
   Mirrors the fixes used on the merchant dashboard.php:
   1. min-width:0 on every grid/flex child (grid items otherwise
      refuse to shrink below their content's natural width, which
      forces horizontal overflow on the whole page)
   2. a height-constrained, clipped chart wrapper so Chart.js
      canvases can't render past their container on mobile
   3. real @media breakpoints collapsing the layout on small screens
*/
.admin-dash-grid {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 20px;
  align-items: start;
  min-width: 0;
}
.admin-dash-grid > * { min-width: 0; }

.admin-dash-grid .card,
.admin-dash-grid .table-wrap { overflow: hidden; max-width: 100%; }

/* fixed-height, clipped chart wrapper */
.chart-wrap {
  position: relative;
  height: 260px;
  width: 100%;
  overflow: hidden;
}
.chart-wrap canvas {
  max-width: 100% !important;
  max-height: 100% !important;
}

/* scrollable tables instead of page-wide overflow */
.admin-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
.admin-table-wrap table { min-width: 480px; }

/* hide less-critical columns on small screens */
@media (max-width: 600px) {
  .col-desc, .col-time, .col-bvn { display: none; }
  .admin-table-wrap table { min-width: 0; width: 100%; }
}

/* collapse to single column below 960px */
@media (max-width: 960px) {
  .admin-dash-grid { grid-template-columns: 1fr; }
  .stat-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 380px) {
  .stat-grid { grid-template-columns: 1fr !important; }
}
</style>

<!-- STATS -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-users"></i></div>
    <div class="stat-label">Total Merchants</div>
    <div class="stat-value"><?= number_format($totalMerchants) ?></div>
    <div style="font-size:12px;color:var(--success);margin-top:4px;font-weight:600">+<?= $newToday ?> today</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(22,163,74,.1);color:var(--success)"><i class="ti ti-trending-up"></i></div>
    <div class="stat-label">This Month Revenue</div>
    <div class="stat-value"><?= formatNaira($monthRevenue) ?></div>
    <div style="font-size:12px;color:var(--n500);margin-top:4px">Total: <?= formatNaira($totalRevenue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--p50);color:var(--p600)"><i class="ti ti-coin"></i></div>
    <div class="stat-label">Platform Fees (Month)</div>
    <div class="stat-value"><?= formatNaira($monthFees) ?></div>
    <div style="font-size:12px;color:var(--n500);margin-top:4px">Total earned: <?= formatNaira($totalFees) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i class="ti ti-clock"></i></div>
    <div class="stat-label">Pending Withdrawals</div>
    <div class="stat-value"><?= $pendingWithdrawals ?></div>
    <div style="font-size:12px;color:var(--warning);margin-top:4px;font-weight:600"><?= formatNaira($pendingWithdrawAmt) ?> to process</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-shopping-cart"></i></div>
    <div class="stat-label">Orders This Month</div>
    <div class="stat-value"><?= number_format($monthOrders) ?></div>
    <div style="font-size:12px;color:var(--n500);margin-top:4px">Total: <?= number_format($totalOrders) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-link"></i></div>
    <div class="stat-label">Active Links</div>
    <div class="stat-value"><?= number_format($activeLinks) ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:var(--success-bg);color:var(--success)"><i class="ti ti-crown"></i></div>
    <div class="stat-label">Subscription Revenue (Month)</div>
    <div class="stat-value"><?= formatNaira($monthSubRevenue) ?></div>
    <div style="font-size:12px;color:var(--n500);margin-top:4px">Total: <?= formatNaira($totalSubRevenue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--p50);color:var(--p600)"><i class="ti ti-users"></i></div>
    <div class="stat-label">Active Subscriptions</div>
    <div class="stat-value"><?= number_format($activeSubCount) ?></div>
  </div>
</div>

<!-- MAIN GRID -->
<div class="admin-dash-grid">

  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- REVENUE CHART -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px">
        <div><div class="card-title">Revenue & Fees — Last 14 Days</div></div>
        <div style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900)"><?= formatNaira(array_sum($chartRevenue)) ?></div>
      </div>
      <!-- fixed-height wrapper prevents unbounded growth / overflow on mobile -->
      <div class="chart-wrap">
        <canvas id="adminChart"></canvas>
      </div>
    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="table-wrap">
      <div class="table-head">
        <span class="table-title">Recent Transactions</span>
        <a href="/admin/transactions.php" class="btn btn-outline btn-sm">View all <i class="ti ti-arrow-right"></i></a>
      </div>
      <div class="admin-table-wrap">
        <table>
          <thead><tr><th>Merchant</th><th class="col-desc">Description</th><th>Amount</th><th>Fee</th><th>Status</th><th class="col-time">Time</th></tr></thead>
          <tbody>
          <?php foreach ($recentTxns as $t): ?>
          <tr>
            <td class="td-bold"><?= htmlspecialchars($t['merchant_name']) ?></td>
            <td class="col-desc" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['description']) ?></td>
            <td class="td-bold"><?= formatNaira($t['amount']) ?></td>
            <td style="color:var(--p600);font-weight:600"><?= formatNaira($t['fee']) ?></td>
            <td><span class="badge badge-<?= $t['status']==='success'?'success':'warning' ?>"><?= $t['status'] ?></span></td>
            <td class="col-time" style="font-size:11px;color:var(--n500)"><?= timeAgo($t['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- RECENT MERCHANTS -->
    <div class="table-wrap">
      <div class="table-head">
        <span class="table-title">Recent Signups</span>
        <a href="/admin/merchants.php" class="btn btn-outline btn-sm">View all <i class="ti ti-arrow-right"></i></a>
      </div>
      <div class="admin-table-wrap">
        <table>
          <thead><tr><th>Merchant</th><th>Plan</th><th class="col-bvn">BVN</th><th>Status</th><th class="col-time">Joined</th></tr></thead>
          <tbody>
          <?php foreach ($recentUsers as $u): ?>
          <tr>
            <td>
              <div class="td-bold"><?= htmlspecialchars($u['name']) ?></div>
              <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($u['email']) ?></div>
            </td>
            <td><span class="badge badge-purple"><?= $u['plan'] ?></span></td>
            <td class="col-bvn">
              <?php if ($u['bvn_verified']): ?>
              <span class="badge badge-success"><i class="ti ti-check"></i> Verified</span>
              <?php else: ?>
              <span class="badge badge-warning"><i class="ti ti-clock"></i> Pending</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $u['status']==='active'?'success':($u['status']==='suspended'?'danger':'neutral') ?>"><?= $u['status'] ?></span></td>
            <td class="col-time" style="font-size:11px;color:var(--n500)"><?= timeAgo($u['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- RIGHT COLUMN -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- QUICK ACTIONS -->
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">Quick Actions</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a href="/admin/withdrawals.php?status=pending" class="btn btn-purple btn-md" style="justify-content:center;position:relative">
          <i class="ti ti-cash"></i> Process Withdrawals
          <?php if ($pendingWithdrawals > 0): ?>
          <span style="position:absolute;right:12px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:var(--r-full)"><?= $pendingWithdrawals ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/merchants.php?bvn=0" class="btn btn-outline btn-md" style="justify-content:center"><i class="ti ti-user-check"></i> Review KYC</a>
        <a href="/admin/webhooks.php?status=failed" class="btn btn-outline btn-md" style="justify-content:center"><i class="ti ti-webhook"></i> Failed Webhooks</a>
        <a href="/admin/settings.php" class="btn btn-outline btn-md" style="justify-content:center"><i class="ti ti-settings"></i> Platform Settings</a>
      </div>
    </div>

    <!-- PLAN DISTRIBUTION -->
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">Plan Distribution</div>
      <?php
      $planTotals = array_column($planDist, 'cnt', 'plan');
      $total_users = array_sum($planTotals);
     $planColors = ['free'=>'var(--n300)','starter'=>'var(--p300)','pro'=>'var(--p500)','business'=>'var(--p800)'];
foreach (['free','starter','pro','business'] as $plan):
        $cnt = (int)($planTotals[$plan] ?? 0);
        $pct = $total_users > 0 ? round(($cnt / $total_users) * 100) : 0;
      ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span style="font-weight:600;text-transform:capitalize"><?= $plan ?></span>
          <span style="color:var(--n500)"><?= $cnt ?> (<?= $pct ?>%)</span>
        </div>
        <div style="height:6px;background:var(--n200);border-radius:var(--r-full);overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $planColors[$plan] ?? 'var(--n300)' ?>;border-radius:var(--r-full);transition:width .5s"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TOP MERCHANTS -->
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">Top Merchants</div>
      <?php foreach ($topMerchants as $i => $m): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <div style="width:26px;height:26px;border-radius:50%;background:var(--p50);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--p600);flex-shrink:0"><?= $i+1 ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:var(--n900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($m['name']) ?></div>
          <div style="font-size:11px;color:var(--n500)"><?= $m['txn_count'] ?> transactions</div>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--n900);white-space:nowrap"><?= formatNaira($m['rev']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('adminChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'Revenue (₦)',
        data: <?= json_encode($chartRevenue) ?>,
        borderColor: '#4F43D4',
        backgroundColor: 'rgba(79,67,212,0.08)',
        borderWidth: 2,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#4F43D4',
        pointRadius: 3,
      },
      {
        label: 'Fees Earned (₦)',
        data: <?= json_encode($chartFees) ?>,
        borderColor: '#16A34A',
        backgroundColor: 'rgba(22,163,74,0.06)',
        borderWidth: 2,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#16A34A',
        pointRadius: 3,
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'top', labels: { font: { family: 'Inter', size: 12 }, color: '#6E6C88', boxWidth: 12, usePointStyle: true } },
      tooltip: { callbacks: { label: ctx => '₦' + ctx.parsed.y.toLocaleString('en-NG', {minimumFractionDigits:2}) } }
    },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#9896B0', font: { family: 'Inter', size: 11 } } },
      y: { grid: { color: '#F2F1F6' }, ticks: { color: '#9896B0', font: { family: 'Inter', size: 11 }, callback: v => '₦' + (v/1000).toFixed(0) + 'k' } }
    }
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>