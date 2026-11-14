<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/wa_helpers.php';
requireLogin();

$pageTitle  = 'Orders';
$activePage = 'orders';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// ── ACTIONS (confirm / decline) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = post('action');

    // Dismiss a pending WhatsApp update — scoped to a wa_notifications row,
    // not an order, so handled separately from the order-action block below.
    if ($action === 'wa_dismiss') {
        $waId = (int) post('wa_id');
        $db->prepare("UPDATE wa_notifications SET status='skipped' WHERE id=? AND user_id=?")->execute([$waId, $uid]);
        flash('info', 'WhatsApp update dismissed.');
        redirect('/orders.php');
    }

    $orderId = (int)post('order_id');

    $stmt = $db->prepare("SELECT o.*, pl.title as link_title, pl.redirect_url as link_redirect_url FROM orders o JOIN payment_links pl ON o.link_id=pl.id WHERE o.id=? AND o.user_id=? LIMIT 1");
    $stmt->execute([$orderId, $uid]);
    $order = $stmt->fetch();

    if ($order) {
        if ($action === 'confirm' && $order['status'] === 'pending_confirmation') {

            $redirectTarget = $order['link_redirect_url']
                ? $order['link_redirect_url'] . (str_contains($order['link_redirect_url'], '?') ? '&' : '?') . 'ref=' . urlencode($order['order_ref'])
                : SITE_URL . '/receipt.php?ref=' . urlencode($order['order_ref']);

            try {
                // The checkout is created in the order's own currency (Nomba::createCheckout
                // looks it up from the order), so the buyer is charged what they were quoted.
                $nomba  = new Nomba();
                $result = $nomba->createCheckout([
                    'amount'        => $order['total_amount'],
                    'orderRef'      => $order['order_ref'],
                    'customerName'  => $order['customer_name'],
                    'customerEmail' => $order['customer_email'] ?? 'customer@kweek.ng',
                    'customerPhone' => $order['customer_phone'],
                    'description'   => 'Payment for ' . $order['link_title'],
                    'redirectUrl'   => $redirectTarget,
                    'callbackUrl'   => SITE_URL . '/webhook.php',
                ]);

                $checkoutUrl = $result['data']['checkoutLink']
                    ?? $result['data']['paymentLink']
                    ?? $result['data']['url']
                    ?? '';

                if (empty($checkoutUrl)) {
                    error_log('Order confirm checkout response: ' . json_encode($result));
                    flash('error', 'Order confirmed, but the payment link could not be generated. Please try again or contact support.');
                } else {
                    $checkoutId = $result['data']['orderId'] ?? $result['data']['id'] ?? '';
                    $db->prepare("UPDATE orders SET status='pending_payment', confirmed_at=NOW(), nomba_checkout_id=? WHERE id=?")
                       ->execute([$checkoutId, $orderId]);

                    // The merchant sees naira; for a dollar/cedi order the buyer's amount follows in brackets.
                    $confirmAmt = formatNaira(orderNgnAmount($order));
                    if ($paidIn = orderPaidInLabel($order)) $confirmAmt .= ' (' . $paidIn . ')';

                    notify(
                        $uid,
                        'order_confirmed',
                        'Order Confirmed',
                        'You confirmed an order from ' . $order['customer_name'] . ' for ' . $confirmAmt,
                        'check',
                        '/orders.php?id=' . $orderId
                    );

                    if (!empty($order['customer_email'])) {
                        sendOrderApprovedEmail($order, $checkoutUrl, $user['name'], $order['link_title']);
                    }

                    $orderForWa = $order;
                    $orderForWa['_checkout_url'] = $checkoutUrl;
                    waQueue($db, $orderId, $uid, $order['customer_phone'], 'order_confirmed', waBuildMessage('order_confirmed', $orderForWa, $user));

                    flash('success', 'Order confirmed. The customer has been emailed a payment link (expires in 20 minutes).');
                }
            } catch (Throwable $e) {
                error_log('Order confirm error: ' . $e->getMessage());
                flash('error', 'Something went wrong generating the payment link. Please try again.');
            }

        } elseif ($action === 'decline' && $order['status'] === 'pending_confirmation') {
            $reason = clean(post('decline_reason','No reason provided'));
            $db->prepare("UPDATE orders SET status='declined', declined_at=NOW(), decline_reason=? WHERE id=?")->execute([$reason, $orderId]);
            flash('info','Order declined.');

            $orderForWa = $order;
            $orderForWa['decline_reason'] = $reason;
            waQueue($db, $orderId, $uid, $order['customer_phone'], 'order_declined', waBuildMessage('order_declined', $orderForWa, $user));

        } elseif ($action === 'complete' && $order['status'] === 'paid') {
            $db->prepare("UPDATE orders SET status='completed' WHERE id=?")->execute([$orderId]);
            flash('success','Order marked as completed.');

            waQueue($db, $orderId, $uid, $order['customer_phone'], 'order_completed', waBuildMessage('order_completed', $order, $user));

            // Email customer — order completed
            if (!empty($order['customer_email'])) {
                sendOrderCompletedEmail(
                    $order,
                    $user['name'],
                    $order['link_title']
                );
            }

            // Email merchant — completion confirmation
            sendNotificationEmail(
                $user,
                'Order Completed',
                'Order ' . $order['order_ref'] . ' from ' . $order['customer_name'] . ' has been marked as completed.',
                '/orders.php'
            );

        } elseif ($action === 'cancel') {
            $db->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$orderId]);
            flash('info','Order cancelled.');
        }
    }
    redirect('/orders.php');
}

// ── FILTERS ───────────────────────────────────────────────────
$validStatuses = ['pending_confirmation','confirmed','pending_payment','paid','completed','declined','cancelled'];
$statusFilter  = in_array(get('status'), $validStatuses) ? get('status') : '';
$search        = clean(get('q'));

// Date range (YYYY-MM-DD from a <input type=date>)
$dateFrom = get('from');
$dateTo   = get('to');
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)   ? $dateTo   : '';

// Sort. Amount sorting uses each order's naira value, so a $10 order and a
// ₦20,000 order are compared fairly instead of "10" vs "20000".
$sort = in_array(get('sort'), ['newest','oldest','amount_high','amount_low']) ? get('sort') : 'newest';
$ngnSqlO = ngnAmountSql('o.');
$sortMap = [
    'newest'      => 'o.created_at DESC',
    'oldest'      => 'o.created_at ASC',
    'amount_high' => $ngnSqlO . ' DESC',
    'amount_low'  => $ngnSqlO . ' ASC',
];
$orderBySQL = $sortMap[$sort];

$page          = max(1,(int)get('page',1));
$perPage       = 15;
$offset        = ($page-1)*$perPage;

$where  = ["o.user_id = ?"];
$params = [$uid];
if ($statusFilter) { $where[] = "o.status = ?"; $params[] = $statusFilter; }
if ($search)       { $where[] = "(o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.order_ref LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($dateFrom)      { $where[] = "o.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo)        { $where[] = "o.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("SELECT o.*, pl.title as link_title, pl.slug FROM orders o JOIN payment_links pl ON o.link_id=pl.id WHERE $whereSQL ORDER BY $orderBySQL LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Status counts for tabs
$tabCounts = [];
foreach (['pending_confirmation','confirmed','paid','completed'] as $s) {
    $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status=?");
    $st->execute([$uid,$s]);
    $tabCounts[$s] = (int)$st->fetchColumn();
}

// ── SUMMARY STATS (all-time, not affected by filters) ──────────
$allCountStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?");
$allCountStmt->execute([$uid]);
$allOrdersCount = (int)$allCountStmt->fetchColumn();

// Revenue is in naira: dollar and cedi orders count at the naira credit
// locked when they were placed, never their raw dollar/cedi total.
$revStmt = $db->prepare("SELECT COALESCE(SUM(" . ngnAmountSql() . "),0) FROM orders WHERE user_id=? AND status IN ('paid','completed')");
$revStmt->execute([$uid]);
$totalOrderRevenue = (float)$revStmt->fetchColumn();

$pendingCount   = $tabCounts['pending_confirmation'];
$completedCount = $tabCounts['completed'];

// ── PENDING WHATSAPP UPDATES ─────────────────────────────────────
$waPendingStmt = $db->prepare("SELECT wn.*, o.order_ref, o.customer_name FROM wa_notifications wn
    JOIN orders o ON o.id = wn.order_id
    WHERE wn.user_id=? AND wn.status='pending' ORDER BY wn.created_at DESC LIMIT 20");
$waPendingStmt->execute([$uid]);
$waPending = $waPendingStmt->fetchAll();
$waPendingCount = count($waPending);

// ── Render the orders table + pagination once, into a buffer. Used as-is
// for a normal page load, and returned raw (plus a JSON export payload)
// for an ajax=1 request so search/date filters can swap it in live.
$isAjax = isset($_GET['ajax']);

ob_start();
if (empty($orders)):
?>
<div class="empty-state">
  <div class="empty-icon"><i class="ti ti-shopping-cart"></i></div>
  <div class="empty-title">No orders found</div>
  <div class="empty-sub"><?= $search||$statusFilter||$dateFrom||$dateTo?'Try a different filter or search.':'Share your payment links to start receiving orders.' ?></div>
</div>
<?php
else:
?>
<table>
  <thead>
    <tr>
      <th>Order Ref</th>
      <th>Customer</th>
      <th>Link</th>
      <th>Amount</th>
      <th>Status</th>
      <th>Date</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($orders as $o):
    $statusMap = [
      'pending_confirmation' => ['warning','clock','Pending Confirm'],
      'confirmed'            => ['purple','check','Confirmed'],
      'pending_payment'      => ['warning','credit-card','Awaiting Payment'],
      'paid'                 => ['success','circle-check','Paid'],
      'completed'            => ['success','checks','Completed'],
      'declined'             => ['danger','x','Declined'],
      'cancelled'            => ['neutral','minus','Cancelled'],
      'refunded'             => ['neutral','refresh','Refunded'],
    ];
    [$sc,$si,$sl] = $statusMap[$o['status']] ?? ['neutral','dot','Unknown'];
  ?>
  <tr>
    <td>
      <div class="td-bold" style="font-family:var(--font-mono);font-size:12px">
        <?= htmlspecialchars($o['order_ref']) ?>
        <button class="ref-copy-btn" title="Copy ref" onclick="copyRef('<?= htmlspecialchars(addslashes($o['order_ref'])) ?>')"><i class="ti ti-copy"></i></button>
      </div>
      <div style="font-size:11px;color:var(--muted)"><?= nigeriaTime($o['created_at']) ?></div>
    </td>
    <td>
      <div class="td-bold"><?= htmlspecialchars($o['customer_name']) ?></div>
      <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($o['customer_phone']) ?></div>
    </td>
    <td style="font-size:13px;color:var(--purple-600);font-weight:500"><?= htmlspecialchars($o['link_title']) ?></td>
    <td>
      <div class="td-bold"><?= formatNaira(orderNgnAmount($o)) ?></div>
      <?php if ($paidIn = orderPaidInLabel($o)): ?>
      <div style="font-size:11px;color:var(--muted)">customer paid <?= htmlspecialchars($paidIn) ?></div>
      <?php elseif ($o['delivery_fee'] > 0): ?>
      <div style="font-size:11px;color:var(--muted)">incl. <?= formatNaira($o['delivery_fee']) ?> delivery</div>
      <?php endif; ?>
    </td>
    <td><span class="badge badge-<?= $sc ?>"><i class="ti ti-<?= $si ?>"></i><?= $sl ?></span></td>
    <td style="font-size:12px;color:var(--muted)"><?= timeAgo($o['created_at']) ?></td>
    <td>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($o['status'] === 'pending_confirmation'): ?>
        <!-- Confirm button -->
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button type="submit" name="action" value="confirm" class="btn btn-success btn-sm" onclick="return confirm('Confirm this order from <?= htmlspecialchars(addslashes($o['customer_name'])) ?>?')">
            <i class="ti ti-check"></i> Confirm
          </button>
        </form>
        <!-- Decline button -->
        <button onclick="openDeclineModal(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['customer_name'])) ?>')" class="btn btn-danger btn-sm">
          <i class="ti ti-x"></i> Decline
        </button>
        <?php elseif ($o['status'] === 'paid'): ?>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button type="submit" name="action" value="complete" class="btn btn-purple btn-sm">
            <i class="ti ti-checks"></i> Mark Complete
          </button>
        </form>
        <?php endif; ?>
        <button onclick="openWaModal(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['customer_name'])) ?>')" class="btn btn-wa-outline btn-sm" title="Send WhatsApp update">
          <i class="ti ti-brand-whatsapp"></i>
        </button>
        <button onclick="viewOrder(<?= htmlspecialchars(json_encode($o)) ?>)" class="btn btn-outline btn-sm">
          <i class="ti ti-eye"></i>
        </button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<!-- PAGINATION -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <span class="pag-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> orders</span>
  <div class="pag-btns">
    <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
    <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
    <a href="?page=<?= $i ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>&sort=<?= $sort ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>&sort=<?= $sort ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
endif;
$resultsHtml = ob_get_clean();

// CSV export: amount is the naira value; "paid_in" keeps what a dollar/cedi buyer actually paid.
$exportData = array_map(function($o){
    return [
        'ref'      => $o['order_ref'],
        'customer' => $o['customer_name'],
        'phone'    => $o['customer_phone'],
        'link'     => $o['link_title'],
        'amount'   => orderNgnAmount($o),
        'paid_in'  => orderPaidInLabel($o) ?? '',
        'status'   => $o['status'],
        'date'     => $o['created_at'],
    ];
}, $orders);

if ($isAjax) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $resultsHtml;
    echo "\n<!--EXPORT_DATA-->\n";
    echo json_encode($exportData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── page layout — shared app.css tokens, matching payment-links.php ── */
.or-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.or-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px;margin:0}
.or-header p{font-size:14px;color:var(--muted);margin:2px 0 0}

/* ── summary stat cards ──────────────────────────── */
.or-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px}
.or-stat-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:16px;display:flex;align-items:center;gap:12px;min-width:0}
.or-stat-ic{width:38px;height:38px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.or-stat-ic.purple{background:var(--purple-50);color:var(--purple-600)}
.or-stat-ic.warning{background:var(--warning-bg);color:var(--warning)}
.or-stat-ic.success{background:var(--success-bg);color:var(--success)}
.or-stat-text{min-width:0;flex:1}
.or-stat-val{font-family:var(--font-display);font-size:18px;font-weight:800;color:var(--ink);line-height:1.2;overflow-wrap:anywhere;word-break:break-word}
.or-stat-label{font-size:11.5px;color:var(--muted);margin-top:1px}

/* ── status tabs ─────────────────────────────────── */
.or-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:18px;overflow-x:auto}
.or-tab{display:inline-flex;align-items:center;gap:6px;padding:12px 18px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;color:var(--muted);white-space:nowrap;transition:color .2s}
.or-tab.active{border-bottom-color:var(--purple-600);color:var(--purple-600)}
.or-tab-count{font-size:10px;font-weight:700;padding:2px 7px;border-radius:var(--r-full);background:var(--line);color:var(--ink)}
.or-tab.active .or-tab-count{background:var(--purple-600);color:#fff}

/* ── filters bar ─────────────────────────────────── */
.or-filters{display:flex;align-items:flex-end;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.or-search-wrap{position:relative;flex:1;min-width:200px}
.or-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted-2);font-size:16px;pointer-events:none}
.or-search{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--line);border-radius:var(--r-md);padding:10px 14px 10px 36px;outline:none;background:var(--paper);transition:border-color .2s}
.or-search:focus{border-color:var(--purple-500)}
.or-field{display:flex;flex-direction:column;gap:4px}
.or-field label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.3px}
.or-field input[type="date"],.or-sort{font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--ink);border:1.5px solid var(--line);border-radius:var(--r-md);padding:8.5px 12px;background:var(--paper);outline:none}
.or-sort{cursor:pointer;appearance:none;padding-right:30px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23726E85' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center}
.or-field input[type="date"]:focus,.or-sort:focus{border-color:var(--purple-500)}

/* table wrap, reused from payment-links pattern */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg)}
.table-wrap table{min-width:760px;width:100%;border-collapse:collapse}
.table-wrap thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line)}
.table-wrap tbody td{padding:14px 16px;border-bottom:1px solid var(--line);font-size:13px;color:var(--ink);vertical-align:middle}
.table-wrap tbody tr:last-child td{border-bottom:none}
.table-wrap tbody tr:hover{background:var(--surface)}
.td-bold{font-weight:600;color:var(--ink)}
.ref-copy-btn{background:none;border:none;cursor:pointer;color:var(--muted-2);padding:2px;font-size:12px;vertical-align:middle;margin-left:4px}
.ref-copy-btn:hover{color:var(--purple-600)}

.or-loading{opacity:.5;pointer-events:none;transition:opacity .15s}

/* pagination — matches pl-pagination */
.pagination{background:var(--paper);border-top:1px solid var(--line);margin-top:0;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;flex-wrap:wrap;gap:8px}
.pag-info{font-size:13px;color:var(--muted)}
.pag-btns{display:flex;gap:4px}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:var(--r-md);font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;border:1.5px solid var(--line);transition:all .15s}
.pag-btn:hover{border-color:var(--purple-500);color:var(--purple-600)}
.pag-btn.active{background:var(--purple-600);border-color:var(--purple-600);color:#fff}

/* modal base (.modal/.modal-box/.modal-head/.modal-body/.modal-foot) and
   .form-input/.form-textarea are now defined globally in header.php —
   this page just adds a taller view-modal since order details run long. */
#view-modal .modal-box{ max-width:540px; }

/* order detail modal — receipt-style breakdown + timeline */
.od-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted-2);margin-bottom:6px}
.od-timeline-item{display:flex;gap:10px;align-items:flex-start}
.od-timeline-dot{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px}
.od-timeline-connector{width:1px;height:12px;background:var(--line);margin-left:10.5px}

/* ── WhatsApp pending-updates banner + modals ────────────────── */
.wa-banner{display:flex;align-items:center;gap:14px;background:#E9FBF0;border:1.5px solid #BBF7D0;border-radius:var(--r-lg);padding:14px 18px;margin-bottom:20px;flex-wrap:wrap}
.wa-banner-icon{width:36px;height:36px;border-radius:50%;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.btn-wa-outline{background:none;border:1.5px solid #25D366;color:#1a9c4c}
.btn-wa-outline:hover{background:#25D366;color:#fff}
.wa-pending-item{display:flex;align-items:flex-start;gap:12px;padding:14px 0;border-bottom:1px solid var(--line)}
.wa-pending-item:last-child{border-bottom:none}
.wa-pending-msg{font-size:12.5px;color:var(--muted);background:var(--surface);border-radius:var(--r-md);padding:8px 10px;margin-top:6px;white-space:pre-wrap;max-height:80px;overflow-y:auto}
</style>

<div class="or-header">
  <div>
    <h1>Orders</h1>
    <p>Manage and confirm customer orders</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="exportOrdersCsv()" class="btn btn-outline btn-md"><i class="ti ti-download"></i> Export CSV</button>
    <a href="/payment-links.php" class="btn btn-outline btn-md"><i class="ti ti-link"></i> My Links</a>
  </div>
</div>

<?php if ($waPendingCount > 0): ?>
<div class="wa-banner">
  <div class="wa-banner-icon"><i class="ti ti-brand-whatsapp"></i></div>
  <div style="flex:1">
    <div style="font-size:14px;font-weight:700;color:var(--ink)"><?= $waPendingCount ?> WhatsApp update<?= $waPendingCount!=1?'s':'' ?> ready to send</div>
    <div style="font-size:12.5px;color:var(--muted)">Order confirmations, completions, and declines are queued here — one click sends the pre-written message.</div>
  </div>
  <button class="btn btn-purple btn-md" onclick="openModal('wa-pending-modal')"><i class="ti ti-brand-whatsapp"></i> Review &amp; Send</button>
</div>
<?php endif; ?>

<!-- SUMMARY STATS -->

<div class="or-stats-grid">
  <div class="or-stat-card">
    <div class="or-stat-ic purple"><i class="ti ti-shopping-cart"></i></div>
    <div class="or-stat-text"><div class="or-stat-val"><?= $allOrdersCount ?></div><div class="or-stat-label">Total Orders</div></div>
  </div>
  <div class="or-stat-card">
    <div class="or-stat-ic warning"><i class="ti ti-clock"></i></div>
    <div class="or-stat-text"><div class="or-stat-val"><?= $pendingCount ?></div><div class="or-stat-label">Awaiting Confirmation</div></div>
  </div>
  <div class="or-stat-card">
    <div class="or-stat-ic success"><i class="ti ti-checks"></i></div>
    <div class="or-stat-text"><div class="or-stat-val"><?= $completedCount ?></div><div class="or-stat-label">Completed</div></div>
  </div>
  <div class="or-stat-card">
    <div class="or-stat-ic success"><i class="ti ti-cash"></i></div>
    <div class="or-stat-text"><div class="or-stat-val"><?= formatNaira($totalOrderRevenue) ?></div><div class="or-stat-label">Revenue (Paid+)</div></div>
  </div>
</div>

<!-- STATUS TABS -->
<div class="or-tabs">
  <?php
  $tabs = [
    ''=>'All',
    'pending_confirmation'=>'Pending',
    'confirmed'=>'Confirmed',
    'paid'=>'Paid',
    'completed'=>'Completed',
    'declined'=>'Declined',
  ];
  foreach ($tabs as $val=>$label):
    $isActive = $statusFilter === $val;
    $count = $tabCounts[$val] ?? null;
  ?>
  <a href="?status=<?= $val ?>&q=<?= urlencode($search) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>&sort=<?= $sort ?>"
     class="or-tab <?= $isActive?'active':'' ?>">
    <?= $label ?>
    <?php if ($count !== null && $count > 0): ?><span class="or-tab-count"><?= $count ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- FILTERS -->
<div class="or-filters">
  <div class="or-search-wrap">
    <i class="ti ti-search"></i>
    <input type="text" id="or-search-input" class="or-search" placeholder="Search by name, phone, ref..." value="<?= htmlspecialchars($search) ?>"
      oninput="orLiveSearch()">
  </div>
  <div class="or-field">
    <label>From</label>
    <input type="date" id="or-from" value="<?= htmlspecialchars($dateFrom) ?>" onchange="orLiveSearch()">
  </div>
  <div class="or-field">
    <label>To</label>
    <input type="date" id="or-to" value="<?= htmlspecialchars($dateTo) ?>" onchange="orLiveSearch()">
  </div>
  <select class="or-sort" onchange="updateFilter('sort', this.value)">
    <option value="newest"      <?= $sort==='newest'?'selected':'' ?>>Newest first</option>
    <option value="oldest"      <?= $sort==='oldest'?'selected':'' ?>>Oldest first</option>
    <option value="amount_high" <?= $sort==='amount_high'?'selected':'' ?>>Highest amount</option>
    <option value="amount_low"  <?= $sort==='amount_low'?'selected':'' ?>>Lowest amount</option>
  </select>
  <?php if ($dateFrom || $dateTo || $search || $statusFilter): ?>
  <a href="/orders.php" class="btn btn-outline btn-md" style="padding:8.5px 14px"><i class="ti ti-x"></i> Clear</a>
  <?php endif; ?>
</div>

<!-- ORDERS TABLE -->
<div class="table-wrap" id="or-results"><?= $resultsHtml ?></div>

<!-- DECLINE MODAL -->
<div class="modal" id="decline-modal">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Decline Order</div>
      <button class="modal-close" onclick="closeModal('decline-modal')"><i class="ti ti-x"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <div class="modal-body">
        <input type="hidden" name="order_id" id="decline-order-id">
        <input type="hidden" name="action" value="decline">
        <p style="font-size:14px;color:var(--muted);margin-bottom:16px">You are declining the order from <strong id="decline-customer-name" style="color:var(--ink)"></strong>. Please provide a reason so we can notify the customer.</p>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Reason for declining</label>
          <textarea name="decline_reason" class="form-textarea" placeholder="e.g. Item is out of stock, unable to fulfill today..." required></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('decline-modal')" class="btn btn-outline btn-md">Cancel</button>
        <button type="submit" class="btn btn-danger btn-md"><i class="ti ti-x"></i> Decline Order</button>
      </div>
    </form>
  </div>
</div>

<!-- WHATSAPP: PENDING UPDATES MODAL -->
<div class="modal" id="wa-pending-modal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <div class="modal-title"><i class="ti ti-brand-whatsapp" style="color:#25D366"></i> Pending WhatsApp Updates</div>
      <button class="modal-close" onclick="closeModal('wa-pending-modal')"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body">
      <?php if (empty($waPending)): ?>
      <p style="font-size:13px;color:var(--muted);text-align:center;padding:20px">Nothing pending — you're all caught up.</p>
      <?php else: foreach ($waPending as $wn): ?>
      <div class="wa-pending-item">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700;color:var(--ink)"><?= htmlspecialchars($wn['customer_name']) ?> <span style="font-weight:400;color:var(--muted);font-family:var(--font-mono);font-size:11.5px"><?= htmlspecialchars($wn['order_ref']) ?></span></div>
          <span class="badge badge-purple" style="margin-top:4px"><?= htmlspecialchars(waEventLabel($wn['event_type'])) ?></span>
          <div class="wa-pending-msg"><?= nl2br(htmlspecialchars($wn['message_body'])) ?></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
          <form method="POST" action="/wa-send" target="_blank">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $wn['id'] ?>">
            <button type="submit" class="btn btn-purple btn-sm" style="background:#25D366;border-color:#25D366;white-space:nowrap"><i class="ti ti-brand-whatsapp"></i> Send</button>
          </form>
          <form method="POST" onsubmit="return confirm('Dismiss this update without sending?')">
            <?= csrfField() ?>
            <input type="hidden" name="wa_id" value="<?= $wn['id'] ?>">
            <button type="submit" name="action" value="wa_dismiss" class="btn btn-outline btn-sm" style="white-space:nowrap">Dismiss</button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- WHATSAPP: MANUAL TEMPLATE MODAL (per order) -->
<div class="modal" id="wa-compose-modal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-head">
      <div class="modal-title"><i class="ti ti-brand-whatsapp" style="color:#25D366"></i> Send WhatsApp Update</div>
      <button class="modal-close" onclick="closeModal('wa-compose-modal')"><i class="ti ti-x"></i></button>
    </div>
    <form method="POST" action="/wa-compose" target="_blank" id="wa-compose-form">
      <?= csrfField() ?>
      <div class="modal-body">
        <input type="hidden" name="order_id" id="wa-compose-order-id">
        <p style="font-size:13px;color:var(--muted);margin-bottom:14px">Sending to <strong id="wa-compose-customer-name" style="color:var(--ink)"></strong>. Pick a template — it's built from this order's current data.</p>
        <div class="form-group">
          <label class="form-label">Template</label>
          <select name="event" class="form-select" id="wa-compose-event" onchange="document.getElementById('wa-compose-custom-wrap').style.display = this.value === 'custom' ? 'block' : 'none'">
            <option value="order_confirmed">Order Confirmed</option>
            <option value="payment_received">Payment Received</option>
            <option value="out_for_delivery">Out for Delivery</option>
            <option value="order_completed">Order Completed</option>
            <option value="custom">Custom Message</option>
          </select>
        </div>
        <div class="form-group" id="wa-compose-custom-wrap" style="display:none;margin-bottom:0">
          <label class="form-label">Custom message</label>
          <textarea name="custom_text" class="form-textarea" placeholder="Type your message..."></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('wa-compose-modal')" class="btn btn-outline btn-md">Cancel</button>
        <button type="submit" class="btn btn-purple btn-md" style="background:#25D366;border-color:#25D366"><i class="ti ti-brand-whatsapp"></i> Open in WhatsApp</button>
      </div>
    </form>
  </div>
</div>

<!-- VIEW ORDER MODAL -->
<div class="modal" id="view-modal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <div class="modal-title">Order Details</div>
      <button class="modal-close" onclick="closeModal('view-modal')"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body" id="view-modal-body"></div>
  </div>
</div>

<script>
let ordersExportData = <?= json_encode($exportData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function exportOrdersCsv() {
  if (!ordersExportData.length) {
    showToast('info', 'Nothing to export', 'No orders match the current filters.');
    return;
  }
  const headers = ['Order Ref','Customer','Phone','Link','Amount (₦)','Customer Paid','Status','Date'];
  const rows = ordersExportData.map(o => [o.ref, o.customer, o.phone, o.link, o.amount, o.paid_in, o.status, o.date]);
  const csv = [headers, ...rows]
    .map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(','))
    .join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'kweek-orders-' + new Date().toISOString().slice(0,10) + '.csv';
  link.click();
  URL.revokeObjectURL(link.href);
}

function updateFilter(key, value) {
  const u = new URL(location);
  if (value) u.searchParams.set(key, value); else u.searchParams.delete(key);
  u.searchParams.set('page', 1);
  location = u;
}

// ── LIVE SEARCH — filters in place as you type, no page reload ────────
let orSearchTimer;
function orLiveSearch() {
  clearTimeout(orSearchTimer);
  orSearchTimer = setTimeout(runOrLiveSearch, 300);
}

function runOrLiveSearch() {
  const q    = document.getElementById('or-search-input').value;
  const from = document.getElementById('or-from').value;
  const to   = document.getElementById('or-to').value;

  const url = new URL(window.location);
  if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
  if (from) url.searchParams.set('from', from); else url.searchParams.delete('from');
  if (to) url.searchParams.set('to', to); else url.searchParams.delete('to');
  url.searchParams.set('page', 1);
  window.history.replaceState({}, '', url);

  const fetchUrl = new URL(url);
  fetchUrl.searchParams.set('ajax', '1');

  const results = document.getElementById('or-results');
  results.classList.add('or-loading');

  fetch(fetchUrl)
    .then(r => r.text())
    .then(text => {
      const [html, jsonPart] = text.split('<!--EXPORT_DATA-->');
      results.innerHTML = html;
      results.classList.remove('or-loading');
      try { ordersExportData = JSON.parse(jsonPart); } catch (e) { /* keep previous data */ }
    })
    .catch(() => {
      results.classList.remove('or-loading');
      showToast('error', 'Search failed', 'Could not load results.');
    });
}

function copyRef(ref) {
  navigator.clipboard.writeText(ref).then(() => showToast('success', 'Copied', 'Order ref copied to clipboard'));
}

function openDeclineModal(orderId, name) {
  document.getElementById('decline-order-id').value = orderId;
  document.getElementById('decline-customer-name').textContent = name;
  openModal('decline-modal');
}

function openWaModal(orderId, name) {
  document.getElementById('wa-compose-order-id').value = orderId;
  document.getElementById('wa-compose-customer-name').textContent = name;
  document.getElementById('wa-compose-event').value = 'order_confirmed';
  document.getElementById('wa-compose-custom-wrap').style.display = 'none';
  openModal('wa-compose-modal');
}

function viewOrder(order) {
  const items = order.items ? JSON.parse(order.items) : [];
  const customFields = order.custom_fields ? JSON.parse(order.custom_fields) : null;

  // The item breakdown is in the currency the customer paid in; the headline
  // total and "you receive" figure are always naira.
  const cur      = order.currency || 'NGN';
  const SYMBOLS  = { NGN: '₦', USD: '$', GHS: 'GH₵' };
  const sym      = SYMBOLS[cur] || '₦';
  const foreign  = cur !== 'NGN';
  const fmt      = (n, s) => s + (parseFloat(n || 0)).toLocaleString(undefined, { maximumFractionDigits: 2 });
  const money    = n => fmt(n, sym);   // what the customer sees
  const ngn      = n => fmt(n, '₦');   // what lands in the wallet
  const ngnTotal = foreign ? parseFloat(order.ngn_amount || 0) : parseFloat(order.total_amount || 0);

  const itemsHtml = items.length
    ? items.map(i => `<div style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--surface);border-radius:8px;font-size:13px;margin-bottom:4px"><span>${i.name||i.item_name||''} ${i.qty>1?'x'+i.qty:''}</span><strong>${money(i.price||i.item_price||0)}</strong></div>`).join('')
    : '<p style="font-size:13px;color:var(--muted)">No item details.</p>';

  const customFieldsHtml = (customFields && Object.keys(customFields).length)
    ? Object.entries(customFields).map(([k,v]) => `<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed var(--line);font-size:13px"><span style="color:var(--muted)">${k}</span><span style="font-weight:600;color:var(--ink);text-align:right">${v}</span></div>`).join('')
    : '';

  const subtotal    = parseFloat(order.subtotal || order.total_amount || 0);
  const deliveryFee = parseFloat(order.delivery_fee || 0);

  // Status timeline, built from whichever timestamp columns are actually set
  const timeline = [];
  timeline.push({ label: 'Order placed', time: order.created_at, icon: 'shopping-cart' });
  if (order.confirmed_at) timeline.push({ label: 'Confirmed', time: order.confirmed_at, icon: 'check' });
  if (order.declined_at)  timeline.push({ label: 'Declined' + (order.decline_reason ? ': ' + order.decline_reason : ''), time: order.declined_at, icon: 'x', danger: true });
  if (order.paid_at)      timeline.push({ label: 'Payment received', time: order.paid_at, icon: 'circle-check' });
  if (order.status === 'completed') timeline.push({ label: 'Completed', time: order.updated_at, icon: 'checks' });

  const timelineHtml = timeline.map(t => `
    <div class="od-timeline-item">
      <div class="od-timeline-dot" style="background:${t.danger?'var(--danger-bg)':'var(--success-bg)'};color:${t.danger?'var(--danger)':'var(--success)'}"><i class="ti ti-${t.icon}"></i></div>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--ink)">${t.label}</div>
        <div style="font-size:11.5px;color:var(--muted)">${t.time ? new Date(t.time.replace(' ','T')).toLocaleString() : ''}</div>
      </div>
    </div>
  `).join('<div class="od-timeline-connector"></div>');

  document.getElementById('view-modal-body').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:18px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div><div class="od-section-label">Order Ref</div><div style="font-size:13px;font-weight:600;font-family:var(--font-mono)">${order.order_ref}</div></div>
        <div><div class="od-section-label">Status</div><div style="font-size:13px;font-weight:600;text-transform:capitalize">${order.status.replace(/_/g,' ')}</div></div>
        <div><div class="od-section-label">Customer</div><div style="font-size:13px;font-weight:600">${order.customer_name}</div><div style="font-size:12px;color:var(--muted)">${order.customer_phone}${order.customer_email ? ' · ' + order.customer_email : ''}</div></div>
        <div>
          <div class="od-section-label">Total</div>
          <div style="font-size:18px;font-weight:800;color:var(--ink)">${ngn(ngnTotal)}</div>
          ${foreign ? `<div style="font-size:12px;color:var(--muted)">Customer paid ${money(order.total_amount)}</div>` : ''}
        </div>
      </div>

      <div>
        <div class="od-section-label">Items${foreign ? ' (priced in ' + cur + ')' : ''}</div>
        ${itemsHtml}
        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--line);display:flex;flex-direction:column;gap:4px">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted)"><span>Subtotal</span><span>${money(subtotal)}</span></div>
          ${deliveryFee > 0 ? `<div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted)"><span>Delivery${order.delivery_zone ? ' (' + order.delivery_zone + ')' : ''}</span><span>${money(deliveryFee)}</span></div>` : ''}
          <div style="display:flex;justify-content:space-between;font-size:13.5px;font-weight:700;color:var(--ink)"><span>Total</span><span>${money(order.total_amount)}</span></div>
          ${foreign ? `<div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted);margin-top:6px;padding-top:6px;border-top:1px dashed var(--line)"><span>You receive, before fees${order.exchange_rate ? ' (at ' + sym + '1 = ₦' + parseFloat(order.exchange_rate).toLocaleString() + ')' : ''}</span><strong style="color:var(--ink)">${ngn(ngnTotal)}</strong></div>` : ''}
        </div>
      </div>

      ${order.delivery_address ? `<div><div class="od-section-label">Delivery Address</div><div style="font-size:13px;color:var(--ink)">${order.delivery_address}</div></div>` : ''}

      ${customFieldsHtml ? `<div><div class="od-section-label">Additional Details</div>${customFieldsHtml}</div>` : ''}

      <div>
        <div class="od-section-label" style="margin-bottom:10px">Timeline</div>
        ${timelineHtml}
      </div>

      ${order.nomba_txn_ref ? `<div style="font-size:11.5px;color:var(--muted-2);font-family:var(--font-mono)">Payment ref: ${order.nomba_txn_ref}</div>` : ''}

      ${order.merchant_note ? `<div style="background:var(--purple-50);border:1px solid var(--purple-100);border-radius:10px;padding:12px;font-size:13px;color:var(--purple-600)"><strong>Your note:</strong> ${order.merchant_note}</div>` : ''}

      <button type="button" class="btn btn-purple btn-md" style="background:#25D366;border-color:#25D366;width:100%;justify-content:center" onclick="closeModal('view-modal');openWaModal(${order.id}, '${order.customer_name.replace(/'/g,"\\'")}')">
        <i class="ti ti-brand-whatsapp"></i> Send WhatsApp Update
      </button>
    </div>
  `;
  openModal('view-modal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>