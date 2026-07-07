<?php
// admin/transactions.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Transactions';
$activePage = 'transactions';
$db         = db();

$typeF    = in_array(get('type'),['credit','debit','withdrawal','fee']) ? get('type') : '';
$search   = clean(get('q'));
$dateFrom = get('from') ? date('Y-m-d', strtotime(get('from'))) : date('Y-m-01');
$dateTo   = get('to')   ? date('Y-m-d', strtotime(get('to')))   : date('Y-m-d');
$page     = max(1,(int)get('page',1));
$perPage  = 25;
$offset   = ($page-1)*$perPage;

$where  = ["DATE(t.created_at) BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];
if ($typeF)  { $where[] = "t.type=?"; $params[] = $typeF; }
if ($search) { $where[] = "(u.name LIKE ? OR t.reference LIKE ? OR t.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$wSQL = implode(' AND ', $where);

$cStmt = $db->prepare("SELECT COUNT(*) FROM transactions t JOIN users u ON t.user_id=u.id WHERE $wSQL");
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("SELECT t.*, u.name as merchant_name FROM transactions t JOIN users u ON t.user_id=u.id WHERE $wSQL ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$txns = $stmt->fetchAll();

$sumStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN t.type='credit' THEN t.amount ELSE 0 END),0) as vol, COALESCE(SUM(t.fee),0) as fees FROM transactions t JOIN users u ON t.user_id=u.id WHERE $wSQL");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

include __DIR__ . '/includes/header.php';
?>
<div style="margin-bottom:20px">
  <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Transactions</h1>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-trending-up"></i></div>
    <div class="stat-label">Volume (Period)</div>
    <div class="stat-value" style="font-size:20px"><?= formatNaira($summary['vol']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--p50);color:var(--p600)"><i class="ti ti-coin"></i></div>
    <div class="stat-label">Fees Earned</div>
    <div class="stat-value" style="font-size:20px"><?= formatNaira($summary['fees']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-receipt"></i></div>
    <div class="stat-label">Transactions</div>
    <div class="stat-value" style="font-size:20px"><?= number_format($total) ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px;padding:12px 14px">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:160px;position:relative">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:14px"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or reference..." style="width:100%;font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px 7px 28px;outline:none;background:var(--n50)">
    </div>
    <select name="type" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px;background:var(--n50)">
      <option value="">All Types</option>
      <?php foreach (['credit'=>'Credit','debit'=>'Debit','withdrawal'=>'Withdrawal','fee'=>'Fee'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= $typeF===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= $dateFrom ?>" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px;background:var(--n50)">
    <input type="date" name="to" value="<?= $dateTo ?>" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px;background:var(--n50)">
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-filter"></i> Filter</button>
    <a href="/admin/transactions.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
    <a href="?<?= http_build_query(['q'=>$search,'type'=>$typeF,'from'=>$dateFrom,'to'=>$dateTo,'export'=>'csv']) ?>" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> CSV</a>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($txns)): ?>
  <div style="text-align:center;padding:40px;color:var(--n500)">No transactions found.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Merchant</th><th>Description</th><th>Type</th><th>Amount</th><th>Fee</th><th>Net</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($txns as $t):
      $tc = ['credit'=>'success','debit'=>'danger','withdrawal'=>'warning','refund'=>'purple','fee'=>'neutral'][$t['type']]??'neutral';
    ?>
    <tr>
      <td class="td-bold"><?= htmlspecialchars($t['merchant_name']) ?></td>
      <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px"><?= htmlspecialchars($t['description']) ?></td>
      <td><span class="badge badge-<?= $tc ?>"><?= $t['type'] ?></span></td>
      <td class="td-bold"><?= formatNaira($t['amount']) ?></td>
      <td style="color:var(--p600);font-weight:600"><?= formatNaira($t['fee']) ?></td>
      <td style="color:<?= $t['type']==='credit'?'var(--success)':'var(--danger)' ?>;font-weight:700"><?= formatNaira($t['net_amount']) ?></td>
      <td><span class="badge badge-<?= $t['status']==='success'?'success':($t['status']==='failed'?'danger':'warning') ?>"><?= $t['status'] ?></span></td>
      <td style="font-size:11px;color:var(--n500);white-space:nowrap"><?= nigeriaTime($t['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&type=<?= $typeF ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><a href="?page=<?= $i ?>&type=<?= $typeF ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&type=<?= $typeF ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php
if (get('export') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kweek-txns-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Merchant','Description','Type','Amount','Fee','Net','Status','Date']);
    $all = $db->prepare("SELECT t.*,u.name as merchant_name FROM transactions t JOIN users u ON t.user_id=u.id WHERE $wSQL ORDER BY t.created_at DESC");
    $all->execute($params);
    while ($r=$all->fetch()) fputcsv($out,[$r['merchant_name'],$r['description'],$r['type'],$r['amount'],$r['fee'],$r['net_amount'],$r['status'],nigeriaTime($r['created_at'])]);
    fclose($out); exit;
}

include __DIR__ . '/includes/footer.php';
?>
