<?php

$user = currentUser();
if (!$user) { redirect('/login.php'); }
$notifCount = unreadNotifCount($user['id']);
$flash = flashHtml();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> — Kweek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/kweek-fixes.css">
<style>
html,body{overflow-x:hidden}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p50:#F0EFFE;--p100:#DCD9FC;--p200:#BCB6F8;--p300:#9B93F3;
  --p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p700:#3D33B8;
  --p800:#2E2690;--p900:#1C1660;
  --n0:#FFFFFF;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;
  --n300:#C9C8D8;--n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;
  --n700:#302E45;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#16A34A;--success-bg:#DCFCE7;
  --warning:#D97706;--warning-bg:#FEF3C7;
  --danger:#DC2626;--danger-bg:#FEE2E2;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
  --sidebar-w:260px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n50);color:var(--n800);-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* PRELOADER */
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:32px;font-weight:800;color:var(--n0);letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:48px;height:48px;border-radius:50%;border:3px solid var(--n800);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--n900);z-index:300;display:flex;flex-direction:column;transition:transform .3s ease}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid var(--n800);display:flex;align-items:center;justify-content:space-between}
.logo{font-family:var(--font-display);font-weight:800;font-size:22px;color:var(--n0);text-decoration:none;letter-spacing:-.5px}
.logo .acc{color:var(--p400)}
.sidebar-close{display:none;background:none;border:none;color:var(--n500);font-size:22px;cursor:pointer;padding:4px}

.sidebar-nav{flex:1;padding:12px 10px;overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.sidebar-nav::-webkit-scrollbar{width:4px}
.sidebar-nav::-webkit-scrollbar-track{background:transparent}
.sidebar-nav::-webkit-scrollbar-thumb{background:var(--n700);border-radius:4px}

.nav-section-label{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--n600);padding:12px 12px 6px;margin-top:4px}

.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r-md);text-decoration:none;color:var(--n400);font-size:13.5px;font-weight:500;transition:all .15s;position:relative}
.nav-link i{font-size:18px;flex-shrink:0}
.nav-link:hover{background:var(--n800);color:var(--n0)}
.nav-link.active{background:var(--p800);color:var(--n0)}
.nav-link.active i{color:var(--p300)}
.nav-badge{margin-left:auto;background:var(--p600);color:var(--n0);font-size:10px;font-weight:700;padding:2px 7px;border-radius:var(--r-full)}

.sidebar-bottom{padding:12px 10px;border-top:1px solid var(--n800)}
.user-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r-md);cursor:pointer;transition:background .15s;text-decoration:none}
.user-row:hover{background:var(--n800)}
.user-av{width:36px;height:36px;border-radius:50%;background:var(--p700);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--n0);font-family:var(--font-display);flex-shrink:0}
.user-name{font-size:13px;font-weight:600;color:var(--n200);line-height:1.3}
.user-plan{font-size:11px;color:var(--n500);text-transform:capitalize}
.user-arrow{margin-left:auto;color:var(--n600);font-size:16px}

/* MAIN */
.dash-main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}

/* TOPBAR */
.topbar{background:var(--n0);border-bottom:1px solid var(--n200);height:64px;display:flex;align-items:center;padding:0 28px;justify-content:space-between;position:sticky;top:0;z-index:200}
.topbar-left{display:flex;align-items:center;gap:14px}
.sidebar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--n600);font-size:22px;padding:6px}
.page-title{font-family:var(--font-display);font-size:17px;font-weight:700;color:var(--n900);letter-spacing:-.3px}
.topbar-right{display:flex;align-items:center;gap:8px}
.topbar-btn{width:38px;height:38px;border-radius:var(--r-md);background:none;border:1px solid var(--n200);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--n600);font-size:18px;transition:all .2s;text-decoration:none;position:relative}
.topbar-btn:hover{background:var(--n50);color:var(--n900)}
.notif-dot{position:absolute;top:7px;right:7px;width:7px;height:7px;border-radius:50%;background:var(--danger);border:2px solid var(--n0)}

/* CONTENT */
.dash-content{flex:1;padding:28px}

/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:var(--r-lg);margin-bottom:20px;font-size:14px;font-weight:500}
.flash i{font-size:18px;flex-shrink:0}
.flash-success{background:var(--success-bg);color:var(--success);border:1px solid #BBF7D0}
.flash-error{background:var(--danger-bg);color:var(--danger);border:1px solid #FECACA}
.flash-info{background:var(--p50);color:var(--p700);border:1px solid var(--p100)}
.flash-warning{background:var(--warning-bg);color:var(--warning);border:1px solid #FDE68A}

#flash-wrap{
    overflow:hidden;
    transition:max-height .35s ease, opacity .35s ease, margin .35s ease;
}

#flash-wrap.collapsing{
    max-height:0 !important;
    opacity:0;
    margin:0;
}

/* CARDS */
.card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:24px}
.card-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--n900);margin-bottom:4px}
.card-sub{font-size:13px;color:var(--n500)}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:28px}
.stat-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:22px;transition:border-color .2s}
.stat-card:hover{border-color:var(--p200)}
.stat-icon{width:44px;height:44px;border-radius:var(--r-md);background:var(--p50);display:flex;align-items:center;justify-content:center;color:var(--p600);font-size:20px;margin-bottom:14px;border:1px solid var(--p100)}
.stat-label{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--n400);margin-bottom:6px}
.stat-value{font-family:var(--font-display);font-size:26px;font-weight:800;color:var(--n900);letter-spacing:-.5px;margin-bottom:5px}
.stat-change{font-size:12px;font-weight:500;display:flex;align-items:center;gap:4px}
.stat-change.up{color:var(--success)}
.stat-change.down{color:var(--danger)}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:var(--r-full);text-transform:capitalize}
.badge-success{background:var(--success-bg);color:var(--success)}
.badge-danger{background:var(--danger-bg);color:var(--danger)}
.badge-warning{background:var(--warning-bg);color:var(--warning)}
.badge-purple{background:var(--p50);color:var(--p700)}
.badge-neutral{background:var(--n100);color:var(--n600)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:var(--font-body);font-weight:600;cursor:pointer;border:none;border-radius:var(--r-md);text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-sm{font-size:12px;padding:7px 14px}
.btn-md{font-size:14px;padding:10px 20px}
.btn-lg{font-size:15px;padding:13px 26px}
.btn-purple{background:var(--p600);color:var(--n0)}
.btn-purple:hover{background:var(--p700)}
.btn-outline{background:transparent;color:var(--n700);border:1.5px solid var(--n200)}
.btn-outline:hover{border-color:var(--p300);color:var(--p600)}
.btn-danger{background:var(--danger);color:var(--n0)}
.btn-danger:hover{background:#B91C1C}
.btn-success{background:var(--success);color:var(--n0)}

/* FORMS */
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.form-label{font-size:13px;font-weight:600;color:var(--n600)}
.form-input,.form-select,.form-textarea{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n0);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px;outline:none;transition:border-color .2s,box-shadow .2s;width:100%}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--p500);box-shadow:0 0 0 4px var(--p50)}
.form-input::placeholder,.form-textarea::placeholder{color:var(--n400)}
.form-textarea{resize:vertical;min-height:100px}
.form-input-wrap{position:relative}
.form-input-wrap .form-input{padding-left:42px}
.form-input-wrap .inp-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}
.form-hint{font-size:12px;color:var(--n500);margin-top:4px}
.form-error-msg{font-size:12px;color:var(--danger);display:flex;align-items:center;gap:4px;margin-top:4px}
.form-select{appearance:none;cursor:pointer}

/* TABLE */
.table-wrap{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);overflow:auto;-webkit-overflow-scrolling:touch}
.table-head{padding:18px 22px;border-bottom:1px solid var(--n200);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.table-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--n900)}
.table-actions{display:flex;align-items:center;gap:8px}
.search-wrap{position:relative}
.search-wrap input{font-family:var(--font-body);font-size:13px;background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-md);padding:8px 12px 8px 34px;outline:none;width:200px;transition:all .2s}
.search-wrap input:focus{border-color:var(--p400);background:var(--n0);width:240px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:15px}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--n50)}
th{padding:11px 18px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--n400);border-bottom:1px solid var(--n200)}
td{padding:15px 18px;font-size:13.5px;color:var(--n700);border-bottom:1px solid var(--n100)}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--n50)}
.td-bold{font-weight:600;color:var(--n900)}

/* EMPTY STATE */
.empty-state{text-align:center;padding:60px 20px}
.empty-icon{width:64px;height:64px;border-radius:var(--r-xl);background:var(--p50);display:flex;align-items:center;justify-content:center;color:var(--p400);font-size:28px;margin:0 auto 16px}
.empty-title{font-family:var(--font-display);font-size:17px;font-weight:700;color:var(--n900);margin-bottom:8px}
.empty-sub{font-size:14px;color:var(--n500);margin-bottom:20px}

/* OVERLAY */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:299;display:none}
.overlay.open{display:block}

/* MODAL */
.modal{position:fixed;inset:0;z-index:400;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none;opacity:0;transition:opacity .2s}
.modal.open{pointer-events:all;opacity:1}
.modal-box{background:var(--n0);border-radius:var(--r-xl);width:100%;max-width:480px;overflow:hidden;transform:translateY(20px);transition:transform .25s}
.modal.open .modal-box{transform:translateY(0)}
.modal-head{padding:22px 24px;border-bottom:1px solid var(--n200);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-family:var(--font-display);font-size:17px;font-weight:700;color:var(--n900)}
.modal-close{background:none;border:none;cursor:pointer;color:var(--n500);font-size:20px;padding:4px;border-radius:var(--r-sm);transition:all .15s}
.modal-close:hover{background:var(--n100);color:var(--n900)}
.modal-body{padding:24px}
.modal-foot{padding:18px 24px;border-top:1px solid var(--n200);display:flex;align-items:center;justify-content:flex-end;gap:10px}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-top:1px solid var(--n200);flex-wrap:wrap;gap:10px}
.pag-info{font-size:13px;color:var(--n500)}
.pag-btns{display:flex;gap:6px}
.pag-btn{min-width:34px;height:34px;border-radius:var(--r-md);border:1px solid var(--n200);background:var(--n0);cursor:pointer;font-family:var(--font-body);font-size:13px;font-weight:500;color:var(--n700);display:flex;align-items:center;justify-content:center;transition:all .15s;text-decoration:none;padding:0 10px}
.pag-btn:hover{border-color:var(--p300);color:var(--p700)}
.pag-btn.active{background:var(--p600);border-color:var(--p600);color:var(--n0)}
.pag-btn:disabled{opacity:.4;cursor:not-allowed}

/* PLAN BANNER */
.plan-banner{display:flex;align-items:center;gap:12px;background:var(--p50);border:1px solid var(--p100);border-radius:var(--r-lg);padding:14px 18px;margin-bottom:24px}
.plan-banner i{font-size:20px;color:var(--p600);flex-shrink:0}
.plan-banner-text{flex:1;font-size:13px;color:var(--p800)}
.plan-banner-text strong{font-weight:700}

/* RESPONSIVE */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-close{display:block}
  .dash-main{margin-left:0}
  .sidebar-toggle{display:flex}
  .overlay.open{display:block}
  .topbar{padding:0 16px}
  .dash-content{padding:16px}
  .stat-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:480px){
  .stat-grid{grid-template-columns:1fr}
  .table-head{flex-direction:column;align-items:flex-start}
  .search-wrap input{width:100%}
  .search-wrap input:focus{width:100%}
}
</style>
</head>
<body>

<!-- PRELOADER -->
<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
</div>

<!-- OVERLAY (mobile) -->
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <a href="/dashboard.php" class="logo">Kw<span class="acc">ee</span>k</a>
    <button class="sidebar-close" onclick="closeSidebar()"><i class="ti ti-x"></i></button>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <a href="/dashboard.php" class="nav-link <?= ($activePage??'') === 'dashboard' ? 'active' : '' ?>">
      <i class="ti ti-layout-dashboard"></i> Dashboard
    </a>
    <a href="/payment-links.php" class="nav-link <?= ($activePage??'') === 'links' ? 'active' : '' ?>">
      <i class="ti ti-link"></i> Payment Links
    </a>
    <a href="/orders.php" class="nav-link <?= ($activePage??'') === 'orders' ? 'active' : '' ?>">
      <i class="ti ti-shopping-cart"></i> Orders
      <?php
        $pendingOrders = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending_confirmation'");
        $pendingOrders->execute([$user['id']]);
        $pendingCount = (int)$pendingOrders->fetchColumn();
        if ($pendingCount > 0): ?>
        <span class="nav-badge"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="/transactions.php" class="nav-link <?= ($activePage??'') === 'transactions' ? 'active' : '' ?>">
      <i class="ti ti-receipt"></i> Transactions
    </a>
    <div class="nav-section-label">Finance</div>
    <a href="/withdraw.php" class="nav-link <?= ($activePage??'') === 'withdraw' ? 'active' : '' ?>">
      <i class="ti ti-cash"></i> Withdraw
    </a>
    <div class="nav-section-label">Account</div>
    <a href="/kyc.php" class="nav-link <?= ($activePage??'') === 'kyc' ? 'active' : '' ?>">
      <i class="ti ti-id-badge"></i> KYC Verification
      <?php if (!$user['bvn_verified']): ?>
        <span class="nav-badge" style="background:var(--warning)">!</span>
      <?php endif; ?>
    </a>
    <a href="/settings.php" class="nav-link <?= ($activePage??'') === 'settings' ? 'active' : '' ?>">
      <i class="ti ti-settings"></i> Settings
    </a>
  </nav>
  <div class="sidebar-bottom">
    <a href="/settings.php" class="user-row" style="text-decoration:none">
      <div class="user-av"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
        <div class="user-plan"><?= $user['plan'] ?> plan</div>
      </div>
      <i class="ti ti-chevron-right user-arrow"></i>
    </a>
    <a href="/logout.php" class="nav-link" style="color:var(--danger);margin-top:4px">
      <i class="ti ti-logout"></i> Sign out
    </a>
  </div>
</aside>

<!-- MAIN -->
<main class="dash-main">
  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" onclick="openSidebar()"><i class="ti ti-menu-2"></i></button>
      <span class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></span>
    </div>
    <div class="topbar-right">
      <a href="/notifications.php" class="topbar-btn" title="Notifications">
        <i class="ti ti-bell"></i>
        <?php if ($notifCount > 0): ?><div class="notif-dot"></div><?php endif; ?>
      </a>
      <a href="/create-link.php" class="btn btn-purple btn-sm">
        <i class="ti ti-plus"></i> New link
      </a>
    </div>
  </header>
  <div class="dash-content">
    <?= $flash ?>
    <?php if (!$user['bvn_verified']): ?>
    <div class="plan-banner">
      <i class="ti ti-alert-circle"></i>
      <span class="plan-banner-text"><strong>Verify your BVN</strong> to unlock higher transaction limits and enable withdrawals. <a href="/kyc.php" style="color:var(--p700);font-weight:700">Verify now →</a></span>
    </div>
    <?php endif; ?>