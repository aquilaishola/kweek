<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Notifications';
$activePage = 'notifications';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// ── ACTIONS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'mark_read') {
        $notifId = (int)post('notif_id');
        if ($notifId > 0) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notifId, $uid]);
        }
        if (isAjax()) { jsonResponse(['ok' => true]); }
        redirect('/notifications.php');
    }

    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
        flash('success', 'All notifications marked as read.');
        redirect('/notifications.php');
    }

    if ($action === 'delete') {
        $notifId = (int)post('notif_id');
        if ($notifId > 0) {
            $db->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$notifId, $uid]);
        }
        if (isAjax()) { jsonResponse(['ok' => true]); }
        redirect('/notifications.php');
    }

    if ($action === 'delete_all_read') {
        $db->prepare("DELETE FROM notifications WHERE user_id=? AND is_read=1")->execute([$uid]);
        flash('success', 'Read notifications cleared.');
        redirect('/notifications.php');
    }
}

// ── FILTERS ───────────────────────────────────────────────────
$filter  = in_array(get('filter'), ['all', 'unread']) ? get('filter') : 'all';
$page    = max(1, (int)get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['user_id = ?'];
$params = [$uid];
if ($filter === 'unread') {
    $where[] = 'is_read = 0';
}
$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM notifications WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Counts
$unreadCount = (int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")->execute([$uid]) ?
    (function() use ($db, $uid) {
        $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$uid]);
        return (int)$s->fetchColumn();
    })() : 0;

$totalCount = (int)(function() use ($db, $uid) {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
})();

// Icon + color map per notification type
function notifStyle(string $type): array {
    return match(true) {
        str_contains($type, 'payment')    => ['ti-circle-check',  'var(--success)',  'var(--success-bg)'],
        str_contains($type, 'order')      => ['ti-shopping-cart', 'var(--warning)',  'var(--warning-bg)'],
        str_contains($type, 'withdrawal') => ['ti-cash',          'var(--p600)',     'var(--p50)'],
        str_contains($type, 'kyc')        => ['ti-shield-check',  'var(--success)',  'var(--success-bg)'],
        str_contains($type, 'welcome')    => ['ti-heart',         'var(--p600)',     'var(--p50)'],
        str_contains($type, 'plan'),
        str_contains($type, 'subscription') => ['ti-crown',       'var(--p600)',     'var(--p50)'],
        str_contains($type, 'security')   => ['ti-lock',          'var(--danger)',   'var(--danger-bg)'],
        str_contains($type, 'failed')     => ['ti-alert-circle',  'var(--danger)',   'var(--danger-bg)'],
        default                           => ['ti-bell',           'var(--n500)',     'var(--n100)'],
    };
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── PAGE LAYOUT ─────────────────────────────────────────────── */
.notif-page { max-width: 700px; }

/* ── TOP BAR ─────────────────────────────────────────────────── */
.notif-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 20px;
}

.notif-topbar-left h1 {
  font-family: var(--font-display);
  font-size: 22px;
  font-weight: 800;
  color: var(--n900);
  letter-spacing: -.5px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.notif-count-pill {
  font-family: var(--font-body);
  font-size: 12px;
  font-weight: 700;
  background: var(--p600);
  color: #fff;
  padding: 3px 10px;
  border-radius: var(--r-full);
}

.notif-topbar-left p {
  font-size: 13px;
  color: var(--n500);
  margin-top: 4px;
}

.notif-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

/* ── FILTER TABS ─────────────────────────────────────────────── */
.filter-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--n200);
  margin-bottom: 16px;
}

.filter-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 11px 20px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  color: var(--n500);
  transition: color .2s, border-color .2s;
  white-space: nowrap;
}

.filter-tab:hover { color: var(--n800); }

.filter-tab.active {
  color: var(--p600);
  border-bottom-color: var(--p600);
}

.filter-tab .tab-count {
  font-size: 11px;
  font-weight: 700;
  background: var(--n200);
  color: var(--n600);
  padding: 2px 7px;
  border-radius: var(--r-full);
}

.filter-tab.active .tab-count {
  background: var(--p100);
  color: var(--p700);
}

/* ── NOTIFICATION LIST ───────────────────────────────────────── */
.notif-list {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.notif-item {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 16px 20px;
  background: var(--n0);
  border: 1px solid var(--n200);
  border-bottom: none;
  position: relative;
  transition: background .15s;
}

.notif-item:first-child { border-radius: var(--r-xl) var(--r-xl) 0 0; }
.notif-item:last-child  { border-bottom: 1px solid var(--n200); border-radius: 0 0 var(--r-xl) var(--r-xl); }
.notif-item:only-child  { border-radius: var(--r-xl); border-bottom: 1px solid var(--n200); }

.notif-item:hover { background: var(--n50); }

.notif-item.unread {
  background: color-mix(in srgb, var(--p600) 4%, var(--n0));
}
.notif-item.unread::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 4px;
  background: var(--p600);
  border-radius: var(--r-sm) 0 0 var(--r-sm);
}
.notif-item:first-child.unread::before { border-radius: var(--r-xl) 0 0 var(--r-sm); }
.notif-item:last-child.unread::before  { border-radius: var(--r-sm) 0 0 var(--r-xl); }

/* Icon */
.notif-icon {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

/* Body */
.notif-body { flex: 1; min-width: 0; }

.notif-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--n900);
  line-height: 1.4;
  margin-bottom: 3px;
}

.notif-item.unread .notif-title { color: var(--p800); }

.notif-message {
  font-size: 13px;
  color: var(--n500);
  line-height: 1.55;
  margin-bottom: 6px;
}

.notif-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.notif-time {
  font-size: 11px;
  color: var(--n400);
  display: flex;
  align-items: center;
  gap: 4px;
}

.notif-link {
  font-size: 12px;
  font-weight: 600;
  color: var(--p600);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.notif-link:hover { text-decoration: underline; }

.notif-unread-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--p600);
  flex-shrink: 0;
}

/* Controls */
.notif-controls {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
  opacity: 0;
  transition: opacity .15s;
}

.notif-item:hover .notif-controls { opacity: 1; }

@media (max-width: 600px) {
  .notif-controls { opacity: 1; } /* always show on mobile */
}

.notif-ctrl-btn {
  width: 30px;
  height: 30px;
  border-radius: var(--r-sm);
  background: none;
  border: 1px solid var(--n200);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--n500);
  font-size: 15px;
  transition: all .15s;
}

.notif-ctrl-btn:hover {
  background: var(--n100);
  color: var(--n800);
  border-color: var(--n300);
}

.notif-ctrl-btn.danger:hover {
  background: var(--danger-bg);
  color: var(--danger);
  border-color: #FECACA;
}

/* ── DATE SEPARATOR ─────────────────────────────────────────── */
.date-sep {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--n400);
  padding: 16px 0 8px;
}

.date-sep:first-child { padding-top: 0; }

/* ── EMPTY STATE ─────────────────────────────────────────────── */
.notif-empty {
  text-align: center;
  padding: 56px 24px;
  background: var(--n0);
  border: 1px solid var(--n200);
  border-radius: var(--r-xl);
}

.notif-empty-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--p50);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--p300);
  font-size: 28px;
  margin: 0 auto 16px;
}

.notif-empty h3 {
  font-family: var(--font-display);
  font-size: 17px;
  font-weight: 700;
  color: var(--n900);
  margin-bottom: 8px;
}

.notif-empty p {
  font-size: 14px;
  color: var(--n500);
  line-height: 1.6;
  max-width: 320px;
  margin: 0 auto;
}

/* ── PAGINATION ──────────────────────────────────────────────── */
.notif-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
  flex-wrap: wrap;
  gap: 10px;
}

.pag-info { font-size: 13px; color: var(--n500); }

.pag-btns { display: flex; gap: 6px; }

.pag-btn {
  min-width: 34px;
  height: 34px;
  border-radius: var(--r-md);
  border: 1.5px solid var(--n200);
  background: var(--n0);
  cursor: pointer;
  font-family: var(--font-body);
  font-size: 13px;
  font-weight: 500;
  color: var(--n700);
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  padding: 0 10px;
  transition: all .15s;
}

.pag-btn:hover { border-color: var(--p300); color: var(--p600); }
.pag-btn.active { background: var(--p600); border-color: var(--p600); color: #fff; }
</style>

<div class="notif-page">

  <!-- TOP BAR -->
  <div class="notif-topbar">
    <div class="notif-topbar-left">
      <h1>
        Notifications
        <?php if ($unreadCount > 0): ?>
        <span class="notif-count-pill"><?= $unreadCount ?> new</span>
        <?php endif; ?>
      </h1>
      <p>Stay on top of payments, orders and account updates.</p>
    </div>
    <div class="notif-actions">
      <?php if ($unreadCount > 0): ?>
      <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-outline btn-sm">
          <i class="ti ti-checks"></i> Mark all read
        </button>
      </form>
      <?php endif; ?>
      <?php if ($totalCount > $unreadCount): ?>
      <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_all_read">
        <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Delete all read notifications?')">
          <i class="ti ti-trash"></i> Clear read
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- FILTER TABS -->
  <div class="filter-tabs">
    <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
      <i class="ti ti-bell"></i>
      All
      <span class="tab-count"><?= $totalCount ?></span>
    </a>
    <a href="?filter=unread" class="filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">
      <i class="ti ti-bell-ringing"></i>
      Unread
      <span class="tab-count"><?= $unreadCount ?></span>
    </a>
  </div>

  <!-- NOTIFICATION LIST -->
  <?php if (empty($notifications)): ?>

  <div class="notif-empty">
    <div class="notif-empty-icon">
      <i class="ti ti-bell-off"></i>
    </div>
    <h3><?= $filter === 'unread' ? 'All caught up!' : 'No notifications yet' ?></h3>
    <p>
      <?php if ($filter === 'unread'): ?>
        You have no unread notifications. Check back after your next payment or order.
      <?php else: ?>
        Notifications about payments, orders and account activity will appear here.
      <?php endif; ?>
    </p>
    <?php if ($filter === 'unread'): ?>
    <a href="?filter=all" class="btn btn-outline btn-md" style="margin-top:16px">
      <i class="ti ti-history"></i> View all notifications
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <?php
  // Group by date
  $grouped = [];
  foreach ($notifications as $n) {
      $dateKey = date('Y-m-d', strtotime($n['created_at']));
      $grouped[$dateKey][] = $n;
  }

  $today     = date('Y-m-d');
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  ?>

  <?php foreach ($grouped as $dateKey => $group): ?>

  <!-- DATE SEPARATOR -->
  <div class="date-sep">
    <?php
    if ($dateKey === $today)          echo 'Today';
    elseif ($dateKey === $yesterday)  echo 'Yesterday';
    else                              echo date('l, F j', strtotime($dateKey));
    ?>
  </div>

  <div class="notif-list">
    <?php foreach ($group as $n):
      [$icon, $iconColor, $iconBg] = notifStyle($n['type']);
      $isUnread = !$n['is_read'];
    ?>
    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= $n['id'] ?>">

      <!-- ICON -->
      <div class="notif-icon" style="background:<?= $iconBg ?>;color:<?= $iconColor ?>">
        <i class="ti <?= $icon ?>"></i>
      </div>

      <!-- BODY -->
      <div class="notif-body">
        <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
        <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
        <div class="notif-meta">
          <?php if ($isUnread): ?>
          <span class="notif-unread-dot"></span>
          <?php endif; ?>
          <span class="notif-time">
            <i class="ti ti-clock" style="font-size:12px"></i>
            <?= timeAgo($n['created_at']) ?>
          </span>
          <?php if ($n['link']): ?>
          <a href="<?= htmlspecialchars($n['link']) ?>" class="notif-link" onclick="markRead(<?= $n['id'] ?>)">
            View <i class="ti ti-arrow-right" style="font-size:11px"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- CONTROLS -->
      <div class="notif-controls">
        <?php if ($isUnread): ?>
        <button
          class="notif-ctrl-btn"
          title="Mark as read"
          onclick="markRead(<?= $n['id'] ?>)">
          <i class="ti ti-check"></i>
        </button>
        <?php endif; ?>
        <button
          class="notif-ctrl-btn danger"
          title="Delete"
          onclick="deleteNotif(<?= $n['id'] ?>)">
          <i class="ti ti-trash"></i>
        </button>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <?php endforeach; ?>

  <!-- PAGINATION -->
  <?php if ($totalPages > 1): ?>
  <div class="notif-pagination">
    <span class="pag-info">
      Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
    </span>
    <div class="pag-btns">
      <?php if ($page > 1): ?>
      <a href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>" class="pag-btn">
        <i class="ti ti-chevron-left"></i>
      </a>
      <?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
      <a href="?filter=<?= $filter ?>&page=<?= $i ?>" class="pag-btn <?= $i === $page ? 'active' : '' ?>">
        <?= $i ?>
      </a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>" class="pag-btn">
        <i class="ti ti-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<script>
const CSRF_TOKEN = '<?= csrfToken() ?>';

// Mark single notification as read via AJAX
async function markRead(id) {
  const item = document.getElementById('notif-' + id);

  // Optimistic UI update immediately
  if (item) {
    item.classList.remove('unread');
    const dot = item.querySelector('.notif-unread-dot');
    if (dot) dot.remove();
    const markBtn = item.querySelector('.notif-ctrl-btn:not(.danger)');
    if (markBtn) markBtn.remove();
    // Remove left purple bar
    item.style.background = '';
  }

  // Update unread count pill
  updateUnreadPill(-1);

  try {
    const fd = new FormData();
    fd.append('action',     'mark_read');
    fd.append('notif_id',   id);
    fd.append('csrf_token', CSRF_TOKEN);
    await fetch('/notifications.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
  } catch(e) {
    // Silent fail — page reload will correct state
  }
}

// Delete notification via AJAX
async function deleteNotif(id) {
  const item = document.getElementById('notif-' + id);

  // Optimistic removal with animation
  if (item) {
    item.style.transition = 'opacity .25s, transform .25s, max-height .35s';
    item.style.opacity    = '0';
    item.style.transform  = 'translateX(20px)';
    item.style.overflow   = 'hidden';
    setTimeout(() => {
      item.style.maxHeight = '0';
      item.style.padding   = '0';
      item.style.border    = 'none';
      item.style.margin    = '0';
    }, 250);
    setTimeout(() => item.remove(), 600);
  }

  try {
    const fd = new FormData();
    fd.append('action',     'delete');
    fd.append('notif_id',   id);
    fd.append('csrf_token', CSRF_TOKEN);
    await fetch('/notifications.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
  } catch(e) {}
}

// Update the unread count pill in topbar
function updateUnreadPill(delta) {
  const pill = document.querySelector('.notif-count-pill');
  if (!pill) return;
  let count = parseInt(pill.textContent) + delta;
  if (count <= 0) {
    pill.remove();
  } else {
    pill.textContent = count + ' new';
  }

  // Also update sidebar badge if present
  const sidebarBadge = document.querySelector('.nav-link[href="/notifications.php"] .badge-count');
  if (sidebarBadge) {
    let sc = parseInt(sidebarBadge.textContent) + delta;
    if (sc <= 0) sidebarBadge.remove();
    else sidebarBadge.textContent = sc;
  }
}

// Mark as read when clicking "View" link
document.querySelectorAll('.notif-link').forEach(link => {
  link.addEventListener('click', function() {
    const item = this.closest('.notif-item');
    if (item) {
      const id = item.id.replace('notif-', '');
      // Don't await — fire and forget, follow the link
      markRead(parseInt(id));
    }
  });
});

<?php if ($filter === 'unread' && !empty($notifications)): ?>
setTimeout(() => {
  document.querySelectorAll('.notif-item.unread').forEach(item => {
    const id = parseInt(item.id.replace('notif-', ''));
    markRead(id);
  });
}, 3000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
