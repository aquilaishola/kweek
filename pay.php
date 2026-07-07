<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';

// Get slug from URL or query string
$slug = '';
$uri  = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#/pay/([a-z0-9\-]+)#i', $uri, $m)) {
    $slug = clean($m[1]);
} elseif (!empty($_GET['slug'])) {
    $slug = clean($_GET['slug']);
}

if (empty($slug)) {
    http_response_code(404);
    die('Payment link not found.');
}

$db = db();

// Fetch link + merchant
$stmt = $db->prepare("
    SELECT pl.*, u.name AS merchant_name, u.plan AS merchant_plan, u.id AS merchant_id
    FROM payment_links pl
    JOIN users u ON pl.user_id = u.id
    WHERE pl.slug = ?
      AND pl.status = 'active'
      AND u.status  = 'active'
    LIMIT 1
");
$stmt->execute([$slug]);
$link = $stmt->fetch();

// 404 page
function notFound(string $msg = 'Payment link not found or has been deactivated.'): never {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found — Kweek</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@700;800&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,sans-serif;background:#0F0E1C;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}
    .box{max-width:420px}.ic{width:72px;height:72px;border-radius:50%;background:rgba(79,67,212,.15);display:flex;align-items:center;justify-content:center;color:#7B6FEE;font-size:32px;margin:0 auto 20px}
    h2{font-family:Sora,sans-serif;font-size:22px;font-weight:800;color:#fff;margin-bottom:10px;letter-spacing:-.5px}
    p{font-size:14px;color:#6E6C88;line-height:1.65;margin-bottom:24px}
    a{display:inline-flex;align-items:center;gap:6px;background:#4F43D4;color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px}</style></head>
    <body><div class="box"><div class="ic"><i class="ti ti-link-off"></i></div><h2>Link Not Found</h2><p>' . htmlspecialchars($msg) . '</p><a href="/"><i class="ti ti-home"></i> Go to Kweek</a></div></body></html>';
    exit;
}

if (!$link) notFound();
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) notFound('This payment link has expired. Contact the merchant for a new one.');

// Fetch menu items (for menu/group types)
$items = [];
if (in_array($link['link_type'], ['menu', 'group'])) {
    $iStmt = $db->prepare("SELECT * FROM link_items WHERE link_id = ? AND is_available = 1 ORDER BY sort_order, id");
    $iStmt->execute([$link['id']]);
    $items = $iStmt->fetchAll();
}

$zones        = !empty($link['delivery_zones']) ? json_decode($link['delivery_zones'], true) : [];
$accentColor  = preg_match('/^#[0-9A-Fa-f]{6}$/', $link['accent_color'] ?? '') ? $link['accent_color'] : '#4F43D4';

// ── ORDER PROCESSING ─────────────────────────────────────────
$orderError    = '';
$orderSuccess  = false;
$awaitingConf  = false;
$orderTotal    = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Rate limit by IP
    if (!checkRateLimit(clientIp(), 'pay_' . $link['id'], 20, 3600)) {
        $orderError = 'Too many requests. Please wait a moment and try again.';
    } else {
        $customerName  = clean(post('customer_name'));
        $customerPhone = preg_replace('/\D/', '', post('customer_phone'));
        $customerEmail = filter_var(post('customer_email'), FILTER_VALIDATE_EMAIL) ?: null;
        $deliveryZone  = clean(post('delivery_zone'));
        $deliveryAddr  = clean(post('delivery_address'));
        $selectedQtys  = $_POST['item_qty'] ?? [];

        // ── BUILD LINE ITEMS ──────────────────────────────────
        $orderItems  = [];
        $subtotal    = 0.0;
        $deliveryFee = 0.0;

        if ($link['link_type'] === 'simple') {
            $amount = ($link['amount'] > 0)
                ? (float)$link['amount']
                : abs((float)post('custom_amount'));
            if ($amount < 1) {
                $orderError = 'Please enter a valid amount.';
            } else {
                $subtotal     = $amount;
                $orderItems[] = ['name' => $link['title'], 'price' => $amount, 'qty' => 1];
            }

        } elseif (in_array($link['link_type'], ['menu', 'group'])) {
            foreach ($items as $item) {
                $qty = max(0, (int)($selectedQtys[$item['id']] ?? 0));
                if ($qty > 0) {
                    $orderItems[] = [
                        'item_id' => $item['id'],
                        'name'    => $item['name'],
                        'price'   => (float)$item['price'],
                        'qty'     => $qty,
                    ];
                    $subtotal += (float)$item['price'] * $qty;
                }
            }
            if (empty($orderItems)) {
                $orderError = 'Please select at least one item before paying.';
            }

        } elseif ($link['link_type'] === 'installment') {
            $cfg      = json_decode($link['installment_config'] ?? '{}', true);
            $amount   = (float)($cfg['per_installment'] ?? 0);
            $subtotal = $amount;
            if ($subtotal < 1) {
                $orderError = 'Invalid installment configuration. Contact the merchant.';
            } else {
                $orderItems[] = ['name' => $link['title'] . ' — Installment', 'price' => $subtotal, 'qty' => 1];
            }
        }

        // ── DELIVERY FEE ──────────────────────────────────────
        if (empty($orderError) && !empty($zones) && $deliveryZone) {
            foreach ($zones as $z) {
                if ($z['name'] === $deliveryZone) {
                    $deliveryFee = (float)$z['fee'];
                    break;
                }
            }
        }

        $orderTotal = $subtotal + $deliveryFee;

        // ── VALIDATION ────────────────────────────────────────
        if (empty($orderError)) {
            if (strlen($customerName) < 2)   $orderError = 'Please enter your full name.';
            elseif (strlen($customerPhone) < 10) $orderError = 'Please enter a valid Nigerian phone number.';
            elseif ($orderTotal < 100)        $orderError = 'Minimum payment amount is ₦100.';
        }

        // ── MONTHLY CAP CHECK ─────────────────────────────────
        if (empty($orderError) && !checkMonthlyCap($link['merchant_id'], $orderTotal)) {
            $orderError = 'The merchant has reached their monthly transaction limit. Please contact them directly.';
        }

        // ── CREATE ORDER ──────────────────────────────────────
        if (empty($orderError)) {
            $orderRef       = generateOrderRef();
            $initialStatus  = $link['require_confirmation'] ? 'pending_confirmation' : 'confirmed';

            try {
                $db->prepare("
                    INSERT INTO orders
                        (link_id, user_id, order_ref, customer_name, customer_email, customer_phone,
                         delivery_zone, delivery_fee, delivery_address, subtotal, total_amount, items, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $link['id'],
                    $link['merchant_id'],
                    $orderRef,
                    $customerName,
                    $customerEmail,
                    $customerPhone,
                    $deliveryZone ?: null,
                    $deliveryFee,
                    $deliveryAddr ?: null,
                    $subtotal,
                    $orderTotal,
                    json_encode($orderItems),
                    $initialStatus,
                ]);

                $orderId = (int)$db->lastInsertId();

                // Bump view/order count
                $db->prepare("UPDATE payment_links SET total_orders = total_orders + 1 WHERE id = ?")
                   ->execute([$link['id']]);

                // Notify merchant
                notify(
                    $link['merchant_id'],
                    'new_order',
                    'New order from ' . $customerName,
                    formatNaira($orderTotal) . ' order on "' . $link['title'] . '"' .
                        ($link['require_confirmation'] ? ' — needs your confirmation' : ''),
                    'shopping-cart',
                    '/orders.php'
                );

                // ── REQUIRE CONFIRMATION FLOW ─────────────────
                if ($link['require_confirmation']) {
                    $orderSuccess = true;
                    $awaitingConf = true;

                } else {
                    // ── LAUNCH NOMBA CHECKOUT ─────────────────
                    $nomba  = new Nomba();
                    $redirectTarget = $link['redirect_url']
    ? $link['redirect_url'] . (str_contains($link['redirect_url'], '?') ? '&' : '?') . 'ref=' . urlencode($orderRef)
    : SITE_URL . '/receipt.php?ref=' . urlencode($orderRef);

$result = $nomba->createCheckout([
    'amount'        => $orderTotal,
    'orderRef'      => $orderRef,
    'customerName'  => $customerName,
    'customerEmail' => $customerEmail ?? 'customer@kweek.ng',
    'customerPhone' => $customerPhone,
    'description'   => 'Payment for ' . $link['title'],
    'redirectUrl'   => $redirectTarget,
    'callbackUrl'   => SITE_URL . '/webhook.php',
]);

                    $checkoutUrl = $result['data']['checkoutLink']
                        ?? $result['data']['paymentLink']
                        ?? $result['data']['url']
                        ?? '';

                    if (!empty($checkoutUrl)) {
                        // Save nomba checkout id
                        $checkoutId = $result['data']['orderId'] ?? $result['data']['id'] ?? '';
                        $db->prepare("UPDATE orders SET status = 'pending_payment', nomba_checkout_id = ? WHERE id = ?")
                           ->execute([$checkoutId, $orderId]);

                        // Redirect customer to Nomba checkout
                        header('Location: ' . $checkoutUrl);
                        exit;

                    } else {
                        // Fallback — log error but don't crash
                        error_log('Nomba checkout response: ' . json_encode($result));
                        $orderError = 'Payment gateway is temporarily unavailable. Please try again in a moment.';
                        // Roll back order creation
                        $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
                    }
                }

            } catch (Throwable $e) {
                error_log('pay.php error: ' . $e->getMessage());
                $orderError = 'Something went wrong. Please try again or contact the merchant.';
            }
        }
    }
}

// Track unique view (simple increment — no session dedupe for now)
$db->prepare("UPDATE payment_links SET view_count = view_count + 1 WHERE id = ?")->execute([$link['id']]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pay <?= htmlspecialchars($link['merchant_name']) ?> — Kweek</title>
<meta name="description" content="Pay <?= htmlspecialchars($link['merchant_name']) ?> securely via Kweek — <?= htmlspecialchars($link['title']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?= htmlspecialchars($accentColor) ?>;
  --acc-soft:<?= htmlspecialchars($accentColor) ?>22;
  --n0:#FFFFFF;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;
  --n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#16A34A;--success-bg:#DCFCE7;
  --danger:#DC2626;--danger-bg:#FEE2E2;
  --warning:#D97706;--warning-bg:#FEF3C7;
  --font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:20px;--r-full:9999px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n50);min-height:100vh;-webkit-font-smoothing:antialiased}

/* PRELOADER */
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .5s ease,visibility .5s ease}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:28px;font-weight:800;color:#fff;letter-spacing:-1px}
.pre-logo .acc{color:var(--acc)}
.pre-ring{width:40px;height:40px;border-radius:50%;border:3px solid rgba(255,255,255,.12);border-top-color:var(--acc);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* LAYOUT */
.pay-page{min-height:100vh;padding:20px 16px 60px;display:flex;flex-direction:column;align-items:center}
.pay-wrap{width:100%;max-width:480px}

/* TOP NAV */
.pay-nav{display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.pay-logo{font-family:var(--font-display);font-weight:800;font-size:20px;color:var(--n800);text-decoration:none;letter-spacing:-.5px}
.pay-logo .acc{color:var(--acc)}
.secure-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--n500);margin-left:auto;padding:4px 10px;background:var(--success-bg);border-radius:var(--r-full);font-weight:600;color:var(--success)}
.secure-tag i{font-size:13px}

/* MERCHANT CARD */
.merchant-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:24px 20px;text-align:center;margin-bottom:12px}
.merch-av{width:56px;height:56px;border-radius:50%;background:var(--acc);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-size:20px;font-weight:800;color:#fff;margin:0 auto 12px}
.merch-name{font-family:var(--font-display);font-size:17px;font-weight:800;color:var(--n900);margin-bottom:4px}
.merch-link-title{font-size:13px;color:var(--n500);line-height:1.5}
.merch-desc{font-size:13px;color:var(--n500);margin-top:8px;line-height:1.6;padding-top:10px;border-top:1px solid var(--n100)}

/* CARDS */
.pay-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:18px 16px;margin-bottom:10px}
.pay-card-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--n400);margin-bottom:14px;display:flex;align-items:center;gap:6px}
.pay-card-label i{font-size:14px;color:var(--acc)}

/* MENU ITEMS */
.menu-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px;border:1.5px solid var(--n200);border-radius:var(--r-lg);margin-bottom:8px;cursor:pointer;transition:border-color .2s,background .2s;user-select:none}
.menu-item:last-child{margin-bottom:0}
.menu-item.selected{border-color:var(--acc);background:var(--acc-soft)}
.menu-item:hover{border-color:var(--acc)}
.mi-info{flex:1;min-width:0}
.mi-name{font-size:14px;font-weight:600;color:var(--n900);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mi-desc{font-size:12px;color:var(--n500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mi-price{font-family:var(--font-display);font-size:14px;font-weight:700;color:var(--acc);white-space:nowrap;flex-shrink:0}
.qty-ctrl{display:flex;align-items:center;gap:6px;flex-shrink:0}
.qty-btn{width:28px;height:28px;border-radius:50%;border:1.5px solid var(--n200);background:var(--n0);cursor:pointer;font-size:16px;font-weight:700;line-height:1;color:var(--n600);display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
.qty-btn:hover{border-color:var(--acc);background:var(--acc);color:#fff}
.qty-display{font-size:14px;font-weight:700;color:var(--n900);min-width:22px;text-align:center}

/* SIMPLE AMOUNT */
.amount-display{font-family:var(--font-display);font-size:36px;font-weight:800;color:var(--acc);letter-spacing:-1px;text-align:center;padding:8px 0}
.amount-sub{font-size:13px;color:var(--n500);text-align:center;margin-top:4px}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-group:last-child{margin-bottom:0}
.form-label{font-size:12px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input,.form-select{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px 11px 40px;outline:none;transition:border-color .2s,box-shadow .2s,background .2s;width:100%}
.form-input:focus,.form-select:focus{background:var(--n0);border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft)}
.form-input::placeholder{color:var(--n400)}
.form-select{appearance:none;cursor:pointer;padding-right:32px}
.inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}

/* ORDER TOTAL */
.order-total{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:16px;margin-bottom:10px}
.ot-row{display:flex;align-items:center;justify-content:space-between;font-size:13px;color:var(--n600);margin-bottom:7px}
.ot-row:last-child{margin-bottom:0;font-size:16px;font-weight:800;color:var(--n900);padding-top:10px;border-top:1.5px dashed var(--n200);margin-top:7px}
.ot-row:last-child .ot-amount{color:var(--acc)}

/* PAY BUTTON */
.pay-btn-main{width:100%;padding:16px;background:var(--acc);color:#fff;border:none;border-radius:var(--r-lg);font-family:var(--font-body);font-size:16px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;transition:filter .2s,transform .2s,box-shadow .2s;margin-bottom:10px;letter-spacing:-.2px}
.pay-btn-main:hover:not(:disabled){filter:brightness(1.1);transform:translateY(-2px);box-shadow:0 8px 24px var(--acc-soft)}
.pay-btn-main:active:not(:disabled){transform:translateY(0)}
.pay-btn-main:disabled{opacity:.5;cursor:not-allowed}

/* SECURE FOOTER */
.secure-footer{display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;color:var(--n400);margin-bottom:4px}
.secure-footer i{color:var(--success);font-size:13px}

/* ERROR BOX */
.error-box{background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:12px 16px;margin-bottom:10px;display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--danger);line-height:1.5}
.error-box i{font-size:16px;flex-shrink:0;margin-top:1px}

/* AWAITING CARD */
.await-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:36px 24px;text-align:center}
.await-icon{width:68px;height:68px;border-radius:50%;background:var(--warning-bg);display:flex;align-items:center;justify-content:center;color:var(--warning);font-size:30px;margin:0 auto 18px;animation:popIn .4s ease}
@keyframes popIn{from{transform:scale(.7);opacity:0}to{transform:scale(1);opacity:1}}
.await-card h2{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:8px;letter-spacing:-.3px}
.await-card p{font-size:14px;color:var(--n500);line-height:1.7}
.await-summary{background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:16px;margin-top:20px;text-align:left}
.await-summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
.await-summary-row:last-child{margin-bottom:0;font-weight:700;color:var(--n900)}

/* POWERED BY */
.powered-by{text-align:center;margin-top:20px;font-size:12px;color:var(--n400)}
.powered-by a{color:var(--acc);text-decoration:none;font-weight:600}

@media(max-width:480px){
  .pay-page{padding:12px 12px 40px}
  .amount-display{font-size:30px}
  .pay-btn-main{font-size:15px;padding:14px}
}
</style>
</head>
<body>

<!-- PRELOADER -->
<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
</div>

<div class="pay-page">
<div class="pay-wrap">

  <!-- TOP NAV -->
  <div class="pay-nav">
    <a href="/" class="pay-logo">Kw<span class="acc">ee</span>k</a>
    <div class="secure-tag"><i class="ti ti-shield-check"></i> Secured</div>
  </div>

  <?php if ($orderSuccess && $awaitingConf): ?>
  <!-- ── AWAITING CONFIRMATION ─────────────────────────────── -->
  <div class="await-card">
    <div class="await-icon"><i class="ti ti-clock-check"></i></div>
    <h2>Order Submitted!</h2>
    <p><?= htmlspecialchars($link['merchant_name']) ?> will review your order and send you a payment link once confirmed. This usually takes a few minutes.</p>
    <div class="await-summary">
      <div class="await-summary-row"><span style="color:var(--n500)">Order for</span><span><?= htmlspecialchars($link['title']) ?></span></div>
      <div class="await-summary-row"><span style="color:var(--n500)">Merchant</span><span><?= htmlspecialchars($link['merchant_name']) ?></span></div>
      <div class="await-summary-row"><span style="color:var(--n500)">Total</span><span><?= formatNaira($orderTotal) ?></span></div>
    </div>
    <p style="margin-top:16px;font-size:12px;color:var(--n500)">You will be contacted on the phone number you provided once your order is confirmed.</p>
  </div>

  <?php else: ?>
  <!-- ── PAYMENT FORM ───────────────────────────────────────── -->

  <!-- MERCHANT INFO -->
  <div class="merchant-card">
    <div class="merch-av"><?= strtoupper(substr($link['merchant_name'], 0, 2)) ?></div>
    <div class="merch-name"><?= htmlspecialchars($link['merchant_name']) ?></div>
    <div class="merch-link-title"><?= htmlspecialchars($link['title']) ?></div>
    <?php if (!empty($link['description'])): ?>
    <div class="merch-desc"><?= nl2br(htmlspecialchars($link['description'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- ERROR BOX -->
  <?php if ($orderError): ?>
  <div class="error-box">
    <i class="ti ti-alert-circle"></i>
    <span><?= htmlspecialchars($orderError) ?></span>
  </div>
  <?php endif; ?>

  <form method="POST" id="pay-form" novalidate>

    <!-- ── SIMPLE PAYMENT ─────────────────────────────────── -->
    <?php if ($link['link_type'] === 'simple'): ?>
    <div class="pay-card">
      <div class="pay-card-label"><i class="ti ti-currency-naira"></i> Payment Amount</div>
      <?php if (!empty($link['amount']) && $link['amount'] > 0): ?>
      <div class="amount-display"><?= formatNaira($link['amount']) ?></div>
      <div class="amount-sub">Fixed payment to <?= htmlspecialchars($link['merchant_name']) ?></div>
      <?php else: ?>
      <div class="form-group" style="margin:0">
        <label class="form-label">Enter amount (₦)</label>
        <div class="inp-wrap">
          <i class="ti ti-currency-naira inp-icon"></i>
          <input type="number" name="custom_amount" class="form-input"
            placeholder="0.00" min="100" step="1"
            value="<?= htmlspecialchars(post('custom_amount')) ?>"
            oninput="refreshTotal()" required>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── MENU / GROUP ───────────────────────────────────── -->
    <?php elseif (in_array($link['link_type'], ['menu', 'group'])): ?>
    <?php if (!empty($items)): ?>
    <div class="pay-card">
      <div class="pay-card-label"><i class="ti ti-list"></i> Select Items</div>
      <?php foreach ($items as $item): ?>
      <div class="menu-item" id="item-wrap-<?= $item['id'] ?>" onclick="focusQty(<?= $item['id'] ?>)">
        <div class="mi-info">
          <div class="mi-name"><?= htmlspecialchars($item['name']) ?></div>
          <?php if ($item['description']): ?>
          <div class="mi-desc"><?= htmlspecialchars($item['description']) ?></div>
          <?php endif; ?>
          <div class="mi-price" style="margin-top:4px"><?= formatNaira($item['price']) ?></div>
        </div>
        <div class="qty-ctrl" onclick="event.stopPropagation()">
          <button type="button" class="qty-btn" onclick="changeQty(<?= $item['id'] ?>, -1)">−</button>
          <span class="qty-display" id="qty-display-<?= $item['id'] ?>">0</span>
          <button type="button" class="qty-btn" onclick="changeQty(<?= $item['id'] ?>, 1)">+</button>
        </div>
        <input type="hidden" name="item_qty[<?= $item['id'] ?>]" id="qty-input-<?= $item['id'] ?>" value="0">
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="pay-card" style="text-align:center;color:var(--n500);padding:32px">
      <i class="ti ti-package-off" style="font-size:32px;margin-bottom:8px;display:block;color:var(--n300)"></i>
      No items available right now. Contact the merchant.
    </div>
    <?php endif; ?>

    <!-- ── INSTALLMENT ────────────────────────────────────── -->
    <?php elseif ($link['link_type'] === 'installment'):
      $cfg = json_decode($link['installment_config'] ?? '{}', true);
    ?>
    <div class="pay-card" style="text-align:center">
      <div class="pay-card-label" style="justify-content:center"><i class="ti ti-credit-card-pay"></i> Installment Payment</div>
      <div class="amount-display"><?= formatNaira($cfg['per_installment'] ?? 0) ?></div>
      <div class="amount-sub">
        1 of <?= (int)($cfg['count'] ?? 2) ?> installments &nbsp;·&nbsp;
        Total: <?= formatNaira($cfg['total'] ?? 0) ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── DELIVERY ZONES ─────────────────────────────────── -->
    <?php if (!empty($zones)): ?>
    <div class="pay-card">
      <div class="pay-card-label"><i class="ti ti-map-pin"></i> Delivery Zone</div>
      <div class="form-group" style="margin-bottom:10px">
        <div class="inp-wrap">
          <i class="ti ti-motorbike inp-icon"></i>
          <select name="delivery_zone" class="form-select" onchange="refreshTotal()" required>
            <option value="">— Select your delivery area —</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= htmlspecialchars($z['name']) ?>"
                    data-fee="<?= (float)$z['fee'] ?>"
                    <?= post('delivery_zone') === $z['name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($z['name']) ?> — <?= formatNaira($z['fee']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Delivery Address <span style="color:var(--n400);font-weight:400">(optional)</span></label>
        <div class="inp-wrap">
          <i class="ti ti-home inp-icon"></i>
          <input type="text" name="delivery_address" class="form-input"
            placeholder="e.g. 14 Bode Thomas, Surulere"
            value="<?= htmlspecialchars(post('delivery_address')) ?>">
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── CUSTOMER DETAILS ───────────────────────────────── -->
    <div class="pay-card">
      <div class="pay-card-label"><i class="ti ti-user"></i> Your Details</div>
      <div class="form-group">
        <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
        <div class="inp-wrap">
          <i class="ti ti-user inp-icon"></i>
          <input type="text" name="customer_name" class="form-input"
            placeholder="Your full name"
            value="<?= htmlspecialchars(post('customer_name')) ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number <span style="color:var(--danger)">*</span></label>
        <div class="inp-wrap">
          <i class="ti ti-phone inp-icon"></i>
          <input type="tel" name="customer_phone" class="form-input"
            placeholder="08012345678"
            value="<?= htmlspecialchars(post('customer_phone')) ?>"
            maxlength="11" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email <span style="color:var(--n400);font-weight:400">(optional — for receipt)</span></label>
        <div class="inp-wrap">
          <i class="ti ti-mail inp-icon"></i>
          <input type="email" name="customer_email" class="form-input"
            placeholder="you@email.com"
            value="<?= htmlspecialchars(post('customer_email')) ?>">
        </div>
      </div>
    </div>

    <!-- ── ORDER TOTAL ────────────────────────────────────── -->
    <div class="order-total" id="order-total-section">
      <div class="ot-row" id="ot-subtotal-row">
        <span>Subtotal</span>
        <span class="ot-amount" id="ot-subtotal">
          <?php
          // Pre-fill total if simple with fixed amount
          if ($link['link_type'] === 'simple' && $link['amount'] > 0)
              echo formatNaira($link['amount']);
          elseif ($link['link_type'] === 'installment')
              echo formatNaira($cfg['per_installment'] ?? 0);
          else
              echo '₦0.00';
          ?>
        </span>
      </div>
      <div class="ot-row" id="ot-delivery-row" style="display:none">
        <span>Delivery</span>
        <span class="ot-amount" id="ot-delivery">₦0.00</span>
      </div>
      <div class="ot-row">
        <span>Total</span>
        <span class="ot-amount" id="ot-total">
          <?php
          if ($link['link_type'] === 'simple' && $link['amount'] > 0)
              echo formatNaira($link['amount']);
          elseif ($link['link_type'] === 'installment')
              echo formatNaira($cfg['per_installment'] ?? 0);
          else
              echo '₦0.00';
          ?>
        </span>
      </div>
    </div>

    <!-- ── PAY BUTTON ─────────────────────────────────────── -->
    <button type="submit" class="pay-btn-main" id="pay-btn">
      <i class="ti ti-lock"></i>
      <span id="pay-btn-text">
        <?php
        if ($link['link_type'] === 'simple' && $link['amount'] > 0)
            echo 'Pay ' . formatNaira($link['amount']) . ' Securely';
        elseif ($link['link_type'] === 'installment')
            echo 'Pay ' . formatNaira($cfg['per_installment'] ?? 0) . ' Securely';
        else
            echo 'Pay Now';
        ?>
      </span>
    </button>
    <div class="secure-footer">
      <i class="ti ti-shield-check"></i>
      Payments secured by Nomba &amp; verified by Kweek
    </div>

  </form>
  <?php endif; ?>

 <?php if (($link['merchant_plan'] ?? 'free') === 'free'): ?>
  <div class="powered-by">
    <a href="/">Powered by Kweek</a> · The payment OS for Nigerian merchants
  </div>
<?php endif; ?>

</div>
</div>

<script>
// Preloader
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader').classList.add('hide'), 900);
});

// ── ITEM DATA ────────────────────────────────────────────────
const itemPrices = {
  <?php foreach ($items as $item): ?>
  <?= $item['id'] ?>: <?= (float)$item['price'] ?>,
  <?php endforeach; ?>
};

// ── QTY CONTROLS ─────────────────────────────────────────────
const qtys = {};
<?php foreach ($items as $item): ?>
qtys[<?= $item['id'] ?>] = 0;
<?php endforeach; ?>

function changeQty(id, delta) {
  qtys[id] = Math.max(0, (qtys[id] || 0) + delta);
  document.getElementById('qty-display-' + id).textContent = qtys[id];
  document.getElementById('qty-input-' + id).value = qtys[id];
  const wrap = document.getElementById('item-wrap-' + id);
  if (wrap) wrap.classList.toggle('selected', qtys[id] > 0);
  refreshTotal();
}

function focusQty(id) {
  if (qtys[id] === 0) changeQty(id, 1);
}

// ── TOTAL CALCULATOR ──────────────────────────────────────────
function fmt(n) {
  return '₦' + parseFloat(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2 });
}

function refreshTotal() {
  let sub = 0;

  // Fixed simple amount
  const linkType  = '<?= $link['link_type'] ?>';
  const fixedAmt  = <?= (float)($link['amount'] ?? 0) ?>;

  if (linkType === 'simple') {
    if (fixedAmt > 0) {
      sub = fixedAmt;
    } else {
      const customInp = document.querySelector('input[name="custom_amount"]');
      sub = parseFloat(customInp?.value || 0);
    }
  } else if (linkType === 'installment') {
    sub = <?= (float)($cfg['per_installment'] ?? 0) ?>;
  } else {
    // Menu / group
    for (const id in qtys) {
      sub += (qtys[id] || 0) * (itemPrices[id] || 0);
    }
  }

  // Delivery
  let deliv = 0;
  const zoneSelect = document.querySelector('select[name="delivery_zone"]');
  if (zoneSelect && zoneSelect.value) {
    const opt = zoneSelect.options[zoneSelect.selectedIndex];
    deliv = parseFloat(opt.dataset.fee || 0);
  }

  const total = sub + deliv;

  // Update DOM
  const elSub    = document.getElementById('ot-subtotal');
  const elDeliv  = document.getElementById('ot-delivery');
  const elDelivR = document.getElementById('ot-delivery-row');
  const elTotal  = document.getElementById('ot-total');
  const btnText  = document.getElementById('pay-btn-text');

  if (elSub)   elSub.textContent   = fmt(sub);
  if (elTotal) elTotal.textContent = fmt(total);

  if (deliv > 0) {
    if (elDelivR) elDelivR.style.display = 'flex';
    if (elDeliv)  elDeliv.textContent    = fmt(deliv);
  } else {
    if (elDelivR) elDelivR.style.display = 'none';
  }

  if (btnText) {
    btnText.textContent = total > 0
      ? 'Pay ' + fmt(total) + ' Securely'
      : 'Pay Now';
  }
}

// ── FORM SUBMIT ───────────────────────────────────────────────
document.getElementById('pay-form')?.addEventListener('submit', function(e) {
  const btn     = document.getElementById('pay-btn');
  const btnText = document.getElementById('pay-btn-text');

  // Client-side validation
  const name  = document.querySelector('input[name="customer_name"]')?.value?.trim();
  const phone = document.querySelector('input[name="customer_phone"]')?.value?.replace(/\D/g,'');

  if (!name || name.length < 2) {
    e.preventDefault();
    document.querySelector('input[name="customer_name"]').focus();
    return;
  }

  if (!phone || phone.length < 10) {
    e.preventDefault();
    document.querySelector('input[name="customer_phone"]').focus();
    return;
  }

  // Show loading
  if (btn) btn.disabled = true;
  if (btnText) {
    btnText.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px"><span style="width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite;display:inline-block"></span> Processing payment...</span>';
  }
});

// Init total on load
refreshTotal();
</script>
</body>
</html>
