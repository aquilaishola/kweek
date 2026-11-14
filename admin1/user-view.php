<?php
$pageTitle = 'Merchant Details';
$activePage = 'users';
require_once __DIR__ . '/includes/layout-top.php';

$uid = (int)get('id');
$stmt = $db->prepare("SELECT * FROM users WHERE id=? AND owner_user_id IS NULL LIMIT 1");
$stmt->execute([$uid]);
$merchant = $stmt->fetch();
if (!$merchant) { flash('error','Merchant not found.'); redirect('/admin/users.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'change_plan') {
        $newPlan = post('plan');
        if (in_array($newPlan, ['free','starter','pro','business'], true)) {
            $db->prepare("UPDATE users SET plan=? WHERE id=?")->execute([$newPlan, $uid]);
            flash('success', 'Plan updated to ' . ucfirst($newPlan) . '.');
        }
    } elseif ($action === 'toggle_status') {
        $new = $merchant['status'] === 'suspended' ? 'active' : 'suspended';
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $uid]);
        flash('success', 'Status set to ' . $new . '.');
    } elseif ($action === 'toggle_bvn') {
        $new = $merchant['bvn_verified'] ? 0 : 1;
        $db->prepare("UPDATE users SET bvn_verified=? WHERE id=?")->execute([$new, $uid]);
        flash('success', $new ? 'BVN marked verified.' : 'BVN verification revoked.');
    } elseif ($action === 'wallet_adjust') {
        $amount = (float)post('adjust_amount');
        $reason = clean(post('adjust_reason'));
        if ($amount != 0 && $reason !== '') {
            if ($amount > 0) {
                creditWallet($uid, $amount, 0, 'Admin adjustment: ' . $reason, generateRef('ADJ'));
            } else {
                debitWallet($uid, abs($amount), 'Admin adjustment: ' . $reason, generateRef('ADJ'));
            }
            flash('success', 'Wallet adjusted by ' . formatNaira($amount) . '.');
        } else {
            flash('error', 'Enter an amount and a reason for the adjustment.');
        }
    }
    redirect('/admin/user-view.php?id=' . $uid);
}

$kycStmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id=? LIMIT 1");
$kycStmt->execute([$uid]);
$kyc = $kycStmt->fetch();

$linksStmt = $db->prepare("SELECT id,title,slug,status,total_orders,total_revenue FROM payment_links WHERE user_id=? ORDER BY total_revenue DESC LIMIT 5");
$linksStmt->execute([$uid]);
$links = $linksStmt->fetchAll();

$ordersStmt = $db->prepare("SELECT order_ref,customer_name,total_amount,status,created_at FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
$ordersStmt->execute([$uid]);
$orders = $ordersStmt->fetchAll();

$txnStmt = $db->prepare("SELECT type,net_amount,description,status,created_at FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
$txnStmt->execute([$uid]);
$txns = $txnStmt->fetchAll();
?>

<a href="/admin/users.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--n500);margin-bottom:16px"><i class="ti ti-arrow-left"></i> Back to merchants</a>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px;align-items:start" id="uv-grid">
  <div style="display:flex;flex-direction:column;gap:16px">

    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <div class="side-av" style="width:48px;height:48px;font-size:16px"><?= strtoupper(substr($merchant['name'],0,2)) ?></div>
        <div>
          <div style="font-weight:700;font-size:15px"><?= htmlspecialchars($merchant['name']) ?></div>
          <div class="td-muted"><?= htmlspecialchars($merchant['email']) ?></div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Phone</span><span><?= htmlspecialchars($merchant['phone'] ?: '—') ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Status</span><span class="badge badge-<?= $merchant['status']==='active'?'success':($merchant['status']==='suspended'?'danger':'warning') ?>"><?= ucfirst($merchant['status']) ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Plan</span><span class="badge badge-purple"><?= ucfirst($merchant['plan']) ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Wallet Balance</span><span class="mono td-bold"><?= formatNaira($merchant['wallet_balance']) ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Total Earned</span><span class="mono"><?= formatNaira($merchant['total_earned']) ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">BVN Verified</span><span><?= $merchant['bvn_verified'] ? '<i class="ti ti-circle-check" style="color:var(--success)"></i> Yes' : '<i class="ti ti-circle-x" style="color:var(--n400)"></i> No' ?></span></div>
        <div style="display:flex;justify-content:space-between"><span class="td-muted">Joined</span><span><?= date('M j, Y', strtotime($merchant['created_at'])) ?></span></div>
      </div>

      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <form method="POST" onsubmit="return confirm('<?= $merchant['status']==='suspended'?'Reactivate':'Suspend' ?> this merchant?')">
          <?= csrfField() ?><input type="hidden" name="action" value="toggle_status">
          <button type="submit" class="btn btn-sm <?= $merchant['status']==='suspended'?'btn-success':'btn-danger' ?>">
            <i class="ti ti-<?= $merchant['status']==='suspended'?'player-play':'ban' ?>"></i> <?= $merchant['status']==='suspended'?'Reactivate':'Suspend' ?>
          </button>
        </form>
        <form method="POST" onsubmit="return confirm('Toggle BVN verification status?')">
          <?= csrfField() ?><input type="hidden" name="action" value="toggle_bvn">
          <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-shield-check"></i> <?= $merchant['bvn_verified']?'Revoke BVN':'Mark BVN Verified' ?></button>
        </form>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:700;font-size:13px;margin-bottom:12px">Change Plan</div>
      <form method="POST" style="display:flex;gap:8px">
        <?= csrfField() ?><input type="hidden" name="action" value="change_plan">
        <select name="plan" class="filter-select" style="flex:1">
          <?php foreach (['free','starter','pro','business'] as $p): ?>
          <option value="<?= $p ?>" <?= $merchant['plan']===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-purple btn-sm">Update</button>
      </form>
    </div>

    <div class="card">
      <div style="font-weight:700;font-size:13px;margin-bottom:4px">Adjust Wallet Balance</div>
      <div class="td-muted" style="margin-bottom:12px">Positive credits, negative debits. Logged as a transaction with your reason attached.</div>
      <form method="POST" style="display:flex;flex-direction:column;gap:8px" onsubmit="return confirm('Adjust this merchant\'s wallet balance?')">
        <?= csrfField() ?><input type="hidden" name="action" value="wallet_adjust">
        <input type="number" step="0.01" name="adjust_amount" class="filter-input" placeholder="Amount (e.g. -500 or 1500)" required>
        <input type="text" name="adjust_reason" class="filter-input" placeholder="Reason (required)" required>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-adjustments"></i> Apply Adjustment</button>
      </form>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="table-wrap">
      <div style="padding:14px 16px;font-weight:700;font-size:13px">Top Payment Links</div>
      <table>
        <thead><tr><th>Title</th><th>Status</th><th>Orders</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php if (empty($links)): ?><tr class="empty-row"><td colspan="4">No payment links yet.</td></tr><?php else: foreach ($links as $l): ?>
          <tr>
            <td class="td-bold"><?= htmlspecialchars($l['title']) ?></td>
            <td><span class="badge badge-<?= $l['status']==='active'?'success':'neutral' ?>"><?= ucfirst($l['status']) ?></span></td>
            <td><?= $l['total_orders'] ?></td>
            <td class="mono"><?= formatNaira($l['total_revenue']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-wrap">
      <div style="padding:14px 16px;font-weight:700;font-size:13px">Recent Orders</div>
      <table>
        <thead><tr><th>Ref</th><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
          <?php if (empty($orders)): ?><tr class="empty-row"><td colspan="5">No orders yet.</td></tr><?php else: foreach ($orders as $o): ?>
          <tr>
            <td class="mono td-muted"><?= htmlspecialchars($o['order_ref']) ?></td>
            <td><?= htmlspecialchars($o['customer_name']) ?></td>
            <td class="mono"><?= formatNaira($o['total_amount']) ?></td>
            <td><span class="badge badge-neutral"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
            <td class="td-muted"><?= timeAgo($o['created_at']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-wrap">
      <div style="padding:14px 16px;font-weight:700;font-size:13px">Recent Transactions</div>
      <table>
        <thead><tr><th>Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
          <?php if (empty($txns)): ?><tr class="empty-row"><td colspan="5">No transactions yet.</td></tr><?php else: foreach ($txns as $t): ?>
          <tr>
            <td style="text-transform:capitalize"><?= htmlspecialchars($t['type']) ?></td>
            <td class="mono"><?= formatNaira($t['net_amount']) ?></td>
            <td class="td-muted"><?= htmlspecialchars($t['description']) ?></td>
            <td><span class="badge badge-neutral"><?= ucfirst($t['status']) ?></span></td>
            <td class="td-muted"><?= timeAgo($t['created_at']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>@media(max-width:960px){#uv-grid{grid-template-columns:1fr}}</style>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>