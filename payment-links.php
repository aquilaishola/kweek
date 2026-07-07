<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Payment Links';
$activePage = 'links';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    $linkId = (int)post('link_id');

    $stmt = $db->prepare("SELECT id FROM payment_links WHERE id=? AND user_id=?");
    $stmt->execute([$linkId, $uid]);
    if ($stmt->fetch()) {
        if ($action === 'pause') {
            $db->prepare("UPDATE payment_links SET status='paused' WHERE id=?")->execute([$linkId]);
            flash('success', 'Payment link paused.');
        } elseif ($action === 'activate') {
            $db->prepare("UPDATE payment_links SET status='active' WHERE id=?")->execute([$linkId]);
            flash('success', 'Payment link activated.');
        } elseif ($action === 'archive') {
            $db->prepare("UPDATE payment_links SET status='archived' WHERE id=?")->execute([$linkId]);
            flash('success', 'Payment link archived.');
        } elseif ($action === 'delete') {
            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            $db->prepare("DELETE FROM payment_links WHERE id=? AND user_id=?")->execute([$linkId, $uid]);
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            flash('success', 'Payment link deleted.');
        }
    }
    redirect('/payment-links.php');
}

// Filters
$statusFilter = in_array(get('status'), ['active','paused','archived']) ? get('status') : '';
$search       = clean(get('q'));
$page         = max(1, (int)get('page', 1));
$perPage      = 12;
$offset       = ($page - 1) * $perPage;

$where  = ["user_id = ?"];
$params = [$uid];
if ($statusFilter) { $where[] = "status = ?"; $params[] = $statusFilter; }
if ($search)       { $where[] = "(title LIKE ? OR slug LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM payment_links WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$links = $stmt->fetchAll();

// Plan check — free = 1 link max
$planLimit  = $user['plan'] === 'free' ? 1 : PHP_INT_MAX;
$cntS       = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=?");
$cntS->execute([$uid]);
$linkCount  = (int)$cntS->fetchColumn();
$canCreate  = $linkCount < $planLimit;

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── page layout ─────────────────────────────────── */
.pl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.pl-header h1{font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px;margin:0}
.pl-header p{font-size:14px;color:var(--n500);margin:2px 0 0}

/* ── filters ─────────────────────────────────────── */
.pl-filters{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.pl-search-wrap{position:relative;flex:1;min-width:200px}
.pl-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:16px;pointer-events:none}
.pl-search{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:10px 14px 10px 36px;outline:none;background:var(--n0);transition:border-color .2s}
.pl-search:focus{border-color:var(--p400)}
.pl-status-tabs{display:flex;gap:6px;flex-wrap:wrap}
.pl-tab{padding:8px 16px;border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid var(--n200);background:var(--n0);color:var(--n600);transition:all .2s}
.pl-tab.active,.pl-tab:hover{border-color:var(--p600);background:var(--p600);color:#fff}

/* ── grid ────────────────────────────────────────── */
.pl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:20px}
@media(max-width:500px){.pl-grid{grid-template-columns:1fr}}

/* ── card ────────────────────────────────────────── */
.pl-card{
  background:var(--n0);
  border:1.5px solid var(--n200);
  border-radius:var(--r-lg);
  display:flex;
  flex-direction:column;
  transition:border-color .2s,transform .2s,box-shadow .2s;
}
.pl-card:hover{border-color:var(--p200);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.07)}
.pl-card-bar{height:8px;border-radius:calc(var(--r-lg) - 1.5px) calc(var(--r-lg) - 1.5px) 0 0;flex-shrink:0}
.pl-card-bar.active{background:var(--p600)}
.pl-card-bar.paused{background:var(--warning)}
.pl-card-bar.archived{background:var(--n300)}
.pl-card-body{padding:20px;display:flex;flex-direction:column;gap:0;flex:1}

/* card title row */
.pl-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:12px}
.pl-card-title-wrap{flex:1;min-width:0}
.pl-card-title{font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--n900);margin-bottom:3px;overflow-wrap:anywhere;word-break:break-word;line-height:1.35}
.pl-card-slug{
    font-size:12px;
    color:var(--n500);
    font-family:monospace;
    overflow-wrap:anywhere;
    word-break:break-word;
    white-space:normal;
    line-height:1.45;
    max-width:100%;
}

/* stats */
.pl-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px}
.pl-stat{text-align:center;padding:10px 6px;background:var(--n50);border-radius:var(--r-md)}
.pl-stat-val{font-family:var(--font-display);font-size:16px;font-weight:800;color:var(--n900);overflow-wrap:anywhere;word-break:break-word}
.pl-stat-val.sm{font-size:13px}
.pl-stat-label{font-size:10px;color:var(--n500);text-transform:uppercase;letter-spacing:.5px;margin-top:1px}

/* actions row */
.pl-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.pl-actions .btn{flex:1 1 auto;min-width:64px;white-space:nowrap;justify-content:center}
.pl-more-wrap{position:relative;flex-shrink:0}
.pl-more-btn{padding:7px 10px!important;flex:none!important}

/* ── portal dropdown (fixed, appended to body) ───── */
.pl-dropdown{
  display:none;
  position:fixed; /* positions via JS */
  background:var(--n0);
  border:1px solid var(--n200);
  border-radius:var(--r-lg);
  padding:6px;
  min-width:170px;
  z-index:9999;
  box-shadow:0 8px 28px rgba(0,0,0,.12);
}
.pl-dropdown.open{display:block}
.dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:9px 12px;font-family:var(--font-body);font-size:13px;font-weight:500;color:var(--n700);background:none;border:none;cursor:pointer;border-radius:var(--r-sm);transition:background .15s;text-align:left;white-space:nowrap}
.dropdown-item:hover{background:var(--n50)}
.dropdown-item.danger{color:var(--danger)}
.dropdown-item.danger:hover{background:#fff1f1}

/* ── pagination ──────────────────────────────────── */
.pl-pagination{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);margin-top:20px;display:flex;align-items:center;justify-content:space-between;padding:10px 16px;flex-wrap:wrap;gap:8px}
.pag-info{font-size:13px;color:var(--n500)}
.pag-btns{display:flex;gap:4px}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:var(--r-md);font-size:13px;font-weight:600;color:var(--n600);text-decoration:none;border:1.5px solid var(--n200);transition:all .15s}
.pag-btn:hover{border-color:var(--p400);color:var(--p600)}
.pag-btn.active{background:var(--p600);border-color:var(--p600);color:#fff}

/* ── empty state ─────────────────────────────────── */
.pl-empty{text-align:center;padding:60px 20px}
</style>

<!-- PAGE HEADER -->
<div class="pl-header">
  <div>
    <h1><?= $total ?> Payment Link<?= $total!==1?'s':'' ?></h1>
    <p>Manage all your payment collection links</p>
  </div>
  <?php if ($canCreate): ?>
    <a href="/create-link.php" class="btn btn-purple btn-md"><i class="ti ti-plus"></i> New Link</a>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:10px;background:var(--p50);border:1px solid var(--p100);border-radius:var(--r-lg);padding:10px 16px;font-size:13px;color:var(--p800)">
      <i class="ti ti-lock" style="font-size:16px;color:var(--p600)"></i>
      Free plan limit reached.
      <a href="/settings.php?tab=billing" style="color:var(--p600);font-weight:700;margin-left:4px">Upgrade →</a>
    </div>
  <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="pl-filters">
  <div class="pl-search-wrap">
    <i class="ti ti-search"></i>
    <input type="text" id="search-input" class="pl-search"
      placeholder="Search links…" value="<?= htmlspecialchars($search) ?>"
      oninput="debounceSearch(this.value)">
  </div>
  <div class="pl-status-tabs">
    <?php foreach ([''=>'All','active'=>'Active','paused'=>'Paused','archived'=>'Archived'] as $val=>$label): ?>
      <a href="?status=<?= $val ?>&q=<?= urlencode($search) ?>"
         class="pl-tab<?= $statusFilter===$val?' active':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- LINKS GRID -->
<?php if (empty($links)): ?>
<div class="card pl-empty">
  <div class="empty-icon" style="margin:0 auto 16px"><i class="ti ti-link"></i></div>
  <div class="empty-title"><?= $search ? 'No links match your search' : 'No payment links yet' ?></div>
  <div class="empty-sub"><?= $search ? 'Try a different search term.' : 'Create your first payment link and share it on WhatsApp to start collecting payments.' ?></div>
  <?php if (!$search && $canCreate): ?>
    <a href="/create-link.php" class="btn btn-purple btn-md" style="margin-top:16px"><i class="ti ti-link"></i> Create your first link</a>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="pl-grid">
  <?php foreach ($links as $link):
    $statusColors = [
      'active'   => ['success', 'circle-check'],
      'paused'   => ['warning', 'pause'],
      'archived' => ['neutral', 'archive'],
    ];
    [$sc, $si] = $statusColors[$link['status']] ?? ['neutral','dot'];
   $shareUrl = SITE_URL . '/pay/' . $link['slug'];
    $menuId   = 'menu-' . $link['id'];
  ?>
  <div class="pl-card">
    <div class="pl-card-bar <?= $link['status'] ?>"></div>
    <div class="pl-card-body">

      <!-- Title + badge -->
      <div class="pl-card-head">
        <div class="pl-card-title-wrap">
        <div class="pl-card-title" title="<?= htmlspecialchars($link['title']) ?>"><?= htmlspecialchars($link['title']) ?></div>
<div class="pl-card-slug" title="<?= htmlspecialchars(SITE_URL . '/pay/' . $link['slug']) ?>">/pay/<?= htmlspecialchars(mb_strlen($link['slug']) > 22 ? mb_substr($link['slug'], 0, 22) . '…' : $link['slug']) ?></div>
        </div>
        <span class="badge badge-<?= $sc ?>" style="flex-shrink:0"><i class="ti ti-<?= $si ?>"></i><?= ucfirst($link['status']) ?></span>
      </div>

      <!-- Stats -->
      <div class="pl-stats">
        <div class="pl-stat">
          <div class="pl-stat-val"><?= $link['total_orders'] ?></div>
          <div class="pl-stat-label">Orders</div>
        </div>
        <div class="pl-stat">
          <div class="pl-stat-val sm"><?= formatNaira($link['total_revenue']) ?></div>
          <div class="pl-stat-label">Revenue</div>
        </div>
        <div class="pl-stat">
          <div class="pl-stat-val"><?= $link['view_count'] ?></div>
          <div class="pl-stat-label">Views</div>
        </div>
      </div>

      <!-- Actions -->
      <div class="pl-actions">
       <button onclick="copyLink('<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>')" class="btn btn-outline btn-sm">
  <i class="ti ti-copy"></i> <span class="btn-label">Copy</span>
</button>
<a href="/edit-link.php?id=<?= $link['id'] ?>" class="btn btn-outline btn-sm">
  <i class="ti ti-edit"></i> <span class="btn-label">Edit</span>
</a>
        <a href="<?= htmlspecialchars($shareUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="flex:none;padding:7px 10px">
          <i class="ti ti-external-link"></i>
        </a>
        <!-- three-dot trigger -->
        <div class="pl-more-wrap">
          <button class="btn btn-outline btn-sm pl-more-btn"
            onclick="toggleMenu(event, '<?= $menuId ?>')"
            aria-haspopup="true" aria-expanded="false">
            <i class="ti ti-dots"></i>
          </button>
        </div>
      </div>

    </div><!-- /card-body -->
  </div><!-- /card -->

  <!-- Dropdown portal: rendered here, repositioned to body via JS -->
  <div id="<?= $menuId ?>" class="pl-dropdown" data-link-id="<?= $link['id'] ?>">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
      <?php if ($link['status'] === 'active'): ?>
        <button type="submit" name="action" value="pause" class="dropdown-item">
          <i class="ti ti-pause"></i> Pause link
        </button>
      <?php elseif ($link['status'] === 'paused'): ?>
        <button type="submit" name="action" value="activate" class="dropdown-item">
          <i class="ti ti-play"></i> Activate link
        </button>
      <?php endif; ?>
      <button type="submit" name="action" value="archive" class="dropdown-item">
        <i class="ti ti-archive"></i> Archive
      </button>
      <div style="height:1px;background:var(--n200);margin:4px 0"></div>
      <button type="submit" name="action" value="delete" class="dropdown-item danger"
        onclick="return confirm('Delete this payment link? This cannot be undone.')">
        <i class="ti ti-trash"></i> Delete
      </button>
    </form>
  </div>

  <?php endforeach; ?>
</div><!-- /grid -->

<!-- PAGINATION -->
<?php if ($totalPages > 1): ?>
<div class="pl-pagination">
  <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
  <div class="pag-btns">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <a href="?page=<?= $i ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
/* ── Portal dropdown ─────────────────────────────────────────────────
   Move every .pl-dropdown into <body> so it isn't clipped by any
   ancestor's overflow or stacking context. Position it via JS on open.
─────────────────────────────────────────────────────────────────────── */
(function () {
  // move all menus to body on load
  document.querySelectorAll('.pl-dropdown').forEach(el => document.body.appendChild(el));

  let openMenu = null;

  window.toggleMenu = function (event, menuId) {
    event.stopPropagation();
    const btn  = event.currentTarget;
    const menu = document.getElementById(menuId);
    if (!menu) return;

    if (openMenu && openMenu !== menu) closeMenu(openMenu);
    if (menu.classList.contains('open')) { closeMenu(menu); return; }

    // measure real menu size before showing
    menu.style.visibility = 'hidden';
    menu.style.display    = 'block';
    const menuW = menu.offsetWidth;
    const menuH = menu.offsetHeight;
    menu.style.display    = '';
    menu.style.visibility = '';

    const rect = btn.getBoundingClientRect();
    const vw   = window.innerWidth;
    const vh   = window.innerHeight;
    const gap  = 6;

    // prefer below button, flip above if not enough room
    let top = rect.bottom + gap;
    if (top + menuH > vh - 8) top = rect.top - menuH - gap;
    if (top < 8) top = 8;

    // right-align to button, shift left if it overflows
    let left = rect.right - menuW;
    if (left < 8) left = 8;
    if (left + menuW > vw - 8) left = vw - menuW - 8;

    menu.style.position = 'fixed';
    menu.style.top      = top  + 'px';
    menu.style.left     = left + 'px';
    menu.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
    openMenu      = menu;
    openMenu._btn = btn;
  };

  function closeMenu(menu) {
    if (!menu) return;
    menu.classList.remove('open');
    if (menu._btn) menu._btn.setAttribute('aria-expanded', 'false');
    openMenu = null;
  }

  // close on outside click or scroll
  document.addEventListener('click',  () => closeMenu(openMenu));
  document.addEventListener('scroll', () => closeMenu(openMenu), true);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(openMenu); });
})();

/* ── Copy link ──────────────────────────────────────────────────────── */
function copyLink(url) {
  navigator.clipboard.writeText(url)
    .then(() => showToast('success', 'Copied!', 'Payment link copied to clipboard'));
}

/* ── Search debounce ────────────────────────────────────────────────── */
let searchTimer;
function debounceSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const url = new URL(window.location);
    url.searchParams.set('q', val);
    url.searchParams.set('page', 1);
    window.location = url;
  }, 500);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>