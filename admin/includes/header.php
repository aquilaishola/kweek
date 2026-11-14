<?php
// Expects: $pageTitle, $activePage set by the calling page.
// requireAdminLogin() must already have run before this is included.
$admin = currentAdmin();
$pageTitle = $pageTitle ?? 'Admin';
$activePage = $activePage ?? '';
$flash = function_exists('flashHtml') ? flashHtml() : '';

$pendingKycCount = 0;
if (adminCan($admin, 'manage_kyc')) {
    $pendingKycCount = (int) db()->query("SELECT COUNT(*) FROM user_kyc WHERE status = 'pending'")->fetchColumn();
}
$pendingWithdrawalsCount = 0;
if (adminCan($admin, 'manage_withdrawals')) {
    $pendingWithdrawalsCount = (int) db()->query("SELECT COUNT(*) FROM withdrawals WHERE status IN ('pending','processing')")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Kweek Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/kweek-icon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ── admin-specific layout, built on the same design tokens as the
   merchant dashboard (var(--ink), var(--purple-600), var(--r-lg), etc.) ── */
.sidebar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--ink);font-size:22px;padding:6px;margin-right:4px}
.app-overlay{position:fixed;inset:0;background:rgba(15,14,28,.5);z-index:290;display:none}
.app-overlay.open{display:block}

@media (max-width:960px){
  .app-sidebar{ display:flex !important; flex-direction:column; position:fixed; top:0; left:0; z-index:300;
    height:100dvh; width:270px; max-width:82vw; transform:translateX(-100%); transition:transform .28s ease;
    overflow-y:auto; -webkit-overflow-scrolling:touch; }
  .app-sidebar.open{ transform:translateX(0); box-shadow:20px 0 50px -20px rgba(0,0,0,.35); }
  .sidebar-toggle{ display:flex; align-items:center; justify-content:center; }
  .side-close{ display:flex !important; }
}
.side-close{ display:none; align-items:center; justify-content:center; width:30px; height:30px; border-radius:8px;
  background:rgba(255,255,255,.06); border:none; color:rgba(255,255,255,.7); font-size:17px; cursor:pointer; margin-left:auto; }
.side-close:hover{ background:rgba(255,255,255,.12); color:#fff; }
.side-brand-row{ display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; padding:0 6px; }
.admin-brand{ display:flex; flex-direction:column; gap:0; text-decoration:none; }
.admin-brand-name{ font-family:var(--font-display,inherit); font-size:19px; font-weight:800; color:#fff; letter-spacing:-.5px; }
.admin-brand-sub{ font-size:10.5px; color:#9B93F3; letter-spacing:1px; text-transform:uppercase; font-weight:700; }
.side-group-label{ font-size:10px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:rgba(255,255,255,.32); padding:16px 12px 6px; }
.side-group-label:first-child{ padding-top:14px; }
.nav-badge-warn{ margin-left:auto; background:var(--warning); color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:9999px; }

.side-bottom{ margin-top:auto; display:flex; flex-direction:column; gap:2px; padding-top:10px; border-top:1px solid rgba(255,255,255,.08); }
.side-user-row{ display:flex; align-items:center; gap:10px; padding:10px; border-radius:var(--r-md); text-decoration:none; transition:background .15s; flex:1; min-width:0; }
.side-user-row:hover{ background:rgba(255,255,255,.06); }
.side-user-av{ width:34px; height:34px; border-radius:50%; background:var(--purple-600); color:#fff; display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:700; flex-shrink:0; }
.side-user-info{ flex:1; min-width:0; }
.side-user-name{ font-size:13px; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.side-user-plan{ font-size:11px; color:rgba(255,255,255,.5); }
.side-user-row-wrap{ display:flex; align-items:center; gap:4px; }
.side-signout-btn{ display:flex; align-items:center; justify-content:center; width:34px; height:34px; flex-shrink:0;
  border-radius:var(--r-md); background:none; border:none; cursor:pointer; color:#FCA5A5; font-size:17px; text-decoration:none; transition:background .15s,color .15s; }
.side-signout-btn:hover{ background:rgba(220,38,38,.15); color:#fff; }

.flash{ display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:var(--r-lg); margin-bottom:20px; font-size:13.5px; font-weight:500; }
.flash i{ font-size:17px; flex-shrink:0; }
.flash-success{ background:var(--success-bg); color:var(--success); border:1px solid rgba(30,158,90,.25); }
.flash-error{ background:var(--danger-bg); color:var(--danger); border:1px solid rgba(220,38,38,.2); }
.flash-warning{ background:var(--warning-bg); color:var(--warning); border:1px solid rgba(217,119,6,.25); }
.flash-info{ background:var(--purple-50); color:var(--purple-600); border:1px solid var(--purple-100); }

.app-topbar{ display:flex; align-items:center; justify-content:space-between; flex-wrap:nowrap; gap:10px; }
.app-topbar > div:first-child{ display:flex; align-items:center; min-width:0; flex:1 1 auto; }
.topbar-title{ min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.topbar-actions{ display:flex; align-items:center; gap:10px; flex-shrink:0; }
.role-chip{ display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border-radius:var(--r-full); font-size:12.5px; font-weight:600; background:var(--surface); color:var(--ink); border:1px solid var(--line); }

@media (max-width:960px){ .app-topbar{ padding:12px 16px; gap:8px; } }
#flash-wrap{ margin-bottom:10px; }
.side-nav{ margin-bottom:18px; }

/* ── shared admin content widgets, used across dashboard/users/kyc/etc ── */
.stat-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
@media (max-width:960px){ .stat-grid{ grid-template-columns:repeat(2,1fr); } }
@media (max-width:520px){ .stat-grid{ grid-template-columns:1fr; } }
.stat-card{ background:var(--paper); border:1.5px solid var(--line); border-radius:var(--r-lg); padding:18px; }
.stat-card-label{ font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--muted-2); display:flex; align-items:center; gap:6px; margin-bottom:10px; }
.stat-card-value{ font-family:var(--font-display,inherit); font-size:24px; font-weight:800; color:var(--ink); letter-spacing:-.5px; }
.stat-card-sub{ font-size:12px; color:var(--muted); margin-top:4px; }

.filter-bar{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; align-items:center; }
.filter-tab{ padding:8px 16px; border-radius:var(--r-full); font-size:13px; font-weight:600; text-decoration:none; color:var(--muted); background:var(--surface); border:1px solid transparent; }
.filter-tab.active{ background:var(--purple-50); color:var(--purple-600); border-color:var(--purple-100); }
.search-input-wrap{ margin-left:auto; position:relative; min-width:220px; }
.search-input-wrap i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted-2); font-size:15px; }
.search-input-wrap input{ width:100%; box-sizing:border-box; padding:9px 12px 9px 34px; border-radius:var(--r-full); border:1.5px solid var(--line); background:var(--paper); font-size:13px; font-family:var(--font-body); outline:none; }
.search-input-wrap input:focus{ border-color:var(--purple-500); }

.doc-thumb{ display:block; border-radius:var(--r-md); border:1px solid var(--line); overflow:hidden; width:100%; max-width:340px; }
.doc-thumb img{ display:block; width:100%; height:auto; }
.doc-pdf-box{ display:flex; align-items:center; gap:10px; padding:14px; border-radius:var(--r-md); border:1px solid var(--line); background:var(--surface); text-decoration:none; color:var(--ink); font-size:13px; font-weight:600; max-width:340px; }

.pager{ display:flex; align-items:center; justify-content:center; gap:6px; margin-top:18px; }
.pager a, .pager span{ display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 8px; border-radius:var(--r-md); font-size:13px; font-weight:600; text-decoration:none; color:var(--ink); border:1px solid var(--line); }
.pager a:hover{ background:var(--surface); }
.pager .current{ background:var(--purple-600); color:#fff; border-color:var(--purple-600); }
.pager .disabled{ opacity:.4; pointer-events:none; }

/* ── form inputs — the merchant app defines these per-page in its own
   header.php rather than in app.css, so admin needs its own copy too ── */
.form-group{ display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
.form-label{ font-size:12.5px; font-weight:600; color:var(--ink); letter-spacing:.2px; }
.form-input-wrap{ position:relative; }
.form-input-wrap .inp-icon{ position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px; color:var(--muted-2); pointer-events:none; }
.form-input-wrap .form-input{ padding-left:38px; }
.form-input, .form-select, .form-textarea{
  width:100%; box-sizing:border-box; font-family:var(--font-body); font-size:14px; color:var(--ink);
  background:var(--surface); border:1.5px solid transparent; border-bottom:1.5px solid var(--line);
  border-radius:var(--r-sm); padding:11px 14px; outline:none; transition:all .15s;
}
.form-input::placeholder, .form-textarea::placeholder{ color:var(--muted-2); }
.form-input:focus, .form-select:focus, .form-textarea:focus{
  background:var(--paper); border-color:var(--purple-500); box-shadow:0 0 0 4px var(--purple-50);
}
.form-input:disabled, .form-select:disabled, .form-textarea:disabled{ opacity:.6; cursor:not-allowed; }
.form-hint{ font-size:11.5px; color:var(--muted); margin-top:2px; }
.form-textarea{ resize:vertical; min-height:88px; font-family:var(--font-body); }
.form-select{
  cursor:pointer; appearance:none; padding-right:32px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23726E85' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 12px center;
}

/* ── tables — force horizontal scroll on narrow screens instead of
   squashing columns; app.css's .table-wrap apparently doesn't reliably
   get this on its own (same issue the merchant pages work around locally) ── */
.table-wrap{ overflow-x:auto; -webkit-overflow-scrolling:touch; background:var(--paper); border:1.5px solid var(--line); border-radius:var(--r-lg); }
.table-wrap table{ min-width:720px; width:100%; border-collapse:collapse; }
.table-wrap thead th{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); text-align:left; padding:12px 16px; border-bottom:1px solid var(--line); white-space:nowrap; }
.table-wrap tbody td{ padding:13px 16px; border-bottom:1px solid var(--line); font-size:13px; color:var(--ink); }
.table-wrap tbody tr:last-child td{ border-bottom:none; }
.table-head{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); gap:10px; flex-wrap:wrap; }
.table-title{ font-size:14px; font-weight:700; color:var(--ink); }
.td-bold{ font-weight:600; color:var(--ink); }
.empty-state{ text-align:center; padding:32px 20px; }
.empty-icon{ width:48px; height:48px; border-radius:50%; background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:20px; color:var(--muted-2); margin:0 auto 12px; }
.empty-title{ font-size:14px; font-weight:700; color:var(--ink); }
.empty-sub{ font-size:12.5px; color:var(--muted); margin-top:4px; }

.online-dot{ display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--success); margin-right:5px; box-shadow:0 0 0 3px var(--success-bg); }

/* ── reusable responsive layout classes — used on user-detail, kyc-review,
   admins, settings, anywhere with a "main content + side panel" split or
   a small inline stat row ── */
.detail-grid{ display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start; }
.stat-mini-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
@media (max-width:900px){
  .detail-grid{ grid-template-columns:1fr; }
}
@media (max-width:640px){
  .stat-mini-grid{ grid-template-columns:repeat(2,1fr); }
}
@media (max-width:420px){
  .stat-mini-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="app-overlay" id="app-overlay" onclick="kwCloseSidebar()"></div>

<script>
function kwOpenSidebar(){ document.getElementById('app-sidebar').classList.add('open'); document.getElementById('app-overlay').classList.add('open'); }
function kwCloseSidebar(){ document.getElementById('app-sidebar').classList.remove('open'); document.getElementById('app-overlay').classList.remove('open'); }
window.addEventListener('resize', () => { if (window.innerWidth > 960) kwCloseSidebar(); });

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.flash').forEach(function (el) {
    var closeBtn = document.createElement('button');
    closeBtn.innerHTML = '<i class="ti ti-x"></i>';
    closeBtn.style.cssText = 'margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:15px;padding:2px;flex-shrink:0;line-height:1';
    closeBtn.onclick = function () { el.remove(); };
    el.appendChild(closeBtn);
    setTimeout(function () { if (el.parentNode) el.remove(); }, 6000);
  });
});
</script>

<div class="app-shell">

  <!-- SIDEBAR -->
  <aside class="app-sidebar" id="app-sidebar" style="background:#0F0E1C">
    <div class="side-brand-row">
      <a href="/admin/dashboard.php" class="admin-brand">
        <span class="admin-brand-name">Kw<span style="color:#9B93F3">ee</span>k</span>
        <span class="admin-brand-sub">Admin</span>
      </a>
      <button class="side-close" onclick="kwCloseSidebar()" aria-label="Close menu"><i class="ti ti-x"></i></button>
    </div>

    <nav class="side-nav">
      <div class="side-group-label">Overview</div>
      <a href="/admin/dashboard.php" class="side-link <?= $activePage === 'dashboard' ? 'active' : '' ?>"><i class="ti ti-layout-dashboard"></i> Dashboard</a>

      <?php if (adminCan($admin, 'manage_users') || adminCan($admin, 'view_users')): ?>
      <div class="side-group-label">Merchants</div>
      <a href="/admin/users.php" class="side-link <?= $activePage === 'users' ? 'active' : '' ?>"><i class="ti ti-users-group"></i> Users</a>
      <?php endif; ?>

      <?php if (adminCan($admin, 'manage_kyc')): ?>
      <a href="/admin/kyc.php" class="side-link <?= $activePage === 'kyc' ? 'active' : '' ?>">
        <i class="ti ti-id-badge"></i> KYC Review
        <?php if ($pendingKycCount > 0): ?><span class="nav-badge-warn"><?= $pendingKycCount ?></span><?php endif; ?>
      </a>
      <?php endif; ?>

      <?php if (adminCanAny($admin, ['manage_links','view_links'])): ?>
      <div class="side-group-label">Sell</div>
      <a href="/admin/links.php" class="side-link <?= $activePage === 'links' ? 'active' : '' ?>"><i class="ti ti-link"></i> Payment Links</a>
      <?php endif; ?>
      <?php if (adminCan($admin, 'view_orders')): ?>
      <a href="/admin/orders.php" class="side-link <?= $activePage === 'orders' ? 'active' : '' ?>"><i class="ti ti-shopping-cart"></i> Orders</a>
      <?php endif; ?>

      <?php if (adminCan($admin, 'manage_withdrawals')): ?>
      <div class="side-group-label">Money</div>
      <a href="/admin/withdrawals.php" class="side-link <?= $activePage === 'withdrawals' ? 'active' : '' ?>">
        <i class="ti ti-cash"></i> Withdrawals
        <?php if ($pendingWithdrawalsCount > 0): ?><span class="nav-badge-warn"><?= $pendingWithdrawalsCount ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php if (adminCan($admin, 'view_transactions')): ?>
      <a href="/admin/transactions.php" class="side-link <?= $activePage === 'transactions' ? 'active' : '' ?>"><i class="ti ti-receipt"></i> Transactions</a>
      <?php endif; ?>
      <?php if (adminCan($admin, 'view_subscriptions') || adminCan($admin, 'manage_subscriptions')): ?>
      <a href="/admin/subscriptions.php" class="side-link <?= $activePage === 'subscriptions' ? 'active' : '' ?>"><i class="ti ti-crown"></i> Subscriptions</a>
      <?php endif; ?>

      <?php if (adminCanAny($admin, ['manage_admins','manage_settings'])): ?>
      <div class="side-group-label">System</div>
      <?php if (adminCan($admin, 'manage_admins')): ?>
      <a href="/admin/admins.php" class="side-link <?= $activePage === 'admins' ? 'active' : '' ?>"><i class="ti ti-shield-lock"></i> Admin Accounts</a>
      <?php endif; ?>
      <?php if (adminCan($admin, 'manage_settings')): ?>
      <a href="/admin/settings.php" class="side-link <?= $activePage === 'settings' ? 'active' : '' ?>"><i class="ti ti-settings"></i> Settings</a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="side-bottom">
      <div class="side-user-row-wrap">
        <div class="side-user-row" style="cursor:default">
          <div class="side-user-av"><?= htmlspecialchars(adminInitials($admin['name'])) ?></div>
          <div class="side-user-info">
            <div class="side-user-name"><?= htmlspecialchars($admin['name']) ?></div>
            <div class="side-user-plan"><?= htmlspecialchars(adminRoleLabel($admin['role'])) ?></div>
          </div>
        </div>
        <a href="/admin/logout.php" class="side-signout-btn" title="Sign out" aria-label="Sign out"><i class="ti ti-logout"></i></a>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="app-main">
    <header class="app-topbar">
      <div style="display:flex;align-items:center;">
        <button class="sidebar-toggle" onclick="kwOpenSidebar()" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
        <div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
      </div>
      <div class="topbar-actions">
        <span class="role-chip"><i class="ti ti-shield"></i> <?= htmlspecialchars(adminRoleLabel($admin['role'])) ?></span>
      </div>
    </header>

    <main class="app-content">
      <?php if ($flash): ?><div id="flash-wrap"><?= $flash ?></div><?php endif; ?>
