<?php
// admin/withdrawals.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/nomba.php';
requireAdmin();

$pageTitle  = 'Withdrawals';
$activePage = 'withdrawals';
$db         = db();

// ── PROCESS WITHDRAWAL ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    $wid    = (int)post('withdrawal_id');

    $wStmt = $db->prepare("SELECT * FROM withdrawals WHERE id=? LIMIT 1");
    $wStmt->execute([$wid]);
    $w = $wStmt->fetch();

    if ($w) {
        if ($action === 'approve') {
            try {
                $nomba  = new Nomba();
                $result = $nomba->transfer([
                    'amount'        => $w['net_amount'],
                    'bankCode'      => $w['bank_code'],
                    'accountNumber' => $w['account_number'],
                    'accountName'   => $w['account_name'],
                    'narration'     => 'Kweek withdrawal ' . $w['reference'],
                    'reference'     => $w['reference'],
                ]);
                $nombaRef = $result['data']['reference'] ?? null;
                $status   = (($result['data']['status'] ?? '') === 'SUCCESS') ? 'success' : 'processing';
                $db->prepare("UPDATE withdrawals SET status=?,nomba_ref=?,processed_at=NOW() WHERE id=?")->execute([$status,$nombaRef,$wid]);
                notify($w['user_id'],'withdrawal_done','Withdrawal Processed',formatNaira($w['net_amount']).' sent to '.$w['bank_name'].' '.$w['account_number'],'check-circle','/transactions.php');
                flash('success','Withdrawal processed. Nomba ref: ' . ($nombaRef ?? 'pending'));
            } catch (Throwable $e) {
                flash('error','Transfer failed: ' . $e->getMessage());
            }
        } elseif ($action === 'fail') {
            $reason = clean(post('reason','Rejected by admin'));
            // Refund wallet
            creditWallet($w['user_id'], $w['amount'], 0, 'Withdrawal refund: '.$reason, generateRef('RFD'));
            $db->prepare("UPDATE withdrawals SET status='failed',failure_reason=? WHERE id=?")->execute([$reason,$wid]);
            notify($w['user_id'],'withdrawal_failed','Withdrawal Failed','Your withdrawal of '.formatNaira($w['amount']).' was rejected: '.$reason,'alert-circle','/withdraw.php');
            flash('info','Withdrawal rejected and funds refunded to merchant wallet.');
        }
    }
    redirect('/admin/withdrawals.php');
}

// Filters
$statusF = in_array(get('status'),['pending','processing','success','failed']) ? get('status') : '';
$page    = max(1,(int)get('page',1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$where  = ['1=1'];
$params = [];
if ($statusF) { $where[] = "w.status=?"; $params[] = $statusF; }
$wSQL = implode(' AND ', $where);

$cStmt = $db->prepare("SELECT COUNT(*) FROM withdrawals w WHERE $wSQL");
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("SELECT w.*, u.name as merchant_name, u.email as merchant_email FROM withdrawals w JOIN users u ON w.user_id=u.id WHERE $wSQL ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

// Summary
$pendingAmt    = (float)$db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='pending'")->fetchColumn();
$processedToday = (float)$db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='success' AND DATE(processed_at)='".date('Y-m-d')."'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Withdrawals</h1>
    <p style="font-size:13px;color:var(--n500);margin-top:2px">Review and process merchant withdrawal requests</p>
  </div>
</div>

<!-- SUMMARY -->
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i class="ti ti-clock"></i></div>
    <div class="stat-label">Pending Amount</div>
    <div class="stat-value" style="font-size:20px"><?= formatNaira($pendingAmt) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--success-bg);color:var(--success)"><i class="ti ti-check"></i></div>
    <div class="stat-label">Processed Today</div>
    <div class="stat-value" style="font-size:20px"><?= formatNaira($processedToday) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><i class="ti ti-receipt"></i></div>
    <div class="stat-label">Total Records</div>
    <div class="stat-value" style="font-size:20px"><?= number_format($total) ?></div>
  </div>
</div>

<!-- STATUS TABS -->
<div style="display:flex;gap:0;border-bottom:1px solid var(--n200);margin-bottom:16px;overflow-x:auto">
  <?php foreach ([''=>'All','pending'=>'Pending','processing'=>'Processing','success'=>'Success','failed'=>'Failed'] as $v=>$l): ?>
  <a href="?status=<?= $v ?>" style="padding:10px 16px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?= $statusF===$v?'var(--p600)':'transparent' ?>;color:<?= $statusF===$v?'var(--p600)':'var(--n500)' ?>;white-space:nowrap"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <?php if (empty($withdrawals)): ?>
  <div style="text-align:center;padding:48px;color:var(--n500)">
    <i class="ti ti-cash" style="font-size:36px;display:block;margin-bottom:10px;color:var(--n300)"></i>
    No withdrawals found.
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Merchant</th><th>Reference</th><th>Amount</th><th>Net</th><th>Bank</th><th>Status</th><th>Date</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($withdrawals as $w):
      $sc = ['pending'=>'warning','processing'=>'purple','success'=>'success','failed'=>'danger'][$w['status']]??'neutral';
    ?>
    <tr>
      <td>
        <div class="td-bold"><?= htmlspecialchars($w['merchant_name']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($w['merchant_email']) ?></div>
      </td>
      <td style="font-family:monospace;font-size:11px;color:var(--n500)"><?= htmlspecialchars($w['reference']) ?></td>
      <td class="td-bold"><?= formatNaira($w['amount']) ?></td>
      <td style="color:var(--success);font-weight:700"><?= formatNaira($w['net_amount']) ?></td>
      <td>
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($w['bank_name']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= htmlspecialchars($w['account_number']) ?> · <?= htmlspecialchars($w['account_name']) ?></div>
      </td>
      <td><span class="badge badge-<?= $sc ?>"><?= ucfirst($w['status']) ?></span></td>
      <td style="font-size:11px;color:var(--n500)"><?= timeAgo($w['created_at']) ?></td>
      <td>
        <?php if ($w['status'] === 'pending'): ?>
        <div style="display:flex;gap:4px">
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" onclick="return confirm('Process this withdrawal of <?= formatNaira($w['net_amount']) ?> to <?= htmlspecialchars(addslashes($w['account_name'])) ?>?')">
              <i class="ti ti-check"></i> Process
            </button>
          </form>
          <button onclick="openRejectModal(<?= $w['id'] ?>)" class="btn btn-sm btn-danger"><i class="ti ti-x"></i> Reject</button>
        </div>
        <?php elseif ($w['nomba_ref']): ?>
        <span style="font-family:monospace;font-size:10px;color:var(--n500)"><?= htmlspecialchars(substr($w['nomba_ref'],0,16)) ?>...</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&status=<?= $statusF ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><a href="?page=<?= $i ?>&status=<?= $statusF ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&status=<?= $statusF ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- REJECT MODAL -->
<div class="modal" id="reject-modal">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title">Reject Withdrawal</div><button class="modal-close" onclick="closeModal('reject-modal')"><i class="ti ti-x"></i></button></div>
    <form method="POST">
      <?= csrfField() ?>
      <div class="modal-body">
        <input type="hidden" name="action" value="fail">
        <input type="hidden" name="withdrawal_id" id="reject-wid">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Reason for rejection</label>
          <input type="text" name="reason" class="form-input" placeholder="e.g. Invalid account details" required>
        </div>
        <p style="font-size:12px;color:var(--n500);margin-top:10px"><i class="ti ti-info-circle"></i> The full amount will be refunded to the merchant's Kweek wallet.</p>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('reject-modal')" class="btn btn-outline btn-md">Cancel</button>
        <button type="submit" class="btn btn-danger btn-md"><i class="ti ti-x"></i> Reject & Refund</button>
      </div>
    </form>
  </div>
</div>
<script>
function openRejectModal(wid) { document.getElementById('reject-wid').value = wid; openModal('reject-modal'); }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
