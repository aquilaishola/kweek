<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapabilityAny(['manage_links', 'view_links']);

$admin      = currentAdmin();
$canManage  = adminCan($admin, 'manage_links');
$pageTitle  = 'Payment Links';
$activePage = 'links';
$db         = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    verifyCsrf();
    if (post('action') === 'toggle_status') {
        $id = (int) post('link_id');
        $stmt = $db->prepare("SELECT status, user_id, title FROM payment_links WHERE id = ?");
        $stmt->execute([$id]);
        $link = $stmt->fetch();
        if ($link) {
            $newStatus = $link['status'] === 'active' ? 'paused' : 'active';
            $db->prepare("UPDATE payment_links SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            notify((int)$link['user_id'], 'link_status', 'Payment Link ' . ($newStatus === 'paused' ? 'Paused' : 'Reactivated'), '"' . $link['title'] . '" was ' . ($newStatus === 'paused' ? 'paused' : 'reactivated') . ' by an admin.', 'link', '/payment-links.php');
            flash('success', 'Link ' . ($newStatus === 'paused' ? 'paused' : 'reactivated') . '.');
        }
        redirect('/admin/links.php?' . http_build_query(array_filter(['status' => get('status'), 'q' => get('q')])));
    }
}

// ── stats ──────────────────────────────────────────────────────
$totalLinks   = (int) $db->query("SELECT COUNT(*) FROM payment_links")->fetchColumn();
$activeLinks  = (int) $db->query("SELECT COUNT(*) FROM payment_links WHERE status = 'active'")->fetchColumn();
$pausedLinks  = (int) $db->query("SELECT COUNT(*) FROM payment_links WHERE status = 'paused'")->fetchColumn();
$newLinksToday = (int) $db->query("SELECT COUNT(*) FROM payment_links WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalRevenue = (float) $db->query("SELECT COALESCE(SUM(total_revenue),0) FROM payment_links")->fetchColumn();

// ── list ───────────────────────────────────────────────────────
$statusFilter = in_array(get('status'), ['active','paused','archived']) ? get('status') : '';
$search = trim((string) get('q'));

$where = ['1=1']; $params = [];
if ($statusFilter !== '') { $where[] = 'pl.status = ?'; $params[] = $statusFilter; }
if ($search !== '') { $where[] = '(pl.title LIKE ? OR pl.slug LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM payment_links pl JOIN users u ON u.id = pl.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT pl.*, u.name AS user_name, u.email AS user_email
    FROM payment_links pl JOIN users u ON u.id = pl.user_id
    WHERE $whereSql ORDER BY pl.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function linksUrl(array $overrides = []): string {
    $q = array_filter(array_merge(['status'=>get('status'),'q'=>get('q')], $overrides), fn($v)=>$v!=='' && $v!==null);
    return '/admin/links.php?' . http_build_query($q);
}

include __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-link"></i> Total Links</div><div class="stat-card-value"><?= number_format($totalLinks) ?></div><div class="stat-card-sub">+<?= $newLinksToday ?> today</div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-circle-check"></i> Active</div><div class="stat-card-value"><?= number_format($activeLinks) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-player-pause"></i> Paused</div><div class="stat-card-value"><?= number_format($pausedLinks) ?></div></div>
  <div class="stat-card"><div class="stat-card-label"><i class="ti ti-trending-up"></i> Total Revenue</div><div class="stat-card-value"><?= formatNaira($totalRevenue) ?></div><div class="stat-card-sub">Across all links, all time</div></div>
</div>

<div class="filter-bar">
  <a href="<?= linksUrl(['status'=>'']) ?>" class="filter-tab <?= $statusFilter===''?'active':'' ?>">All</a>
  <a href="<?= linksUrl(['status'=>'active']) ?>" class="filter-tab <?= $statusFilter==='active'?'active':'' ?>">Active</a>
  <a href="<?= linksUrl(['status'=>'paused']) ?>" class="filter-tab <?= $statusFilter==='paused'?'active':'' ?>">Paused</a>
  <a href="<?= linksUrl(['status'=>'archived']) ?>" class="filter-tab <?= $statusFilter==='archived'?'active':'' ?>">Archived</a>
  <form method="GET" class="search-input-wrap">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
    <i class="ti ti-search"></i>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search title, slug, merchant...">
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($rows)): ?>
  <div class="empty-state"><div class="empty-title">No payment links found</div></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Link</th><th>Merchant</th><th>Orders</th><th>Revenue</th><th>Views</th><th>Status</th><th>Created</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): ?>
    <tr>
      <td>
        <div class="td-bold"><?= htmlspecialchars($l['title']) ?></div>
        <div style="font-size:12px;color:var(--muted)">/<?= htmlspecialchars($l['slug']) ?> · <?= htmlspecialchars(ucfirst($l['link_type'])) ?></div>
      </td>
      <td><a href="/admin/user-detail.php?id=<?= $l['user_id'] ?>" style="color:var(--ink);text-decoration:none;font-size:13px;font-weight:600"><?= htmlspecialchars($l['user_name']) ?></a></td>
      <td class="td-bold"><?= number_format($l['total_orders']) ?></td>
      <td class="td-bold"><?= formatNaira((float)$l['total_revenue']) ?></td>
      <td style="color:var(--muted)"><?= number_format($l['view_count']) ?></td>
      <td><span class="badge badge-<?= $l['status']==='active'?'success':($l['status']==='archived'?'neutral':'warning') ?>"><?= ucfirst($l['status']) ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($l['created_at']) ?></td>
      <?php if ($canManage): ?>
      <td>
        <?php if ($l['status'] !== 'archived'): ?>
        <form method="POST" onsubmit="return confirm('<?= $l['status']==='active'?'Pause':'Reactivate' ?> this link?')">
          <?= csrfField() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="link_id" value="<?= $l['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm"><?= $l['status']==='active'?'Pause':'Reactivate' ?></button>
        </form>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= linksUrl(['page'=>$page-1]) ?>">‹</a>
  <span class="current"><?= $page ?></span><span>of <?= $totalPages ?></span>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= linksUrl(['page'=>$page+1]) ?>">›</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
