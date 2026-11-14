<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Payment Links';
$activePage = 'payment-links';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$planLimit = linkLimitFor($user['plan']);

function kweekActiveLinkCount(PDO $db, int $uid): int {
    $s = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status='active'");
    $s->execute([$uid]);
    return (int) $s->fetchColumn();
}
function kweekNonArchivedLinkCount(PDO $db, int $uid): int {
    $s = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status IN ('active','paused')");
    $s->execute([$uid]);
    return (int) $s->fetchColumn();
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    $linkId = (int)post('link_id');

    $stmt = $db->prepare("SELECT * FROM payment_links WHERE id=? AND user_id=?");
    $stmt->execute([$linkId, $uid]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($action === 'pause') {
            $db->prepare("UPDATE payment_links SET status='paused' WHERE id=?")->execute([$linkId]);
            flash('success', 'Payment link paused.');

        } elseif ($action === 'activate') {
            if (kweekActiveLinkCount($db, $uid) >= $planLimit) {
                flash('error', "You've reached your plan's limit of $planLimit active link" . ($planLimit === 1 ? '' : 's') . ". Pause or archive another link first, or upgrade your plan.");
            } else {
                $db->prepare("UPDATE payment_links SET status='active' WHERE id=?")->execute([$linkId]);
                flash('success', 'Payment link activated.');
            }

        } elseif ($action === 'archive') {
            $db->prepare("UPDATE payment_links SET status='archived' WHERE id=?")->execute([$linkId]);
            flash('success', 'Payment link archived.');

        } elseif ($action === 'delete') {
            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            $db->prepare("DELETE FROM payment_links WHERE id=? AND user_id=?")->execute([$linkId, $uid]);
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            flash('success', 'Payment link deleted.');

        } elseif ($action === 'duplicate') {
            if (kweekNonArchivedLinkCount($db, $uid) >= $planLimit) {
                flash('error', "You've reached your plan's limit of $planLimit link" . ($planLimit === 1 ? '' : 's') . ". Archive or delete a link, or upgrade to add more.");
            } else {
                $copy = $existing;
                unset($copy['id']);
                $copy['title']        = $copy['title'] . ' (Copy)';
                $copy['slug']         = $copy['slug'] . '-copy-' . strtolower(bin2hex(random_bytes(3)));
                $copy['status']       = 'paused';
                $copy['total_orders']  = 0;
                $copy['total_revenue'] = 0;
                $copy['view_count']    = 0;
                $copy['created_at']    = date('Y-m-d H:i:s');

                $cols         = array_keys($copy);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colList      = implode(',', $cols);
                $db->prepare("INSERT INTO payment_links ($colList) VALUES ($placeholders)")->execute(array_values($copy));
                flash('success', 'Link duplicated — it was created paused so you can review it first.');
            }
        }
    }
    redirect('/payment-links.php');
}

// Filters
$statusFilter = in_array(get('status'), ['active','paused','archived']) ? get('status') : '';
$search       = clean(get('q'));
$sort         = in_array(get('sort'), ['newest','oldest','revenue','orders','views']) ? get('sort') : 'newest';
$page         = max(1, (int)get('page', 1));
$perPage      = 12;
$offset       = ($page - 1) * $perPage;

$where  = ["user_id = ?"];
$params = [$uid];
if ($statusFilter) { $where[] = "status = ?"; $params[] = $statusFilter; }
if ($search)       { $where[] = "(title LIKE ? OR slug LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);

$orderMap = [
    'newest'  => 'created_at DESC',
    'oldest'  => 'created_at ASC',
    'revenue' => 'total_revenue DESC',
    'orders'  => 'total_orders DESC',
    'views'   => 'view_count DESC',
];
$orderSQL = $orderMap[$sort];

$countStmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM payment_links WHERE $whereSQL ORDER BY $orderSQL LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$links = $stmt->fetchAll();

$linkCount  = kweekActiveLinkCount($db, $uid);
$canCreate  = $linkCount < $planLimit;

// ── Render the results (grid + pagination, or empty state) once, into a
// buffer. Used as-is for a normal page load, and returned raw for an
// ajax=1 request so the search box can swap it in without a full reload.
$isAjax = isset($_GET['ajax']);

ob_start();
if (empty($links)):
?>
<div class="card pl-empty">
  <div class="empty-icon" style="margin:0 auto 16px"><i class="ti ti-link"></i></div>
  <div class="empty-title"><?= $search ? 'No links match your search' : 'No payment links yet' ?></div>
  <div class="empty-sub"><?= $search ? 'Try a different search term.' : 'Create your first payment link and share it on WhatsApp to start collecting payments.' ?></div>
  <?php if (!$search && $canCreate): ?>
    <a href="/create-link.php" class="btn btn-purple btn-md" style="margin-top:16px"><i class="ti ti-link"></i> Create your first link</a>
  <?php endif; ?>
</div>
<?php
else:
?>
<div class="pl-grid">
  <?php foreach ($links as $link):
    $statusColors = [
      'active'   => ['success', 'circle-check'],
      'paused'   => ['warning', 'pause'],
      'archived' => ['neutral', 'archive'],
    ];
    [$sc, $si] = $statusColors[$link['status']] ?? ['neutral','dot'];
    $shareUrl  = rtrim(SITE_URL, '/') . '/pay/' . $link['slug'];
    $menuId    = 'menu-' . $link['id'];
    $convRate  = $link['view_count'] > 0 ? round(($link['total_orders'] / $link['view_count']) * 100, 1) : 0;
    $waText    = "Pay for {$link['title']} securely via Kweek: {$shareUrl}";
  ?>
  <div class="pl-card">
    <div class="pl-card-bar <?= $link['status'] ?>"></div>
    <div class="pl-card-body">

      <div class="pl-card-head">
        <div class="pl-card-title-wrap">
          <div class="pl-card-title" title="<?= htmlspecialchars($link['title']) ?>"><?= htmlspecialchars($link['title']) ?></div>
          <div class="pl-card-slug" title="<?= htmlspecialchars(SITE_URL . '/pay/' . $link['slug']) ?>">/pay/<?= htmlspecialchars(mb_strlen($link['slug']) > 22 ? mb_substr($link['slug'], 0, 22) . '…' : $link['slug']) ?></div>
        </div>
        <span class="badge badge-<?= $sc ?>" style="flex-shrink:0"><i class="ti ti-<?= $si ?>"></i><?= ucfirst($link['status']) ?></span>
      </div>

      <div class="pl-stats">
        <div class="pl-stat"><div class="pl-stat-val"><?= $link['total_orders'] ?></div><div class="pl-stat-label">Orders</div></div>
        <div class="pl-stat"><div class="pl-stat-val sm"><?= formatNaira($link['total_revenue']) ?></div><div class="pl-stat-label">Revenue</div></div>
        <div class="pl-stat"><div class="pl-stat-val"><?= $link['view_count'] ?></div><div class="pl-stat-label">Views</div></div>
        <div class="pl-stat"><div class="pl-stat-val"><?= $convRate ?>%</div><div class="pl-stat-label">Conv.</div></div>
      </div>

      <div class="pl-actions">
        <button onclick="copyLink('<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>')" class="btn btn-outline btn-sm">
          <i class="ti ti-copy"></i> <span class="btn-label">Copy</span>
        </button>
        <a href="<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" title="View payment page">
          <i class="ti ti-external-link"></i> <span class="btn-label">View</span>
        </a>
        <a href="https://wa.me/?text=<?= urlencode($waText) ?>" target="_blank" rel="noopener" class="btn btn-wa btn-sm" title="Share on WhatsApp">
          <i class="ti ti-brand-whatsapp"></i>
        </a>
        <button onclick="openQr('<?= htmlspecialchars($shareUrl, ENT_QUOTES) ?>','<?= htmlspecialchars($link['title'], ENT_QUOTES) ?>')" class="btn btn-outline btn-sm" title="QR code" style="flex:none;padding:7px 10px">
          <i class="ti ti-qrcode"></i>
        </button>
        <a href="/edit-link.php?id=<?= $link['id'] ?>" class="btn btn-outline btn-sm">
          <i class="ti ti-edit"></i> <span class="btn-label">Edit</span>
        </a>
        <div class="pl-more-wrap">
          <button class="btn btn-outline btn-sm pl-more-btn" onclick="toggleMenu(event, '<?= $menuId ?>')" aria-haspopup="true" aria-expanded="false">
            <i class="ti ti-dots"></i>
          </button>
        </div>
      </div>

    </div>
  </div>

  <div id="<?= $menuId ?>" class="pl-dropdown" data-link-id="<?= $link['id'] ?>">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
      <?php if ($link['status'] === 'active'): ?>
        <button type="submit" name="action" value="pause" class="dropdown-item"><i class="ti ti-pause"></i> Pause link</button>
      <?php elseif ($link['status'] === 'paused'): ?>
        <button type="submit" name="action" value="activate" class="dropdown-item"><i class="ti ti-play"></i> Activate link</button>
      <?php endif; ?>
      <button type="submit" name="action" value="duplicate" class="dropdown-item"><i class="ti ti-copy"></i> Duplicate</button>
      <button type="submit" name="action" value="archive" class="dropdown-item"><i class="ti ti-archive"></i> Archive</button>
      <div style="height:1px;background:var(--line);margin:4px 0"></div>
      <button type="submit" name="action" value="delete" class="dropdown-item danger" onclick="return confirm('Delete this payment link? This cannot be undone.')">
        <i class="ti ti-trash"></i> Delete
      </button>
    </form>
  </div>

  <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pl-pagination">
  <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
  <div class="pag-btns">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <a href="?page=<?= $i ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
endif;
$resultsHtml = ob_get_clean();

if ($isAjax) {
    header('Content-Type: text/html; charset=utf-8');
    echo $resultsHtml;
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<style>
.pl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.pl-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px;margin:0}
.pl-header p{font-size:14px;color:var(--muted);margin:2px 0 0}

.pl-filters{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.pl-search-wrap{position:relative;flex:1;min-width:200px}
.pl-search-wrap i.ti-search{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted-2);font-size:16px;pointer-events:none}
.pl-search{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--line);border-radius:var(--r-md);padding:10px 14px 10px 36px;outline:none;background:var(--paper);transition:border-color .2s}
.pl-search:focus{border-color:var(--purple-500)}
.pl-sort{font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--ink);border:1.5px solid var(--line);border-radius:var(--r-md);padding:9px 30px 9px 12px;background:var(--paper);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23726E85' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center}
.pl-sort:focus{border-color:var(--purple-500)}
.pl-status-tabs{display:flex;gap:6px;flex-wrap:wrap}
.pl-tab{padding:8px 16px;border-radius:9999px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid var(--line);background:var(--paper);color:var(--muted);transition:all .2s}
.pl-tab.active,.pl-tab:hover{border-color:var(--purple-600);background:var(--purple-600);color:#fff}

.pl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:20px}
@media(max-width:500px){.pl-grid{grid-template-columns:1fr}}

.pl-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);display:flex;flex-direction:column;transition:border-color .2s,transform .2s,box-shadow .2s}
.pl-card:hover{border-color:var(--purple-100);transform:translateY(-2px);box-shadow:0 6px 20px rgba(20,15,40,.07)}
.pl-card-bar{height:8px;border-radius:calc(var(--r-lg) - 1.5px) calc(var(--r-lg) - 1.5px) 0 0;flex-shrink:0}
.pl-card-bar.active{background:var(--purple-600)}
.pl-card-bar.paused{background:var(--warning)}
.pl-card-bar.archived{background:var(--line)}
.pl-card-body{padding:20px;display:flex;flex-direction:column;gap:0;flex:1}

.pl-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:12px}
.pl-card-title-wrap{flex:1;min-width:0}
.pl-card-title{font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--ink);margin-bottom:3px;overflow-wrap:anywhere;word-break:break-word;line-height:1.35}
.pl-card-slug{font-size:12px;color:var(--muted);font-family:var(--font-mono);overflow-wrap:anywhere;word-break:break-word;white-space:normal;line-height:1.45;max-width:100%}

.pl-stats{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:6px;margin-bottom:16px}
.pl-stat{text-align:center;padding:10px 4px;background:var(--surface);border-radius:var(--r-md)}
.pl-stat-val{font-family:var(--font-mono);font-size:14px;font-weight:700;color:var(--ink);overflow-wrap:anywhere;word-break:break-word}
.pl-stat-val.sm{font-size:12px}
.pl-stat-label{font-size:9.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-top:1px}

.pl-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.pl-actions .btn{flex:1 1 auto;min-width:64px;white-space:nowrap;justify-content:center}
.pl-actions .btn-wa{background:#25D366;color:#fff;flex:none!important;padding:7px 10px}
.pl-actions .btn-wa:hover{background:#1EBE57}
.pl-more-wrap{position:relative;flex-shrink:0}
.pl-more-btn{padding:7px 10px!important;flex:none!important}

.pl-dropdown{display:none;position:fixed;background:var(--paper);border:1px solid var(--line);border-radius:var(--r-lg);padding:6px;min-width:170px;z-index:9999;box-shadow:0 8px 28px rgba(0,0,0,.12)}
.pl-dropdown.open{display:block}
.dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:9px 12px;font-family:var(--font-body);font-size:13px;font-weight:500;color:var(--ink);background:none;border:none;cursor:pointer;border-radius:var(--r-sm);transition:background .15s;text-align:left;white-space:nowrap}
.dropdown-item:hover{background:var(--surface)}
.dropdown-item.danger{color:var(--danger)}
.dropdown-item.danger:hover{background:var(--danger-bg)}

.pl-pagination{background:var(--paper);border:1px solid var(--line);border-radius:var(--r-lg);margin-top:20px;display:flex;align-items:center;justify-content:space-between;padding:10px 16px;flex-wrap:wrap;gap:8px}
.pag-info{font-size:13px;color:var(--muted)}
.pag-btns{display:flex;gap:4px}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:var(--r-md);font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;border:1.5px solid var(--line);transition:all .15s}
.pag-btn:hover{border-color:var(--purple-500);color:var(--purple-600)}
.pag-btn.active{background:var(--purple-600);border-color:var(--purple-600);color:#fff}

.pl-empty{text-align:center;padding:60px 20px}

.pl-loading{opacity:.5;pointer-events:none;transition:opacity .15s}

.qr-modal-backdrop{position:fixed;inset:0;background:rgba(15,14,28,.55);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px}
.qr-modal-backdrop.open{display:flex}
.qr-modal{background:var(--paper);border-radius:var(--r-lg);padding:28px;width:100%;max-width:320px;text-align:center}
.qr-modal h4{font-family:var(--font-display);font-size:16px;font-weight:700;margin-bottom:4px;color:var(--ink)}
.qr-modal p{font-size:12px;color:var(--muted);margin-bottom:18px;word-break:break-all}
#qr-canvas-wrap{display:flex;align-items:center;justify-content:center;padding:16px;background:#fff;border-radius:var(--r-md);border:1px solid var(--line);margin-bottom:16px;min-height:200px}
#qr-canvas-wrap canvas{border-radius:4px}
.qr-modal-actions{display:flex;gap:8px}
.qr-modal-actions .btn{flex:1;justify-content:center}

.qr-loading{display:flex;align-items:center;justify-content:center;width:200px;height:200px}
.qr-spinner{width:34px;height:34px;border:3px solid var(--line);border-top-color:var(--purple-600);border-radius:50%;animation:qr-spin .7s linear infinite}
@keyframes qr-spin{to{transform:rotate(360deg)}}
</style>

<div class="pl-header">
  <div>
    <h1 id="pl-count-heading"><?= $total ?> Payment Link<?= $total!==1?'s':'' ?></h1>
    <p>Manage all your payment collection links</p>
  </div>
  <?php if ($canCreate): ?>
    <a href="/create-link.php" class="btn btn-purple btn-md"><i class="ti ti-plus"></i> New Link</a>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:10px;background:var(--purple-50);border:1px solid var(--purple-100);border-radius:var(--r-lg);padding:10px 16px;font-size:13px;color:var(--purple-600)">
      <i class="ti ti-lock" style="font-size:16px"></i>
      Free plan limit reached.
      <a href="/settings.php?tab=billing" style="color:var(--purple-600);font-weight:700;margin-left:4px">Upgrade →</a>
    </div>
  <?php endif; ?>
</div>

<div class="pl-filters">
  <div class="pl-search-wrap">
    <i class="ti ti-search"></i>
    <input type="text" id="search-input" class="pl-search"
      placeholder="Search links…" value="<?= htmlspecialchars($search) ?>"
      oninput="liveSearch(this.value)">
  </div>
  <select class="pl-sort" onchange="changeSort(this.value)">
    <option value="newest"  <?= $sort==='newest'?'selected':'' ?>>Newest first</option>
    <option value="oldest"  <?= $sort==='oldest'?'selected':'' ?>>Oldest first</option>
    <option value="revenue" <?= $sort==='revenue'?'selected':'' ?>>Highest revenue</option>
    <option value="orders"  <?= $sort==='orders'?'selected':'' ?>>Most orders</option>
    <option value="views"   <?= $sort==='views'?'selected':'' ?>>Most views</option>
  </select>
  <div class="pl-status-tabs">
    <?php foreach ([''=>'All','active'=>'Active','paused'=>'Paused','archived'=>'Archived'] as $val=>$label): ?>
      <a href="?status=<?= $val ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>"
         class="pl-tab<?= $statusFilter===$val?' active':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div id="pl-results"><?= $resultsHtml ?></div>

<div class="qr-modal-backdrop" id="qr-modal" onclick="if(event.target===this) closeQr()">
  <div class="qr-modal">
    <h4 id="qr-title">Scan to pay</h4>
    <p id="qr-url"></p>
    <div id="qr-canvas-wrap"></div>
    <div class="qr-modal-actions">
      <button class="btn btn-outline btn-md" onclick="closeQr()">Close</button>
      <button class="btn btn-purple btn-md" onclick="downloadQr()"><i class="ti ti-download"></i> Download</button>
    </div>
  </div>
</div>

<script>
function reparentDropdowns() {
  document.querySelectorAll('.pl-dropdown').forEach(el => document.body.appendChild(el));
}
reparentDropdowns();

let openMenu = null;
window.toggleMenu = function (event, menuId) {
  event.stopPropagation();
  const btn  = event.currentTarget;
  const menu = document.getElementById(menuId);
  if (!menu) return;
  if (openMenu && openMenu !== menu) closeMenu(openMenu);
  if (menu.classList.contains('open')) { closeMenu(menu); return; }

  menu.style.visibility = 'hidden';
  menu.style.display    = 'block';
  const menuW = menu.offsetWidth, menuH = menu.offsetHeight;
  menu.style.display    = '';
  menu.style.visibility = '';

  const rect = btn.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight, gap = 6;
  let top = rect.bottom + gap;
  if (top + menuH > vh - 8) top = rect.top - menuH - gap;
  if (top < 8) top = 8;
  let left = rect.right - menuW;
  if (left < 8) left = 8;
  if (left + menuW > vw - 8) left = vw - menuW - 8;

  menu.style.position = 'fixed';
  menu.style.top  = top + 'px';
  menu.style.left = left + 'px';
  menu.classList.add('open');
  btn.setAttribute('aria-expanded', 'true');
  openMenu = menu; openMenu._btn = btn;
};

function closeMenu(menu) {
  if (!menu) return;
  menu.classList.remove('open');
  if (menu._btn) menu._btn.setAttribute('aria-expanded', 'false');
  openMenu = null;
}
document.addEventListener('click',  () => closeMenu(openMenu));
document.addEventListener('scroll', () => closeMenu(openMenu), true);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(openMenu); });

function copyLink(url) {
  navigator.clipboard.writeText(url)
    .then(() => showToast('success', 'Copied!', 'Payment link copied to clipboard'));
}

// ── LIVE SEARCH — filters in place as you type, no page reload ────────
let searchTimer;
function liveSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => runLiveSearch(val), 300);
}

function runLiveSearch(val) {
  const url = new URL(window.location);
  url.searchParams.set('q', val);
  url.searchParams.set('page', 1);
  window.history.replaceState({}, '', url);

  const fetchUrl = new URL(url);
  fetchUrl.searchParams.set('ajax', '1');

  const results = document.getElementById('pl-results');
  results.classList.add('pl-loading');

  fetch(fetchUrl)
    .then(r => r.text())
    .then(html => {
      results.innerHTML = html;
      results.classList.remove('pl-loading');
      reparentDropdowns();
    })
    .catch(() => {
      results.classList.remove('pl-loading');
      showToast('error', 'Search failed', 'Could not load results.');
    });
}

function changeSort(val) {
  const url = new URL(window.location);
  url.searchParams.set('sort', val);
  url.searchParams.set('page', 1);
  window.location = url;
}

/* ── QR modal ─────────────────────────────────────────────────────── */
let currentQrCanvas = null;
let currentQrPlainUrl = '';

function openQr(url, title) {
  document.getElementById('qr-title').textContent = title;
  document.getElementById('qr-url').textContent = url;
  currentQrPlainUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=8&data=' + encodeURIComponent(url);

  const wrap = document.getElementById('qr-canvas-wrap');
  wrap.innerHTML = '<div class="qr-loading"><div class="qr-spinner"></div></div>';
  currentQrCanvas = null;
  document.getElementById('qr-modal').classList.add('open');

  const qrImg = new Image();
  qrImg.crossOrigin = 'anonymous';
  qrImg.onload = function () {
    const size = 240;
    const canvas = document.createElement('canvas');
    canvas.width = size; canvas.height = size;
    canvas.style.width = '200px'; canvas.style.height = '200px';
    const ctx = canvas.getContext('2d');
    ctx.drawImage(qrImg, 0, 0, size, size);
    wrap.innerHTML = '';
    wrap.appendChild(canvas);
    currentQrCanvas = canvas;
  };
  qrImg.onerror = function () {
    wrap.innerHTML = '<p style="font-size:12px;color:var(--danger)">Couldn\'t load the QR code. Try again.</p>';
  };
  qrImg.src = currentQrPlainUrl;
}

function closeQr() {
  document.getElementById('qr-modal').classList.remove('open');
}

function downloadQr() {
  const link = document.createElement('a');
  link.download = 'kweek-payment-qr.png';
  if (currentQrCanvas) {
    link.href = currentQrCanvas.toDataURL('image/png');
    link.click();
  } else if (currentQrPlainUrl) {
    fetch(currentQrPlainUrl)
      .then(r => r.blob())
      .then(blob => {
        link.href = URL.createObjectURL(blob);
        link.click();
        URL.revokeObjectURL(link.href);
      })
      .catch(() => showToast('error', 'Download failed', 'Could not download the QR code — try again.'));
  }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>