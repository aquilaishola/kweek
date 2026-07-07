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


include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px">Transactions</h1>
    <p style="font-size:14px;color:var(--n500);margin-top:2px">Full history of your earnings and withdrawals</p>
  </div>
  <a href="/withdraw.php" class="btn btn-purple btn-md"><i class="ti ti-cash"></i> Withdraw</a>
</div>

<!-- SUMMARY CARDS -->
<div class="stat-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--success-bg);color:var(--success)"><i class="ti ti-arrow-down-left"></i></div>
    <div class="stat-label">Total In</div>
    <div class="stat-value"><?= formatNaira($summary['total_in']) ?></div>
    <div style="font-size:12px;color:var(--n500)"><?= nigeriaTime($dateFrom) ?> — <?= nigeriaTime($dateTo) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--danger-bg);color:var(--danger)"><i class="ti ti-arrow-up-right"></i></div>
    <div class="stat-label">Total Out</div>
    <div class="stat-value"><?= formatNaira($summary['total_out']) ?></div>
    <div style="font-size:12px;color:var(--n500)">Withdrawals &amp; fees</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-wallet"></i></div>
    <div class="stat-label">Wallet Balance</div>
    <div class="stat-value"><?= formatNaira($user['wallet_balance']) ?></div>
    <div style="font-size:12px;color:var(--n500)">Available now</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-receipt"></i></div>
    <div class="stat-label">Transactions</div>
    <div class="stat-value"><?= number_format($summary['total_count']) ?></div>
    <div style="font-size:12px;color:var(--n500)">In selected period</div>
  </div>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;padding:16px 20px">
  <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:160px">
      <div style="font-size:12px;font-weight:600;color:var(--n600);margin-bottom:6px">Search</div>
      <div style="position:relative">
        <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:15px"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Description or reference..."
          style="width:100%;font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:9px 12px 9px 32px;outline:none;background:var(--n0);transition:all .2s">
      </div>
    </div>
    <div>
      <div style="font-size:12px;font-weight:600;color:var(--n600);margin-bottom:6px">Type</div>
      <select name="type" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:9px 12px;outline:none;background:var(--n0);cursor:pointer">
        <option value="">All types</option>
        <?php foreach (['credit'=>'Credit (Money In)','debit'=>'Debit','withdrawal'=>'Withdrawal','refund'=>'Refund','fee'=>'Fee'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $typeFilter===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <div style="font-size:12px;font-weight:600;color:var(--n600);margin-bottom:6px">From</div>
      <input type="date" name="from" value="<?= $dateFrom ?>" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:9px 12px;outline:none;background:var(--n0)">
    </div>
    <div>
      <div style="font-size:12px;font-weight:600;color:var(--n600);margin-bottom:6px">To</div>
      <input type="date" name="to" value="<?= $dateTo ?>" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:9px 12px;outline:none;background:var(--n0)">
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
      <td><span style="font-family:monospace;font-size:11px;color:var(--n500)"><?= htmlspecialchars(substr($t['reference'],0,20)) ?>...</span></td>
      <td style="max-width:200px">
        <div style="font-size:13px;font-weight:500;color:var(--n800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($t['description']) ?></div>
      </td>
      <td><span class="badge badge-<?= $tc ?>"><?= ucfirst($t['type']) ?></span></td>
      <td class="td-bold"><?= formatNaira($t['amount']) ?></td>
      <td style="color:var(--n500);font-size:13px"><?= formatNaira($t['fee']) ?></td>
      <td style="font-weight:700;color:<?= $isCredit?'var(--success)':'var(--danger)' ?>">
        <?= $isCredit?'+':'-' ?><?= formatNaira($t['net_amount']) ?>
      </td>
      <td style="font-size:13px;color:var(--n600)"><?= formatNaira($t['balance_after']) ?></td>
      <td><span class="badge badge-<?= $t['status']==='success'?'success':($t['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($t['status']) ?></span></td>
      <td style="font-size:12px;color:var(--n500);white-space:nowrap"><?= nigeriaTime($t['created_at']) ?></td>
      <td>
  <?php if (!empty($t['order_ref'])): ?>
  <a href="/receipt/<?= urlencode($t['order_ref']) ?>" target="_blank" class="btn btn-outline btn-sm">
    <i class="ti ti-receipt"></i> View
  </a>
  <?php else: ?>
  <span style="font-size:12px;color:var(--n400)">—</span>
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

<?php
include __DIR__ . '/includes/footer.php';
?>
