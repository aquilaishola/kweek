<?php
// admin/includes/header.php
$admin = currentAdmin();
$flash = flashHtml();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — Kweek Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p50:#F0EFFE;--p100:#DCD9FC;--p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p700:#3D33B8;--p800:#2E2690;--p900:#1C1660;
  --n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n300:#C9C8D8;
  --n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n700:#302E45;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#16A34A;--success-bg:#DCFCE7;--danger:#DC2626;--danger-bg:#FEE2E2;--warning:#D97706;--warning-bg:#FEF3C7;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
  --sidebar-w:240px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n50);color:var(--n800);-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* PRELOADER */
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:24px;font-weight:800;color:var(--n0);letter-spacing:-.5px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:40px;height:40px;border-radius:50%;border:3px solid var(--n800);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--n900);z-index:300;display:flex;flex-direction:column;transition:transform .3s}
.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--n800)}
.admin-logo{display:flex;align-items:center;gap:8px}
.logo-mark{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n0);letter-spacing:-.5px}
.logo-mark .acc{color:var(--p400)}
.admin-badge{font-size:10px;font-weight:700;background:var(--p800);color:var(--p300);padding:2px 8px;border-radius:var(--r-full);letter-spacing:.5px;text-transform:uppercase}

.sidebar-nav{flex:1;padding:12px 10px;overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.nav-section{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--n600);padding:10px 10px 5px;margin-top:6px}
.nav-link{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:var(--r-md);text-decoration:none;color:var(--n400);font-size:13px;font-weight:500;transition:all .15s}
.nav-link i{font-size:17px;flex-shrink:0}
.nav-link:hover{background:var(--n800);color:var(--n0)}
.nav-link.active{background:var(--p800);color:var(--n0)}
.nav-link.active i{color:var(--p300)}
.nav-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:var(--r-full)}

.sidebar-bottom{padding:12px 10px;border-top:1px solid var(--n800)}
.admin-user{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:var(--r-md)}
.admin-av{width:32px;height:32px;border-radius:50%;background:var(--p700);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.admin-name{font-size:12px;font-weight:600;color:var(--n300)}
.admin-role{font-size:10px;color:var(--n600);text-transform:capitalize}

/* MAIN */
.admin-main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.topbar{background:var(--n0);border-bottom:1px solid var(--n200);height:60px;display:flex;align-items:center;padding:0 24px;justify-content:space-between;position:sticky;top:0;z-index:200}
.page-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--n900);letter-spacing:-.3px}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-tag{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:var(--danger);background:var(--danger-bg);padding:5px 12px;border-radius:var(--r-full)}

.admin-content{flex:1;padding:24px}

/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--r-lg);margin-bottom:18px;font-size:13px;font-weight:500}
.flash i{font-size:17px;flex-shrink:0}
.flash-success{background:var(--success-bg);color:var(--success);border:1px solid #BBF7D0}
.flash-error{background:var(--danger-bg);color:var(--danger);border:1px solid #FECACA}
.flash-info{background:var(--p50);color:var(--p700);border:1px solid var(--p100)}

/* CARDS */
.card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:22px}
.card-title{font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--n900);margin-bottom:3px}
.card-sub{font-size:13px;color:var(--n500)}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:24px}
.stat-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:20px;transition:border-color .2s}
.stat-card:hover{border-color:var(--p200)}
.stat-icon{width:42px;height:42px;border-radius:var(--r-md);background:var(--p50);display:flex;align-items:center;justify-content:center;color:var(--p600);font-size:19px;margin-bottom:12px}
.stat-label{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--n400);margin-bottom:5px}
.stat-value{font-family:var(--font-display);font-size:24px;font-weight:800;color:var(--n900);letter-spacing:-.5px}

/* TABLE */
.table-wrap{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);overflow-x:auto;-webkit-overflow-scrolling:touch}
table{min-width:680px}
.table-head{padding:16px 20px;border-bottom:1px solid var(--n200);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.table-title{font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--n900)}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--n50)}
th{padding:10px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--n400);border-bottom:1px solid var(--n200)}
td{padding:13px 16px;font-size:13px;color:var(--n700);border-bottom:1px solid var(--n100)}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--n50)}
.td-bold{font-weight:600;color:var(--n900)}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:var(--r-full);text-transform:capitalize}
.badge-success{background:var(--success-bg);color:var(--success)}
.badge-danger{background:var(--danger-bg);color:var(--danger)}
.badge-warning{background:var(--warning-bg);color:var(--warning)}
.badge-purple{background:var(--p50);color:var(--p700)}
.badge-neutral{background:var(--n100);color:var(--n600)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;font-family:var(--font-body);font-weight:600;cursor:pointer;border:none;border-radius:var(--r-md);text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-sm{font-size:12px;padding:6px 12px}
.btn-md{font-size:13px;padding:9px 18px}
.btn-purple{background:var(--p600);color:#fff}
.btn-purple:hover{background:var(--p700)}
.btn-outline{background:transparent;color:var(--n700);border:1.5px solid var(--n200)}
.btn-outline:hover{border-color:var(--p300);color:var(--p600)}
.btn-danger{background:var(--danger);color:#fff}
.btn-success{background:var(--success);color:#fff}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-label{font-size:12px;font-weight:600;color:var(--n600)}
.form-input,.form-select{font-family:var(--font-body);font-size:13px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:9px 12px;outline:none;transition:all .2s;width:100%}
.form-input:focus,.form-select:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 3px var(--p50)}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--n200);flex-wrap:wrap;gap:8px}
.pag-info{font-size:12px;color:var(--n500)}
.pag-btns{display:flex;gap:4px}
.pag-btn{min-width:30px;height:30px;border-radius:var(--r-sm);border:1px solid var(--n200);background:var(--n0);cursor:pointer;font-family:var(--font-body);font-size:12px;font-weight:500;color:var(--n700);display:flex;align-items:center;justify-content:center;transition:all .15s;text-decoration:none;padding:0 8px}
.pag-btn:hover{border-color:var(--p300);color:var(--p600)}
.pag-btn.active{background:var(--p600);border-color:var(--p600);color:#fff}

/* MODAL */
.modal{position:fixed;inset:0;z-index:500;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none;opacity:0;transition:opacity .2s;background:rgba(0,0,0,.5)}
.modal.open{pointer-events:all;opacity:1}
.modal-box{background:var(--n0);border-radius:var(--r-xl);width:100%;max-width:480px;transform:translateY(16px);transition:transform .25s}
.modal.open .modal-box{transform:translateY(0)}
.modal-head{padding:18px 20px;border-bottom:1px solid var(--n200);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--n900)}
.modal-close{background:none;border:none;cursor:pointer;color:var(--n500);font-size:18px;padding:4px;border-radius:var(--r-sm)}
.modal-close:hover{background:var(--n100)}
.modal-body{padding:20px}
.modal-foot{padding:16px 20px;border-top:1px solid var(--n200);display:flex;align-items:center;justify-content:flex-end;gap:8px}

.search-inp{font-family:var(--font-body);font-size:13px;background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 12px 7px 32px;outline:none;transition:all .2s;width:200px}
.search-inp:focus{border-color:var(--p400);background:var(--n0);width:240px}

.sidebar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--n600);font-size:20px;padding:6px}

.admin-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:299;display:none}
.admin-overlay.open{display:block}

@media(max-width:700px){
  .stat-grid{grid-template-columns:repeat(2,1fr)!important;gap:14px!important}
}
@media(max-width:480px){
  .stat-grid{grid-template-columns:1fr!important}
}
.stat-value{overflow-wrap:anywhere;word-break:break-word}

.admin-dash-grid{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
@media(max-width:900px){.admin-dash-grid{grid-template-columns:1fr}}

.modal-box{max-height:90vh;overflow-y:auto}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .admin-main{margin-left:0}
  .admin-content{padding:16px}
  .sidebar-toggle{display:flex}
}
</style>
</head>
<body>

<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
</div>

<!-- SIDEBAR -->
<div class="admin-overlay" id="admin-overlay" onclick="toggleAdminSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="admin-logo">
      <span class="logo-mark">Kw<span class="acc">ee</span>k</span>
      <span class="admin-badge">Admin</span>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Overview</div>
    <a href="/admin/" class="nav-link <?= ($activePage??'')==='dashboard'?'active':'' ?>"><i class="ti ti-layout-dashboard"></i> Dashboard</a>

    <div class="nav-section">Merchants</div>
    <a href="/admin/merchants.php" class="nav-link <?= ($activePage??'')==='merchants'?'active':'' ?>"><i class="ti ti-users"></i> All Merchants</a>
  <a href="/admin/merchants.php?bvn=0" class="nav-link <?= ($activePage??'')==='pending_merchants'?'active':'' ?>"><i class="ti ti-user-check"></i> KYC Pending</a>

    <div class="nav-section">Finance</div>
    <a href="/admin/transactions.php" class="nav-link <?= ($activePage??'')==='transactions'?'active':'' ?>"><i class="ti ti-receipt"></i> Transactions</a>
    <a href="/admin/orders.php" class="nav-link <?= ($activePage??'')==='orders'?'active':'' ?>"><i class="ti ti-shopping-cart"></i> Orders</a>
    <a href="/admin/withdrawals.php" class="nav-link <?= ($activePage??'')==='withdrawals'?'active':'' ?>">
      <i class="ti ti-cash"></i> Withdrawals
      <?php
        $pendingW = db()->prepare("SELECT COUNT(*) FROM withdrawals WHERE status='pending'");
        $pendingW->execute();
        $wc = (int)$pendingW->fetchColumn();
        if ($wc > 0): ?><span class="nav-badge"><?= $wc ?></span><?php endif;
      ?>
    </a>

    <div class="nav-section">System</div>
    <a href="/admin/settings.php" class="nav-link <?= ($activePage??'')==='settings'?'active':'' ?>"><i class="ti ti-settings"></i> Settings</a>
    <a href="/admin/webhooks.php" class="nav-link <?= ($activePage??'')==='webhooks'?'active':'' ?>"><i class="ti ti-webhook"></i> Webhook Logs</a>
  </nav>
  <div class="sidebar-bottom">
    <div class="admin-user">
      <div class="admin-av"><?= strtoupper(substr($admin['name']??'A',0,2)) ?></div>
      <div>
        <div class="admin-name"><?= htmlspecialchars($admin['name']??'Admin') ?></div>
        <div class="admin-role"><?= htmlspecialchars(str_replace('_',' ',$admin['role']??'')) ?></div>
      </div>
    </div>
    <a href="/admin/logout.php" class="nav-link" style="color:var(--danger);margin-top:4px"><i class="ti ti-logout"></i> Sign out</a>
  </div>
</aside>

<script>
function toggleAdminSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('admin-overlay').classList.toggle('open');
}
</script>

<!-- MAIN -->
<main class="admin-main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
     <button onclick="toggleAdminSidebar()" class="sidebar-toggle" id="mob-toggle"><i class="ti ti-menu-2"></i></button>
      <span class="page-title"><?= htmlspecialchars($pageTitle??'Admin') ?></span>
    </div>
    <div class="topbar-right">
      <div class="topbar-tag"><i class="ti ti-shield-lock"></i> Admin Panel</div>
      <a href="/" target="_blank" class="btn btn-outline btn-sm"><i class="ti ti-external-link"></i> View Site</a>
    </div>
  </header>
  <div class="admin-content">
    <?= $flash ?>
