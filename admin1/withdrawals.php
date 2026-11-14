<?php
$pageTitle = 'Withdrawals';
$activePage = 'withdrawals';
require_once __DIR__ . '/includes/layout-top.php';

if (!in_array($admin['role'], ['super_admin','finance'], true)) {
    flash('error', "You don't have permission to view this page.");
    redirect('/admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $wid = (int)post('withdrawal_id');
    $action = post('action');

    $stmt = $db->prepare("SELECT * FROM withdrawals WHERE id=? LIMIT 1");
    $stmt->execute([$wid]);
    $w = $stmt->fetch();

    if (!$w) {
        flash('error', 'Withdrawal not found.');
    } elseif ($w['status'] !== 'pending') {
        flash('error', 'This withdrawal has already been processed.');
    } elseif ($action === 'approve') {
 
        $db->prepare("UPDATE withdrawals SET status='success', processed_at=NOW() WHERE id=?")->execute([$wid]);
        notify($w['user_id'], 'withdrawal', 'Withdrawal completed', 'Your withdrawal of ' . formatNaira($w['net_amount']) . ' has been processed.', 'bank', '/transactions.php');
        flash('success', 'Withdrawal approved and marked as processed.');
    } elseif ($action === 'reject') {
        $reason = clean(post('reject_reason')) ?: 'Rejected by admin.';
        $db->prepare("UPDATE withdrawals SET status='failed', failure_reason=?, processed_at=NOW() WHERE id=?")->execute([$reason, $wid]);
        creditWallet($w['user_id'], $w['amount'], 0, 'Withdrawal rejected — refund: ' . $reason, generateRef('RFD'));
        notify($w['user_id'], 'withdrawal', 'Withdrawal rejected', 'Your withdrawal request was rejected: ' . $reason . '. Funds have been returned to your wallet.', 'bank', '/transactions.php');
        flash('success', 'Withdrawal rejected and wallet refunded.');
    }
    redirect('/admin/withdrawals.php');
}

$statusFilter = get('status', 'pending');
$where = [];
$params = [];
if (in_array($statusFilter, ['pending','processing','success','failed'], true)) {
    $where[] = "w.status = ?"; $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT w.*, u.name AS merchant_name, u.email AS merchant_email
    FROM withdrawals w JOIN users u ON w.user_id = u.id
    $whereSql ORDER BY w.created_at DESC LIMIT 50
");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();
?>

<div class="filter-bar">
  <?php foreach (['pending'=>'Pending','processing'=>'Processing','success'=>'Completed','failed'=>'Failed','' =>'All'] as $val => $label): ?>
  <a href="?status=<?= $val ?>" class="btn btn-sm <?= $statusFilter === $val ? 'btn-purple' : 'btn-outline' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>Merchant</th><th>Amount</th><th>Fee</th><th>Net</th><th>Destination</th><th>Status</th><th>Requested</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($withdrawals)): ?>
      <tr class="empty-row"><td colspan="8">No withdrawals in this view.</td></tr>
      <?php else: foreach ($withdrawals as $w):
        $sMap = ['pending'=>'warning','processing'=>'purple','success'=>'success','failed'=>'danger'];
      ?>
      <tr>
        <td>
          <div class="td-bold"><?= htmlspecialchars($w['merchant_name']) ?></div>
          <div class="td-muted"><?= htmlspecialchars($w['merchant_email']) ?></div>
        </td>
        <td class="mono"><?= formatNaira($w['amount']) ?></td>
        <td class="mono td-muted"><?= formatNaira($w['fee']) ?></td>
        <td class="mono td-bold"><?= formatNaira($w['net_amount']) ?></td>
        <td>
          <div class="td-bold" style="font-size:12.5px"><?= htmlspecialchars($w['bank_name']) ?></div>
          <div class="td-muted mono"><?= htmlspecialchars($w['account_number']) ?> · <?= htmlspecialchars($w['account_name']) ?></div>
        </td>
        <td><span class="badge badge-<?= $sMap[$w['status']] ?? 'neutral' ?>"><?= ucfirst($w['status']) ?></span></td>
        <td class="td-muted"><?= timeAgo($w['created_at']) ?></td>
        <td>
          <?php if ($w['status'] === 'pending'): ?>
          <div style="display:flex;gap:6px">
            <form method="POST" onsubmit="return confirm('Approve and mark this withdrawal as processed?')">
              <?= csrfField() ?><input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>"><input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm"><i class="ti ti-check"></i></button>
            </form>
            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('reject-<?= $w['id'] ?>').style.display='flex'"><i class="ti ti-x"></i></button>
          </div>
          <form method="POST" id="reject-<?= $w['id'] ?>" style="display:none;gap:6px;margin-top:6px" onsubmit="return confirm('Reject and refund wallet?')">
            <?= csrfField() ?><input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>"><input type="hidden" name="action" value="reject">
            <input type="text" name="reject_reason" class="filter-input" placeholder="Rejection reason" required style="font-size:12px;padding:6px 10px">
            <button type="submit" class="btn btn-danger btn-sm">Confirm</button>
          </form>
          <?php else: ?>
          <span class="td-muted" style="font-size:12px"><?= $w['failure_reason'] ? htmlspecialchars($w['failure_reason']) : '—' ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>