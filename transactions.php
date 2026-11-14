<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Transactions';
$activePage = 'transactions';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// Filters
$typeFilter  = in_array(get('type'), ['credit','debit','withdrawal','refund','fee']) ? get('type') : '';
$search      = clean(get('q'));
$dateFrom    = get('from') ? date('Y-m-d', strtotime(get('from'))) : date('Y-m-01');
$dateTo      = get('to')   ? date('Y-m-d', strtotime(get('to')))   : date('Y-m-d');
$page        = max(1, (int)get('page', 1));
$perPage     = 20;
$offset      = ($page - 1) * $perPage;

$where  = ["t.user_id = ?", "DATE(t.created_at) BETWEEN ? AND ?"];
$params = [$uid, $dateFrom, $dateTo];

if ($typeFilter) { $where[] = "t.type = ?";            $params[] = $typeFilter; }
if ($search)     { $where[] = "(t.description LIKE ? OR t.reference LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM transactions t WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("
    SELECT t.*, o.order_ref, r.receipt_code
    FROM transactions t
    LEFT JOIN orders o   ON o.id = t.order_id
    LEFT JOIN receipts r ON r.order_id = t.order_id
    WHERE $whereSQL
    ORDER BY t.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$txns = $stmt->fetchAll();

// Summary for period
$sumStmt = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN t.type='credit' THEN t.net_amount ELSE 0 END),0) as total_in,
    COALESCE(SUM(CASE WHEN t.type IN ('withdrawal','debit') THEN t.amount ELSE 0 END),0) as total_out,
    COUNT(*) as total_count
    FROM transactions t WHERE $whereSQL");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

// Breakdown by type for the period — small chips under the summary cards
$typeBreakdownStmt = $db->prepare("SELECT t.type, COUNT(*) as cnt, COALESCE(SUM(t.amount),0) as amt FROM transactions t WHERE $whereSQL GROUP BY t.type");
$typeBreakdownStmt->execute($params);
$typeBreakdown = [];
foreach ($typeBreakdownStmt->fetchAll() as $row) {
    $typeBreakdown[$row['type']] = $row;
}

// CSV Export
if (get('export') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kweek-transactions-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference','Description','Type','Amount','Fee','Net','Balance After','Status','Date']);
    $allStmt = $db->prepare("SELECT * FROM transactions t WHERE $whereSQL ORDER BY t.created_at DESC");
    $allStmt->execute($params);
    while ($row = $allStmt->fetch()) {
        fputcsv($out, [$row['reference'],$row['description'],$row['type'],$row['amount'],$row['fee'],$row['net_amount'],$row['balance_after'],$row['status'],nigeriaTime($row['created_at'])]);
    }
    fclose($out);
    exit;
}

// Quick date presets — used both to render the chips and to detect which (if any) is active
$presets = [
    'today' => ['label' => 'Today',        'from' => date('Y-m-d'),                'to' => date('Y-m-d')],
    '7d'    => ['label' => 'Last 7 Days',  'from' => date('Y-m-d', strtotime('-6 days')),  'to' => date('Y-m-d')],
    '30d'   => ['label' => 'Last 30 Days', 'from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d')],
    'month' => ['label' => 'This Month',   'from' => date('Y-m-01'),               'to' => date('Y-m-d')],
    'year'  => ['label' => 'This Year',    'from' => date('Y-01-01'),              'to' => date('Y-m-d')],
];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── page header ─────────────────────────────────── */
.tx-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.tx-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px;margin:0}
.tx-header p{font-size:14px;color:var(--muted);margin:2px 0 0}

/* ── summary cards — dedicated classes so stacking is guaranteed
   regardless of whatever the shared .stat-grid/.stat-card elsewhere
   in the app looks like. Stacks to 2 cols, then a single column
   ("stacked on top of each other") on small screens. ──────────── */
.tx-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
@media(max-width:900px){.tx-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.tx-stats{grid-template-columns:1fr}}
.tx-stat-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:18px}
.tx-stat-icon{width:36px;height:36px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:12px}
.tx-stat-label{font-size:12px;color:var(--muted);margin-bottom:4px}
.tx-stat-value{font-family:var(--font-display);font-size:19px;font-weight:800;color:var(--ink)}
.tx-stat-sub{font-size:11.5px;color:var(--muted);margin-top:4px}

/* ── type breakdown chips ────────────────────────── */
.tx-breakdown{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.tx-breakdown-chip{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:var(--r-full);background:var(--surface);border:1px solid var(--line);font-size:12px;color:var(--ink)}
.tx-breakdown-chip strong{font-weight:700}

/* ── quick date presets ──────────────────────────── */
.tx-presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.tx-preset{padding:7px 14px;border-radius:var(--r-full);font-size:12.5px;font-weight:600;text-decoration:none;border:1.5px solid var(--line);background:var(--paper);color:var(--muted);transition:all .2s}
.tx-preset.active,.tx-preset:hover{border-color:var(--purple-600);background:var(--purple-600);color:#fff}

/* ── filter card ──────────────────────────────────── */
.tx-filter-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:16px 20px;margin-bottom:20px}
.tx-filter-field{display:flex;flex-direction:column;gap:6px}
.tx-filter-field label{font-size:12px;font-weight:600;color:var(--ink)}

/* ── table ─────────────────────────────────────────── */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg)}
.table-wrap table{min-width:920px;width:100%;border-collapse:collapse}
.table-wrap thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line)}
.table-wrap tbody td{padding:13px 16px;border-bottom:1px solid var(--line);font-size:13px;color:var(--ink);vertical-align:middle}
.table-wrap tbody tr:last-child td{border-bottom:none}
.table-wrap tbody tr:hover{background:var(--surface)}
.td-bold{font-weight:600;color:var(--ink)}
.table-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--line)}
.table-title{font-size:14px;font-weight:700;color:var(--ink)}
.ref-copy-btn{background:none;border:none;cursor:pointer;color:var(--muted-2);padding:2px;font-size:12px;vertical-align:middle;margin-left:4px}
.ref-copy-btn:hover{color:var(--purple-600)}

.pagination{background:var(--paper);border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 16px;flex-wrap:wrap;gap:8px}
.pag-info{font-size:13px;color:var(--muted)}
.pag-btns{display:flex;gap:4px}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:var(--r-md);font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;border:1.5px solid var(--line);transition:all .15s}
.pag-btn:hover{border-color:var(--purple-500);color:var(--purple-600)}
.pag-btn.active{background:var(--purple-600);border-color:var(--purple-600);color:#fff}
</style>

<div class="tx-header">
  <div>
    <h1>Transactions</h1>
    <p>Full history of your earnings and withdrawals</p>
  </div>
  <a href="/withdraw.php" class="btn btn-purple btn-md"><i class="ti ti-cash"></i> Withdraw</a>
</div>

<!-- SUMMARY CARDS -->
<div class="tx-stats">
  <div class="tx-stat-card">
    <div class="tx-stat-icon" style="background:var(--success-bg);color:var(--success)"><i class="ti ti-arrow-down-left"></i></div>
    <div class="tx-stat-label">Total In</div>
    <div class="tx-stat-value"><?= formatNaira($summary['total_in']) ?></div>
    <div class="tx-stat-sub"><?= nigeriaTime($dateFrom) ?> — <?= nigeriaTime($dateTo) ?></div>
  </div>
  <div class="tx-stat-card">
    <div class="tx-stat-icon" style="background:var(--danger-bg);color:var(--danger)"><i class="ti ti-arrow-up-right"></i></div>
    <div class="tx-stat-label">Total Out</div>
    <div class="tx-stat-value"><?= formatNaira($summary['total_out']) ?></div>
    <div class="tx-stat-sub">Withdrawals &amp; fees</div>
  </div>
  <div class="tx-stat-card">
    <div class="tx-stat-icon" style="background:var(--purple-50);color:var(--purple-600)"><i class="ti ti-wallet"></i></div>
    <div class="tx-stat-label">Wallet Balance</div>
    <div class="tx-stat-value"><?= formatNaira($user['wallet_balance']) ?></div>
    <div class="tx-stat-sub">Available now</div>
  </div>
  <div class="tx-stat-card">
    <div class="tx-stat-icon" style="background:var(--surface);color:var(--ink)"><i class="ti ti-receipt"></i></div>
    <div class="tx-stat-label">Transactions</div>
    <div class="tx-stat-value"><?= number_format($summary['total_count']) ?></div>
    <div class="tx-stat-sub">In selected period</div>
  </div>
</div>

<!-- TYPE BREAKDOWN -->
<?php if (!empty($typeBreakdown)): ?>
<div class="tx-breakdown">
  <?php
  $typeIcons = ['credit'=>'arrow-down-left','debit'=>'arrow-up-right','withdrawal'=>'cash','refund'=>'refresh','fee'=>'receipt-2'];
  foreach ($typeBreakdown as $type => $row):
  ?>
  <div class="tx-breakdown-chip">
    <i class="ti ti-<?= $typeIcons[$type] ?? 'dot' ?>"></i>
    <span style="text-transform:capitalize"><?= $type ?></span>
    <strong><?= $row['cnt'] ?></strong>
    <span style="color:var(--muted)">· <?= formatNaira($row['amt']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- QUICK DATE PRESETS -->
<div class="tx-presets">
  <?php foreach ($presets as $p):
    $isActive = ($dateFrom === $p['from'] && $dateTo === $p['to']);
  ?>
  <a href="?from=<?= $p['from'] ?>&to=<?= $p['to'] ?>&type=<?= $typeFilter ?>&q=<?= urlencode($search) ?>"
     class="tx-preset <?= $isActive?'active':'' ?>"><?= $p['label'] ?></a>
  <?php endforeach; ?>
</div>

<!-- FILTERS -->
<div class="tx-filter-card">
  <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div class="tx-filter-field" style="flex:1;min-width:160px">
      <label>Search</label>
      <div class="form-input-wrap">
        <i class="ti ti-search inp-icon"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Description or reference..." class="form-input">
      </div>
    </div>
    <div class="tx-filter-field">
      <label>Type</label>
      <select name="type" class="form-select">
        <option value="">All types</option>
        <?php foreach (['credit'=>'Credit (Money In)','debit'=>'Debit','withdrawal'=>'Withdrawal','refund'=>'Refund','fee'=>'Fee'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $typeFilter===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="tx-filter-field">
      <label>From</label>
      <input type="date" name="from" value="<?= $dateFrom ?>" class="form-input">
    </div>
    <div class="tx-filter-field">
      <label>To</label>
      <input type="date" name="to" value="<?= $dateTo ?>" class="form-input">
    </div>
    <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-filter"></i> Filter</button>
    <a href="/transactions.php" class="btn btn-outline btn-md"><i class="ti ti-x"></i> Clear</a>
  </form>
</div>

<!-- TABLE -->
<div class="table-wrap">
  <div class="table-head">
    <span class="table-title"><?= number_format($total) ?> Transaction<?= $total!==1?'s':'' ?></span>
    <a href="/transactions.php?<?= http_build_query(['q'=>$search,'type'=>$typeFilter,'from'=>$dateFrom,'to'=>$dateTo,'export'=>'csv']) ?>" class="btn btn-outline btn-sm">
      <i class="ti ti-download"></i> Export CSV
    </a>
  </div>
  <?php if (empty($txns)): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="ti ti-receipt"></i></div>
    <div class="empty-title">No transactions found</div>
    <div class="empty-sub">Try adjusting your filters or date range.</div>
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Reference</th>
        <th>Description</th>
        <th>Type</th>
        <th>Amount</th>
        <th>Fee</th>
        <th>Net</th>
        <th>Balance After</th>
        <th>Status</th>
        <th>Date</th>
        <th>Receipt</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($txns as $t):
      $isCredit = $t['type'] === 'credit';
      $typeColors = ['credit'=>'success','debit'=>'danger','withdrawal'=>'warning','refund'=>'purple','fee'=>'neutral'];
      $tc = $typeColors[$t['type']] ?? 'neutral';
    ?>
    <tr>
      <td>
        <span style="font-family:var(--font-mono);font-size:11px;color:var(--muted)"><?= htmlspecialchars(substr($t['reference'],0,20)) ?>...</span>
        <button class="ref-copy-btn" title="Copy full reference" onclick="copyRef('<?= htmlspecialchars(addslashes($t['reference'])) ?>')"><i class="ti ti-copy"></i></button>
      </td>
      <td style="max-width:200px">
        <div style="font-size:13px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($t['description']) ?></div>
      </td>
      <td><span class="badge badge-<?= $tc ?>"><?= ucfirst($t['type']) ?></span></td>
      <td class="td-bold"><?= formatNaira($t['amount']) ?></td>
      <td style="color:var(--muted);font-size:13px"><?= formatNaira($t['fee']) ?></td>
      <td style="font-weight:700;color:<?= $isCredit?'var(--success)':'var(--danger)' ?>">
        <?= $isCredit?'+':'-' ?><?= formatNaira($t['net_amount']) ?>
      </td>
      <td style="font-size:13px;color:var(--ink)"><?= formatNaira($t['balance_after']) ?></td>
      <td><span class="badge badge-<?= $t['status']==='success'?'success':($t['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($t['status']) ?></span></td>
      <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= nigeriaTime($t['created_at']) ?></td>
      <td>
  <?php if (!empty($t['order_ref'])): ?>
  <a href="/receipt/<?= urlencode($t['order_ref']) ?>" target="_blank" class="btn btn-outline btn-sm">
    <i class="ti ti-receipt"></i> View
  </a>
  <?php else: ?>
  <span style="font-size:12px;color:var(--muted-2)">—</span>
  <?php endif; ?>
</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <!-- PAGINATION -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&type=<?= $typeFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
      <a href="?page=<?= $i ?>&type=<?= $typeFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&type=<?= $typeFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function copyRef(ref) {
  navigator.clipboard.writeText(ref).then(() => showToast('success', 'Copied', 'Reference copied to clipboard'));
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
