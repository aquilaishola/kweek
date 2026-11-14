<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
$admin = currentAdmin();
$pageTitle  = $pageTitle  ?? 'Admin';
$activePage = $activePage ?? '';

$navItems = [
    ['key'=>'dashboard',     'label'=>'Dashboard',      'icon'=>'ti-layout-dashboard', 'href'=>'/admin/index.php',         'roles'=>['super_admin','support','finance'], 'group'=>'Overview'],
    ['key'=>'users',         'label'=>'Merchants',      'icon'=>'ti-building-store',   'href'=>'/admin/users.php',          'roles'=>['super_admin','support','finance'], 'group'=>'Manage'],
    ['key'=>'orders',        'label'=>'Orders',         'icon'=>'ti-shopping-cart',    'href'=>'/admin/orders.php',         'roles'=>['super_admin','support','finance'], 'group'=>'Manage'],
    ['key'=>'payment_links', 'label'=>'Payment Links',  'icon'=>'ti-link',             'href'=>'/admin/payment-links.php',  'roles'=>['super_admin','support'], 'group'=>'Manage'],
    ['key'=>'transactions',  'label'=>'Transactions',   'icon'=>'ti-receipt',          'href'=>'/admin/transactions.php',   'roles'=>['super_admin','finance'], 'group'=>'Money'],
    ['key'=>'withdrawals',   'label'=>'Withdrawals',    'icon'=>'ti-cash',             'href'=>'/admin/withdrawals.php',    'roles'=>['super_admin','finance'], 'group'=>'Money', 'badge'=>'pendingWithdrawals'],
    ['key'=>'subscriptions', 'label'=>'Subscriptions',  'icon'=>'ti-crown',            'href'=>'/admin/subscriptions.php',  'roles'=>['super_admin','finance'], 'group'=>'Money'],
    ['key'=>'kyc',           'label'=>'KYC / BVN',      'icon'=>'ti-id-badge',         'href'=>'/admin/kyc.php',            'roles'=>['super_admin','support'], 'group'=>'Compliance', 'badge'=>'pendingKyc'],
    ['key'=>'webhooks',      'label'=>'Webhooks',       'icon'=>'ti-plug-connected',   'href'=>'/admin/webhooks.php',       'roles'=>['super_admin'], 'group'=>'System'],
    ['key'=>'admins',        'label'=>'Admin Users',    'icon'=>'ti-users',            'href'=>'/admin/admins.php',         'roles'=>['super_admin'], 'group'=>'System'],
    ['key'=>'settings',      'label'=>'Settings',       'icon'=>'ti-settings',         'href'=>'/admin/settings.php',       'roles'=>['super_admin'], 'group'=>'System'],
];

$db = db();
$badgeCounts = [
    'pendingWithdrawals' => (int)$db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn(),
    'pendingKyc'         => (int)$db->query("SELECT COUNT(*) FROM user_kyc WHERE status='pending'")->fetchColumn(),
];

$initials = strtoupper(substr($admin['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Kweek Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ── admin-only additions on top of the shared app.css design system ── */
.admin-pill{font-family:var(--font-mono);font-size:9px;font-weight:700;background:rgba(123,111,238,.18);color:var(--purple-100);padding:2px 8px;border-radius:var(--r-full);letter-spacing:.6px;text-transform:uppercase;margin-left:6px}
.side-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:var(--r-full)}

.sidebar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--ink);font-size:22px;padding:6px;margin-right:4px}
.app-overlay{position:fixed;inset:0;background:rgba(15,14,28,.5);z-index:290;display:none}
.app-overlay.open{display:block}
.side-close{display:none;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.06);border:none;color:rgba(255,255,255,.7);font-size:17px;cursor:pointer;margin-left:auto}
.side-close:hover{background:rgba(255,255,255,.12);color:#fff}
.side-brand-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding:0 6px}

@media (max-width:960px){
  .app-sidebar{
    display:flex !important;flex-direction:column;position:fixed;top:0;left:0;z-index:300;
    height:100dvh;width:270px;max-width:82vw;transform:translateX(-100%);
    transition:transform .28s ease;overflow-y:auto;-webkit-overflow-scrolling:touch;
  }
  .app-sidebar.open{transform:translateX(0);box-shadow:20px 0 50px -20px rgba(0,0,0,.35)}
  .sidebar-toggle{display:flex;align-items:center;justify-content:center}
  .side-close{display:flex}
}

/* filter/pagination bits not in app.css yet */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.filter-input,.filter-select{font-family:var(--font-body);font-size:13px;padding:9px 13px;border:1.5px solid var(--line);border-radius:var(--r-sm);background:var(--paper);outline:none;max-width:100%}
.filter-input:focus,.filter-select:focus{border-color:var(--purple-500)}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:18px;flex-wrap:wrap}
.page-link{padding:7px 13px;border:1.5px solid var(--line);border-radius:var(--r-sm);font-size:12.5px;font-weight:600;color:var(--muted)}
.page-link.active{background:var(--purple-600);border-color:var(--purple-600);color:#fff}
.empty-row td{text-align:center;padding:40px 20px;color:var(--muted-2)}
.progress-track{height:7px;background:var(--surface);border-radius:var(--r-full);overflow:hidden;margin-top:6px}
.progress-fill{height:100%;background:var(--purple-600);border-radius:var(--r-full)}
.mini-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}
.mini-row:last-child{border-bottom:none}
.table-wrap {
    overflow-x: auto;
}

@media (max-width:960px){ .stat-grid{grid-template-columns:1fr !important} }
@media (max-width:560px){
  .stat-grid{display:block;}
  .stat-card{width:100%}
  .app-content{display:block;}
}
</style>
</head>
<body>

<div class="app-overlay" id="app-overlay" onclick="kwCloseSidebar()"></div>

<div class="app-shell">
  <aside class="app-sidebar" id="app-sidebar">
    <div class="side-brand-row">
      <a href="/admin/index.php" class="side-brand">
        <span style="font-family:var(--font-display);font-weight:700;font-size:19px;color:#fff;letter-spacing:-.4px">Kw<span style="color:var(--purple-100)">ee</span>k</span>
        <span class="admin-pill">Admin</span>
      </a>
      <button class="side-close" onclick="kwCloseSidebar()" aria-label="Close menu"><i class="ti ti-x"></i></button>
    </div>

    <nav class="side-nav">
      <?php $lastGroup = null; foreach ($navItems as $item):
        if (!in_array($admin['role'], $item['roles'], true)) continue;
        if ($item['group'] !== $lastGroup) { echo '<div class="side-label">' . htmlspecialchars($item['group']) . '</div>'; $lastGroup = $item['group']; }
        $count = ($item['badge'] ?? null) ? ($badgeCounts[$item['badge']] ?? 0) : 0;
      ?>
      <a href="<?= $item['href'] ?>" class="side-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
        <i class="ti <?= $item['icon'] ?>"></i>
        <span><?= $item['label'] ?></span>
        <?php if ($count > 0): ?><span class="side-badge"><?= $count ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="side-plan" style="margin-top:auto">
      <div class="sp-top">
        <span class="sp-name"><?= htmlspecialchars($admin['name']) ?></span>
      </div>
      <div style="font-family:var(--font-mono);font-size:10.5px;color:rgba(255,255,255,.5);text-transform:capitalize">
        <?= htmlspecialchars(str_replace('_',' ',$admin['role'])) ?>
      </div>
      <a href="/admin/logout.php"><i class="ti ti-logout"></i> Sign out</a>
    </div>
  </aside>

  <div class="app-main">
    <header class="app-topbar">
      <div style="display:flex;align-items:center">
        <button class="sidebar-toggle" onclick="kwOpenSidebar()" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
        <div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
      </div>
      <div class="topbar-actions">
        <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
      </div>
    </header>

    <main class="app-content">
      <?php if ($f = flashHtml()): ?><div id="flash-wrap"><?= $f ?></div><?php endif; ?>