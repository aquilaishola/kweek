<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requireCapability('manage_team');

$pageTitle  = 'Team';
$activePage = 'team';
$user       = currentUser();

include __DIR__ . '/includes/header.php';
?>

<style>
.tm-dev-wrap{max-width:560px;margin:40px auto 0}
.tm-dev-card{background:linear-gradient(135deg,#2A2270 0%,#4F43D4 65%,#6457E8 100%);border-radius:var(--r-lg);padding:44px 30px;text-align:center;color:#fff}
.tm-dev-card .ic{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 18px}
.tm-dev-card h2{font-family:var(--font-display);font-size:21px;font-weight:800;margin-bottom:10px;letter-spacing:-.3px}
.tm-dev-card p{font-size:14px;color:rgba(255,255,255,.8);max-width:400px;margin:0 auto 22px;line-height:1.7}
.tm-dev-badge{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;background:rgba(255,255,255,.15);padding:6px 14px;border-radius:var(--r-full);margin-bottom:18px}
.tm-dev-list{text-align:left;background:rgba(255,255,255,.08);border-radius:var(--r-md);padding:16px 18px;margin-top:6px;font-size:13px;line-height:2;color:rgba(255,255,255,.9)}
.tm-dev-list i{margin-right:8px;color:#B9F5CD}
</style>

<div class="tm-dev-wrap">
  <div class="tm-dev-card">
    <div class="tm-dev-badge"><i class="ti ti-tools"></i> In Development</div>
    <div class="ic"><i class="ti ti-users"></i></div>
    <h2>Team is getting a rebuild</h2>
    <p>We're reworking Team members — roles, invites, and seat management — alongside the new Chats and checkout customization features. It'll be back shortly, better than before.</p>
    <div class="tm-dev-list">
      <div><i class="ti ti-circle-check"></i> Invite teammates by email, with role-based access</div>
      <div><i class="ti ti-circle-check"></i> Viewer, Manager, and Admin permission levels</div>
      <div><i class="ti ti-circle-check"></i> Seat limits tied to your Pro / Business plan</div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
