<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$user       = currentUser();
$db         = db();
$uid        = $user['id'];

// ── helpers ───────────────────────────────────────────────────
if (!function_exists('bxMoney')) {
    // ₦3,013,745<sup>.47</sup>  (Bachs-style superscript decimals)
    function bxMoney($n) {
        [$i, $d] = explode('.', number_format((float)$n, 2, '.', ','));
        return '₦' . $i . '<sup>.' . $d . '</sup>';
    }
}

// ── GREETING ──────────────────────────────────────────────────
$hr        = (int)(new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('G');
$greet     = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
$vw        = function_exists('sessionUser') ? sessionUser() : null;
$firstName = explode(' ', trim($vw['name'] ?? $user['name'] ?? ''))[0] ?: 'there';

// ── STATS ─────────────────────────────────────────────────────
$monthStart  = date('Y-m-01');
$balance     = (float)$user['wallet_balance'];
$totalEarned = (float)$user['total_earned'];

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

// pending money (orders awaiting confirmation) → balance bar
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id=? AND status='pending_confirmation' LIMIT 500");
$stmt->execute([$uid]);
$pendingAmount = 0.0;
foreach ($stmt->fetchAll() as $po) { $pendingAmount += (float)orderNgnAmount($po); }
$availPct = ($balance + $pendingAmount) > 0 ? round($balance / ($balance + $pendingAmount) * 100) : 100;

// last withdrawal
$stmt = $db->prepare("SELECT net_amount FROM transactions WHERE user_id=? AND type='withdrawal' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$uid]); $lastWd = $stmt->fetchColumn();

// ── LISTS ─────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT o.*, pl.title as link_title, pl.slug FROM orders o JOIN payment_links pl ON o.link_id = pl.id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 10");
$stmt->execute([$uid]); $recentOrders = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$uid]); $recentTxns = $stmt->fetchAll();

$stmt = $db->prepare("SELECT title, slug, total_orders, total_revenue FROM payment_links WHERE user_id=? ORDER BY total_revenue DESC LIMIT 5");
$stmt->execute([$uid]); $topLinks = $stmt->fetchAll();

// ── CHART DATA (90 days, sliced client-side to 7 / 30 / 90) ───
$span  = 90;
$since = date('Y-m-d', strtotime('-' . ($span - 1) . ' days'));
$stmt = $db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(net_amount),0) s FROM transactions WHERE user_id=? AND type='credit' AND created_at>=? GROUP BY DATE(created_at)");
$stmt->execute([$uid, $since . ' 00:00:00']); $revMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $db->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM orders WHERE user_id=? AND status='paid' AND created_at>=? GROUP BY DATE(created_at)");
$stmt->execute([$uid, $since . ' 00:00:00']); $ordMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chartLabels = $revSeries = $ordSeries = [];
for ($i = $span - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M j', strtotime($d));
    $revSeries[]   = (float)($revMap[$d] ?? 0);
    $ordSeries[]   = (int)($ordMap[$d] ?? 0);
}

$statusMap = [
  'pending_confirmation' => ['warning','clock','Pending'],
  'confirmed'            => ['purple','check','Confirmed'],
  'paid'                 => ['success','circle-check','Paid'],
  'completed'            => ['success','checks','Completed'],
  'declined'             => ['danger','x','Declined'],
  'cancelled'            => ['neutral','minus','Cancelled'],
];

// ── PAYMENT BREAKDOWN (last 30 days) ──────────────────────────
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id=? AND created_at>=? LIMIT 2000");
$stmt->execute([$uid, date('Y-m-d', strtotime('-29 days')) . ' 00:00:00']);
$bd = ['ok' => ['Succeeded', '#22A06B', 0.0, 0], 'pend' => ['Pending', '#F2A916', 0.0, 0], 'bad' => ['Declined', '#E5484D', 0.0, 0]];
foreach ($stmt->fetchAll() as $r) {
    $k = in_array($r['status'], ['paid','completed']) ? 'ok' : (in_array($r['status'], ['pending_confirmation','confirmed']) ? 'pend' : 'bad');
    $bd[$k][2] += (float)orderNgnAmount($r); $bd[$k][3]++;
}
$bdTotal = array_sum(array_column($bd, 2));

// ── REVENUE BY MONTH (last 6 months) ──────────────────────────
$stmt = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COALESCE(SUM(net_amount),0) s FROM transactions WHERE user_id=? AND type='credit' AND created_at>=? GROUP BY m");
$stmt->execute([$uid, date('Y-m-01', strtotime('-5 months'))]); $mMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $months[] = [date('M', strtotime($k . '-01')), (float)($mMap[$k] ?? 0)];
}
$mMax = max(array_column($months, 1)) ?: 1;

// ── LATEST PAYMENTS (settled only) ────────────────────────────
$latestPaid = array_slice(array_values(array_filter($recentOrders, fn($o) => in_array($o['status'], ['paid','completed']))), 0, 5);

include __DIR__ . '/includes/header.php';
?>

<style>
.bx{--l:#ECEAF1;--m:#8B8999;--s:#F6F6F8;--p:#4F43D4;--ink:#15131F}
.bx-up{animation:bxUp .65s cubic-bezier(.2,.7,.2,1) both;animation-delay:calc(var(--i,0)*70ms)}
@keyframes bxUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
@keyframes bxIn{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}
.bx-hi{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:6px 0 22px}
.bx-hi h1{font-size:clamp(26px,3.2vw,40px);font-weight:400;letter-spacing:-.04em;line-height:1.1}
.bx-hi-act{display:flex;gap:10px;flex-wrap:wrap}
.bx-b{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 18px;border-radius:12px;font-size:14px;font-weight:600;text-decoration:none;border:1px solid var(--l);background:#fff;color:var(--ink);transition:transform .2s,border-color .2s,box-shadow .2s;position:relative}
.bx-b:hover{transform:translateY(-1px);border-color:#bdb8d6}
.bx-b.p{background:var(--p);border-color:var(--p);color:#fff}
.bx-b.p:hover{box-shadow:0 10px 22px -10px rgba(79,67,212,.7)}
.bx-b.g{background:var(--s);border-color:transparent}
.bx-b em{font-style:normal;background:var(--p);color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:1px 7px}
.bx-card{background:#fff;border:1px solid var(--l);border-radius:22px;padding:24px 26px;min-width:0;transition:box-shadow .3s,border-color .3s}
.bx-card:hover{border-color:#e0dcea;box-shadow:0 18px 40px -28px rgba(21,19,31,.3)}
.bx-lbl{color:var(--m);font-size:15px}
.bx-big{font-size:clamp(32px,3.6vw,48px);font-weight:400;letter-spacing:-.045em;line-height:1.1;margin-top:10px}
.bx-big sup{font-size:.4em;letter-spacing:-.01em;vertical-align:top;position:relative;top:.18em;margin-left:2px}
.bx-top{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;align-items:start}
.bx-col{display:flex;flex-direction:column;gap:18px;min-width:0}
.bx-rev-h{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
.bx-range{color:var(--m);font-size:15px;margin-top:12px;display:flex;align-items:center;gap:6px}
.bx-seg{display:inline-flex;background:var(--s);border-radius:99px;padding:4px;gap:2px}
.bx-seg button{border:0;background:none;font:600 13.5px inherit;font-family:inherit;color:var(--m);padding:7px 14px;border-radius:99px;cursor:pointer;transition:background .2s,color .2s,box-shadow .2s}
.bx-seg button.on{background:#fff;color:var(--ink);box-shadow:0 2px 8px rgba(21,19,31,.1)}
.bx-chart{position:relative;height:290px;margin-top:22px}
/* balance */
.bx-bar{height:10px;border-radius:99px;background:#C9C4F2;overflow:hidden;margin:18px 0 12px}
.bx-bar i{display:block;height:100%;background:var(--p);border-radius:99px;transition:width 1s cubic-bezier(.2,.7,.2,1)}
.bx-leg{display:flex;gap:16px;font-size:14px;color:var(--m);flex-wrap:wrap;align-items:center}
.bx-leg b{font-weight:500;color:var(--ink);margin-left:auto}
.bx-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.bx-wd{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid var(--l);margin-top:16px;padding-top:16px}
.bx-wd small{display:block;color:var(--m);font-size:14px}
.bx-wd strong{font-weight:500;font-size:18px}
/* recent payments stack */
.bx-stack{position:relative;padding-top:18px}
.bx-stack::before,.bx-stack::after{content:'';position:absolute;left:16px;right:16px;top:0;height:60px;border:1px solid var(--l);border-radius:22px;background:#fff}
.bx-stack::after{left:8px;right:8px;top:9px;box-shadow:0 2px 10px rgba(21,19,31,.05)}
.bx-pay{position:relative;z-index:1;box-shadow:0 20px 40px -22px rgba(21,19,31,.28)}
.bx-pay-h{display:flex;justify-content:space-between;align-items:center}
.bx-nav{display:flex;gap:6px}
.bx-nav button{width:32px;height:32px;border-radius:50%;border:0;background:var(--s);color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;transition:background .2s,opacity .2s}
.bx-nav button:hover:not(:disabled){background:#E4E2EC}
.bx-nav button:disabled{opacity:.4;cursor:default}
.bx-slide{display:none;margin-top:18px}
.bx-slide.on{display:block;animation:bxIn .35s cubic-bezier(.2,.7,.2,1)}
.bx-who{display:flex;align-items:center;gap:14px}
.bx-av{width:48px;height:48px;border-radius:50%;background:var(--s);display:flex;align-items:center;justify-content:center;font-weight:600;color:#55546A;flex:none}
.bx-who b{display:block;font-weight:500;font-size:17px}
.bx-who small{color:var(--m);font-size:14px}
.bx-pamt{font-size:28px;letter-spacing:-.03em;margin:16px 0 10px;color:#2F8F4E}
.bx-pamt.pend{color:#B4750E}
.bx-pm{color:var(--m);font-size:13.5px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.bx-empty{color:var(--m);font-size:14px;padding:22px 0 4px;text-align:center}
.bx-empty a{color:var(--p);font-weight:600}
/* overview */
.bx-ov{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:34px 0 14px;flex-wrap:wrap}
.bx-ov h2{font-size:clamp(22px,2.4vw,30px);font-weight:400;letter-spacing:-.03em}
.bx-pill{display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 16px;border:1px solid var(--l);border-radius:12px;font-size:14px;color:var(--ink);background:#fff}
.bx-pill i{color:var(--m)}
.bx-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
.bx-stat{display:flex;flex-direction:column;gap:2px;padding:22px 24px 0;overflow:hidden}
.bx-stat .bx-big{font-size:clamp(28px,2.8vw,38px)}
.bx-chg{font-size:13px;font-weight:600;margin-top:8px;display:inline-flex;align-items:center;gap:4px}
.bx-chg.up{color:#2F8F4E}.bx-chg.down{color:#C0392B}
.bx-chg a{color:inherit;text-decoration:none}
.bx-spark{position:relative;height:140px;margin:10px -24px 0;border-top:0}
.bx-spark::before{content:'';position:absolute;left:24px;right:24px;top:12px;border-top:1.5px dashed #DAD6E6}
.bx-spark canvas{position:relative}
.bx-stat .bx-cta{margin:auto 0 22px;padding-top:14px}
/* lower grid */
.bx-low{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;margin-top:18px;align-items:start}
.bx-ct{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.bx-ct h3{font-size:19px;font-weight:500;letter-spacing:-.02em}
.bx-ct a{font-size:13px;font-weight:600;color:var(--p);text-decoration:none}
.bx-tbl{overflow-x:auto;margin:0 -26px;padding:0 26px;-webkit-overflow-scrolling:touch}
.bx-tbl table{width:100%;border-collapse:collapse}
.bx-tbl th{font-size:12.5px;font-weight:500;color:var(--m);text-align:left;padding:8px 10px 12px 0;border-bottom:1px solid var(--l)}
.bx-tbl td{padding:14px 10px 14px 0;border-bottom:1px solid #F3F1F7;font-size:14px;vertical-align:middle}
.bx-tbl tr{transition:background .15s}.bx-tbl tbody tr:hover{background:#FBFAFD}
.bx-tbl tr:last-child td{border-bottom:0}
.bx-tbl .nm{font-weight:600}.bx-tbl .sb{font-size:12px;color:var(--m)}
.bx-form{display:flex;gap:8px}
.bx-form input{flex:1;min-width:0;height:42px;border:1px solid var(--l);border-radius:12px;padding:0 14px;font:inherit;font-size:14px;background:var(--s);outline:0;transition:border-color .2s,box-shadow .2s,background .2s}
.bx-form input:focus{background:#fff;border-color:var(--p);box-shadow:0 0 0 4px #F2F0FC}
.bx-form button{height:42px;width:46px;border:0;border-radius:12px;background:var(--p);color:#fff;font-size:18px;cursor:pointer;transition:background .2s}
.bx-form button:hover{background:#3D33B8}
.bx-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F1F7}
.bx-row:last-child{border-bottom:0;padding-bottom:0}
.bx-n{width:30px;height:30px;border-radius:9px;background:var(--s);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;color:#55546A;flex:none}
.bx-n.cr{background:#E9F9F0;color:#1E9E5A}.bx-n.db{background:#FDECEA;color:#C0392B}
.bx-info{flex:1;min-width:0}
.bx-info b{display:block;font-weight:500;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bx-info small{color:var(--m);font-size:12px}
.bx-val{font-weight:600;font-size:14px;white-space:nowrap}
.bx-val.cr{color:#2F8F4E}
.bx-trio{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;margin-top:18px}
.bx-trio h3{font-size:15px;font-weight:400;color:var(--m);margin-bottom:18px}
.bx-stk{display:flex;height:6px;border-radius:99px;background:#ECEAF1;overflow:hidden;gap:2px;margin-bottom:6px}
.bx-stk i{display:block;height:100%;border-radius:99px;transition:width 1s cubic-bezier(.2,.7,.2,1)}
.bx-bdr{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid #F3F1F7}
.bx-bdr:last-child{border-bottom:0;padding-bottom:0}
.bx-pill2{width:16px;height:5px;border-radius:9px;flex:none}
.bx-bdr .l{flex:1;font-size:14px}
.bx-bdr .r{text-align:right;font-size:13.5px;white-space:nowrap}
.bx-bdr .r small{display:block;color:var(--m);font-size:12px}
.bx-bars{display:flex;gap:10px;height:210px;align-items:stretch}
.bx-mc{flex:1;display:flex;flex-direction:column;gap:8px;min-width:0}
.bx-trk{flex:1;position:relative;border-radius:10px;background:repeating-linear-gradient(135deg,#F0EEF5 0 4px,#fff 4px 8px);overflow:hidden}
.bx-fillb{position:absolute;left:0;right:0;bottom:0;border-radius:10px;background:#7C74E6;transform-origin:bottom;animation:bxGrow 1s cubic-bezier(.2,.7,.2,1) both;animation-delay:calc(var(--d)*80ms + 300ms);transition:filter .2s}
.bx-mc:last-child .bx-fillb{background:var(--p)}
.bx-mc:hover .bx-fillb{filter:brightness(1.1)}
.bx-mc span{font-size:12px;color:var(--m);text-align:center}
@keyframes bxGrow{from{transform:scaleY(0)}to{transform:scaleY(1)}}
.bx-lp{display:flex;justify-content:space-between;gap:12px;padding:13px 0;border-bottom:1px solid #F3F1F7;font-size:14px}
.bx-lp:last-child{border-bottom:0}
.bx-lp b{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bx-lp span{color:#2F8F4E;font-weight:500;white-space:nowrap}
@media(max-width:1180px){.bx-top,.bx-low{grid-template-columns:1fr}.bx-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){
  .bx-card{padding:20px 18px;border-radius:18px}
  .bx-stats{grid-template-columns:1fr}.bx-chart{height:230px}
  .col-link,.col-time{display:none}
  .bx-tbl{margin:0 -18px;padding:0 18px}
  .bx-hi-act{width:100%}.bx-hi-act .bx-b{flex:1;justify-content:center}
  .bx-stack::before{left:12px;right:12px}.bx-stack::after{left:6px;right:6px}
}
@media(prefers-reduced-motion:reduce){.bx-up,.bx-slide.on{animation:none}}
</style>

<div class="bx">

<?php if (empty($user['bvn_verified'])): ?>
<div class="kw-banner">
  <div class="kw-banner-ic"><i class="ti ti-alert-triangle"></i></div>
  <div class="kw-banner-text">
    <div class="t">Verify your BVN to unlock withdrawals</div>
    <div class="s">Takes about 2 minutes. Required once, by CBN regulation, before you can move money out of Kweek.</div>
  </div>
  <a href="/kyc.php" class="btn btn-purple btn-md"><i class="ti ti-shield-check"></i> Verify now</a>
</div>
<?php endif; ?>

<div class="bx-hi bx-up">
  <h1><?= $greet ?>, <?= htmlspecialchars($firstName) ?></h1>
  <div class="bx-hi-act">
    <a href="/orders.php?status=pending_confirmation" class="bx-b g"><i class="ti ti-clock-check"></i> Confirm orders <?php if ($pendingOrders > 0): ?><em><?= $pendingOrders ?></em><?php endif; ?></a>
    <a href="/create-link.php" class="bx-b p"><i class="ti ti-plus"></i> Create payment link</a>
  </div>
</div>

<!-- TOP: revenue + balance / recent payments -->
<div class="bx-top">

  <div class="bx-card bx-up" style="--i:1">
    <div class="bx-rev-h">
      <div>
        <div class="bx-lbl">Revenue</div>
        <div class="bx-big bx-amt" id="revTotal"><?= bxMoney(array_sum(array_slice($revSeries, -30))) ?></div>
        <div class="bx-range" id="revRange">Last 30 days</div>
      </div>
      <div class="bx-seg" id="revSeg" role="group" aria-label="Revenue period">
        <button type="button" data-n="7">7D</button>
        <button type="button" data-n="30" class="on">30D</button>
        <button type="button" data-n="90">90D</button>
      </div>
    </div>
    <div class="bx-chart"><canvas id="revenueChart"></canvas></div>
  </div>

  <div class="bx-col">
    <div class="bx-card bx-up" style="--i:2">
      <div class="bx-lbl">Kweek Balance</div>
      <div class="bx-big bx-amt"><?= bxMoney($balance) ?></div>
      <div class="bx-bar"><i style="width:<?= (int)$availPct ?>%"></i></div>
      <div class="bx-leg">
        <span><span class="bx-dot" style="background:#4F43D4"></span>Available</span>
        <span><span class="bx-dot" style="background:#C9C4F2"></span>Pending</span>
        <b class="bx-amt"><?= formatNaira($balance) ?> available</b>
      </div>
      <div class="bx-wd">
        <div>
          <small>Last withdrawal</small>
          <strong class="bx-amt"><?= $lastWd !== false && $lastWd !== null ? formatNaira($lastWd) : '—' ?></strong>
        </div>
        <a href="/withdraw.php" class="bx-b g">Withdraw funds</a>
      </div>
    </div>

    <div class="bx-stack bx-up" style="--i:3">
      <div class="bx-card bx-pay">
        <div class="bx-pay-h">
          <span class="bx-lbl">Recent payments</span>
          <div class="bx-nav">
            <button type="button" id="payPrev" aria-label="Previous"><i class="ti ti-chevron-left"></i></button>
            <button type="button" id="payNext" aria-label="Next"><i class="ti ti-chevron-right"></i></button>
          </div>
        </div>
        <?php if (empty($recentOrders)): ?>
        <div class="bx-empty">No payments yet. <a href="/create-link.php">Create a link →</a></div>
        <?php else: foreach (array_slice($recentOrders, 0, 8) as $k => $o):
            $nm = trim($o['customer_name']); $pp = preg_split('/\s+/', $nm);
            $ini = strtoupper(substr($pp[0] ?? '?', 0, 1) . (isset($pp[1]) ? substr($pp[1], 0, 1) : ''));
            $isPend = $o['status'] === 'pending_confirmation';
        ?>
        <div class="bx-slide <?= $k === 0 ? 'on' : '' ?>">
          <div class="bx-who">
            <div class="bx-av"><?= htmlspecialchars($ini) ?></div>
            <div><b><?= htmlspecialchars($nm) ?></b><small><?= htmlspecialchars($o['customer_phone']) ?></small></div>
          </div>
          <div class="bx-pamt bx-amt <?= $isPend ? 'pend' : '' ?>"><?= formatNaira(orderNgnAmount($o)) ?></div>
          <div class="bx-pm"><span><?= htmlspecialchars($o['link_title']) ?></span><span><?= timeAgo($o['created_at']) ?></span></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- OVERVIEW -->
<div class="bx-ov bx-up" style="--i:4">
  <h2>Overview</h2>
  <span class="bx-pill"><?= date('jS M') ?> this month <i class="ti ti-calendar"></i></span>
</div>

<div class="bx-stats">
  <div class="bx-card bx-stat bx-up" style="--i:5">
    <div class="bx-lbl">This month</div>
    <div class="bx-big bx-amt"><?= bxMoney($monthRevenue) ?></div>
    <div class="bx-chg <?= $revenueChange >= 0 ? 'up' : 'down' ?>"><i class="ti ti-arrow-<?= $revenueChange >= 0 ? 'up' : 'down' ?>-right"></i> <?= abs(round($revenueChange, 1)) ?>% vs last month</div>
    <div class="bx-spark"><canvas id="sp1"></canvas></div>
  </div>
  <div class="bx-card bx-stat bx-up" style="--i:6">
    <div class="bx-lbl">Orders this month</div>
    <div class="bx-big"><?= number_format($monthOrders) ?></div>
    <?php if ($pendingOrders > 0): ?>
    <div class="bx-chg" style="color:#B4750E"><a href="/orders.php?status=pending_confirmation"><i class="ti ti-clock"></i> <?= $pendingOrders ?> pending</a></div>
    <?php else: ?>
    <div class="bx-chg up"><i class="ti ti-check"></i> All confirmed</div>
    <?php endif; ?>
    <div class="bx-spark"><canvas id="sp2"></canvas></div>
  </div>
  <div class="bx-card bx-stat bx-up" style="--i:7">
    <div class="bx-lbl">Total earned</div>
    <div class="bx-big bx-amt"><?= bxMoney($totalEarned) ?></div>
    <div class="bx-chg" style="color:var(--m)">All time</div>
    <div class="bx-spark"><canvas id="sp3"></canvas></div>
  </div>
  <div class="bx-card bx-stat bx-up" style="--i:8">
    <div class="bx-lbl">Active links</div>
    <div class="bx-big"><?= $activeLinks ?></div>
    <div class="bx-cta"><a href="/create-link.php" class="bx-b g"><i class="ti ti-plus"></i> New link</a></div>
  </div>
</div>

<!-- BREAKDOWN / MONTHLY / LATEST -->
<div class="bx-trio">
  <div class="bx-card bx-up" style="--i:9">
    <h3>Payment Breakdown <span style="font-size:12px">· 30 days</span></h3>
    <div class="bx-stk">
      <?php foreach ($bd as $b): if ($bdTotal > 0 && $b[2] > 0): ?><i style="width:<?= round($b[2] / $bdTotal * 100, 1) ?>%;background:<?= $b[1] ?>"></i><?php endif; endforeach; ?>
    </div>
    <?php foreach ($bd as $b): ?>
    <div class="bx-bdr">
      <span class="bx-pill2" style="background:<?= $b[1] ?>"></span>
      <span class="l"><?= $b[0] ?></span>
      <span class="r bx-amt"><?= formatNaira($b[2]) ?> (<?= $bdTotal > 0 ? round($b[2] / $bdTotal * 100) : 0 ?>%)<small><?= $b[3] ?> payment<?= $b[3] === 1 ? '' : 's' ?></small></span>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="bx-card bx-up" style="--i:10">
    <h3>Revenue by month</h3>
    <div class="bx-bars">
      <?php foreach ($months as $mi => $m): $h = $m[1] > 0 ? max(8, round($m[1] / $mMax * 100)) : 0; ?>
      <div class="bx-mc" title="<?= $m[0] ?>: <?= formatNaira($m[1]) ?>">
        <div class="bx-trk"><?php if ($h): ?><div class="bx-fillb" style="height:<?= $h ?>%;--d:<?= $mi ?>"></div><?php endif; ?></div>
        <span><?= $m[0] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bx-card bx-up" style="--i:11">
    <h3>Latest payments</h3>
    <?php if (empty($latestPaid)): ?>
      <div class="bx-empty">No settled payments yet.</div>
    <?php else: foreach ($latestPaid as $lp): ?>
      <div class="bx-lp"><b><?= htmlspecialchars($lp['customer_name']) ?></b><span class="bx-amt"><?= formatNaira(orderNgnAmount($lp)) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- LOWER -->
<div class="bx-low">

  <div class="bx-card bx-up" style="--i:9">
    <div class="bx-ct"><h3>Recent orders</h3><a href="/orders.php">View all →</a></div>
    <?php if (empty($recentOrders)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="ti ti-shopping-cart"></i></div>
      <div class="empty-title">No orders yet</div>
      <div class="empty-sub">Share your payment link and orders will appear here.</div>
      <a href="/create-link.php" class="btn btn-purple btn-md"><i class="ti ti-link"></i> Create payment link</a>
    </div>
    <?php else: ?>
    <div class="bx-tbl">
      <table>
        <thead><tr><th>Customer</th><th class="col-link">Link</th><th>Amount</th><th>Status</th><th class="col-time">Time</th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $order):
          [$sc,$si,$sl] = $statusMap[$order['status']] ?? ['neutral','dot','Unknown']; ?>
          <tr>
            <td><div class="nm"><?= htmlspecialchars($order['customer_name']) ?></div><div class="sb"><?= htmlspecialchars($order['customer_phone']) ?></div></td>
            <td class="col-link"><a href="/pay/<?= htmlspecialchars($order['slug']) ?>" target="_blank" style="color:var(--p);text-decoration:none;font-weight:500"><?= htmlspecialchars($order['link_title']) ?> <i class="ti ti-external-link" style="font-size:11px"></i></a></td>
            <td class="bx-amt" style="font-weight:600;white-space:nowrap">
              <?= formatNaira(orderNgnAmount($order)) ?>
              <?php if ($paidIn = orderPaidInLabel($order)): ?><div class="sb" style="font-weight:400">customer paid <?= htmlspecialchars($paidIn) ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $sc ?>"><i class="ti ti-<?= $si ?>"></i><?= $sl ?></span></td>
            <td class="col-time sb"><?= timeAgo($order['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="bx-col">
    <div class="bx-card bx-up" style="--i:10">
      <div class="bx-ct" style="margin-bottom:4px"><h3>Verify a receipt</h3></div>
      <div class="bx-lbl" style="font-size:13.5px;margin-bottom:14px">Enter an order ref or TXN code</div>
      <form action="/receipt.php" method="GET" class="bx-form">
        <input type="text" name="ref" placeholder="ORD-20260730-A1B2C3 or TXN-8847-KQPL" required>
        <button type="submit" aria-label="Verify"><i class="ti ti-shield-search"></i></button>
      </form>
    </div>

    <div class="bx-card bx-up" style="--i:11">
      <div class="bx-ct"><h3>Top links</h3><a href="/payment-links.php">View all</a></div>
      <?php if (empty($topLinks)): ?>
      <div class="bx-empty">No links yet. <a href="/create-link.php">Create one →</a></div>
      <?php else: foreach ($topLinks as $i => $link): ?>
      <div class="bx-row">
        <div class="bx-n"><?= $i + 1 ?></div>
        <div class="bx-info"><b title="<?= htmlspecialchars($link['title']) ?>"><?= htmlspecialchars($link['title']) ?></b><small><?= (int)$link['total_orders'] ?> orders</small></div>
        <div class="bx-val bx-amt"><?= formatNaira($link['total_revenue']) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="bx-card bx-up" style="--i:12">
      <div class="bx-ct"><h3>Recent transactions</h3><a href="/transactions.php">View all</a></div>
      <?php if (empty($recentTxns)): ?>
      <div class="bx-empty">No transactions yet.</div>
      <?php else: foreach ($recentTxns as $txn):
        $isCredit = $txn['type'] === 'credit';
        $typeIcon = ['credit'=>'arrow-down-left','debit'=>'arrow-up-right','withdrawal'=>'arrow-up-right','refund'=>'rotate','fee'=>'receipt-2'][$txn['type']] ?? 'dot'; ?>
      <div class="bx-row">
        <div class="bx-n <?= $isCredit ? 'cr' : 'db' ?>"><i class="ti ti-<?= $typeIcon ?>"></i></div>
        <div class="bx-info"><b title="<?= htmlspecialchars($txn['description']) ?>"><?= htmlspecialchars($txn['description']) ?></b><small><?= timeAgo($txn['created_at']) ?></small></div>
        <div class="bx-val bx-amt <?= $isCredit ? 'cr' : '' ?>"><?= $isCredit ? '+' : '−' ?><?= formatNaira($txn['net_amount']) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

</div><!-- /bx -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const LABELS = <?= json_encode($chartLabels) ?>;
const REV    = <?= json_encode($revSeries) ?>;
const ORD    = <?= json_encode($ordSeries) ?>;
const P = '#4F43D4';
const naira = n => '₦' + n.toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
const money = n => { const [i,d] = n.toFixed(2).split('.'); return '₦' + Number(i).toLocaleString('en-NG') + '<sup>.' + d + '</sup>'; };
const fill = c => {
  const {ctx, chartArea} = c.chart;
  if (!chartArea) return 'rgba(79,67,212,.1)';
  const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
  g.addColorStop(0, 'rgba(79,67,212,.18)'); g.addColorStop(1, 'rgba(79,67,212,0)');
  return g;
};
Chart.defaults.font.family = "'Space Grotesk', sans-serif";

/* main revenue chart */
let n = 30;
const rc = new Chart(document.getElementById('revenueChart'), {
  type: 'line',
  data: { labels: LABELS.slice(-n), datasets: [{ data: REV.slice(-n), borderColor: P, borderWidth: 2.5, tension: .42, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: P, fill: true, backgroundColor: fill }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    animation: { duration: 900, easing: 'easeOutQuart' },
    plugins: { legend: { display: false }, tooltip: { displayColors: false, backgroundColor: '#15131F', padding: 10, callbacks: { label: c => naira(c.parsed.y) } } },
    scales: {
      x: { grid: { color: '#DAD6E6', drawTicks: false }, border: { display: false, dash: [4,4] }, ticks: { color: '#8B8999', font: { size: 13 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, align: 'inner', padding: 10 } },
      y: { display: false, beginAtZero: true }
    }
  }
});
const rangeTxt = {7:'Last 7 days', 30:'Last 30 days', 90:'Last 90 days'};
document.querySelectorAll('#revSeg button').forEach(b => b.addEventListener('click', () => {
  n = +b.dataset.n;
  document.querySelectorAll('#revSeg button').forEach(x => x.classList.toggle('on', x === b));
  rc.data.labels = LABELS.slice(-n); rc.data.datasets[0].data = REV.slice(-n); rc.update();
  document.getElementById('revTotal').innerHTML = money(REV.slice(-n).reduce((a,c) => a + c, 0));
  document.getElementById('revRange').textContent = rangeTxt[n];
}));

/* sparklines */
function spark(id, data, labels) {
  const el = document.getElementById(id); if (!el) return;
  new Chart(el, { type: 'line',
    data: { labels, datasets: [{ data, borderColor: P, borderWidth: 2, tension: .45, pointRadius: 0, fill: true, backgroundColor: fill }] },
    options: { responsive: true, maintainAspectRatio: false, events: [], animation: { duration: 1200 },
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
      scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { autoSkip: false, maxRotation: 0, color: '#8B8999', font: { size: 11 }, padding: 8,
             callback: function (v, i, a) { return (i === 0 || i === a.length - 1 || i === Math.floor(a.length / 2)) ? this.getLabelForValue(v) : ''; } } },
        y: { display: false, beginAtZero: true } },
      layout: { padding: { top: 8, left: 24, right: 24, bottom: 6 } } } });
}
spark('sp1', REV.slice(-30), LABELS.slice(-30)); spark('sp2', ORD.slice(-30), LABELS.slice(-30)); spark('sp3', REV, LABELS);

/* recent payments carousel */
(function () {
  const s = document.querySelectorAll('.bx-slide'); if (!s.length) return;
  const prev = document.getElementById('payPrev'), next = document.getElementById('payNext'); let i = 0;
  function show(k) { i = Math.max(0, Math.min(s.length - 1, k)); s.forEach((e, j) => e.classList.toggle('on', j === i)); prev.disabled = i === 0; next.disabled = i === s.length - 1; }
  prev.onclick = () => show(i - 1); next.onclick = () => show(i + 1); show(0);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>