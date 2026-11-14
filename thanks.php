<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/integration.php';

$ref = get('ref');
if ($ref) {
    require_once __DIR__ . '/includes/payment.php';
    finalizeOrderPayment($ref); // no-op if already paid, cheap if not — same as receipt.php
}

$db = db();
kwIntegrationSchema($db);

$stmt = $db->prepare("
    SELECT o.*, pl.title AS link_title, pl.accent_color, pl.logo_url, pl.redirect_url,
           pl.success_title, pl.success_message, pl.success_buttons, pl.show_receipt_button,
           u.name AS merchant_name
    FROM orders o
    JOIN payment_links pl ON pl.id = o.link_id
    JOIN users u ON u.id = o.user_id
    WHERE o.order_ref = ?
    LIMIT 1
");
$stmt->execute([$ref]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    die('Order not found.');
}

$isPaid = in_array($order['status'], ['paid', 'completed'], true);

// ── tell the merchant's website (after the buyer's browser has been answered) ──
if ($isPaid) {
    ignore_user_abort(true);
    register_shutdown_function(function () use ($ref) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        kwDispatchOrderPaidWebhook($ref, 6);
    });
}

// ── send the buyer back to the merchant's site (after a short countdown) ──
$returnUrl = kwResolveRedirect($order['redirect_override'] ?? null, $order['redirect_url'] ?? null);

// Link has no saved redirect URL, but the pay request was signed with the merchant's
// secret (signature covers redirect_url), so the stored override is trustworthy.
if (!$returnUrl
    && !empty($order['signed_request'])
    && !empty($order['redirect_override'])
    && preg_match('#^https?://#i', $order['redirect_override'])) {
    $returnUrl = $order['redirect_override'];
}

$tries     = max(0, (int)get('n', 0));

$redirectTarget = null;
if ($returnUrl && $isPaid) {
    $redirectTarget = kwAppendQuery($returnUrl, array_filter([
        'ref'       => $order['order_ref'],
        'reference' => $order['client_reference'] ?? null,
        'status'    => 'success',
    ], fn($v) => $v !== null && $v !== ''));
}

// Payment not confirmed yet — keep checking for a few seconds, then fall back to the page below.
$waiting = $returnUrl && !$isPaid && $tries < 6;

$accentColor    = preg_match('/^#[0-9A-Fa-f]{6}$/', $order['accent_color'] ?? '') ? $order['accent_color'] : '#4F43D4';
$successButtons = $order['success_buttons'] ? json_decode($order['success_buttons'], true) : [];
$showReceiptBtn = (bool)($order['show_receipt_button'] ?? 1);
$orderCurrency  = linkCurrency($order['currency'] ?? 'NGN');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $waiting ? 'Confirming payment' : 'Payment Successful' ?> — Kweek</title>
<?php if ($waiting): ?><meta http-equiv="refresh" content="3;url=/thanks.php?ref=<?= urlencode($order['order_ref']) ?>&n=<?= $tries + 1 ?>"><?php endif; ?>
<?php if ($redirectTarget): ?><meta http-equiv="refresh" content="6;url=<?= htmlspecialchars($redirectTarget) ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?= htmlspecialchars($accentColor) ?>;
  --acc-soft:<?= htmlspecialchars($accentColor) ?>22;
  --n0:#FFFFFF;--n50:#F9F9FB;--n200:#E4E3EE;
  --n400:#9896B0;--n500:#6E6C88;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#1E9E5A;--success-bg:#E9F9F0;
  --font:'Space Grotesk',sans-serif;
  --r-lg:16px;--r-xl:20px;--r-full:9999px;
}
body{font-family:var(--font);background:var(--n50);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:24px 16px 50px;-webkit-font-smoothing:antialiased}
.wrap{width:100%;max-width:440px}
.nav{display:flex;align-items:center;justify-content:center;margin-bottom:22px}
.logo{display:flex;align-items:center;gap:8px;text-decoration:none}
.logo-mark{width:26px;height:26px;border-radius:7px;background:var(--acc);display:flex;align-items:center;justify-content:center}
.logo-mark svg{width:15px;height:15px}
.logo-word{font-weight:700;font-size:17px;color:var(--n800);letter-spacing:-.3px}
.hero{background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-xl);padding:34px 24px;text-align:center;margin-bottom:14px}
.hero-icon{width:64px;height:64px;border-radius:50%;background:var(--success-bg);color:var(--success);display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 18px;animation:pop .4s ease}
.hero-icon.wait{background:var(--acc-soft);color:var(--acc)}
.hero-icon.wait i{animation:spin 1s linear infinite}
@keyframes pop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
@keyframes spin{to{transform:rotate(360deg)}}
.hero-logo{width:56px;height:56px;border-radius:14px;object-fit:cover;margin:0 auto 16px;display:block}
.hero h1{font-size:21px;font-weight:700;color:var(--n900);margin-bottom:8px;letter-spacing:-.4px}
.hero p{font-size:14px;color:var(--n500);line-height:1.7;white-space:pre-wrap}
.hero p strong{color:var(--acc);font-weight:700}
.cta-list{display:flex;flex-direction:column;gap:10px;margin-top:22px}
.cta-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px;border-radius:var(--r-lg);font-family:var(--font);font-size:14.5px;font-weight:700;text-decoration:none;cursor:pointer;border:none;transition:transform .15s,filter .15s}
.cta-btn:hover{transform:translateY(-1px)}
.cta-primary{background:var(--acc);color:#fff}
.cta-primary:hover{filter:brightness(1.08)}
.cta-outline{background:var(--n0);color:var(--n800);border:1.5px solid var(--n200)}
.cta-outline:hover{border-color:var(--acc);color:var(--acc)}
.powered{text-align:center;margin-top:20px;font-size:12px;color:var(--n400)}
.powered a{color:var(--acc);text-decoration:none;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <div class="nav">
    <a href="/" class="logo">
      <span class="logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/><rect x="14" y="36" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/></svg></span>
      <span class="logo-word">kweek</span>
    </a>
  </div>

  <div class="hero">
  <?php if ($waiting): ?>
    <div class="hero-icon wait"><i class="ti ti-loader-2"></i></div>
    <h1>Confirming your payment…</h1>
    <p>Hang on a moment. We'll take you back to <?= htmlspecialchars($order['merchant_name']) ?> as soon as it's confirmed.</p>

  <?php elseif ($redirectTarget): ?>
    <?php if (!empty($order['logo_url'])): ?>
    <img src="<?= htmlspecialchars($order['logo_url']) ?>" class="hero-logo" alt="">
    <?php else: ?>
    <div class="hero-icon"><i class="ti ti-circle-check"></i></div>
    <?php endif; ?>

    <h1><?= htmlspecialchars($order['success_title'] ?: 'Payment Successful!') ?></h1>
    <p>Redirecting you back to <?= htmlspecialchars($order['merchant_name']) ?> in <strong id="cd">5</strong> seconds…</p>

    <div class="cta-list">
      <a href="<?= htmlspecialchars($redirectTarget) ?>" class="cta-btn cta-primary">Go now <i class="ti ti-arrow-right"></i></a>
      <?php if ($showReceiptBtn): ?>
      <a href="/receipt.php?ref=<?= urlencode($order['order_ref']) ?>" class="cta-btn cta-outline"><i class="ti ti-receipt"></i> View Receipt</a>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <?php if (!empty($order['logo_url'])): ?>
    <img src="<?= htmlspecialchars($order['logo_url']) ?>" class="hero-logo" alt="">
    <?php else: ?>
    <div class="hero-icon"><i class="ti ti-circle-check"></i></div>
    <?php endif; ?>

    <h1><?= htmlspecialchars($order['success_title'] ?: 'Payment Successful!') ?></h1>
    <p><?= htmlspecialchars($order['success_message'] ?: ('Thanks for paying ' . $order['merchant_name'] . '. Your order "' . $order['link_title'] . '" for ' . formatMoney($order['total_amount'], $orderCurrency) . ' has been received.')) ?></p>

    <div class="cta-list">
      <?php if ($returnUrl): /* paid check failed to confirm in time: let them go back manually */ ?>
      <a href="<?= htmlspecialchars(kwAppendQuery($returnUrl, array_filter(['ref' => $order['order_ref'], 'reference' => $order['client_reference'] ?? null, 'status' => 'pending']))) ?>" class="cta-btn cta-primary">Return to <?= htmlspecialchars($order['merchant_name']) ?> <i class="ti ti-arrow-right"></i></a>
      <?php endif; ?>
      <?php foreach ($successButtons as $b): ?>
      <a href="<?= htmlspecialchars($b['url']) ?>" target="_blank" rel="noopener" class="cta-btn cta-primary"><?= htmlspecialchars($b['label']) ?> <i class="ti ti-external-link"></i></a>
      <?php endforeach; ?>
      <?php if ($showReceiptBtn): ?>
      <a href="/receipt.php?ref=<?= urlencode($order['order_ref']) ?>" class="cta-btn <?= (empty($successButtons) && !$returnUrl) ? 'cta-primary' : 'cta-outline' ?>"><i class="ti ti-receipt"></i> View Receipt</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>

  <div class="powered">
    <a href="/">Powered by Kweek</a> · Secure payments for Nigerian merchants
  </div>
</div>

<?php if ($redirectTarget): ?>
<script>
(function () {
  var s = 5, el = document.getElementById('cd'), url = <?= json_encode($redirectTarget) ?>;
  var t = setInterval(function () {
    s--;
    if (s <= 0) { clearInterval(t); window.location.href = url; return; }
    if (el) el.textContent = s;
  }, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
