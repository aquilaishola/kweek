<?php
$pageTitle = 'KYC / BVN Verification';
$activePage = 'kyc';
require_once __DIR__ . '/includes/layout-top.php';

if (!in_array($admin['role'], ['super_admin','support'], true)) {
    flash('error', "You don't have permission to view this page.");
    redirect('/admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $kycId  = (int)post('kyc_id');
    $action = post('action');

    $stmt = $db->prepare("SELECT * FROM user_kyc WHERE id=? LIMIT 1");
    $stmt->execute([$kycId]);
    $k = $stmt->fetch();

    if ($k && $action === 'approve') {
        $db->prepare("UPDATE user_kyc SET status='verified', verified_at=NOW() WHERE id=?")->execute([$kycId]);
        $db->prepare("UPDATE users SET bvn_verified=1 WHERE id=?")->execute([$k['user_id']]);
        notify($k['user_id'], 'kyc', 'BVN Verified', 'Your BVN has been verified. Withdrawals are now unlocked.', 'shield', '/settings.php');
        flash('success', 'KYC approved.');
    } elseif ($k && $action === 'reject') {
        $db->prepare("UPDATE user_kyc SET status='failed' WHERE id=?")->execute([$kycId]);
        notify($k['user_id'], 'kyc', 'BVN Verification Failed', 'We could not verify your BVN details. Please try again or contact support.', 'shield', '/verify-bvn.php');
        flash('success', 'KYC marked as failed.');
    }
    redirect('/admin/kyc.php');
}

$statusFilter = get('status', 'pending');
$where = [];
$params = [];
if (in_array($statusFilter, ['pending','verified','failed'], true)) {
    $where[] = "k.status = ?"; $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT k.*, u.name AS merchant_name, u.email AS merchant_email, u.phone AS merchant_phone
    FROM user_kyc k JOIN users u ON k.user_id = u.id
    $whereSql ORDER BY k.created_at DESC LIMIT 50
");
$stmt->execute($params);
$kycRows = $stmt->fetchAll();
?>

<div class="filter-bar">
  <?php foreach (['pending'=>'Pending','verified'=>'Verified','failed'=>'Failed','' =>'All'] as $val => $label): ?>
  <a href="?status=<?= $val ?>" class="btn btn-sm <?= $statusFilter === $val ? 'btn-purple' : 'btn-outline' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>Merchant</th><th>BVN (masked)</th><th>KYC Name</th><th>Watchlisted</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($kycRows)): ?>
      <tr class="empty-row"><td colspan="7">No records in this view.</td></tr>
      <?php else: foreach ($kycRows as $k):
        $sMap = ['pending'=>'warning','verified'=>'success','failed'=>'danger'];
      ?>
      <tr>
        <td>
          <div class="td-bold"><?= htmlspecialchars($k['merchant_name']) ?></div>
          <div class="td-muted"><?= htmlspecialchars($k['merchant_email']) ?></div>
        </td>
        <td class="mono">•••• <?= htmlspecialchars($k['bvn_last4'] ?: '----') ?></td>
        <td><?= htmlspecialchars(trim(($k['kyc_first_name'] ?? '') . ' ' . ($k['kyc_last_name'] ?? '')) ?: '—') ?></td>
        <td><?php if ($k['watch_listed']): ?><span class="badge badge-danger"><i class="ti ti-alert-triangle"></i> Flagged</span><?php else: ?><span class="badge badge-neutral">Clear</span><?php endif; ?></td>
        <td><span class="badge badge-<?= $sMap[$k['status']] ?? 'neutral' ?>"><?= ucfirst($k['status']) ?></span></td>
        <td class="td-muted"><?= timeAgo($k['created_at']) ?></td>
        <td>
          <?php if ($k['status'] === 'pending'): ?>
          <div style="display:flex;gap:6px">
            <form method="POST" onsubmit="return confirm('Approve this BVN verification?')">
              <?= csrfField() ?><input type="hidden" name="kyc_id" value="<?= $k['id'] ?>"><input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm"><i class="ti ti-check"></i></button>
            </form>
            <form method="POST" onsubmit="return confirm('Mark this KYC as failed?')">
              <?= csrfField() ?><input type="hidden" name="kyc_id" value="<?= $k['id'] ?>"><input type="hidden" name="action" value="reject">
              <button type="submit" class="btn btn-danger btn-sm"><i class="ti ti-x"></i></button>
            </form>
          </div>
          <?php else: ?>
          <span class="td-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>