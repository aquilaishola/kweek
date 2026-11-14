<?php

$pageTitle  = $pageTitle  ?? 'Kweek';
$activePage = $activePage ?? '';

require_once __DIR__ . '/chat_helpers.php';

$flash = function_exists('flashHtml') ? flashHtml() : '';

// unread notifications (bell badge + dropdown)
$unreadCount = 0;
$notifs      = [];
if (!empty($user['id'])) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unreadCount = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
    $stmt->execute([$user['id']]);
    $notifs = $stmt->fetchAll();
}

// pending orders (badge on the Orders nav link)
$pendingOrdersCount = 0;
if (!empty($user['id'])) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending_confirmation'");
    $stmt->execute([$user['id']]);
    $pendingOrdersCount = (int) $stmt->fetchColumn();
}

// unread live-chat messages (badge on the Chats nav link)
$chatUnreadCount = 0;
if (!empty($user['id'])) {
    $chatUnreadCount = chatUnreadCountForMerchant(db(), (int)$user['id']);
}

// trial days remaining
$trialDaysLeft = null;
if (!empty($user['trial_ends_at'])) {
    $diff = (strtotime($user['trial_ends_at']) - time()) / 86400;
    $trialDaysLeft = max(0, (int) ceil($diff));
}

$planChipDaysLeft = null;
$planChipIsTrial  = false;
if (!empty($user['id']) && ($user['plan'] ?? 'free') !== 'free') {
    $chipSub = db()->prepare("SELECT status, ends_at FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1");
    $chipSub->execute([$user['id']]);
    $chipRow = $chipSub->fetch();
    if ($chipRow && !empty($chipRow['ends_at'])) {
        $planChipIsTrial  = $chipRow['status'] === 'trial';
        $chipDiff          = (strtotime($chipRow['ends_at']) - time()) / 86400;
        $planChipDaysLeft  = max(0, (int) ceil($chipDiff));
    }
}

$viewer = sessionUser();
$onTeam = $viewer && isTeamMember($viewer);

$initials = '';
if (!empty($viewer['name'])) {
    $parts = preg_split('/\s+/', trim($viewer['name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$plan    = $user['plan'] ?? 'free';
$orgName = $user['business_name'] ?? $user['name'] ?? 'My business';
$orgInit = strtoupper(mb_substr(trim($orgName), 0, 1)) ?: 'K';

// "Get started" progress pill: verify BVN → create a link → get first payment
$setup = ['bvn' => !empty($user['bvn_verified']), 'link' => false, 'paid' => false];
if (!empty($user['id'])) {
    $q = db()->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id = ?");
    $q->execute([$user['id']]); $setup['link'] = (int)$q->fetchColumn() > 0;
    $q = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('paid','completed')");
    $q->execute([$user['id']]); $setup['paid'] = (int)$q->fetchColumn() > 0;
}
$setupDone = count(array_filter($setup));
$setupPct  = (int) round($setupDone / 3 * 100);
$setupNext = !$setup['bvn'] ? '/kyc.php' : (!$setup['link'] ? '/create-link.php' : '/payment-links.php');
$setupTip  = !$setup['bvn'] ? 'Next: verify your BVN' : (!$setup['link'] ? 'Next: create your first payment link' : 'Next: share your link and get paid');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Kweek</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/kweek-icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/kweek-icon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="/assets/img/kweek-icon-192.png">
<meta name="theme-color" content="#ffffff">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ===== Bachs-style shell (overrides app.css) ===== */
.app-shell{--bx-line:#ECEAF1;--bx-mut:#8B8999;--bx-soft:#F6F6F8;--bx-ink:#15131F;--bx-p:#4F43D4;display:flex;min-height:100vh;background:#fff;color:var(--bx-ink)}
.app-shell .app-sidebar{position:sticky;top:0;height:100vh;width:252px;flex:none;background:var(--bx-soft);border-right:1px solid var(--bx-line);padding:16px 12px 14px;display:flex;flex-direction:column;overflow-y:auto;color:var(--bx-ink)}
.app-shell.bx-collapsed .app-sidebar{display:none}
.app-shell .app-main{flex:1;min-width:0;margin-left:0!important;width:auto;background:#fff}
.bx-brand-row{display:flex;align-items:center;justify-content:space-between;padding:0 6px;margin-bottom:14px}
.bx-brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--bx-ink);font-weight:700;font-size:19px;letter-spacing:-.4px}
.bx-brand span.m{width:28px;height:28px;border-radius:8px;background:var(--bx-p);display:flex;align-items:center;justify-content:center}
.bx-brand svg{width:17px;height:17px}
.bx-org{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--bx-line);border-radius:14px;padding:9px 12px;text-decoration:none;color:var(--bx-ink);margin-bottom:18px;transition:border-color .2s,box-shadow .2s}
.bx-org:hover{border-color:#d3cfe3;box-shadow:0 6px 18px -10px rgba(21,19,31,.25)}
.bx-org-av{width:28px;height:28px;border-radius:8px;background:var(--bx-soft);border:1px solid var(--bx-line);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex:none}
.bx-org-name{flex:1;min-width:0;font-weight:600;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bx-org i{color:var(--bx-mut);font-size:16px}
.app-shell .side-nav{display:flex;flex-direction:column;gap:3px;margin-bottom:0}
.app-shell .side-link{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:11px;color:#4B4A5A;font-weight:500;font-size:14.5px;text-decoration:none;transition:background .15s,color .15s}
.app-shell .side-link i{font-size:20px;color:#6E6D80;transition:color .15s}
.app-shell .side-link:hover{background:rgba(21,19,31,.05);color:var(--bx-ink)}
.app-shell .side-link.active{background:#ECECF1;color:var(--bx-ink);font-weight:600}
.app-shell .side-link.active i{color:var(--bx-p)}
.nav-badge-warn{margin-left:auto;background:var(--bx-p);color:#fff;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:9999px}
.app-shell .side-bottom{margin-top:auto;display:flex;flex-direction:column;gap:3px;padding-top:14px;border-top:1px solid var(--bx-line)}
.app-shell .side-plan{background:#fff;border:1px solid var(--bx-line);border-radius:14px;padding:12px 13px;margin-bottom:8px}
.app-shell .sp-top{display:flex;justify-content:space-between;font-size:12.5px;font-weight:600;color:var(--bx-ink)}
.app-shell .sp-days{color:var(--bx-mut);font-weight:500}
.app-shell .sp-progress-track{height:6px;background:#ECEAF1;border-radius:9px;margin:10px 0;overflow:hidden}
.app-shell .sp-progress-fill{height:100%;background:var(--bx-p);border-radius:9px;transition:width .6s}
.app-shell .sp-progress-fill.is-low{background:#D97706}
.app-shell .sp-upgrade-btn{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--bx-p);text-decoration:none;background:var(--purple-50,#F2F0FC);border-radius:9px;padding:8px}
.app-shell .side-user-row-wrap{display:flex;align-items:center;gap:4px;margin-top:8px}
.app-shell .side-user-row{display:flex;align-items:center;gap:10px;padding:8px;border-radius:12px;text-decoration:none;flex:1;min-width:0;transition:background .15s}
.app-shell .side-user-row:hover{background:rgba(21,19,31,.05)}
.app-shell .side-user-av{width:34px;height:34px;border-radius:50%;background:var(--bx-p);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:700;flex:none}
.side-user-info{flex:1;min-width:0}
.app-shell .side-user-name{font-size:13px;font-weight:600;color:var(--bx-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.app-shell .side-user-plan{font-size:11.5px;color:var(--bx-mut);text-transform:capitalize}
.side-signout-btn{display:flex;align-items:center;justify-content:center;width:34px;height:34px;flex:none;border-radius:10px;color:#B42318;font-size:18px;text-decoration:none;transition:background .15s}
.side-signout-btn:hover{background:#FDECEA}
.side-close{display:none;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;background:#fff;border:1px solid var(--bx-line);color:var(--bx-ink);font-size:17px;cursor:pointer}

/* topbar */
.app-shell .app-topbar{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 28px;background:rgba(255,255,255,.88);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:0;box-shadow:none}
.bx-tl{display:flex;align-items:center;gap:12px;min-width:0;flex:1}
.sidebar-toggle{display:flex;align-items:center;justify-content:center;background:none;border:0;cursor:pointer;color:#55546A;font-size:22px;padding:6px;border-radius:9px;transition:background .15s}
.sidebar-toggle:hover{background:var(--bx-soft)}
.topbar-title{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;font-size:17px;letter-spacing:-.02em}
.topbar-actions{display:flex;align-items:center;gap:10px;flex:none}
.bx-search{display:flex;align-items:center;gap:9px;background:var(--bx-soft);border:1px solid var(--bx-line);border-radius:13px;padding:0 12px;height:40px;width:280px;transition:border-color .2s,box-shadow .2s,width .25s}
.bx-search:focus-within{border-color:var(--bx-p);box-shadow:0 0 0 4px #F2F0FC;background:#fff;width:340px}
.bx-search i{color:var(--bx-mut);font-size:17px}
.bx-search input{flex:1;min-width:0;border:0;background:none;outline:0;font:inherit;font-size:14px;color:var(--bx-ink)}
.bx-search input::placeholder{color:var(--bx-mut)}
.bx-search kbd{font:500 11.5px var(--font-body,inherit);color:var(--bx-mut);background:#fff;border:1px solid var(--bx-line);border-radius:6px;padding:1px 6px}
.bx-fb{display:inline-flex;align-items:center;height:40px;padding:0 18px;border:1px solid var(--bx-line);border-radius:13px;font-size:14.5px;font-weight:500;color:var(--bx-ink);text-decoration:none;background:#fff;transition:border-color .2s,transform .2s}
.bx-fb:hover{border-color:#bdb8d6;transform:translateY(-1px)}
.app-shell .icon-btn{width:40px;height:40px;border:1px solid var(--bx-line);border-radius:13px;background:#fff;color:var(--bx-ink);display:flex;align-items:center;justify-content:center;font-size:19px;cursor:pointer;position:relative;flex:none;transition:border-color .2s,transform .2s}
.app-shell .icon-btn:hover{border-color:#bdb8d6;transform:translateY(-1px)}
.app-shell .topbar-avatar{width:38px;height:38px;border-radius:50%;background:var(--bx-p);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;text-decoration:none;flex:none;transition:transform .2s}
.app-shell .topbar-avatar:hover{transform:scale(1.06)}
.app-shell .app-content{padding:6px 28px 64px;max-width:1360px;width:100%}
body.bx-hide .bx-amt{filter:blur(9px);user-select:none;transition:filter .2s}

.app-overlay{position:fixed;inset:0;background:rgba(15,14,28,.5);z-index:290;display:none}
.app-overlay.open{display:block}
@media (max-width:960px){
  .app-shell .app-sidebar{display:flex!important;position:fixed;top:0;left:0;z-index:300;height:100dvh;width:272px;max-width:84vw;transform:translateX(-100%);transition:transform .28s ease;overflow-y:auto;-webkit-overflow-scrolling:touch}
  .app-shell .app-sidebar.open{transform:none;box-shadow:20px 0 50px -20px rgba(0,0,0,.35)}
  .side-close{display:flex}
  .app-shell .app-topbar{padding:12px 16px}
  .app-shell .app-content{padding:4px 16px 56px}
  .bx-search{width:210px}.bx-search:focus-within{width:210px}.bx-search kbd{display:none}
}
@media (max-width:760px){.bx-search,.bx-fb,.bx-gs{display:none}.topbar-actions{gap:8px}}
@media (max-width:600px){.notif-pop{position:fixed!important;top:64px;right:10px;left:10px;width:auto;max-width:none}}

/* ===== make every other page match the new shell ===== */
:root{--paper:#fff;--surface:#F6F6F8;--line:#ECEAF1;--r-lg:18px}
.app-shell .card,.app-shell .table-wrap,.app-shell .stat-card{background:#fff;border:1px solid var(--bx-line);border-radius:20px;box-shadow:none}
.app-shell .table-perf{display:none}
.app-shell .btn{border-radius:12px}
.app-shell .badge{border-radius:99px}
.app-shell .app-content h1{letter-spacing:-.03em}

/* plan card: readable text */
.app-shell .side-plan{background:#fff;border:1px solid #E1DDEC;box-shadow:0 1px 2px rgba(21,19,31,.04)}
.app-shell .side-plan .sp-name{color:#15131F!important;font-weight:700;font-size:13px}
.app-shell .side-plan .sp-days{color:#4B4A5A!important;font-weight:600;font-size:12.5px}
.app-shell .sp-progress-track{background:#E4E1EE}
.app-shell .sp-upgrade-btn{background:var(--bx-p)!important;color:#fff!important;font-weight:600;padding:9px;transition:background .2s}
.app-shell .sp-upgrade-btn:hover{background:#3D33B8!important}
.app-shell .side-user-plan{color:#6B6A7B}
.app-shell .side-user-name{color:#15131F}

/* get started pill */
.bx-gs{display:inline-flex;align-items:center;gap:9px;height:40px;padding:0 14px;border:1px solid var(--bx-line);border-radius:13px;background:#fff;font-size:14px;font-weight:500;color:var(--bx-ink);text-decoration:none;transition:border-color .2s,transform .2s}
.bx-gs:hover{border-color:#bdb8d6;transform:translateY(-1px)}
.bx-ring{width:18px;height:18px;border-radius:50%;background:conic-gradient(var(--bx-p) calc(var(--pct)*1%),#E4E1EE 0);display:grid;place-items:center;flex:none}
.bx-ring::after{content:'';width:12px;height:12px;border-radius:50%;background:#fff}

/* ===== flash, forms, modal, buttons (unchanged behaviour) ===== */
.flash{display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:var(--r-lg);margin-bottom:20px;font-size:13.5px;font-weight:500}
.flash i{font-size:17px;flex-shrink:0}
.flash-success{background:var(--success-bg);color:var(--success);border:1px solid rgba(30,158,90,.25)}
.flash-error{background:var(--danger-bg);color:var(--danger);border:1px solid rgba(220,38,38,.2)}
.flash-warning{background:var(--warning-bg);color:var(--warning);border:1px solid rgba(217,119,6,.25)}
.flash-info{background:var(--purple-50);color:var(--purple-600);border:1px solid var(--purple-100)}
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.form-label{font-size:12.5px;font-weight:600;color:var(--ink);letter-spacing:.2px}
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--muted-2);pointer-events:none}
.form-input-wrap .form-input{padding-left:38px}
.form-input,.form-select,.form-textarea{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;color:var(--ink);background:var(--surface);border:1.5px solid transparent;border-bottom:1.5px solid var(--line);border-radius:var(--r-sm);padding:11px 14px;outline:none;transition:all .15s}
.form-input::placeholder,.form-textarea::placeholder{color:var(--muted-2)}
.form-input:focus,.form-select:focus,.form-textarea:focus{background:var(--paper);border-color:var(--purple-500);box-shadow:0 0 0 4px var(--purple-50)}
.form-input:disabled,.form-select:disabled,.form-textarea:disabled{opacity:.6;cursor:not-allowed}
.form-input.is-valid{border-color:var(--success)}.form-input.is-invalid{border-color:var(--danger)}
.form-hint{font-size:11.5px;color:var(--muted);margin-top:2px}
.form-textarea{resize:vertical;min-height:88px}
.form-select{cursor:pointer;appearance:none;padding-right:32px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23726E85' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;padding:20px;background:rgba(15,14,28,.55);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.modal.open{display:flex}
.modal-box{background:var(--paper);border-radius:var(--r-lg);width:100%;max-width:480px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(0,0,0,.45);animation:kwModalIn .18s ease}
@keyframes kwModalIn{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);flex-shrink:0}
.modal-title{font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--ink)}
.modal-close{background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;padding:5px;border-radius:var(--r-sm);transition:background .15s,color .15s;line-height:1}
.modal-close:hover{background:var(--surface);color:var(--ink)}
.modal-body{padding:22px;overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch}
.modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid var(--line);flex-shrink:0}
@media (max-width:480px){.modal-box{max-width:100%}.modal-foot{flex-direction:column-reverse}.modal-foot .btn{width:100%;justify-content:center}}
.table-wrap{overflow-x:auto}
.btn-success{background:var(--success);color:#fff}.btn-success:hover{background:#178A4F}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#B91C1C}
.btn-white{background:#fff;color:var(--purple-600)}.btn-white:hover{background:var(--purple-50)}
@keyframes spin{to{transform:rotate(360deg)}}
#flash-wrap{margin-bottom:10px}
</style>
</head>
<body>

<?php include __DIR__ . '/../partials/preloader.php'; ?>

<div class="app-overlay" id="app-overlay" onclick="kwCloseSidebar()"></div>

<script>
function kwOpenSidebar(){document.getElementById('app-sidebar').classList.add('open');document.getElementById('app-overlay').classList.add('open')}
function kwCloseSidebar(){document.getElementById('app-sidebar').classList.remove('open');document.getElementById('app-overlay').classList.remove('open')}
function kwToggleSidebar(){
  if(window.innerWidth>960){
    const c=document.querySelector('.app-shell').classList.toggle('bx-collapsed');
    try{localStorage.setItem('kwSide',c?'1':'0')}catch(e){}
  }else{kwOpenSidebar()}
}
window.addEventListener('resize',()=>{if(window.innerWidth>960)kwCloseSidebar()});

document.addEventListener('DOMContentLoaded',function(){
  try{
    if(localStorage.getItem('kwSide')==='1'&&innerWidth>960)document.querySelector('.app-shell').classList.add('bx-collapsed');
    if(localStorage.getItem('kwHide')==='1'){document.body.classList.add('bx-hide');const e=document.querySelector('#eye-btn i');if(e)e.className='ti ti-eye-off'}
  }catch(e){}
  const tt=document.querySelector('.topbar-title');if(tt&&document.querySelector('.app-content h1'))tt.style.display='none';
  const eb=document.getElementById('eye-btn');
  if(eb)eb.addEventListener('click',()=>{
    const h=document.body.classList.toggle('bx-hide');
    eb.firstElementChild.className=h?'ti ti-eye-off':'ti ti-eye';
    try{localStorage.setItem('kwHide',h?'1':'0')}catch(e){}
  });
  document.addEventListener('keydown',e=>{
    if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k'){const s=document.getElementById('bx-q');if(s){e.preventDefault();s.focus()}}
  });

  document.querySelectorAll('.flash').forEach(function(el){
    const b=document.createElement('button');
    b.innerHTML='<i class="ti ti-x"></i>';b.setAttribute('aria-label','Dismiss');
    b.style.cssText='margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:15px;padding:2px;flex-shrink:0;line-height:1';
    b.onclick=function(){dismissFlash(el)};el.appendChild(b);
    el._flashTimer=setTimeout(function(){dismissFlash(el)},5000);
  });
  function dismissFlash(el){
    if(!el||!el.parentNode)return;clearTimeout(el._flashTimer);
    el.style.overflow='hidden';el.style.maxHeight=el.offsetHeight+'px';
    el.style.transition='max-height .25s ease,opacity .2s ease,margin-bottom .25s ease,padding .25s ease';
    void el.offsetHeight;
    requestAnimationFrame(function(){el.style.maxHeight='0px';el.style.opacity='0';el.style.marginBottom='0px';el.style.paddingTop='0px';el.style.paddingBottom='0px'});
    el.addEventListener('transitionend',function h(){el.removeEventListener('transitionend',h);el.remove()});
  }
});

function openModal(id){const m=document.getElementById(id);if(!m)return;m.classList.add('open');document.body.style.overflow='hidden'}
function closeModal(id){const m=document.getElementById(id);if(!m)return;m.classList.remove('open');document.body.style.overflow=''}
document.addEventListener('click',function(e){document.querySelectorAll('.modal.open').forEach(function(m){if(e.target===m)closeModal(m.id)})});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.modal.open').forEach(function(m){closeModal(m.id)});kwCloseSidebar()}});
</script>

<div class="app-shell">

  <!-- SIDEBAR -->
  <aside class="app-sidebar" id="app-sidebar">
    <div class="bx-brand-row">
      <a href="/dashboard.php" class="bx-brand">
        <span class="m"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#fff"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></span>kweek
      </a>
      <button class="side-close" id="side-close" onclick="kwCloseSidebar()" aria-label="Close menu"><i class="ti ti-x"></i></button>
    </div>

    <a href="/settings.php" class="bx-org" title="Business settings">
      <span class="bx-org-av"><?= htmlspecialchars($orgInit) ?></span>
      <span class="bx-org-name"><?= htmlspecialchars($orgName) ?></span>
      <i class="ti ti-selector"></i>
    </a>

    <nav class="side-nav">
      <a href="/dashboard.php" class="side-link <?= $activePage === 'dashboard' ? 'active' : '' ?>"><i class="ti ti-home-2"></i> Home</a>
      <a href="/payment-links.php" class="side-link <?= $activePage === 'payment-links' ? 'active' : '' ?>"><i class="ti ti-link"></i> Payment Links</a>
      <a href="/orders.php" class="side-link <?= $activePage === 'orders' ? 'active' : '' ?>">
        <i class="ti ti-shopping-cart"></i> Orders
        <?php if ($pendingOrdersCount > 0): ?><span class="nav-badge-warn"><?= $pendingOrdersCount ?></span><?php endif; ?>
      </a>
      <a href="/customers.php" class="side-link <?= $activePage === 'customers' ? 'active' : '' ?>"><i class="ti ti-users-group"></i> Customers</a>
      <a href="/chats.php" class="side-link <?= $activePage === 'chats' ? 'active' : '' ?>">
        <i class="ti ti-message-circle-2"></i> Chats
        <?php if ($chatUnreadCount > 0): ?><span class="nav-badge-warn"><?= $chatUnreadCount ?></span><?php endif; ?>
      </a>
      <a href="/analytics.php" class="side-link <?= $activePage === 'analytics' ? 'active' : '' ?>"><i class="ti ti-chart-line"></i> Analytics</a>
      <a href="/transactions.php" class="side-link <?= $activePage === 'transactions' ? 'active' : '' ?>"><i class="ti ti-receipt"></i> Transactions</a>
      <a href="/withdraw.php" class="side-link <?= $activePage === 'withdraw' ? 'active' : '' ?>"><i class="ti ti-cash"></i> Withdraw</a>
    </nav>

    <div class="side-bottom">
<?php if ($plan === 'free'): ?>
      <div class="side-plan">
        <div class="sp-top"><span class="sp-name">Free plan</span></div>
        <div class="sp-progress-track"><div class="sp-progress-fill" style="width:12%"></div></div>
        <a href="/settings.php?tab=billing" class="sp-upgrade-btn"><i class="ti ti-sparkles"></i> Upgrade to Pro</a>
      </div>
<?php elseif ($planChipDaysLeft !== null): ?>
<?php $subPctLeft = max(0, min(100, (int) round(($planChipDaysLeft / 30) * 100))); ?>
      <div class="side-plan">
        <div class="sp-top">
          <span class="sp-name"><?= htmlspecialchars(ucfirst($plan)) ?> <?= $planChipIsTrial ? 'trial' : 'plan' ?></span>
          <span class="sp-days"><?= $planChipDaysLeft ?>d left</span>
        </div>
        <div class="sp-progress-track"><div class="sp-progress-fill <?= $planChipDaysLeft <= 3 ? 'is-low' : '' ?>" style="width:<?= $subPctLeft ?>%"></div></div>
        <?php if ($plan !== 'business'): ?><a href="/settings.php?tab=billing" class="sp-upgrade-btn"><i class="ti ti-arrow-up-circle"></i> Upgrade now</a><?php endif; ?>
      </div>
<?php endif; ?>
      <a href="/api-webhooks.php" class="side-link <?= $activePage === 'api-webhooks' ? 'active' : '' ?>"><i class="ti ti-code"></i> API &amp; Webhooks</a>
      <a href="/kyc.php" class="side-link <?= $activePage === 'kyc' ? 'active' : '' ?>">
        <i class="ti ti-id-badge"></i> KYC Verification
        <?php if (empty($user['bvn_verified'])): ?><span class="nav-badge-warn">!</span><?php endif; ?>
      </a>
      <a href="/settings.php" class="side-link <?= $activePage === 'settings' ? 'active' : '' ?>"><i class="ti ti-settings"></i> All Settings</a>

      <div class="side-user-row-wrap">
        <a href="/settings.php" class="side-user-row">
          <div class="side-user-av"><?= htmlspecialchars($initials ?: '?') ?></div>
          <div class="side-user-info">
            <div class="side-user-name"><?= htmlspecialchars($viewer['name'] ?? '') ?></div>
            <div class="side-user-plan">
              <?php if ($onTeam): ?>
                <?= htmlspecialchars(ucfirst(teamRole($viewer))) ?> · <?= htmlspecialchars($user['name'] ?? 'team') ?>'s account
              <?php else: ?>
                <?= htmlspecialchars(ucfirst($plan)) ?> plan
              <?php endif; ?>
            </div>
          </div>
        </a>
        <a href="/logout.php" class="side-signout-btn" title="Sign out" aria-label="Sign out"><i class="ti ti-logout"></i></a>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="app-main">
    <header class="app-topbar">
      <div class="bx-tl">
        <button class="sidebar-toggle" id="sidebar-toggle" onclick="kwToggleSidebar()" aria-label="Toggle sidebar"><i class="ti ti-layout-sidebar"></i></button>
        <?php if ($activePage !== 'dashboard'): ?><div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div><?php endif; ?>
      </div>
      <div class="topbar-actions">
<?php if ($setupDone < 3): ?>
        <a href="<?= $setupNext ?>" class="bx-gs" title="<?= htmlspecialchars($setupTip) ?>"><span class="bx-ring" style="--pct:<?= $setupPct ?>"></span>Get started <small style="color:#8B8999"><?= $setupDone ?>/3</small></a>
<?php endif; ?>
        <form class="bx-search" action="/receipt.php" method="GET" role="search">
          <i class="ti ti-search"></i>
          <input id="bx-q" type="text" name="ref" placeholder="Verify a receipt (ORD- or TXN-)" aria-label="Verify a receipt" autocomplete="off">
          <kbd>⌘K</kbd>
        </form>
        <a href="/contact.php" class="bx-fb">Feedback</a>
        <button class="icon-btn" id="eye-btn" type="button" aria-label="Hide or show amounts" title="Hide or show amounts"><i class="ti ti-eye"></i></button>
        <div style="position:relative">
          <button class="icon-btn" id="notif-btn" aria-label="Notifications">
            <i class="ti ti-bell"></i>
            <?php if ($unreadCount > 0): ?><span class="icon-dot"></span><?php endif; ?>
          </button>
          <div class="notif-pop" id="notif-pop">
            <div class="notif-head">
              <span>Notifications</span>
              <?php if ($unreadCount > 0): ?><a href="/notifications.php?read_all=1">Mark all read</a><?php endif; ?>
            </div>
            <?php if (empty($notifs)): ?>
              <div class="notif-empty">You're all caught up.</div>
            <?php else: ?>
              <?php foreach ($notifs as $n): ?>
              <a href="<?= htmlspecialchars($n['link'] ?: '#') ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                <div class="notif-ic"><i class="ti ti-<?= htmlspecialchars($n['icon'] ?: 'bell') ?>"></i></div>
                <div class="notif-body">
                  <div class="t"><?= htmlspecialchars($n['title']) ?></div>
                  <div class="s"><?= htmlspecialchars($n['message']) ?></div>
                  <span class="time"><?= function_exists('timeAgo') ? timeAgo($n['created_at']) : htmlspecialchars($n['created_at']) ?></span>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <a href="/settings.php" class="topbar-avatar"><?= htmlspecialchars($initials ?: '?') ?></a>
      </div>
    </header>

    <main class="app-content">
      <?php if ($flash): ?><div id="flash-wrap"><?= $flash ?></div><?php endif; ?>