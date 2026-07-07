<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Webhook Logs';
$activePage = 'webhooks';
$db         = db();

// Retry failed webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $logId = (int)post('log_id');
    $stmt  = $db->prepare("SELECT * FROM webhook_logs WHERE id = ? LIMIT 1");
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if ($log) {
        $payload = json_decode($log['payload'], true);
        try {
            require_once __DIR__ . '/../webhook.php';
            // Re-process inline (simplified — in production use a queue)
            $db->prepare("UPDATE webhook_logs SET status='received', error=NULL WHERE id=?")->execute([$logId]);
            flash('success', 'Webhook marked for reprocessing.');
        } catch (Throwable $e) {
            flash('error', 'Retry failed: ' . $e->getMessage());
        }
    }
    redirect('/admin/webhooks.php');
}

// Filters
$statusF = in_array(get('status'), ['received','processed','failed','ignored']) ? get('status') : '';
$eventF  = clean(get('event'));
$page    = max(1, (int)get('page', 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($statusF) { $where[] = "status = ?";     $params[] = $statusF; }
if ($eventF)  { $where[] = "event_type LIKE ?"; $params[] = "%$eventF%"; }
$wSQL = implode(' AND ', $where);

$cStmt = $db->prepare("SELECT COUNT(*) FROM webhook_logs WHERE $wSQL");
$cStmt->execute($params);
$total      = (int)$cStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM webhook_logs WHERE $wSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Status counts
$counts = [];
foreach (['received','processed','failed','ignored'] as $s) {
    $cs = $db->query("SELECT COUNT(*) FROM webhook_logs WHERE status='$s'")->fetchColumn();
    $counts[$s] = (int)$cs;
}

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Webhook Logs</h1>
    <p style="font-size:13px;color:var(--n500);margin-top:2px">Monitor all incoming Nomba webhook events</p>
  </div>
</div>

<!-- STAT PILLS -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
  <?php foreach ([
    'received'  => ['neutral', 'inbox'],
    'processed' => ['success', 'circle-check'],
    'failed'    => ['danger',  'alert-circle'],
    'ignored'   => ['neutral', 'minus'],
  ] as $s => [$color, $icon]): ?>
  <a href="?status=<?= $s ?>"
     style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;
            background:<?= $statusF===$s?'var(--p600)':'var(--n0)' ?>;color:<?= $statusF===$s?'#fff':'var(--n700)' ?>;
            border:1.5px solid <?= $statusF===$s?'var(--p600)':'var(--n200)' ?>">
    <i class="ti ti-<?= $icon ?>"></i><?= ucfirst($s) ?>
    <span style="background:<?= $statusF===$s?'rgba(255,255,255,.25)':'var(--n100)' ?>;padding:2px 7px;border-radius:var(--r-full);font-size:11px"><?= $counts[$s] ?></span>
  </a>
  <?php endforeach; ?>
  <?php if ($statusF): ?>
  <a href="/admin/webhooks.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--r-full);font-size:13px;font-weight:500;text-decoration:none;background:var(--n100);color:var(--n600)">
    <i class="ti ti-x"></i> Clear
  </a>
  <?php endif; ?>
</div>

<!-- FILTER -->
<div class="card" style="padding:12px 14px;margin-bottom:16px">
  <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusF) ?>">
    <div style="position:relative;flex:1;min-width:160px">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:14px"></i>
      <input type="text" name="event" value="<?= htmlspecialchars($eventF) ?>" placeholder="Event type e.g. checkout.order.paid"
        style="width:100%;font-family:var(--font-body);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 10px 7px 28px;outline:none;background:var(--n50)">
    </div>
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-filter"></i> Filter</button>
  </form>
</div>

<!-- TABLE -->
<div class="table-wrap">
  <?php if (empty($logs)): ?>
  <div style="text-align:center;padding:48px;color:var(--n500)">
    <i class="ti ti-webhook" style="font-size:36px;display:block;margin-bottom:10px;color:var(--n300)"></i>
    No webhook logs found.
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Event Type</th>
        <th>Status</th>
        <th>Error</th>
        <th>Processed At</th>
        <th>Received</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log):
      $sc = ['processed'=>'success','failed'=>'danger','received'=>'warning','ignored'=>'neutral'][$log['status']]??'neutral';
    ?>
    <tr>
      <td style="font-family:monospace;font-size:11px;color:var(--n500)">#<?= $log['id'] ?></td>
      <td>
        <span style="font-size:12px;font-weight:600;font-family:monospace;background:var(--n100);padding:3px 8px;border-radius:var(--r-sm)">
          <?= htmlspecialchars($log['event_type']) ?>
        </span>
      </td>
      <td><span class="badge badge-<?= $sc ?>"><?= $log['status'] ?></span></td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--danger)">
        <?= $log['error'] ? htmlspecialchars($log['error']) : '—' ?>
      </td>
      <td style="font-size:11px;color:var(--n500)"><?= $log['processed_at'] ? nigeriaTime($log['processed_at']) : '—' ?></td>
      <td style="font-size:11px;color:var(--n500);white-space:nowrap"><?= nigeriaTime($log['created_at']) ?></td>
      <td>
        <div style="display:flex;gap:4px">
          <button onclick="viewPayload(<?= htmlspecialchars(json_encode($log['payload'])) ?>)" class="btn btn-outline btn-sm">
            <i class="ti ti-eye"></i> Payload
          </button>
          <?php if ($log['status'] === 'failed'): ?>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
            <button type="submit" class="btn btn-sm btn-purple"><i class="ti ti-refresh"></i> Retry</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pag-btns">
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&status=<?= $statusF ?>&event=<?= urlencode($eventF) ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><a href="?page=<?= $i ?>&status=<?= $statusF ?>&event=<?= urlencode($eventF) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&status=<?= $statusF ?>&event=<?= urlencode($eventF) ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- PAYLOAD MODAL -->
<div class="modal" id="payload-modal">
  <div class="modal-box" style="max-width:640px">
    <div class="modal-head">
      <div class="modal-title">Webhook Payload</div>
      <button class="modal-close" onclick="closeModal('payload-modal')"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body" style="padding:0">
      <pre id="payload-content" style="background:var(--n900);color:#A8FF78;padding:20px;overflow:auto;max-height:400px;font-family:monospace;font-size:12px;line-height:1.7;border-radius:0 0 var(--r-xl) var(--r-xl)"></pre>
    </div>
  </div>
</div>

<script>
function viewPayload(raw) {
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
    document.getElementById('payload-content').textContent = JSON.stringify(parsed, null, 2);
  } catch(e) {
    document.getElementById('payload-content').textContent = raw;
  }
  openModal('payload-modal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
