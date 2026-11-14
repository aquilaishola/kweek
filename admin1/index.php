<?php
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/layout-top.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$prevWeekStart = date('Y-m-d', strtotime('-14 days'));

// ── CORE STATS ──────────────────────────────────────────────
$totalMerchants  = (int)$db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL")->fetchColumn();
$activeMerchants = (int)$db->query("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND status='active'")->fetchColumn();
$suspended       = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='suspended'")->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE owner_user_id IS NULL AND created_at >= ?");
$stmt->execute([$weekAgo]); $newThisWeek = (int)$stmt->fetchColumn();
$stmt->execute([$prevWeekStart]); $sinceTwoWeeks = (int)$stmt->fetchColumn();
$newPrevWeek = $sinceTwoWeeks - $newThisWeek;
$signupChange = $newPrevWeek > 0 ? (($newThisWeek - $newPrevWeek) / $newPrevWeek) * 100 : ($newThisWeek > 0 ? 100 : 0);

$stmt = $db->prepare("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND created_at>=?");
$stmt->execute([$monthStart]); $platformRevenueMonth = (float)$stmt->fetchColumn();
$stmt->execute([$lastMonthStart]);
$lastMonthFeeStmt = $db->prepare("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND created_at BETWEEN ? AND ?");
$lastMonthFeeStmt->execute([$lastMonthStart, $lastMonthEnd . ' 23:59:59']);
$platformRevenueLastMonth = (float)$lastMonthFeeStmt->fetchColumn();
$revenueChange = $platformRevenueLastMonth > 0 ? (($platformRevenueMonth - $platformRevenueLastMonth) / $platformRevenueLastMonth) * 100 : 0;

$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0), COUNT(*) FROM orders WHERE DATE(created_at)=? AND status IN ('paid','completed')");
$stmt->execute([$today]);
[$volumeToday, $ordersToday] = $stmt->fetch(PDO::FETCH_NUM);

$walletLiability = (float)$db->query("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE owner_user_id IS NULL")->fetchColumn();

$pendingWithdrawalsAmt = (float)$db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='pending'")->fetchColumn();
$pendingWithdrawalsCount = (int)$db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
$pendingKycCount = (int)$db->query("SELECT COUNT(*) FROM user_kyc WHERE status='pending'")->fetchColumn();
$pendingOrderConfirm = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending_confirmation'")->fetchColumn();

// ── PLAN DISTRIBUTION ───────────────────────────────────────
$planCounts = $db->query("SELECT plan, COUNT(*) c FROM users WHERE owner_user_id IS NULL GROUP BY plan")->fetchAll(PDO::FETCH_KEY_PAIR);
$planCounts += ['free'=>0,'starter'=>0,'pro'=>0,'business'=>0];

// ── ORDER STATUS BREAKDOWN (this month) ─────────────────────
$stmt = $db->prepare("SELECT status, COUNT(*) c FROM orders WHERE created_at >= ? GROUP BY status");
$stmt->execute([$monthStart]);
$orderStatusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalOrdersMonth = array_sum($orderStatusCounts);

// ── TOP MERCHANTS THIS MONTH ─────────────────────────────────
$stmt = $db->prepare("
    SELECT u.id, u.name, u.plan, COALESCE(SUM(o.total_amount),0) vol, COUNT(o.id) cnt
    FROM users u
    JOIN orders o ON o.user_id = u.id AND o.status IN ('paid','completed') AND o.created_at >= ?
    WHERE u.owner_user_id IS NULL
    GROUP BY u.id ORDER BY vol DESC LIMIT 6
");
$stmt->execute([$monthStart]);
$topMerchants = $stmt->fetchAll();
$topMerchantMax = $topMerchants ? max(array_column($topMerchants, 'vol')) : 1;

// ── 7-DAY REVENUE CHART ──────────────────────────────────────
$chartLabels = []; $chartData = []; $volData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($d));
    $stmt = $db->prepare("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE type='credit' AND DATE(created_at)=?");
    $stmt->execute([$d]); $chartData[] = (float)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=? AND status IN ('paid','completed')");
    $stmt->execute([$d]); $volData[] = (float)$stmt->fetchColumn();
}

$recentTxns = $db->query("SELECT t.*, u.name AS merchant_name FROM transactions t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 8")->fetchAll();
$recentWithdrawals = $db->query("SELECT w.*, u.name AS merchant_name FROM withdrawals w JOIN users u ON w.user_id = u.id WHERE w.status='pending' ORDER BY w.created_at ASC LIMIT 6")->fetchAll();
$recentKyc = $db->query("SELECT k.*, u.name AS merchant_name FROM user_kyc k JOIN users u ON k.user_id = u.id WHERE k.status='pending' ORDER BY k.created_at ASC LIMIT 6")->fetchAll();
$recentSignups = $db->query("SELECT id,name,email,plan,status,created_at FROM users WHERE owner_user_id IS NULL ORDER BY created_at DESC LIMIT 6")->fetchAll();
$suspendedMerchants = $db->query("SELECT id,name,email,updated_at FROM users WHERE status='suspended' AND owner_user_id IS NULL ORDER BY updated_at DESC LIMIT 5")->fetchAll();

$statusLabels = ['pending_confirmation'=>'Pending Confirmation','confirmed'=>'Confirmed','pending_payment'=>'Pending Payment','verifying'=>'Verifying','paid'=>'Paid','completed'=>'Completed','cancelled'=>'Cancelled'];
$statusColors = ['pending_confirmation'=>'#D97706','confirmed'=>'#6457E8','pending_payment'=>'#D97706','verifying'=>'#6457E8','paid'=>'#1E9E5A','completed'=>'#1E9E5A','cancelled'=>'#9896B0'];
?>

<!-- ── ROW 1 — CORE STATS ──────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-ic"><i class="ti ti-building-store"></i></div>
    <div class="stat-label">Total Merchants</div>
    <div class="stat-value"><?= number_format($totalMerchants) ?></div>
    <div class="stat-sub <?= $signupChange >= 0 ? 'up' : 'down' ?>">
      <i class="ti ti-arrow-<?= $signupChange >= 0 ? 'up' : 'down' ?>-right"></i>
      <?= $newThisWeek ?> new this week (<?= abs(round($signupChange)) ?>%)
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-ic"><i class="ti ti-chart-line"></i></div>
    <div class="stat-label">Platform Fee Revenue — This Month</div>
    <div class="stat-value"><?= formatNaira($platformRevenueMonth) ?></div>
    <div class="stat-sub <?= $revenueChange >= 0 ? 'up' : 'down' ?>">
      <i class="ti ti-arrow-<?= $revenueChange >= 0 ? 'up' : 'down' ?>-right"></i>
      <?= abs(round($revenueChange,1)) ?>% vs last month
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-ic"><i class="ti ti-shopping-cart"></i></div>
    <div class="stat-label">Volume Processed Today</div>
    <div class="stat-value"><?= formatNaira($volumeToday) ?></div>
    <div class="stat-sub"><i class="ti ti-receipt"></i> <?= (int)$ordersToday ?> paid orders</div>
  </div>
  <div class="stat-card">
    <div class="stat-ic"><i class="ti ti-wallet"></i></div>
    <div class="stat-label">Wallet Liability</div>
    <div class="stat-value"><?= formatNaira($walletLiability) ?></div>
    <div class="stat-sub">Held across all merchant wallets</div>
  </div>
</div>

<!-- ── ROW 2 — ACTION QUEUES (things admins actually act on) ── -->
<div class="stat-grid" style="margin-bottom:20px">
  <a href="/admin/withdrawals.php" class="stat-card" style="border-left:3px solid var(--warning)">
    <div class="stat-ic" style="background:var(--warning-bg);color:var(--warning)"><i class="ti ti-cash-banknote"></i></div>
    <div class="stat-label">Pending Withdrawals</div>
    <div class="stat-value"><?= $pendingWithdrawalsCount ?></div>
    <div class="stat-sub"><?= formatNaira($pendingWithdrawalsAmt) ?> awaiting payout</div>
  </a>
  <a href="/admin/kyc.php" class="stat-card" style="border-left:3px solid var(--p600)">
    <div class="stat-ic"><i class="ti ti-shield-check"></i></div>
    <div class="stat-label">Pending KYC Reviews</div>
    <div class="stat-value"><?= $pendingKycCount ?></div>
    <div class="stat-sub">Awaiting BVN verification</div>
  </a>
  <a href="/admin/orders.php?status=pending_confirmation" class="stat-card" style="border-left:3px solid var(--warning)">
    <div class="stat-ic" style="background:var(--warning-bg);color:var(--warning)"><i class="ti ti-clock-check"></i></div>
    <div class="stat-label">Orders Awaiting Merchant Confirmation</div>
    <div class="stat-value"><?= $pendingOrderConfirm ?></div>
    <div class="stat-sub">Across all merchants</div>
  </a>
  <a href="/admin/users.php?status=suspended" class="stat-card" style="border-left:3px solid var(--danger)">
    <div class="stat-ic" style="background:var(--danger-bg);color:var(--danger)"><i class="ti ti-ban"></i></div>
    <div class="stat-label">Suspended Merchants</div>
    <div class="stat-value"><?= $suspended ?></div>
    <div class="stat-sub"><?= $activeMerchants ?> currently active</div>
  </a>
</div>

<!-- ── ROW 3 — CHART + PLAN DISTRIBUTION ──────────────────────── -->
<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:20px;align-items:start;margin-bottom:20px" class="dash-2col">
  <div class="card">
    <div class="section-title">Revenue &amp; Volume — Last 7 Days</div>
    <div style="height:230px"><canvas id="revChart"></canvas></div>
  </div>

  <div class="card">
    <div class="section-title">Plan Distribution</div>
    <?php
    $planMeta = ['free'=>['label'=>'Free','color'=>'#9896B0'],'starter'=>['label'=>'Starter','color'=>'#D97706'],'pro'=>['label'=>'Pro','color'=>'#4F43D4'],'business'=>['label'=>'Business','color'=>'#1E9E5A']];
    foreach ($planMeta as $key => $meta):
      $count = $planCounts[$key] ?? 0;
      $pct = $totalMerchants > 0 ? round(($count / $totalMerchants) * 100) : 0;
    ?>
    <div style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
        <span style="font-weight:600"><?= $meta['label'] ?></span>
        <span class="td-muted"><?= $count ?> (<?= $pct ?>%)</span>
      </div>
      <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── ROW 4 — ORDER STATUS + TOP MERCHANTS ───────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:20px;align-items:start;margin-bottom:20px" class="dash-2col">
  <div class="card">
    <div class="section-title">Order Status — This Month <span class="td-muted" style="font-weight:400;font-size:12px">(<?= $totalOrdersMonth ?> total)</span></div>
    <?php if ($totalOrdersMonth === 0): ?>
    <div style="text-align:center;padding:24px 0;color:var(--n400);font-size:13px">No orders yet this month.</div>
    <?php else: foreach ($statusLabels as $key => $label):
      $c = $orderStatusCounts[$key] ?? 0;
      if ($c === 0) continue;
      $pct = round(($c / $totalOrdersMonth) * 100);
    ?>
    <div style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px">
        <span style="font-weight:600"><?= $label ?></span>
        <span class="td-muted"><?= $c ?> (<?= $pct ?>%)</span>
      </div>
      <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $statusColors[$key] ?>"></div></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="table-wrap">
    <div style="padding:16px;display:flex;justify-content:space-between;align-items:center">
      <div class="section-title" style="margin-bottom:0">Top Merchants This Month</div>
      <a href="/admin/users.php" class="btn btn-outline btn-sm">All merchants</a>
    </div>
    <table>
      <thead><tr><th>Merchant</th><th>Plan</th><th>Orders</th><th>Volume</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($topMerchants)): ?>
        <tr class="empty-row"><td colspan="5">No paid orders yet this month.</td></tr>
        <?php else: foreach ($topMerchants as $m): $barPct = round(($m['vol'] / $topMerchantMax) * 100); ?>
        <tr>
          <td class="td-bold"><?= htmlspecialchars($m['name']) ?></td>
          <td><span class="badge badge-purple"><?= ucfirst($m['plan']) ?></span></td>
          <td><?= (int)$m['cnt'] ?></td>
          <td class="mono td-bold"><?= formatNaira($m['vol']) ?></td>
          <td style="width:80px"><div class="progress-track" style="margin-top:0"><div class="progress-fill" style="width:<?= $barPct ?>%"></div></div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── ROW 5 — ACTION FEEDS ────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;align-items:start" class="dash-3col">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="section-title" style="margin-bottom:0">Withdrawal Queue</div>
      <a href="/admin/withdrawals.php" class="btn btn-outline btn-sm">View</a>
    </div>
    <?php if (empty($recentWithdrawals)): ?>
    <div style="text-align:center;padding:20px 0;color:var(--n400);font-size:13px">Nothing pending.</div>
    <?php else: foreach ($recentWithdrawals as $w): ?>
    <div class="mini-row">
      <div style="min-width:0">
        <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($w['merchant_name']) ?></div>
        <div class="td-muted"><?= timeAgo($w['created_at']) ?></div>
      </div>
      <div style="font-weight:700;font-size:13px;white-space:nowrap;margin-left:8px"><?= formatNaira($w['net_amount']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="section-title" style="margin-bottom:0">KYC Queue</div>
      <a href="/admin/kyc.php" class="btn btn-outline btn-sm">View</a>
    </div>
    <?php if (empty($recentKyc)): ?>
    <div style="text-align:center;padding:20px 0;color:var(--n400);font-size:13px">Nothing pending.</div>
    <?php else: foreach ($recentKyc as $k): ?>
    <div class="mini-row">
      <div style="min-width:0">
        <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($k['merchant_name']) ?></div>
        <div class="td-muted"><?= timeAgo($k['created_at']) ?></div>
      </div>
      <?php if ($k['watch_listed']): ?><span class="badge badge-danger">Flagged</span><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="section-title" style="margin-bottom:0">New Signups</div>
      <a href="/admin/users.php" class="btn btn-outline btn-sm">View</a>
    </div>
    <?php foreach ($recentSignups as $u): ?>
    <div class="mini-row">
      <div style="min-width:0">
        <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($u['name']) ?></div>
        <div class="td-muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($u['email']) ?></div>
      </div>
      <span class="badge badge-purple"><?= ucfirst($u['plan']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($suspendedMerchants)): ?>
<div class="card" style="margin-top:20px;border-color:var(--danger-bg);background:#FFFBFB">
  <div class="section-title" style="display:flex;align-items:center;gap:8px"><i class="ti ti-alert-triangle" style="color:var(--danger)"></i> Recently Suspended Merchants</div>
  <div class="table-wrap" style="border:none">
    <table>
      <thead><tr><th>Merchant</th><th>Email</th><th>Suspended</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($suspendedMerchants as $s): ?>
        <tr>
          <td class="td-bold"><?= htmlspecialchars($s['name']) ?></td>
          <td class="td-muted"><?= htmlspecialchars($s['email']) ?></td>
          <td class="td-muted"><?= timeAgo($s['updated_at']) ?></td>
          <td><a href="/admin/user-view.php?id=<?= $s['id'] ?>" class="btn btn-outline btn-sm">Review</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('revChart'), {
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      { type:'bar', label:'Volume', data: <?= json_encode($volData) ?>, backgroundColor:'rgba(79,67,212,0.12)', borderRadius:6, yAxisID:'y1', order:2 },
      { type:'line', label:'Fee Revenue', data: <?= json_encode($chartData) ?>, borderColor:'#4F43D4', backgroundColor:'rgba(79,67,212,0.08)', fill:false, tension:.35, borderWidth:2, pointRadius:3, pointBackgroundColor:'#4F43D4', yAxisID:'y', order:1 }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:true, position:'top', labels:{ boxWidth:10, font:{size:11} } } },
    scales:{
      y:{ position:'left', grid:{color:'#F0EFF6'}, ticks:{callback:v=>'₦'+(v/1000).toFixed(0)+'k', font:{size:10}} },
      y1:{ position:'right', grid:{display:false}, ticks:{callback:v=>'₦'+(v/1000).toFixed(0)+'k', font:{size:10}} },
      x:{ grid:{display:false}, ticks:{font:{size:11}} }
    }
  }
});
</script>

<style>
@media(max-width:960px){ .dash-2col,.dash-3col{grid-template-columns:1fr} }
</style>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>