<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/nomba.php';
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

                    notify(
                        $uid,
                        'order_confirmed',
                        'Order Confirmed',
                        'You confirmed an order from ' . $order['customer_name'] . ' for ' . formatNaira($order['total_amount']),
                        'check',
                        '/orders.php?id=' . $orderId
                    );

                    if (!empty($order['customer_email'])) {
                        sendOrderApprovedEmail($order, $checkoutUrl, $user['name'], $order['link_title']);
                    }

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

        } elseif ($action === 'complete' && $order['status'] === 'paid') {
            $db->prepare("UPDATE orders SET status='completed' WHERE id=?")->execute([$orderId]);
            flash('success','Order marked as completed.');

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
$page          = max(1,(int)get('page',1));
$perPage       = 15;
$offset        = ($page-1)*$perPage;

$where  = ["o.user_id = ?"];
$params = [$uid];
if ($statusFilter) { $where[] = "o.status = ?"; $params[] = $statusFilter; }
if ($search)       { $where[] = "(o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.order_ref LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = ceil($total/$perPage);

$stmt = $db->prepare("SELECT o.*, pl.title as link_title, pl.slug FROM orders o JOIN payment_links pl ON o.link_id=pl.id WHERE $whereSQL ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Status counts for tabs
$tabCounts = [];
foreach (['pending_confirmation','confirmed','paid','completed'] as $s) {
    $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status=?");
    $st->execute([$uid,$s]);
    $tabCounts[$s] = (int)$st->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── MODAL: dark overlay, no blue background ─────────────────── */
.modal {
  background: rgba(15,14,28,0.55) !important;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
/* Scrollable tall modals */
#view-modal .modal-box {
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
#view-modal .modal-body {
  overflow-y: auto;
  flex: 1;
  -webkit-overflow-scrolling: touch;
}
/* Table horizontal scroll */
.table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.table-wrap table { min-width: 720px; }

/* Textarea for decline reason */
.form-textarea {
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--n800);
  background: var(--n50);
  border: 1.5px solid var(--n200);
  border-radius: var(--r-md);
  padding: 11px 14px;
  outline: none;
  transition: border-color .2s, box-shadow .2s;
  width: 100%;
  resize: vertical;
  min-height: 88px;
}
.form-textarea:focus {
  background: var(--n0);
  border-color: var(--p500);
  box-shadow: 0 0 0 3px var(--p50);
}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px">Orders</h1>
    <p style="font-size:14px;color:var(--n500);margin-top:2px">Manage and confirm customer orders</p>
  </div>
  <a href="/payment-links.php" class="btn btn-outline btn-md"><i class="ti ti-link"></i> My Links</a>
</div>

<!-- STATUS TABS -->
<div style="display:flex;gap:0;border-bottom:1px solid var(--n200);margin-bottom:20px;overflow-x:auto">
  <?php
  $tabs = [
    ''=>['All',''],
    'pending_confirmation'=>['Pending','warning'],
    'confirmed'=>['Confirmed','purple'],
    'paid'=>['Paid','success'],
    'completed'=>['Completed','success'],
    'declined'=>['Declined','danger'],
  ];
  foreach ($tabs as $val=>[$label,$color]):
    $isActive = $statusFilter === $val;
    $count = $tabCounts[$val] ?? null;
  ?>
  <a href="?status=<?= $val ?>&q=<?= urlencode($search) ?>"
     style="display:inline-flex;align-items:center;gap:6px;padding:12px 18px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?= $isActive?'var(--p600)':'transparent' ?>;color:<?= $isActive?'var(--p600)':'var(--n500)' ?>;white-space:nowrap;transition:color .2s">
    <?= $label ?>
    <?php if ($count !== null && $count > 0): ?>
    <span style="background:<?= $isActive?'var(--p600)':'var(--n200)' ?>;color:<?= $isActive?'#fff':'var(--n600)' ?>;font-size:10px;font-weight:700;padding:2px 7px;border-radius:var(--r-full)"><?= $count ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- SEARCH -->
<div style="position:relative;max-width:340px;margin-bottom:16px">
  <i class="ti ti-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--n400);font-size:16px"></i>
  <input type="text" placeholder="Search by name, phone, ref..." value="<?= htmlspecialchars($search) ?>"
    style="width:100%;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:10px 14px 10px 36px;outline:none;background:var(--n0);transition:all .2s"
    oninput="clearTimeout(window._st);window._st=setTimeout(()=>{const u=new URL(location);u.searchParams.set('q',this.value);u.searchParams.set('page',1);location=u},600)">
</div>

<!-- ORDERS TABLE -->
<div class="table-wrap">
  <?php if (empty($orders)): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="ti ti-shopping-cart"></i></div>
    <div class="empty-title">No orders found</div>
    <div class="empty-sub"><?= $search||$statusFilter?'Try a different filter or search.':'Share your payment links to start receiving orders.' ?></div>
  </div>
  <?php else: ?>
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
        <div class="td-bold" style="font-family:monospace;font-size:12px"><?= htmlspecialchars($o['order_ref']) ?></div>
        <div style="font-size:11px;color:var(--n500)"><?= nigeriaTime($o['created_at']) ?></div>
      </td>
      <td>
        <div class="td-bold"><?= htmlspecialchars($o['customer_name']) ?></div>
        <div style="font-size:12px;color:var(--n500)"><?= htmlspecialchars($o['customer_phone']) ?></div>
      </td>
      <td style="font-size:13px;color:var(--p600);font-weight:500"><?= htmlspecialchars($o['link_title']) ?></td>
      <td>
        <div class="td-bold"><?= formatNaira($o['total_amount']) ?></div>
        <?php if ($o['delivery_fee'] > 0): ?>
        <div style="font-size:11px;color:var(--n500)">incl. <?= formatNaira($o['delivery_fee']) ?> delivery</div>
        <?php endif; ?>
      </td>
      <td><span class="badge badge-<?= $sc ?>"><i class="ti ti-<?= $si ?>"></i><?= $sl ?></span></td>
      <td style="font-size:12px;color:var(--n500)"><?= timeAgo($o['created_at']) ?></td>
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
      <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-left"></i></a><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
      <a href="?page=<?= $i ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>" class="pag-btn"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

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
        <p style="font-size:14px;color:var(--n600);margin-bottom:16px">You are declining the order from <strong id="decline-customer-name"></strong>. Please provide a reason so we can notify the customer.</p>
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
function openDeclineModal(orderId, name) {
  document.getElementById('decline-order-id').value = orderId;
  document.getElementById('decline-customer-name').textContent = name;
  openModal('decline-modal');
}

function viewOrder(order) {
  const items = order.items ? JSON.parse(order.items) : [];
  const itemsHtml = items.length ? items.map(i=>`<div style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--n50);border-radius:8px;font-size:13px;margin-bottom:4px"><span>${i.name||i.item_name||''} ${i.qty>1?'x'+i.qty:''}</span><strong>₦${parseFloat(i.price||i.item_price||0).toLocaleString()}</strong></div>`).join('') : '<p style="font-size:13px;color:var(--n500)">No item details.</p>';

  document.getElementById('view-modal-body').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Order Ref</div><div style="font-size:13px;font-weight:600;font-family:monospace">${order.order_ref}</div></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Status</div><div style="font-size:13px;font-weight:600;text-transform:capitalize">${order.status.replace(/_/g,' ')}</div></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Customer</div><div style="font-size:13px;font-weight:600">${order.customer_name}</div><div style="font-size:12px;color:var(--n500)">${order.customer_phone}</div></div>
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Total</div><div style="font-size:18px;font-weight:800;color:var(--n900)">₦${parseFloat(order.total_amount).toLocaleString()}</div></div>
      </div>
      ${order.delivery_zone ? `<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Delivery Zone</div><div style="font-size:13px">${order.delivery_zone} — ₦${parseFloat(order.delivery_fee).toLocaleString()}</div></div>` : ''}
      ${order.delivery_address ? `<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:4px">Address</div><div style="font-size:13px">${order.delivery_address}</div></div>` : ''}
      <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:8px">Items Ordered</div>${itemsHtml}</div>
      ${order.merchant_note ? `<div style="background:var(--p50);border:1px solid var(--p100);border-radius:10px;padding:12px;font-size:13px;color:var(--p800)"><strong>Your note:</strong> ${order.merchant_note}</div>` : ''}
    </div>
  `;
  openModal('view-modal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>