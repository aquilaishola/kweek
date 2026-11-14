<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('manage_withdrawals');

$pageTitle  = 'Withdrawals';
$activePage = 'withdrawals';
$db         = db();
$admin      = currentAdmin();

// ── stats ──────────────────────────────────────────────────────
$pendingCount   = (int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE status IN ('pending','processing')")->fetchColumn();
$paidOutToday   = (float) $db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='success' AND DATE(processed_at) = CURDATE()")->fetchColumn();
$paidOutMonth   = (float) $db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='success' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
$failedCount    = (int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE status='failed'")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (post('action') === 'update_status') {
        $id = (int) post('withdrawal_id');
        $newStatus = post('new_status');
        if (in_array($newStatus, ['pending','processing','success','failed'], true) && $id) {
            $stmt = $db->prepare("SELECT * FROM withdrawals WHERE id = ?");
            $stmt->execute([$id]);
            $wd = $stmt->fetch();

            if ($wd) {
                $db->prepare("UPDATE withdrawals SET status = ?, processed_at = IF(? IN ('success','failed'), NOW(), processed_at) WHERE id = ?")
                   ->execute([$newStatus, $newStatus, $id]);

                // A withdrawal manually corrected to 'failed' after the wallet
                // was already debited needs the funds returned — refund once,
                // only on the pending/processing → failed transition.
                if ($newStatus === 'failed' && !in_array($wd['status'], ['failed'], true)) {
                    creditWallet($wd['user_id'], (float) $wd['amount'], 0, 'Withdrawal reversal — ' . $wd['reference'], generateRef('RVS'));
                    notify($wd['user_id'], 'withdrawal', 'Withdrawal Failed', formatNaira((float)$wd['amount']) . ' withdrawal failed and has been returned to your wallet.', 'alert-triangle', '/transactions.php');
                }
                flash('success', 'Withdrawal marked ' . $newStatus . '.');
            }
        }
        redirect('/admin/withdrawals.php?' . http_build_query(array_filter(['status'=>get('status')])));
    }
}

$statusFilter = in_array(get('status'), ['pending','processing','success','failed']) ? get('status') : '';
$search = trim((string) get('q'));

$where = ['1=1']; $params = [];
if ($statusFilter !== '') { $where[] = 'w.status = ?'; $params[] = $statusFilter; }
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR w.reference LIKE ? OR w.account_number LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT w.*, u.name AS user_name, u.email AS user_email
    FROM withdrawals w JOIN users u ON u.id = w.user_id
    WHERE $whereSql ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function wdUrl(array $overrides = []): string {
    $q = array_filter(array_merge(['status'=>get('status'),'q'=>get('q')], $overrides), fn($v)=>$v!=='' && $v!==null);
    return '/admin/withdrawals.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-clock"></i> Pending / Processing</div><div class="stat-card-value"><?= number_format($pendingCount) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-cash"></i> Paid Out Today</div><div class="stat-card-value"><?= formatNaira($paidOutToday) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-calendar"></i> Paid Out This Month</div><div class="stat-card-value"><?= formatNaira($paidOutMonth) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-alert-triangle"></i> Failed (All Time)</div><div class="stat-card-value"><?= number_format($failedCount) ?></div></div>
</div>

<div class="filter-bar">
  <a href="<?= wdUrl(['status'=>'']) ?>" class="filter-tab <?= $statusFilter===''?'active':'' ?>">All</a>
  <a href="<?= wdUrl(['status'=>'pending']) ?>" class="filter-tab <?= $statusFilter==='pending'?'active':'' ?>">Pending</a>
  <a href="<?= wdUrl(['status'=>'processing']) ?>" class="filter-tab <?= $statusFilter==='processing'?'active':'' ?>">Processing</a>
  <a href="<?= wdUrl(['status'=>'success']) ?>" class="filter-tab <?= $statusFilter==='success'?'active':'' ?>">Success</a>
  <a href="<?= wdUrl(['status'=>'failed']) ?>" class="filter-tab <?= $statusFilter==='failed'?'active':'' ?>">Failed</a>
  <form method="GET" class="search-input-wrap">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search merchant, ref, account...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state" style="padding:48px 20px"><div class="empty-title">No withdrawals found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Merchant</th><th>Amount</th><th>Bank</th><th>Reference</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $w): ?>
    <tr>
      <td><div class="td-bold"><?= htmlspecialchars($w['user_name']) ?></div><div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($w['user_email']) ?></div></td>
      <td class="td-bold"><?= formatNaira((float)$w['net_amount']) ?></td>
      <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($w['bank_name']) ?><br><?= htmlspecialchars($w['account_number']) ?></td>
      <td style="font-family:var(--font-mono,monospace);font-size:11px;color:var(--muted)"><?= htmlspecialchars($w['reference']) ?></td>
      <td><span class="badge badge-<?= $w['status']==='success'?'success':($w['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($w['status']) ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($w['created_at']) ?></td>
      <td>
        <form method="POST" style="display:flex;gap:6px;align-items:center">
          <?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
          <select name="new_status" class="form-select" style="min-width:110px;padding:6px 26px 6px 10px;font-size:12.5px">
            <?php foreach (['pending','processing','success','failed'] as $s): ?>
            <option value="<?= $s ?>" <?= $w['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Update this withdrawal status?')">Set</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= wdUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= wdUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
