<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('manage_kyc');

$pageTitle  = 'KYC Review';
$activePage = 'kyc';
$db         = db();

$statusFilter = in_array(get('status'), ['pending', 'verified', 'failed']) ? get('status') : 'pending';
$search       = trim((string) get('q'));

$where  = ["uk.status = ?"];
$params = [$statusFilter];

if ($search !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int) get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM user_kyc uk JOIN users u ON u.id = uk.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT uk.*, u.name AS user_name, u.email AS user_email, u.status AS user_status
    FROM user_kyc uk
    JOIN users u ON u.id = uk.user_id
    WHERE $whereSql
    ORDER BY uk.submitted_at DESC, uk.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $db->query("SELECT status, COUNT(*) c FROM user_kyc GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

function kycTabUrl(string $status, string $search): string {
    $q = ['status' => $status];
    if ($search !== '') $q['q'] = $search;
    return '/admin/kyc.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
  <a href="<?= kycTabUrl('pending', $search) ?>" class="filter-tab <?= $statusFilter==='pending'?'active':'' ?>">Pending <?= isset($counts['pending']) ? '('.$counts['pending'].')' : '' ?></a>
  <a href="<?= kycTabUrl('verified', $search) ?>" class="filter-tab <?= $statusFilter==='verified'?'active':'' ?>">Verified <?= isset($counts['verified']) ? '('.$counts['verified'].')' : '' ?></a>
  <a href="<?= kycTabUrl('failed', $search) ?>" class="filter-tab <?= $statusFilter==='failed'?'active':'' ?>">Rejected <?= isset($counts['failed']) ? '('.$counts['failed'].')' : '' ?></a>
  <form method="GET" class="search-input-wrap">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
    <div class="empty-state" style="padding:48px 20px">
      <div class="empty-icon"><i class="ti ti-id-badge"></i></div>
      <div class="empty-title">Nothing here</div>
      <div class="empty-sub">No <?= htmlspecialchars($statusFilter) ?> KYC submissions<?= $search ? ' matching "'.htmlspecialchars($search).'"' : '' ?>.</div>
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Merchant</th>
        <th>Submitted</th>
        <?php if ($statusFilter !== 'pending'): ?><th>Reviewed</th><?php endif; ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <div class="td-bold"><?= htmlspecialchars($r['user_name']) ?></div>
          <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['user_email']) ?></div>
        </td>
        <td style="font-size:13px;color:var(--muted)"><?= $r['submitted_at'] ? nigeriaTime($r['submitted_at']) : '—' ?></td>
        <?php if ($statusFilter !== 'pending'): ?>
        <td style="font-size:13px;color:var(--muted)"><?= $r['reviewed_at'] ? nigeriaTime($r['reviewed_at']) : '—' ?></td>
        <?php endif; ?>
        <td style="text-align:right">
          <a href="/admin/kyc-review.php?user_id=<?= $r['user_id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-eye"></i> Review</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= kycTabUrl($statusFilter,$search) ?>&page=<?= $page-1 ?>">‹</a>
  <span class="current"><?= $page ?></span>
  <span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= kycTabUrl($statusFilter,$search) ?>&page=<?= $page+1 ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
