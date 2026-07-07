<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Merchants';
$activePage = 'merchants';
$db         = db();

// ── ACTIONS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    $uid    = (int)post('user_id');

    if ($uid > 0) {
        if ($action === 'suspend') {
            $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uid]);
            flash('success', 'Merchant suspended.');
        } elseif ($action === 'activate') {
            $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$uid]);
            flash('success', 'Merchant activated.');
        } elseif ($action === 'verify_bvn') {
            $db->prepare("UPDATE users SET bvn_verified=1 WHERE id=?")->execute([$uid]);
            $db->prepare("INSERT INTO user_kyc (user_id,bvn_last4,status,verified_at) VALUES (?,'ADMN','verified',NOW()) ON DUPLICATE KEY UPDATE status='verified',verified_at=NOW()")->execute([$uid]);
            notify($uid,'kyc_verified','Identity Verified','Your BVN has been manually verified by Kweek admin.','shield-check','/withdraw.php');
            flash('success', 'BVN marked as verified.');
        } } elseif ($action === 'change_plan') {
    $newPlan = in_array(post('plan'),['free','starter','pro','business']) ? post('plan') : 'free';
    $db->prepare("UPDATE users SET plan=? WHERE id=?")->execute([$newPlan,$uid]);
    flash('success', 'Plan updated to ' . $newPlan . '.');
} elseif ($action === 'adjust_balance') {
            $amount = (float)post('amount');
            $note   = clean(post('note','Admin adjustment'));
            if ($amount !== 0.0) {
                $ref = generateRef('ADJ');
                if ($amount > 0) {
                    creditWallet($uid, $amount, 0, $note, $ref);
                } else {
                    debitWallet($uid, abs($amount), $note, $ref);
                }
                flash('success', 'Balance adjusted by ' . formatNaira(abs($amount)) . '.');
            }
        }
    redirect('/admin/merchants.php');
}

// ── FILTERS ───────────────────────────────────────────────────
$search    = clean(get('q'));
$planF     = in_array(get('plan'),['free','pro','business']) ? get('plan') : '';
$statusF   = in_array(get('status'),['active','suspended','pending']) ? get('status') : '';
$bvnF      = get('bvn'); // '0' = unverified, '1' = verified
$page      = max(1,(int)get('page',1));
$perPage   = 20;
$offset    = ($page-1)*$perPage;

$where  = ['1=1'];
$params = [];
if ($search)  { $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($planF)   { $where[] = "u.plan=?";          $params[] = $planF; }
if ($statusF) { $where[] = "u.status=?";        $params[] = $statusF; }
if ($bvnF !== '') { $where[] = "u.bvn_verified=?"; $params[] = (int)$bvnF; }

$whereSQL = implode(' AND ', $where);

$cStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSQL");
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("
    SELECT u.*,
        (SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=u.id AND type='credit') as total_earned,
        (SELECT COUNT(*) FROM payment_links WHERE user_id=u.id) as link_count,
        (SELECT COUNT(*) FROM orders WHERE user_id=u.id AND status IN ('paid','completed')) as order_count
    FROM users u WHERE $whereSQL ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$merchants = $stmt->fetchAll();

// View merchant detail (modal)
$viewId = (int)get('view');
$viewMerchant = null;
$viewKyc = null;
if ($viewId) {
    $vStmt = $db->prepare("
        SELECT u.*,
            (SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=u.id AND type='credit') as total_earned,
            (SELECT COUNT(*) FROM payment_links WHERE user_id=u.id) as link_count,
            (SELECT COUNT(*) FROM orders WHERE user_id=u.id AND status IN ('paid','completed')) as order_count,
            (SELECT COUNT(*) FROM withdrawals WHERE user_id=u.id AND status='success') as withdrawal_count,
            (SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE user_id=u.id AND status='success') as withdrawn_total
        FROM users u WHERE u.id=? LIMIT 1
    ");
    $vStmt->execute([$viewId]);
    $viewMerchant = $vStmt->fetch();

    if ($viewMerchant) {
        $kStmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id=? LIMIT 1");
        $kStmt->execute([$viewId]);
        $viewKyc = $kStmt->fetch();
    }
}
include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px"><?= number_format($total) ?> Merchants</h1>
    <p style="font-size:13px;color:var(--n500);margin-top:2px">Manage all Kweek merchant accounts</p>
  </div>
  <a href="/admin/merchants.php?export=csv&<?= http_build_query(['q'=>$search,'plan'=>$planF,'status'=>$statusF]) ?>" class="btn btn-outline btn-sm"><i class="ti ti-download"></i> Export CSV</a>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:18px;padding:14px 16px">
  <form method="GET" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:160px;position:relative">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:15px"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, phone..."
        style="width:100%;font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:8px 12px 8px 30px;outline:none;background:var(--n50);transition:all .2s">
    </div>
    <?php foreach ([
      ['plan',   [''=>'All Plans','free'=>'Free','pro'=>'Pro','business'=>'Business'], $planF],
      ['status', [''=>'All Status','active'=>'Active','suspended'=>'Suspended','pending'=>'Pending'], $statusF],
      ['bvn',    [''=>'All KYC','1'=>'BVN Verified','0'=>'BVN Pending'], $bvnF],
    ] as [$name, $opts, $val]): ?>
    <select name="<?= $name ?>" style="font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:8px 10px;outline:none;background:var(--n50);cursor:pointer">
      <?php foreach ($opts as $v => $l): ?>
      <option value="<?= $v ?>" <?= (string)$val===(string)$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-filter"></i> Filter</button>
    <a href="/admin/merchants.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
  </form>
</div>

<!-- TABLE -->
<div class="table-wrap">
  <?php if (empty($merchants)): ?>
  <div style="text-align:center;padding:48px 20px;color:var(--n500)">
    <i class="ti ti-users" style="font-size:36px;margin-bottom:10px;display:block;color:var(--n300)"></i>
    No merchants match your filters.
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Merchant</th>
        <th>Plan</th>
        <th>KYC</th>
        <th>Balance</th>
        <th>Revenue</th>
        <th>Links</th>
        <th>Status</th>
        <th>Joined</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($merchants as $m): ?>
    <tr>
      <td>
        <div class="td-bold"><?= htmlspecialchars($m['name']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($m['email']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($m['phone']) ?></div>
      </td>
      <td><span class="badge badge-<?= $m['plan']==='free'?'neutral':($m['plan']==='pro'?'purple':'success') ?>"><?= $m['plan'] ?></span></td>
      <td>
        <?php if ($m['bvn_verified']): ?>
        <span class="badge badge-success"><i class="ti ti-shield-check"></i> Verified</span>
        <?php else: ?>
        <span class="badge badge-warning"><i class="ti ti-clock"></i> Pending</span>
        <?php endif; ?>
      </td>
      <td class="td-bold"><?= formatNaira($m['wallet_balance']) ?></td>
      <td style="color:var(--success);font-weight:600"><?= formatNaira($m['total_earned']) ?></td>
      <td style="text-align:center"><?= $m['link_count'] ?></td>
      <td>
        <span class="badge badge-<?= $m['status']==='active'?'success':($m['status']==='suspended'?'danger':'neutral') ?>">
          <?= $m['status'] ?>
        </span>
      </td>
      <td style="font-size:11px;color:var(--n500);white-space:nowrap"><?= date('M j, Y',strtotime($m['created_at'])) ?></td>
      <td>
        <div style="display:flex;gap:4px;flex-wrap:nowrap">
          <a href="?view=<?= $m['id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-eye"></i></a>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
            <?php if ($m['status'] === 'active'): ?>
            <button type="submit" name="action" value="suspend" class="btn btn-sm" style="background:var(--danger-bg);color:var(--danger);border:none" onclick="return confirm('Suspend this merchant?')"><i class="ti ti-ban"></i></button>
            <?php else: ?>
            <button type="submit" name="action" value="activate" class="btn btn-sm" style="background:var(--success-bg);color:var(--success);border:none"><i class="ti ti-check"></i></button>
            <?php endif; ?>
            <?php if (!$m['bvn_verified']): ?>
            <button type="submit" name="action" value="verify_bvn" class="btn btn-sm btn-outline" onclick="return confirm('Manually verify BVN for this merchant?')"><i class="ti ti-shield-check"></i></button>
            <?php endif; ?>
          </form>
          <button onclick="openBalanceModal(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>')" class="btn btn-outline btn-sm"><i class="ti ti-wallet"></i></button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <!-- PAGINATION -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&<?= http_build_query(['q'=>$search,'plan'=>$planF,'status'=>$statusF,'bvn'=>$bvnF]) ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
      <a href="?page=<?= $i ?>&<?= http_build_query(['q'=>$search,'plan'=>$planF,'status'=>$statusF,'bvn'=>$bvnF]) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&<?= http_build_query(['q'=>$search,'plan'=>$planF,'status'=>$statusF,'bvn'=>$bvnF]) ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- BALANCE MODAL -->
<div class="modal" id="balance-modal">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Adjust Balance</div>
      <button class="modal-close" onclick="closeModal('balance-modal')"><i class="ti ti-x"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <div class="modal-body">
        <input type="hidden" name="action" value="adjust_balance">
        <input type="hidden" name="user_id" id="bal-uid">
        <p style="font-size:13px;color:var(--n600);margin-bottom:16px">Adjusting balance for: <strong id="bal-name"></strong></p>
        <div class="form-group">
          <label class="form-label">Amount (₦) — use negative to debit</label>
          <input type="number" name="amount" class="form-input" placeholder="e.g. 5000 or -1000" step="0.01" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Reason / Note</label>
          <input type="text" name="note" class="form-input" placeholder="e.g. Manual top-up by admin" required>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('balance-modal')" class="btn btn-outline btn-md">Cancel</button>
        <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-check"></i> Apply Adjustment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openBalanceModal(uid, name) {
  document.getElementById('bal-uid').value  = uid;
  document.getElementById('bal-name').textContent = name;
  openModal('balance-modal');
}
<?php
// CSV export
if (get('export') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="kweek-merchants-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['ID','Name','Email','Phone','Plan','BVN','Status','Balance','Total Earned','Joined']);
    $all = $db->prepare("SELECT u.*, (SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE user_id=u.id AND type='credit') as total_earned FROM users u WHERE $whereSQL ORDER BY created_at DESC");
    $all->execute($params);
    while ($r = $all->fetch()) fputcsv($out,[$r['id'],$r['name'],$r['email'],$r['phone'],$r['plan'],$r['bvn_verified']?'Yes':'No',$r['status'],$r['wallet_balance'],$r['total_earned'],date('Y-m-d H:i',strtotime($r['created_at']))]);
    fclose($out); exit;
}
?>
</script>

<!-- MERCHANT DETAIL MODAL -->
<?php if ($viewMerchant): ?>
<div class="modal" id="view-merchant-modal">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-head">
      <div class="modal-title">Merchant Details</div>
      <a href="/admin/merchants.php<?= $search||$planF||$statusF ? '?'.http_build_query(['q'=>$search,'plan'=>$planF,'status'=>$statusF]) : '' ?>" class="modal-close"><i class="ti ti-x"></i></a>
    </div>
    <div class="modal-body">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--n200)">
        <div style="width:52px;height:52px;border-radius:50%;background:var(--p700);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;color:#fff;flex-shrink:0"><?= strtoupper(substr($viewMerchant['name'],0,2)) ?></div>
        <div>
          <div style="font-size:16px;font-weight:700;color:var(--n900)"><?= htmlspecialchars($viewMerchant['name']) ?></div>
          <div style="font-size:12px;color:var(--n500)"><?= htmlspecialchars($viewMerchant['email']) ?> · <?= htmlspecialchars($viewMerchant['phone']) ?></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Plan</div><span class="badge badge-purple"><?= $viewMerchant['plan'] ?></span></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Account Status</div><span class="badge badge-<?= $viewMerchant['status']==='active'?'success':($viewMerchant['status']==='suspended'?'danger':'neutral') ?>"><?= $viewMerchant['status'] ?></span></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Wallet Balance</div><div class="td-bold"><?= formatNaira($viewMerchant['wallet_balance']) ?></div></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Total Earned</div><div style="color:var(--success);font-weight:700"><?= formatNaira($viewMerchant['total_earned']) ?></div></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Payment Links</div><?= $viewMerchant['link_count'] ?></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Completed Orders</div><?= $viewMerchant['order_count'] ?></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Withdrawals</div><?= $viewMerchant['withdrawal_count'] ?> (<?= formatNaira($viewMerchant['withdrawn_total']) ?>)</div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--n400);margin-bottom:3px">Joined</div><?= date('M j, Y', strtotime($viewMerchant['created_at'])) ?></div>
      </div>

      <!-- BVN / KYC -->
      <div style="background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:14px;margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--n500)">KYC / BVN</div>
          <?php if ($viewMerchant['bvn_verified']): ?>
          <span class="badge badge-success"><i class="ti ti-shield-check"></i> Verified</span>
          <?php else: ?>
          <span class="badge badge-warning"><i class="ti ti-clock"></i> Pending</span>
          <?php endif; ?>
        </div>
        <?php if ($viewKyc): ?>
        <div style="font-size:13px;color:var(--n700)">
          <?php if ($viewKyc['full_name']): ?><div>Name on file: <strong><?= htmlspecialchars($viewKyc['full_name']) ?></strong></div><?php endif; ?>
          <?php if ($viewKyc['bvn_last4']): ?><div>BVN ending: <strong><?= htmlspecialchars($viewKyc['bvn_last4']) ?></strong></div><?php endif; ?>
          <?php if ($viewKyc['dob']): ?><div>DOB: <?= date('M j, Y', strtotime($viewKyc['dob'])) ?></div><?php endif; ?>
          <?php if ($viewKyc['verified_at']): ?><div>Verified: <?= date('M j, Y g:i A', strtotime($viewKyc['verified_at'])) ?></div><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-size:13px;color:var(--n500)">No KYC record submitted yet.</div>
        <?php endif; ?>
        <?php if (!$viewMerchant['bvn_verified']): ?>
        <form method="POST" style="margin-top:10px">
          <?= csrfField() ?>
          <input type="hidden" name="user_id" value="<?= $viewMerchant['id'] ?>">
          <button type="submit" name="action" value="verify_bvn" class="btn btn-outline btn-sm" onclick="return confirm('Manually verify BVN for this merchant?')"><i class="ti ti-shield-check"></i> Manually Verify</button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Bank details -->
      <?php if ($viewMerchant['bank_name']): ?>
      <div style="margin-bottom:16px">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--n500);margin-bottom:6px">Withdrawal Bank Account</div>
        <div style="font-size:13px"><?= htmlspecialchars($viewMerchant['bank_name']) ?> · <?= htmlspecialchars($viewMerchant['account_number']) ?> · <?= htmlspecialchars($viewMerchant['account_name']) ?></div>
      </div>
      <?php endif; ?>

      <!-- Change plan -->
      <div style="border-top:1px solid var(--n200);padding-top:16px">
        <form method="POST" style="display:flex;gap:8px;align-items:flex-end">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_plan">
          <input type="hidden" name="user_id" value="<?= $viewMerchant['id'] ?>">
          <div style="flex:1">
            <label class="form-label">Change Plan</label>
            <select name="plan" class="form-select">
              <?php foreach (['free','starter','pro','business'] as $p): ?>
              <option value="<?= $p ?>" <?= $viewMerchant['plan']===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-check"></i> Update</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>document.addEventListener('DOMContentLoaded', () => openModal('view-merchant-modal'));</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
