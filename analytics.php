<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Analytics';
$activePage = 'analytics';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$isAdvanced = in_array($user['plan'], ['pro', 'business'], true);

// ── Date range (presets + custom, same pattern as transactions.php) ──
$dateFrom = get('from') ? date('Y-m-d', strtotime(get('from'))) : date('Y-m-01');
$dateTo   = get('to')   ? date('Y-m-d', strtotime(get('to')))   : date('Y-m-d');
if (strtotime($dateFrom) > strtotime($dateTo)) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$presets = [
    'today' => ['label' => 'Today',        'from' => date('Y-m-d'),                        'to' => date('Y-m-d')],
    '7d'    => ['label' => 'Last 7 Days',  'from' => date('Y-m-d', strtotime('-6 days')),  'to' => date('Y-m-d')],
    '30d'   => ['label' => 'Last 30 Days', 'from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d')],
    'month' => ['label' => 'This Month',   'from' => date('Y-m-01'),                       'to' => date('Y-m-d')],
    'year'  => ['label' => 'This Year',    'from' => date('Y-01-01'),                      'to' => date('Y-m-d')],
];

function kweekPeriodStats(PDO $db, int $uid, string $from, string $to): array {
    $stmt = $db->prepare("SELECT
        COUNT(DISTINCT CASE WHEN status IN ('paid','completed') THEN id END) as paid_orders,
        COUNT(*) as total_orders,
        COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) as revenue,
        COUNT(DISTINCT customer_phone) as customers
        FROM orders WHERE user_id=? AND created_at BETWEEN ? AND ?");
    $stmt->execute([$uid, $from . ' 00:00:00', $to . ' 23:59:59']);
    $row = $stmt->fetch();
    $row['aov'] = $row['paid_orders'] > 0 ? $row['revenue'] / $row['paid_orders'] : 0;
    return $row;
}

// ── CSV export (before any HTML output) ─────────────────────────────
if (get('export') === 'csv') {
    $section = in_array(get('section'), ['trend', 'links', 'zones']) ? get('section') : 'trend';
    if (in_array($section, ['zones']) && !$isAdvanced) {
        http_response_code(403);
        exit('This export requires the Pro or Business plan.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kweek-analytics-' . $section . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($section === 'trend') {
        fputcsv($out, ['Date', 'Orders', 'Revenue']);
        $stmt = $db->prepare("SELECT DATE(created_at) as d, COUNT(*) as orders,
                               COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) as revenue
                               FROM orders WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY d ORDER BY d");
        $stmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        foreach ($stmt->fetchAll() as $r) fputcsv($out, [$r['d'], $r['orders'], $r['revenue']]);
    } elseif ($section === 'links') {
        fputcsv($out, ['Link', 'Slug', 'Orders (period)', 'Revenue (period)']);
        $stmt = $db->prepare("SELECT pl.title, pl.slug, COUNT(o.id) as orders, COALESCE(SUM(o.total_amount),0) as revenue
                               FROM payment_links pl
                               LEFT JOIN orders o ON o.link_id = pl.id AND o.status IN ('paid','completed') AND o.created_at BETWEEN ? AND ?
                               WHERE pl.user_id = ? GROUP BY pl.id ORDER BY revenue DESC");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $uid]);
        foreach ($stmt->fetchAll() as $r) fputcsv($out, [$r['title'], $r['slug'], $r['orders'], $r['revenue']]);
    } elseif ($section === 'zones') {
        fputcsv($out, ['Delivery Zone', 'Orders', 'Revenue']);
        $stmt = $db->prepare("SELECT delivery_zone, COUNT(*) as orders, COALESCE(SUM(total_amount),0) as revenue
                               FROM orders WHERE user_id=? AND status IN ('paid','completed') AND delivery_zone IS NOT NULL AND delivery_zone != ''
                               AND created_at BETWEEN ? AND ? GROUP BY delivery_zone ORDER BY revenue DESC");
        $stmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        foreach ($stmt->fetchAll() as $r) fputcsv($out, [$r['delivery_zone'], $r['orders'], $r['revenue']]);
    }
    fclose($out);
    exit;
}

// ── Summary stats ────────────────────────────────────────────────────
$summary = kweekPeriodStats($db, $uid, $dateFrom, $dateTo);

// ── Revenue trend + orders over time (one query, filled to continuous days) ──
$trendStmt = $db->prepare("SELECT DATE(created_at) as d, COUNT(*) as orders,
    COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) as revenue
    FROM orders WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY d ORDER BY d");
$trendStmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$trendRaw = [];
foreach ($trendStmt->fetchAll() as $r) { $trendRaw[$r['d']] = $r; }

$trendLabels = []; $trendRevenue = []; $trendOrders = [];
$cursor = strtotime($dateFrom);
$end    = strtotime($dateTo);
while ($cursor <= $end) {
    $d = date('Y-m-d', $cursor);
    $trendLabels[]  = date('M j', $cursor);
    $trendRevenue[] = isset($trendRaw[$d]) ? (float)$trendRaw[$d]['revenue'] : 0;
    $trendOrders[]  = isset($trendRaw[$d]) ? (int)$trendRaw[$d]['orders'] : 0;
    $cursor = strtotime('+1 day', $cursor);
}

// ── Top performing links (period-scoped) ─────────────────────────────
$linksStmt = $db->prepare("SELECT pl.title, pl.slug, pl.status, COUNT(o.id) as period_orders, COALESCE(SUM(o.total_amount),0) as period_revenue
    FROM payment_links pl
    LEFT JOIN orders o ON o.link_id = pl.id AND o.status IN ('paid','completed') AND o.created_at BETWEEN ? AND ?
    WHERE pl.user_id = ? GROUP BY pl.id ORDER BY period_revenue DESC LIMIT 8");
$linksStmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $uid]);
$topLinks = $linksStmt->fetchAll();
$maxLinkRevenue = max(array_column($topLinks, 'period_revenue') ?: [0]);

// ── Order status breakdown ────────────────────────────────────────────
$statusStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY status");
$statusStmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$statusBreakdown = $statusStmt->fetchAll();
$statusTotal = array_sum(array_column($statusBreakdown, 'cnt')) ?: 1;

// ── Advanced analytics (Pro/Business only) ────────────────────────────
$segmentation = []; $zonePerformance = []; $peakHours = []; $comparison = null;

if ($isAdvanced) {
    // New vs returning customers
    $segStmt = $db->prepare("
        SELECT CASE WHEN prior.cnt > 0 THEN 'returning' ELSE 'new' END as segment,
               COUNT(DISTINCT cur.customer_phone) as customers,
               COALESCE(SUM(cur.total_amount),0) as revenue
        FROM orders cur
        LEFT JOIN (
            SELECT customer_phone, COUNT(*) as cnt FROM orders WHERE user_id=? AND created_at < ? GROUP BY customer_phone
        ) prior ON prior.customer_phone = cur.customer_phone
        WHERE cur.user_id=? AND cur.status IN ('paid','completed') AND cur.created_at BETWEEN ? AND ?
        GROUP BY segment
    ");
    $segStmt->execute([$uid, $dateFrom . ' 00:00:00', $uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    foreach ($segStmt->fetchAll() as $r) { $segmentation[$r['segment']] = $r; }
    $segmentation += ['new' => ['segment'=>'new','customers'=>0,'revenue'=>0], 'returning' => ['segment'=>'returning','customers'=>0,'revenue'=>0]];

    // Delivery zone performance
    $zoneStmt = $db->prepare("SELECT delivery_zone, COUNT(*) as orders, COALESCE(SUM(total_amount),0) as revenue
        FROM orders WHERE user_id=? AND status IN ('paid','completed') AND delivery_zone IS NOT NULL AND delivery_zone != ''
        AND created_at BETWEEN ? AND ? GROUP BY delivery_zone ORDER BY revenue DESC LIMIT 10");
    $zoneStmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $zonePerformance = $zoneStmt->fetchAll();
    $maxZoneRevenue = max(array_column($zonePerformance, 'revenue') ?: [0]);

    // Peak order times (hour of day, 0-23)
    $hourStmt = $db->prepare("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM orders WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY hr");
    $hourStmt->execute([$uid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $hourRaw = [];
    foreach ($hourStmt->fetchAll() as $r) { $hourRaw[(int)$r['hr']] = (int)$r['cnt']; }
    for ($h = 0; $h < 24; $h++) { $peakHours[] = $hourRaw[$h] ?? 0; }

    // Period-over-period comparison
    $periodDays   = (int)round((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1;
    $prevTo       = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
    $prevFrom     = date('Y-m-d', strtotime($prevTo . ' -' . ($periodDays - 1) . ' days'));
    $prevSummary  = kweekPeriodStats($db, $uid, $prevFrom, $prevTo);

    $pctChange = function ($cur, $prev) {
        if ($prev == 0) return $cur > 0 ? 100.0 : 0.0;
        return round((($cur - $prev) / $prev) * 100, 1);
    };
    $comparison = [
        'prev_from' => $prevFrom, 'prev_to' => $prevTo,
        'revenue'   => ['cur' => $summary['revenue'], 'prev' => $prevSummary['revenue'], 'change' => $pctChange($summary['revenue'], $prevSummary['revenue'])],
        'orders'    => ['cur' => $summary['total_orders'], 'prev' => $prevSummary['total_orders'], 'change' => $pctChange($summary['total_orders'], $prevSummary['total_orders'])],
        'aov'       => ['cur' => $summary['aov'], 'prev' => $prevSummary['aov'], 'change' => $pctChange($summary['aov'], $prevSummary['aov'])],
    ];
}

include __DIR__ . '/includes/header.php';
?>

<style>
.an-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.an-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px;margin:0}
.an-header p{font-size:14px;color:var(--muted);margin:2px 0 0}

.an-presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.an-preset{padding:7px 14px;border-radius:var(--r-full);font-size:12.5px;font-weight:600;text-decoration:none;border:1.5px solid var(--line);background:var(--paper);color:var(--muted);transition:all .2s}
.an-preset.active,.an-preset:hover{border-color:var(--purple-600);background:var(--purple-600);color:#fff}

.an-filter-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.an-filter-field{display:flex;flex-direction:column;gap:6px}
.an-filter-field label{font-size:12px;font-weight:600;color:var(--ink)}

.an-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
@media(max-width:900px){.an-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.an-stats{grid-template-columns:1fr}}
.an-stat-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:18px}
.an-stat-label{font-size:12px;color:var(--muted);margin-bottom:6px}
.an-stat-value{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--ink)}
.an-stat-delta{font-size:11.5px;font-weight:700;margin-top:6px;display:inline-flex;align-items:center;gap:3px}
.an-stat-delta.up{color:var(--success)}
.an-stat-delta.down{color:var(--danger)}

.an-section{margin-bottom:24px}
.an-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.an-section-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--ink)}
.an-section-sub{font-size:12.5px;color:var(--muted);margin-top:2px}

.an-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;min-width:0}
.an-grid-2 > *{min-width:0}
@media(max-width:860px){.an-grid-2{grid-template-columns:1fr}}

.an-chart-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:18px;min-width:0}
.an-canvas-wrap{position:relative;height:230px;width:100%}
.an-canvas-wrap canvas{width:100% !important;height:100% !important}
.an-chart-card canvas{max-height:260px}

.an-bar-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
.an-bar-row:last-child{border-bottom:none}
.an-bar-info{flex:1;min-width:0}
.an-bar-title{font-size:13px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.an-bar-track{height:6px;border-radius:var(--r-full);background:var(--surface);margin-top:6px;overflow:hidden}
.an-bar-fill{height:100%;border-radius:var(--r-full);background:var(--purple-600)}
.an-bar-value{font-size:13px;font-weight:700;color:var(--ink);white-space:nowrap;text-align:right;min-width:90px}
.an-bar-sub{font-size:11px;color:var(--muted)}

.an-legend{display:flex;flex-direction:column;gap:8px;margin-top:14px}
.an-legend-item{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink)}
.an-legend-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.an-legend-item strong{margin-left:auto}

.an-upsell{background:linear-gradient(135deg,#2A2270 0%,#4F43D4 65%,#6457E8 100%);border-radius:var(--r-lg);padding:32px 26px;text-align:center;color:#fff}
.an-upsell i{font-size:30px;margin-bottom:12px;display:block}
.an-upsell h3{font-family:var(--font-display);font-size:18px;font-weight:800;margin-bottom:8px}
.an-upsell p{font-size:13.5px;color:rgba(255,255,255,.75);max-width:440px;margin:0 auto 18px;line-height:1.6}

.an-segment-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:520px){.an-segment-cards{grid-template-columns:1fr}}
.an-segment-card{border-radius:var(--r-lg);padding:18px;border:1.5px solid var(--line)}
.an-segment-card.new{background:var(--purple-50);border-color:var(--purple-100)}
.an-segment-card.returning{background:var(--success-bg);border-color:#BBF7D0}
</style>

<div class="an-header">
  <div>
    <h1>Analytics</h1>
    <p>How your business is performing, at a glance.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'export'=>'csv','section'=>'trend']) ?>" class="btn btn-outline btn-md"><i class="ti ti-download"></i> Export CSV</a>
    <button onclick="window.print()" class="btn btn-outline btn-md"><i class="ti ti-printer"></i> Export PDF</button>
  </div>
</div>

<div class="an-presets">
  <?php foreach ($presets as $p): $isActive = ($dateFrom === $p['from'] && $dateTo === $p['to']); ?>
  <a href="?from=<?= $p['from'] ?>&to=<?= $p['to'] ?>" class="an-preset <?= $isActive?'active':'' ?>"><?= $p['label'] ?></a>
  <?php endforeach; ?>
</div>

<div class="an-filter-card">
  <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div class="an-filter-field">
      <label>From</label>
      <input type="date" name="from" value="<?= $dateFrom ?>" class="form-input">
    </div>
    <div class="an-filter-field">
      <label>To</label>
      <input type="date" name="to" value="<?= $dateTo ?>" class="form-input">
    </div>
    <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-filter"></i> Apply</button>
  </form>
</div>

<!-- SUMMARY STATS -->
<div class="an-stats">
  <div class="an-stat-card">
    <div class="an-stat-label">Revenue</div>
    <div class="an-stat-value"><?= formatNaira($summary['revenue']) ?></div>
    <?php if ($comparison): $c = $comparison['revenue']; ?>
    <div class="an-stat-delta <?= $c['change']>=0?'up':'down' ?>"><i class="ti ti-arrow-<?= $c['change']>=0?'up':'down' ?>-right"></i> <?= abs($c['change']) ?>% vs prior period</div>
    <?php endif; ?>
  </div>
  <div class="an-stat-card">
    <div class="an-stat-label">Orders</div>
    <div class="an-stat-value"><?= number_format($summary['total_orders']) ?></div>
    <?php if ($comparison): $c = $comparison['orders']; ?>
    <div class="an-stat-delta <?= $c['change']>=0?'up':'down' ?>"><i class="ti ti-arrow-<?= $c['change']>=0?'up':'down' ?>-right"></i> <?= abs($c['change']) ?>% vs prior period</div>
    <?php endif; ?>
  </div>
  <div class="an-stat-card">
    <div class="an-stat-label">Avg. Order Value</div>
    <div class="an-stat-value"><?= formatNaira($summary['aov']) ?></div>
    <?php if ($comparison): $c = $comparison['aov']; ?>
    <div class="an-stat-delta <?= $c['change']>=0?'up':'down' ?>"><i class="ti ti-arrow-<?= $c['change']>=0?'up':'down' ?>-right"></i> <?= abs($c['change']) ?>% vs prior period</div>
    <?php endif; ?>
  </div>
  <div class="an-stat-card">
    <div class="an-stat-label">Unique Customers</div>
    <div class="an-stat-value"><?= number_format($summary['customers']) ?></div>
  </div>
</div>

<!-- REVENUE + ORDERS CHARTS -->
<div class="an-section">
  <div class="an-grid-2">
    <div class="an-chart-card">
      <div class="an-section-title" style="margin-bottom:2px">Revenue Trend</div>
      <div class="an-section-sub" style="margin-bottom:12px">Daily revenue from paid &amp; completed orders</div>
      <div class="an-canvas-wrap"><canvas id="chart-revenue"></canvas></div>
    </div>
    <div class="an-chart-card">
      <div class="an-section-title" style="margin-bottom:2px">Orders Over Time</div>
      <div class="an-section-sub" style="margin-bottom:12px">All orders placed per day, any status</div>
      <div class="an-canvas-wrap"><canvas id="chart-orders"></canvas></div>
    </div>
  </div>
</div>

<!-- TOP LINKS + STATUS BREAKDOWN -->
<div class="an-section">
  <div class="an-grid-2">
    <div class="an-chart-card">
      <div class="an-section-head">
        <div>
          <div class="an-section-title">Top-Performing Links</div>
          <div class="an-section-sub">Ranked by revenue in the selected period</div>
        </div>
        <a href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'export'=>'csv','section'=>'links']) ?>" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> CSV</a>
      </div>
      <?php if (empty($topLinks)): ?>
      <div class="empty-state" style="padding:24px"><div class="empty-title">No link activity yet</div></div>
      <?php else: foreach ($topLinks as $l): $pct = $maxLinkRevenue > 0 ? ($l['period_revenue']/$maxLinkRevenue)*100 : 0; ?>
      <div class="an-bar-row">
        <div class="an-bar-info">
          <div class="an-bar-title"><?= htmlspecialchars($l['title']) ?></div>
          <div class="an-bar-track"><div class="an-bar-fill" style="width:<?= $pct ?>%"></div></div>
          <div class="an-bar-sub"><?= $l['period_orders'] ?> order<?= $l['period_orders']!=1?'s':'' ?></div>
        </div>
        <div class="an-bar-value"><?= formatNaira($l['period_revenue']) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="an-chart-card">
      <div class="an-section-title" style="margin-bottom:2px">Order Status Breakdown</div>
      <div class="an-section-sub" style="margin-bottom:12px">Share of orders by current status</div>
      <div class="an-canvas-wrap"><canvas id="chart-status"></canvas></div>
      <div class="an-legend" id="status-legend"></div>
    </div>
  </div>
</div>

<!-- ADVANCED ANALYTICS -->
<div class="an-section">
  <div class="an-section-title" style="margin-bottom:4px">Advanced Analytics</div>
  <div class="an-section-sub" style="margin-bottom:16px">Customer behavior, delivery performance, and timing patterns.</div>

  <?php if (!$isAdvanced): ?>
  <div class="an-upsell">
    <i class="ti ti-chart-dots"></i>
    <h3>Unlock Advanced Analytics</h3>
    <p>Customer segmentation, delivery-zone performance, peak order times, and period comparisons — available on Pro and Business.</p>
    <a href="/settings.php?tab=billing" class="btn btn-white btn-md"><i class="ti ti-arrow-up"></i> Upgrade Plan</a>
  </div>
  <?php else: ?>

  <div class="an-grid-2" style="margin-bottom:16px">
    <div class="an-chart-card">
      <div class="an-section-title" style="margin-bottom:2px">Customers: New vs Returning</div>
      <div class="an-section-sub" style="margin-bottom:14px">Based on whether they'd ordered before this period</div>
      <div class="an-segment-cards">
        <div class="an-segment-card new">
          <div style="font-size:12px;color:var(--muted);margin-bottom:4px">New Customers</div>
          <div style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--ink)"><?= $segmentation['new']['customers'] ?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:4px"><?= formatNaira($segmentation['new']['revenue']) ?> revenue</div>
        </div>
        <div class="an-segment-card returning">
          <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Returning Customers</div>
          <div style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--ink)"><?= $segmentation['returning']['customers'] ?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:4px"><?= formatNaira($segmentation['returning']['revenue']) ?> revenue</div>
        </div>
      </div>
    </div>

    <div class="an-chart-card">
      <div class="an-section-title" style="margin-bottom:2px">Peak Order Times</div>
      <div class="an-section-sub" style="margin-bottom:12px">Orders placed by hour of day</div>
      <div class="an-canvas-wrap"><canvas id="chart-hours"></canvas></div>
    </div>
  </div>

  <div class="an-chart-card">
    <div class="an-section-head">
      <div>
        <div class="an-section-title">Delivery Zone Performance</div>
        <div class="an-section-sub">Where your delivered orders are going</div>
      </div>
      <a href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'export'=>'csv','section'=>'zones']) ?>" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> CSV</a>
    </div>
    <?php if (empty($zonePerformance)): ?>
    <div class="empty-state" style="padding:24px"><div class="empty-title">No delivery orders in this period</div></div>
    <?php else: foreach ($zonePerformance as $z): $pct = $maxZoneRevenue > 0 ? ($z['revenue']/$maxZoneRevenue)*100 : 0; ?>
    <div class="an-bar-row">
      <div class="an-bar-info">
        <div class="an-bar-title"><?= htmlspecialchars($z['delivery_zone']) ?></div>
        <div class="an-bar-track"><div class="an-bar-fill" style="width:<?= $pct ?>%"></div></div>
        <div class="an-bar-sub"><?= $z['orders'] ?> order<?= $z['orders']!=1?'s':'' ?></div>
      </div>
      <div class="an-bar-value"><?= formatNaira($z['revenue']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const kwLabels  = <?= json_encode($trendLabels) ?>;
const kwRevenue = <?= json_encode($trendRevenue) ?>;
const kwOrders  = <?= json_encode($trendOrders) ?>;

const purple = '#4F43D4';
const purpleLight = 'rgba(79,67,212,.12)';

new Chart(document.getElementById('chart-revenue'), {
  type: 'line',
  data: { labels: kwLabels, datasets: [{ data: kwRevenue, borderColor: purple, backgroundColor: purpleLight, fill: true, tension: .3, pointRadius: 0 }] },
  options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => '₦' + v.toLocaleString() } } } }
});

new Chart(document.getElementById('chart-orders'), {
  type: 'bar',
  data: { labels: kwLabels, datasets: [{ data: kwOrders, backgroundColor: purple, borderRadius: 4 }] },
  options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

<?php if (!empty($statusBreakdown)): ?>
const statusData = <?= json_encode(array_map(fn($s) => ['status' => str_replace('_',' ', ucwords($s['status'])), 'cnt' => (int)$s['cnt']], $statusBreakdown)) ?>;
const statusColors = ['#4F43D4','#16A34A','#D97706','#DC2626','#6457E8','#9896B0','#B4ACF5'];
new Chart(document.getElementById('chart-status'), {
  type: 'doughnut',
  data: {
    labels: statusData.map(s => s.status),
    datasets: [{ data: statusData.map(s => s.cnt), backgroundColor: statusColors, borderWidth: 0 }]
  },
  options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '65%' }
});
const legendEl = document.getElementById('status-legend');
const total = <?= (int)$statusTotal ?>;
statusData.forEach((s, i) => {
  const pct = total > 0 ? Math.round((s.cnt / total) * 100) : 0;
  const row = document.createElement('div');
  row.className = 'an-legend-item';
  row.innerHTML = `<span class="an-legend-dot" style="background:${statusColors[i % statusColors.length]}"></span>${s.status} <strong>${s.cnt} (${pct}%)</strong>`;
  legendEl.appendChild(row);
});
<?php endif; ?>

<?php if ($isAdvanced): ?>
const hoursData = <?= json_encode($peakHours) ?>;
const hoursLabels = Array.from({length:24}, (_,h) => (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? 'am' : 'pm'));
new Chart(document.getElementById('chart-hours'), {
  type: 'bar',
  data: { labels: hoursLabels, datasets: [{ data: hoursData, backgroundColor: purple, borderRadius: 3 }] },
  options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { autoSkip: true, maxTicksLimit: 8 } } } }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>