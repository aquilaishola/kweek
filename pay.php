<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/chat_helpers.php';
require_once __DIR__ . '/includes/integration.php';

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
    SELECT pl.*, u.name AS merchant_name, u.plan AS merchant_plan, u.id AS merchant_id, u.webhook_secret AS merchant_secret
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
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Space Grotesk",sans-serif;background:#FFFFFF;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}
    .box{max-width:420px}.ic{width:72px;height:72px;border-radius:50%;background:#F2F0FC;display:flex;align-items:center;justify-content:center;color:#4F43D4;font-size:32px;margin:0 auto 20px}
    h2{font-size:22px;font-weight:700;color:#15131F;margin-bottom:10px;letter-spacing:-.5px}
    p{font-size:14px;color:#6B6879;line-height:1.65;margin-bottom:24px}
    a{display:inline-flex;align-items:center;gap:6px;background:#4F43D4;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px}</style></head>
    <body><div class="box"><div class="ic"><i class="ti ti-link-off"></i></div><h2>Link Not Found</h2><p>' . htmlspecialchars($msg) . '</p><a href="/"><i class="ti ti-home"></i> Go to Kweek</a></div></body></html>';
    exit;
}

if (!$link) notFound();
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) notFound('This payment link has expired. Contact the merchant for a new one.');

// ── CURRENCY ─────────────────────────────────────────────────
// Each link is priced in a single currency, chosen by the merchant. Older
// links have no value stored and quietly fall back to naira.
$currency = linkCurrency($link['currency'] ?? 'NGN');
$curSym   = currencySymbol($currency);
$curIcon  = currencyIcon($currency);
$minAmt   = minAmountFor($currency);
$money    = fn($n) => formatMoney($n, $currency);

// Fetch menu items (for menu/group types)
$items = [];
if (in_array($link['link_type'], ['menu', 'group'])) {
    $iStmt = $db->prepare("SELECT * FROM link_items WHERE link_id = ? AND is_available = 1 ORDER BY sort_order, id");
    $iStmt->execute([$link['id']]);
    $items = $iStmt->fetchAll();
}

$zones        = !empty($link['delivery_zones']) ? json_decode($link['delivery_zones'], true) : [];
$accentColor  = preg_match('/^#[0-9A-Fa-f]{6}$/', $link['accent_color'] ?? '') ? $link['accent_color'] : '#4F43D4';
$cfg          = $link['link_type'] === 'installment' ? (json_decode($link['installment_config'] ?? '{}', true) ?: []) : [];

// A link with no fixed amount lets the payer choose what to pay.
$isOpenAmount = $link['link_type'] === 'simple' && !((float)($link['amount'] ?? 0) > 0);

// ── EXTERNAL-SITE INTEGRATION (prefilled / locked fields, reference, redirect) ──
// A website can send buyers here with ?name=&email=&phone=&amount=&reference=&redirect_url=&sig=
// The form posts back to this same URL, so the values are present on POST too and
// can be enforced server-side instead of trusting the (editable) form fields.
$hasIntegration = kwIntegrationSchema($db);

$P = [];
foreach (['name', 'email', 'phone', 'amount', 'reference', 'redirect_url'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && trim($_GET[$k]) !== '') $P[$k] = trim($_GET[$k]);
}

// Optional signature: HMAC of the values with the merchant's webhook secret.
// Present-but-wrong means the link was tampered with → refuse. Absent is allowed
// (plain prefill) and is flagged "signed_request: false" in the webhook.
$requestSigned = false;
if (isset($_GET['sig'])) {
    $secret = (string)($link['merchant_secret'] ?? '');
    if ($secret === '' || !hash_equals(kwPaySignature($secret, $slug, $P), strtolower((string)$_GET['sig']))) {
        notFound('This payment request could not be verified. Please go back to the website you came from and try again.');
    }
    $requestSigned = true;
}

$lockName  = isset($P['name']);
$lockEmail = isset($P['email']) && filter_var($P['email'], FILTER_VALIDATE_EMAIL);
$lockPhone = isset($P['phone']) && strlen(kwNormPhone($P['phone'])) >= 10;
$presetAmount = ($isOpenAmount && isset($P['amount']) && (float)$P['amount'] > 0) ? round((float)$P['amount'], 2) : null;
$clientRef    = isset($P['reference']) ? mb_substr($P['reference'], 0, 190) : null;

// Per-payment redirect: honoured only on the same host as the link's saved redirect URL.
$redirectNow  = kwResolveRedirect($P['redirect_url'] ?? null, $link['redirect_url'] ?? null);
// A verified signature proves the merchant's own site built this URL, so trust its redirect_url
if (!$redirectNow && $requestSigned && !empty($P['redirect_url'])
    && preg_match('#^https?://#i', $P['redirect_url'])
    && filter_var($P['redirect_url'], FILTER_VALIDATE_URL)) {
    $redirectNow = $P['redirect_url'];
}
$redirectOverride = ($redirectNow && $redirectNow !== trim((string)($link['redirect_url'] ?? ''))) ? $redirectNow : null;

// Values the form shows (locked ones win over anything typed/posted)
$vName  = $lockName  ? $P['name']                 : post('customer_name');
$vEmail = $lockEmail ? $P['email']                : post('customer_email');
$vPhone = $lockPhone ? kwNormPhone($P['phone'])   : post('customer_phone');

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
        $customerName  = $lockName  ? clean($P['name'])               : clean(post('customer_name'));
        $customerPhone = $lockPhone ? kwNormPhone($P['phone'])        : preg_replace('/\D/', '', post('customer_phone'));
        $customerEmail = $lockEmail ? $P['email']                     : (filter_var(post('customer_email'), FILTER_VALIDATE_EMAIL) ?: null);
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
                : ($presetAmount ?? abs((float)post('custom_amount')));
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

        // Merchants are always paid in naira. For a dollar or cedi link we fix the
        // naira value at the admin's current rate and keep it on the order, so the
        // wallet credit later matches exactly what the merchant was shown.
        $exchangeRate = 1.0;
        $ngnAmount    = $orderTotal;

        // ── VALIDATION ────────────────────────────────────────
        if (empty($orderError)) {
            if (strlen($customerName) < 2)   $orderError = 'Please enter your full name.';
            elseif (strlen($customerPhone) < 10) $orderError = 'Please enter a valid phone number.';
            elseif ($orderTotal < $minAmt)    $orderError = 'Minimum payment amount is ' . $money($minAmt) . '.';
        }

        // ── EXCHANGE RATE (dollar and cedi links) ─────────────
        if (empty($orderError) && $currency !== 'NGN') {
            $exchangeRate = ngnRate($currency);
            if ($exchangeRate <= 0) {
                $orderError = 'Payments in ' . currencyNoun($currency) . ' are temporarily unavailable. Please try again later.';
            } else {
                $ngnAmount = round($orderTotal * $exchangeRate, 2);
            }
        }

        // ── MONTHLY CAP CHECK ─────────────────────────────────
        // Caps are set in naira, so they are checked against the naira value of the order.
        if (empty($orderError) && !checkMonthlyCap($link['merchant_id'], $ngnAmount)) {
            $orderError = 'The merchant has reached their monthly transaction limit. Please contact them directly.';
        }

        // ── CREATE ORDER ──────────────────────────────────────
        if (empty($orderError)) {
            $orderRef       = generateOrderRef();
            $initialStatus  = $link['require_confirmation'] ? 'pending_confirmation' : 'confirmed';

            try {
                $row = [
                    'link_id'          => $link['id'],
                    'user_id'          => $link['merchant_id'],
                    'order_ref'        => $orderRef,
                    'customer_name'    => $customerName,
                    'customer_email'   => $customerEmail,
                    'customer_phone'   => $customerPhone,
                    'delivery_zone'    => $deliveryZone ?: null,
                    'delivery_fee'     => $deliveryFee,
                    'delivery_address' => $deliveryAddr ?: null,
                    'subtotal'         => $subtotal,
                    'total_amount'     => $orderTotal,
                    'currency'         => $currency,
                    'exchange_rate'    => $exchangeRate,
                    'ngn_amount'       => $ngnAmount,
                    'items'            => json_encode($orderItems),
                    'status'           => $initialStatus,
                ];
                if ($hasIntegration) {
                    $row['client_reference']  = $clientRef;
                    $row['redirect_override'] = $redirectOverride;
                    $row['signed_request']    = $requestSigned ? 1 : 0;
                }
                $db->prepare("INSERT INTO orders (" . implode(',', array_keys($row)) . ") VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")
                   ->execute(array_values($row));

                $orderId = (int)$db->lastInsertId();

                // Bump view/order count
                $db->prepare("UPDATE payment_links SET total_orders = total_orders + 1 WHERE id = ?")
                   ->execute([$link['id']]);

                // Notify merchant
                notify(
                    $link['merchant_id'],
                    'new_order',
                    'New order from ' . $customerName,
                    $money($orderTotal) . ' order on "' . $link['title'] . '"' .
                        ($link['require_confirmation'] ? ' — needs your confirmation' : ''),
                    'shopping-cart',
                    '/orders.php'
                );

                // ── REQUIRE CONFIRMATION FLOW ─────────────────
                if ($link['require_confirmation']) {
                    $orderSuccess = true;
                    $awaitingConf = true;

                } else {
                    // ── LAUNCH CHECKOUT ───────────────────────
                    // The buyer always comes back through thanks.php first: it confirms the
                    // payment, fires the merchant's webhook, and only then redirects to the
                    // merchant's own site (if one is set).
                    $nomba = new Nomba();
                    $redirectTarget = SITE_URL . '/thanks.php?ref=' . urlencode($orderRef);
                    $cancelTarget   = SITE_URL . '/pay/' . $slug;

                    $result = $nomba->createCheckout([
                        'amount'        => $orderTotal,
                        'currency'      => $currency,
                        'orderRef'      => $orderRef,
                        'customerName'  => $customerName,
                        'customerEmail' => $customerEmail ?? 'customer@kweek.ng',
                        'customerPhone' => $customerPhone,
                        'description'   => 'Payment for ' . $link['title'],
                        'redirectUrl'   => $redirectTarget,
                        'cancelUrl'     => $cancelTarget,
                        'callbackUrl'   => SITE_URL . '/webhook.php',
                    ]);

                    $checkoutUrl = $result['data']['checkoutLink']
                        ?? $result['data']['paymentLink']
                        ?? $result['data']['url']
                        ?? '';

                    if (!empty($checkoutUrl)) {
                        // Save the gateway's checkout id
                        $checkoutId = $result['data']['orderId'] ?? $result['data']['id'] ?? '';
                        $db->prepare("UPDATE orders SET status = 'pending_payment', nomba_checkout_id = ? WHERE id = ?")
                           ->execute([$checkoutId, $orderId]);

                        // Redirect customer to the hosted checkout
                        header('Location: ' . $checkoutUrl);
                        exit;

                    } else {
                        // Fallback — log the details but keep the page friendly
                        error_log('Checkout response: ' . json_encode($result));
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

$lockIc = fn($on) => $on ? '<i class="ti ti-lock lock-ic" title="Provided by ' . htmlspecialchars($link['merchant_name'], ENT_QUOTES) . '"></i>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pay <?= htmlspecialchars($link['merchant_name']) ?> — Kweek</title>
<meta name="description" content="Pay <?= htmlspecialchars($link['merchant_name']) ?> securely via Kweek — <?= htmlspecialchars($link['title']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?= htmlspecialchars($accentColor) ?>;
  --acc-soft:<?= htmlspecialchars($accentColor) ?>22;
  --n0:#FFFFFF;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;
  --n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#1E9E5A;--success-bg:#E9F9F0;
  --danger:#DC2626;--danger-bg:#FEF2F2;
  --warning:#D97706;--warning-bg:#FEF3E2;
  --font:'Space Grotesk',sans-serif;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:20px;--r-full:9999px;
}
html[data-theme="dark"]{
  --n0:#1B1830;--n50:#141227;--n100:#232032;--n200:#2C2844;
  --n400:#8A87A0;--n500:#A8A4BE;--n600:#D6D3DE;--n800:#F0EFF5;--n900:#FFFFFF;
  --success-bg:#123324;--danger-bg:#3A1414;--warning-bg:#3A2A0A;
}
html[data-theme="dark"] body{background:#0F0D1C}
.theme-toggle{width:34px;height:34px;border-radius:50%;border:1.5px solid var(--n200);background:var(--n0);color:var(--n600);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:all .15s;flex-shrink:0}
.theme-toggle:hover{border-color:var(--acc);color:var(--acc)}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--n50);min-height:100vh;-webkit-font-smoothing:antialiased}

/* PRELOADER */
#kweek-preloader{position:fixed;inset:0;background:var(--n0);z-index:99999;display:flex;align-items:center;justify-content:center;transition:opacity .5s ease,visibility .5s ease}
#kweek-preloader.kw-hide{opacity:0;visibility:hidden;pointer-events:none}
#kweek-preloader .kw-mark{width:60px;height:60px;border-radius:15px;background:var(--acc);display:flex;align-items:center;justify-content:center;animation:kwZoom 1.1s ease-in-out infinite}
#kweek-preloader .kw-mark svg{width:34px;height:34px}
@keyframes kwZoom{0%{transform:scale(.78);opacity:.7}50%{transform:scale(1.12);opacity:1}100%{transform:scale(.78);opacity:.7}}

/* LAYOUT */
.pay-page{min-height:100vh;padding:20px 16px 60px;display:flex;flex-direction:column;align-items:center}
.pay-wrap{width:100%;max-width:480px}

/* TOP NAV */
.pay-nav{display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.pay-logo{display:flex;align-items:center;gap:8px;text-decoration:none}
.pay-logo-mark{width:26px;height:26px;border-radius:7px;background:var(--acc);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pay-logo-mark svg{width:15px;height:15px}
.pay-logo-word{font-weight:700;font-size:17px;color:var(--n800);letter-spacing:-.3px}
.secure-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;margin-left:auto;padding:4px 10px;background:var(--success-bg);border-radius:var(--r-full);font-weight:700;color:var(--success)}
.secure-tag i{font-size:13px}

/* MERCHANT CARD */
.merchant-card{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:24px 20px;text-align:center;margin-bottom:12px}
.merch-av{width:56px;height:56px;border-radius:50%;background:var(--acc);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;margin:0 auto 12px}
.merch-name{font-size:17px;font-weight:700;color:var(--n900);margin-bottom:4px}
.merch-link-title{font-size:13px;color:var(--n500);line-height:1.5}
.merch-desc{font-size:13px;color:var(--n500);margin-top:8px;line-height:1.6;padding-top:10px;border-top:1px solid var(--n100)}
.merch-ref{display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:11.5px;color:var(--n500);background:var(--n100);border-radius:var(--r-full);padding:4px 11px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

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
.mi-price{font-size:14px;font-weight:700;color:var(--acc);white-space:nowrap;flex-shrink:0}
.mi-thumb{width:52px;height:52px;border-radius:var(--r-md);object-fit:cover;flex-shrink:0;border:1px solid var(--n200)}
.mi-details-btn{background:none;border:none;padding:0;font-family:var(--font);font-size:11.5px;font-weight:700;color:var(--acc);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.qty-ctrl{display:flex;align-items:center;gap:6px;flex-shrink:0}
.qty-btn{width:28px;height:28px;border-radius:50%;border:1.5px solid var(--n200);background:var(--n0);cursor:pointer;font-size:16px;font-weight:700;line-height:1;color:var(--n600);display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
.qty-btn:hover{border-color:var(--acc);background:var(--acc);color:#fff}
.qty-display{font-size:14px;font-weight:700;color:var(--n900);min-width:22px;text-align:center}

/* SIMPLE AMOUNT */
.amount-display{font-size:36px;font-weight:700;color:var(--acc);letter-spacing:-1px;text-align:center;padding:8px 0}
.amount-sub{font-size:13px;color:var(--n500);text-align:center;margin-top:4px}

/* FORM */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-group:last-child{margin-bottom:0}
.form-label{font-size:12px;font-weight:600;color:var(--n600)}
.inp-wrap{position:relative}
.form-input,.form-select{font-family:var(--font);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px 11px 40px;outline:none;transition:border-color .2s,box-shadow .2s,background .2s;width:100%}
.form-input:focus,.form-select:focus{background:var(--n0);border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft)}
.form-input::placeholder{color:var(--n400)}
.form-input[readonly]{background:var(--n100);color:var(--n600);cursor:not-allowed;padding-right:38px}
.form-input[readonly]:focus{border-color:var(--n200);box-shadow:none;background:var(--n100)}
.lock-ic{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:15px;color:var(--n400);pointer-events:auto}
.form-select{appearance:none;cursor:pointer;padding-right:32px}
.inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}

/* ORDER TOTAL */
.order-total{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:16px;margin-bottom:10px}
.ot-row{display:flex;align-items:center;justify-content:space-between;font-size:13px;color:var(--n600);margin-bottom:7px}
.ot-row:last-child{margin-bottom:0;font-size:16px;font-weight:700;color:var(--n900);padding-top:10px;border-top:1.5px dashed var(--n200);margin-top:7px}
.ot-row:last-child .ot-amount{color:var(--acc)}

/* PAY BUTTON */
.pay-btn-main{width:100%;padding:16px;background:var(--acc);color:#fff;border:none;border-radius:var(--r-lg);font-family:var(--font);font-size:16px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;transition:filter .2s,transform .2s,box-shadow .2s;margin-bottom:10px;letter-spacing:-.2px}
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
.await-card h2{font-size:20px;font-weight:700;color:var(--n900);margin-bottom:8px;letter-spacing:-.3px}
.await-card p{font-size:14px;color:var(--n500);line-height:1.7}
.await-summary{background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:16px;margin-top:20px;text-align:left}
.await-summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
.await-summary-row:last-child{margin-bottom:0;font-weight:700;color:var(--n900)}

/* POWERED BY */
.powered-by{text-align:center;margin-top:20px;font-size:12px;color:var(--n400)}
.powered-by a{color:var(--acc);text-decoration:none;font-weight:700}

/* ITEM DETAIL MODAL */
.kw-modal-overlay{position:fixed;inset:0;background:rgba(15,14,28,.55);z-index:9998;display:none;align-items:flex-end;justify-content:center;padding:0}
.kw-modal-overlay.open{display:flex}
.kw-modal-box{background:var(--n0);border-radius:var(--r-xl) var(--r-xl) 0 0;width:100%;max-width:480px;max-height:85vh;overflow-y:auto;padding:20px;animation:slideUp .25s ease}
@media(min-width:540px){.kw-modal-overlay{align-items:center}.kw-modal-box{border-radius:var(--r-xl);margin:0 16px}}
@keyframes slideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
.kw-modal-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:50%;background:var(--n100);border:none;color:var(--n600);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.kw-modal-box{position:relative}
.kw-modal-img{width:100%;border-radius:var(--r-lg);margin-bottom:14px;max-height:220px;object-fit:cover}
.kw-modal-box h3{font-size:17px;font-weight:700;color:var(--n900);margin-bottom:8px;padding-right:30px}
.kw-modal-box p{font-size:13.5px;color:var(--n500);line-height:1.7;margin-bottom:16px;white-space:pre-wrap}
.kw-modal-price{font-size:18px;font-weight:700;color:var(--acc);margin-bottom:14px}

/* LIVE CHAT WIDGET */
.chat-bubble{position:fixed;bottom:20px;right:20px;width:54px;height:54px;border-radius:50%;background:var(--acc);color:#fff;border:none;display:flex;align-items:center;justify-content:center;font-size:22px;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:9990;transition:transform .2s}
.chat-bubble:hover{transform:scale(1.06)}
.chat-bubble .chat-dot{position:absolute;top:2px;right:2px;width:12px;height:12px;border-radius:50%;background:var(--danger);border:2px solid var(--n0);display:none}
.chat-bubble.has-unread .chat-dot{display:block}
.chat-panel{position:fixed;bottom:86px;right:20px;width:340px;max-width:calc(100vw - 32px);height:440px;max-height:65vh;background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);box-shadow:0 16px 40px rgba(0,0,0,.18);display:none;flex-direction:column;overflow:hidden;z-index:9991}
.chat-panel.open{display:flex}
.chat-head{background:var(--acc);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}
.chat-head-av{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
.chat-head-text{flex:1;min-width:0}
.chat-head-name{font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-head-sub{font-size:11px;opacity:.85;display:flex;align-items:center;gap:4px}
.chat-head-sub .dot{width:6px;height:6px;border-radius:50%;background:#4ADE80;display:inline-block}
.chat-close{background:none;border:none;color:#fff;font-size:18px;cursor:pointer;opacity:.85}
.chat-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:var(--n50)}
.chat-msg{max-width:78%;padding:9px 12px;border-radius:var(--r-lg);font-size:13px;line-height:1.5}
.chat-msg.mine{align-self:flex-end;background:var(--acc);color:#fff;border-bottom-right-radius:4px}
.chat-msg.theirs{align-self:flex-start;background:var(--n0);border:1px solid var(--n200);color:var(--n900);border-bottom-left-radius:4px}
.chat-empty{margin:auto;text-align:center;font-size:12.5px;color:var(--n400);padding:20px}
.chat-input-row{display:flex;gap:8px;padding:10px;border-top:1px solid var(--n200);background:var(--n0)}
.chat-input-row input{flex:1;font-family:var(--font);font-size:13px;border:1.5px solid var(--n200);border-radius:var(--r-full);padding:9px 14px;outline:none;background:var(--n50);color:var(--n800)}
.chat-input-row input:focus{border-color:var(--acc)}
.chat-send-btn{width:36px;height:36px;border-radius:50%;background:var(--acc);border:none;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
.chat-send-btn:disabled{opacity:.5;cursor:not-allowed}

/* CHAT — "who are you?" step */
.chat-intro{flex:1;overflow-y:auto;padding:18px 16px;display:none;flex-direction:column;gap:10px;background:var(--n50)}
.chat-intro-title{font-size:15px;font-weight:700;color:var(--n900)}
.chat-intro-text{font-size:12.5px;color:var(--n500);line-height:1.6;margin-bottom:2px}
.chat-intro input{font-family:var(--font);font-size:13.5px;color:var(--n800);background:var(--n0);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px;outline:none;width:100%}
.chat-intro input:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft)}
.chat-intro-error{display:none;font-size:12px;color:var(--danger);line-height:1.5}
.chat-start-btn{font-family:var(--font);font-size:14px;font-weight:700;background:var(--acc);color:#fff;border:none;border-radius:var(--r-md);padding:12px;cursor:pointer;transition:filter .2s}
.chat-start-btn:hover{filter:brightness(1.1)}
.chat-intro-note{font-size:11px;color:var(--n400);line-height:1.5}

@media(max-width:480px){
  .chat-panel{right:12px;bottom:80px;width:calc(100vw - 24px)}
  .chat-bubble{right:16px;bottom:16px}
}
@media(max-width:480px){
  .pay-page{padding:12px 12px 40px}
  .amount-display{font-size:30px}
  .pay-btn-main{font-size:15px;padding:14px}
}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<!-- PRELOADER -->
<div id="kweek-preloader">
  <div class="kw-mark">
    <svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/><rect x="14" y="36" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/></svg>
  </div>
</div>

<div class="pay-page">
<div class="pay-wrap">

  <!-- TOP NAV -->
  <div class="pay-nav">
    <a href="/" class="pay-logo">
      <span class="pay-logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/><rect x="14" y="36" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/></svg></span>
      <span class="pay-logo-word">kweek</span>
    </a>
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
      <div class="await-summary-row"><span style="color:var(--n500)">Total</span><span><?= $money($orderTotal) ?></span></div>
    </div>
    <p style="margin-top:16px;font-size:12px;color:var(--n500)">You will be contacted on the phone number you provided once your order is confirmed.</p>
  </div>

  <?php else: ?>
  <!-- ── PAYMENT FORM ───────────────────────────────────────── -->

  <!-- MERCHANT INFO -->
  <div class="merchant-card">
    <?php if (!empty($link['logo_url'])): ?>
    <img src="<?= htmlspecialchars($link['logo_url']) ?>" alt="<?= htmlspecialchars($link['merchant_name']) ?>" class="merch-av" style="object-fit:cover">
    <?php else: ?>
    <div class="merch-av"><?= strtoupper(substr($link['merchant_name'], 0, 2)) ?></div>
    <?php endif; ?>
    <div class="merch-name"><?= htmlspecialchars($link['merchant_name']) ?></div>
    <div class="merch-link-title"><?= htmlspecialchars($link['title']) ?></div>
    <?php if (!empty($link['description'])): ?>
    <div class="merch-desc"><?= nl2br(htmlspecialchars($link['description'])) ?></div>
    <?php endif; ?>
    <?php if ($clientRef): ?>
    <div class="merch-ref"><i class="ti ti-hash"></i> Ref: <?= htmlspecialchars($clientRef) ?></div>
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
      <div class="pay-card-label"><i class="ti <?= $curIcon ?>"></i> Payment Amount</div>
      <?php if (!$isOpenAmount): ?>
      <div class="amount-display"><?= $money($link['amount']) ?></div>
      <div class="amount-sub">Fixed payment to <?= htmlspecialchars($link['merchant_name']) ?></div>
      <?php elseif ($presetAmount !== null): ?>
      <!-- Amount supplied by the merchant's website: shown, not editable -->
      <div class="amount-display"><?= $money($presetAmount) ?></div>
      <div class="amount-sub">Amount set by <?= htmlspecialchars($link['merchant_name']) ?></div>
      <input type="hidden" name="custom_amount" value="<?= htmlspecialchars((string)$presetAmount) ?>">
      <?php else: ?>
      <!-- Open amount: the payer decides how much to send -->
      <div class="form-group" style="margin:0">
        <label class="form-label">Enter amount (<?= $curSym ?>)</label>
        <div class="inp-wrap">
          <i class="ti <?= $curIcon ?> inp-icon"></i>
          <input type="number" name="custom_amount" class="form-input"
            placeholder="<?= $minAmt ?>" min="<?= $minAmt ?>" step="any" inputmode="decimal"
            value="<?= htmlspecialchars(post('custom_amount')) ?>"
            oninput="refreshTotal()" required>
        </div>
        <div style="font-size:11.5px;color:var(--n400);margin-top:2px">Minimum: <?= $money($minAmt) ?></div>
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
        <?php if (!empty($item['image_url'])): ?>
        <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="mi-thumb" loading="lazy" decoding="async" width="52" height="52">
        <?php endif; ?>
        <div class="mi-info">
          <div class="mi-name"><?= htmlspecialchars($item['name']) ?></div>
          <?php if ($item['description']): ?>
          <div class="mi-desc"><?= htmlspecialchars($item['description']) ?></div>
          <?php endif; ?>
          <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
            <div class="mi-price"><?= $money($item['price']) ?></div>
            <?php if (!empty($item['description']) || !empty($item['image_url'])): ?>
            <button type="button" class="mi-details-btn" onclick="event.stopPropagation();viewItemDetails(<?= $item['id'] ?>)">View details</button>
            <?php endif; ?>
          </div>
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
    <?php elseif ($link['link_type'] === 'installment'): ?>
    <div class="pay-card" style="text-align:center">
      <div class="pay-card-label" style="justify-content:center"><i class="ti ti-credit-card-pay"></i> Installment Payment</div>
      <div class="amount-display"><?= $money($cfg['per_installment'] ?? 0) ?></div>
      <div class="amount-sub">
        1 of <?= (int)($cfg['count'] ?? 2) ?> installments &nbsp;·&nbsp;
        Total: <?= $money($cfg['total'] ?? 0) ?>
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
              <?= htmlspecialchars($z['name']) ?> — <?= $money($z['fee']) ?>
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
            value="<?= htmlspecialchars($vName) ?>" <?= $lockName ? 'readonly' : '' ?> required>
          <?= $lockIc($lockName) ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number <span style="color:var(--danger)">*</span></label>
        <div class="inp-wrap">
          <i class="ti ti-phone inp-icon"></i>
          <input type="tel" name="customer_phone" class="form-input"
            placeholder="08012345678"
            value="<?= htmlspecialchars($vPhone) ?>"
            <?= $lockPhone ? 'readonly' : 'maxlength="11"' ?> required>
          <?= $lockIc($lockPhone) ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email <?= $lockEmail ? '' : '<span style="color:var(--n400);font-weight:400">(optional — for receipt)</span>' ?></label>
        <div class="inp-wrap">
          <i class="ti ti-mail inp-icon"></i>
          <input type="email" name="customer_email" class="form-input"
            placeholder="you@email.com"
            value="<?= htmlspecialchars($vEmail) ?>" <?= $lockEmail ? 'readonly' : '' ?>>
          <?= $lockIc($lockEmail) ?>
        </div>
      </div>
    </div>

    <!-- ── ORDER TOTAL ────────────────────────────────────── -->
    <div class="order-total" id="order-total-section">
      <div class="ot-row" id="ot-subtotal-row">
        <span>Subtotal</span>
        <span class="ot-amount" id="ot-subtotal">
          <?php
          if ($link['link_type'] === 'simple' && !$isOpenAmount)
              echo $money($link['amount']);
          elseif ($link['link_type'] === 'simple' && $presetAmount !== null)
              echo $money($presetAmount);
          elseif ($link['link_type'] === 'installment')
              echo $money($cfg['per_installment'] ?? 0);
          else
              echo $money(0);
          ?>
        </span>
      </div>
      <div class="ot-row" id="ot-delivery-row" style="display:none">
        <span>Delivery</span>
        <span class="ot-amount" id="ot-delivery"><?= $money(0) ?></span>
      </div>
      <div class="ot-row">
        <span>Total</span>
        <span class="ot-amount" id="ot-total">
          <?php
          if ($link['link_type'] === 'simple' && !$isOpenAmount)
              echo $money($link['amount']);
          elseif ($link['link_type'] === 'simple' && $presetAmount !== null)
              echo $money($presetAmount);
          elseif ($link['link_type'] === 'installment')
              echo $money($cfg['per_installment'] ?? 0);
          else
              echo $money(0);
          ?>
        </span>
      </div>
    </div>

    <!-- ── PAY BUTTON ─────────────────────────────────────── -->
    <button type="submit" class="pay-btn-main" id="pay-btn">
      <i class="ti ti-lock"></i>
      <span id="pay-btn-text">
        <?php
        if ($link['link_type'] === 'simple' && !$isOpenAmount)
            echo 'Pay ' . $money($link['amount']) . ' Securely';
        elseif ($link['link_type'] === 'simple' && $presetAmount !== null)
            echo 'Pay ' . $money($presetAmount) . ' Securely';
        elseif ($link['link_type'] === 'installment')
            echo 'Pay ' . $money($cfg['per_installment'] ?? 0) . ' Securely';
        else
            echo 'Pay Now';
        ?>
      </span>
    </button>
    <div class="secure-footer">
      <i class="ti ti-shield-check"></i>
      Payments secured &amp; verified by Kweek
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

<!-- ITEM DETAIL MODAL -->
<div class="kw-modal-overlay" id="item-modal-overlay" onclick="if(event.target===this) closeItemModal()">
  <div class="kw-modal-box">
    <button type="button" class="kw-modal-close" onclick="closeItemModal()"><i class="ti ti-x"></i></button>
    <img id="item-modal-img" class="kw-modal-img" style="display:none">
    <h3 id="item-modal-name"></h3>
    <div class="kw-modal-price" id="item-modal-price"></div>
    <p id="item-modal-desc"></p>
    <button type="button" class="pay-btn-main" style="margin-bottom:0" onclick="closeItemModal()"><i class="ti ti-check"></i> Got it</button>
  </div>
</div>

<?php if ($link['chat_enabled'] ?? 1): ?>
<!-- LIVE CHAT WIDGET -->
<button type="button" class="chat-bubble" id="chat-bubble-btn" onclick="toggleChat()">
  <i class="ti ti-message-circle-2"></i>
  <span class="chat-dot" id="chat-dot"></span>
</button>
<div class="chat-panel" id="chat-panel">
  <div class="chat-head">
    <?php if (!empty($link['logo_url'])): ?>
    <img src="<?= htmlspecialchars($link['logo_url']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0" loading="lazy" decoding="async">
    <?php else: ?>
    <div class="chat-head-av"><?= strtoupper(substr($link['merchant_name'],0,2)) ?></div>
    <?php endif; ?>
    <div class="chat-head-text">
      <div class="chat-head-name"><?= htmlspecialchars($link['merchant_name']) ?></div>
      <div class="chat-head-sub"><span class="dot"></span> Usually replies within a few hours</div>
    </div>
    <button type="button" class="chat-close" onclick="toggleChat()"><i class="ti ti-x"></i></button>
  </div>

  <!-- Step 1: introduce yourself (shown until the visitor has done it once) -->
  <div class="chat-intro" id="chat-intro">
    <div class="chat-intro-title">Before we start</div>
    <div class="chat-intro-text">Tell <?= htmlspecialchars($link['merchant_name']) ?> who you are, so they know who they are talking to and can follow up by email if you step away.</div>
    <input type="text" id="chat-name" placeholder="Your name" maxlength="80" autocomplete="name" onkeydown="if(event.key==='Enter') startChat()">
    <input type="email" id="chat-email" placeholder="you@email.com" maxlength="190" autocomplete="email" onkeydown="if(event.key==='Enter') startChat()">
    <div class="chat-intro-error" id="chat-intro-error"></div>
    <button type="button" class="chat-start-btn" onclick="startChat()">Start chatting</button>
    <div class="chat-intro-note">Your name and email are shared only with this merchant.</div>
  </div>

  <!-- Step 2: the conversation -->
  <div class="chat-body" id="chat-body" style="display:none">
    <div class="chat-empty" id="chat-empty">Ask a question about "<?= htmlspecialchars($link['title']) ?>" before you pay — <?= htmlspecialchars($link['merchant_name']) ?> will see it right away.</div>
  </div>
  <div class="chat-input-row" id="chat-input-row" style="display:none">
    <input type="text" id="chat-input" placeholder="Type a message..." maxlength="1000" onkeydown="if(event.key==='Enter') sendChatMsg()">
    <button type="button" class="chat-send-btn" id="chat-send-btn" onclick="sendChatMsg()"><i class="ti ti-send"></i></button>
  </div>
  <div id="chat-error" style="display:none;font-size:11.5px;color:var(--danger);padding:0 14px 10px"></div>
</div>
<?php endif; ?>

<script>
// Preloader — same reusable pattern/timing as the rest of the site
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('kweek-preloader').classList.add('kw-hide'), 500);
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
const CUR_SYMBOL = <?= json_encode($curSym) ?>;

function fmt(n) {
  return CUR_SYMBOL + parseFloat(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2 });
}

function refreshTotal() {
  let sub = 0;

  const linkType  = <?= json_encode($link['link_type']) ?>;
  const fixedAmt  = <?= (float)($link['amount'] ?? 0) ?>;
  const presetAmt = <?= json_encode($presetAmount) ?>;

  if (linkType === 'simple') {
    if (fixedAmt > 0) {
      sub = fixedAmt;
    } else if (presetAmt !== null) {
      sub = presetAmt;
    } else {
      const customInp = document.querySelector('input[name="custom_amount"]');
      sub = parseFloat(customInp?.value || 0) || 0;
    }
  } else if (linkType === 'installment') {
    sub = <?= (float)($cfg['per_installment'] ?? 0) ?>;
  } else {
    for (const id in qtys) {
      sub += (qtys[id] || 0) * (itemPrices[id] || 0);
    }
  }

  let deliv = 0;
  const zoneSelect = document.querySelector('select[name="delivery_zone"]');
  if (zoneSelect && zoneSelect.value) {
    const opt = zoneSelect.options[zoneSelect.selectedIndex];
    deliv = parseFloat(opt.dataset.fee || 0);
  }

  const total = sub + deliv;

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

  if (btn) btn.disabled = true;
  if (btnText) {
    btnText.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px"><span style="width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite;display:inline-block"></span> Processing payment...</span>';
  }
});

// Init total on load
refreshTotal();

// ── ITEM DETAILS MODAL ──────────────────────────────────────
const itemDetails = {
  <?php foreach ($items as $item): ?>
  <?= $item['id'] ?>: {
    name: <?= json_encode($item['name']) ?>,
    price: <?= (float)$item['price'] ?>,
    desc: <?= json_encode($item['description'] ?: '') ?>,
    img: <?= json_encode($item['image_url'] ?: '') ?>
  },
  <?php endforeach; ?>
};

function viewItemDetails(id) {
  const it = itemDetails[id];
  if (!it) return;
  document.getElementById('item-modal-name').textContent = it.name;
  document.getElementById('item-modal-price').textContent = fmt(it.price);
  document.getElementById('item-modal-desc').textContent = it.desc || 'No further details for this item.';
  const img = document.getElementById('item-modal-img');
  if (it.img) { img.src = it.img; img.style.display = 'block'; } else { img.style.display = 'none'; }
  document.getElementById('item-modal-overlay').classList.add('open');
}
function closeItemModal() {
  document.getElementById('item-modal-overlay').classList.remove('open');
}

<?php if ($link['chat_enabled'] ?? 1): ?>
// ── LIVE CHAT (polling — no websockets needed) ──────────────
const CHAT_LINK_ID  = <?= (int)$link['id'] ?>;
const CHAT_ID_KEY   = 'kweek_chat_identity';
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
let chatLastId = 0;
let chatOpen = false;
let chatPollTimer = null;
let chatIdentity = null;

try {
  const saved = JSON.parse(localStorage.getItem(CHAT_ID_KEY) || 'null');
  if (saved && saved.name && EMAIL_PATTERN.test(saved.email || '')) chatIdentity = saved;
} catch (e) { /* storage may be unavailable; the form simply shows again */ }

function showChatStage() {
  const ready = !!chatIdentity;
  document.getElementById('chat-intro').style.display     = ready ? 'none' : 'flex';
  document.getElementById('chat-body').style.display      = ready ? 'flex' : 'none';
  document.getElementById('chat-input-row').style.display = ready ? 'flex' : 'none';
}

function prefillChatIntro() {
  const nameEl  = document.getElementById('chat-name');
  const emailEl = document.getElementById('chat-email');
  const formName  = document.querySelector('#pay-form input[name="customer_name"]')?.value?.trim();
  const formEmail = document.querySelector('#pay-form input[name="customer_email"]')?.value?.trim();
  if (nameEl  && !nameEl.value  && formName)  nameEl.value  = formName;
  if (emailEl && !emailEl.value && formEmail) emailEl.value = formEmail;
}

function startChat() {
  const name  = document.getElementById('chat-name').value.trim();
  const email = document.getElementById('chat-email').value.trim();
  const errEl = document.getElementById('chat-intro-error');

  if (name.length < 2) {
    errEl.textContent = 'Please tell us your name so the merchant knows who you are.';
    errEl.style.display = 'block';
    document.getElementById('chat-name').focus();
    return;
  }
  if (!EMAIL_PATTERN.test(email)) {
    errEl.textContent = 'That email address doesn\'t look quite right. Could you check it?';
    errEl.style.display = 'block';
    document.getElementById('chat-email').focus();
    return;
  }

  errEl.style.display = 'none';
  chatIdentity = { name: name, email: email };
  try { localStorage.setItem(CHAT_ID_KEY, JSON.stringify(chatIdentity)); } catch (e) {}

  showChatStage();
  fetchChatMessages();
  document.getElementById('chat-input').focus();
}

function toggleChat() {
  chatOpen = !chatOpen;
  document.getElementById('chat-panel').classList.toggle('open', chatOpen);
  document.getElementById('chat-bubble-btn').classList.remove('has-unread');
  if (chatOpen) {
    showChatStage();
    if (chatIdentity) {
      fetchChatMessages();
      document.getElementById('chat-input').focus();
    } else {
      prefillChatIntro();
      document.getElementById('chat-name').focus();
    }
    if (!chatPollTimer) chatPollTimer = setInterval(fetchChatMessages, 4000);
  }
}

function renderChatMsg(m) {
  const body = document.getElementById('chat-body');
  const empty = document.getElementById('chat-empty');
  if (empty) empty.remove();
  const div = document.createElement('div');
  div.className = 'chat-msg ' + (m.sender_type === 'customer' ? 'mine' : 'theirs');
  div.textContent = m.message;
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
}

function fetchChatMessages() {
  fetch(`/chat-poll.php?link_id=${CHAT_LINK_ID}&after=${chatLastId}&role=customer`)
    .then(r => r.json().catch(() => { throw new Error('Server did not return JSON (HTTP ' + r.status + ')'); }))
    .then(data => {
      if (!data.ok) { console.error('Kweek chat poll error:', data.error); return; }
      if (data.messages && data.messages.length) {
        data.messages.forEach(m => {
          renderChatMsg(m);
          chatLastId = Math.max(chatLastId, parseInt(m.id));
          if (m.sender_type === 'merchant' && !chatOpen) {
            document.getElementById('chat-bubble-btn').classList.add('has-unread');
          }
        });
      }
    })
    .catch(err => console.error('Kweek chat poll failed:', err));
}

function sendChatMsg() {
  if (!chatIdentity) { showChatStage(); return; }

  const input = document.getElementById('chat-input');
  const msg = input.value.trim();
  if (!msg) return;
  const btn = document.getElementById('chat-send-btn');
  const errEl = document.getElementById('chat-error');
  errEl.style.display = 'none';
  btn.disabled = true;

  const fd = new FormData();
  fd.append('link_id', CHAT_LINK_ID);
  fd.append('message', msg);
  fd.append('customer_name', chatIdentity.name);
  fd.append('customer_email', chatIdentity.email);

  fetch('/chat-send', { method: 'POST', body: fd })
    .then(r => r.json().catch(() => { throw new Error('Server did not return JSON (HTTP ' + r.status + ')'); }))
    .then(data => {
      btn.disabled = false;
      if (data.ok) {
        renderChatMsg({ sender_type: 'customer', message: msg });
        chatLastId = Math.max(chatLastId, parseInt(data.id));
        input.value = '';
      } else {
        errEl.textContent = data.error || 'Message failed to send. Please try again.';
        errEl.style.display = 'block';
      }
    })
    .catch(err => {
      btn.disabled = false;
      errEl.textContent = 'Could not reach the server. Please check your connection and try again.';
      errEl.style.display = 'block';
      console.error('Kweek chat send failed:', err);
    });
}

setInterval(() => { if (!chatOpen && chatIdentity) fetchChatMessages(); }, 15000);
<?php endif; ?>
</script>
</body>
</html>