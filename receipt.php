<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';

$ref = $_GET['ref'] ?? '';
if ($ref) {
    require_once __DIR__ . '/includes/payment.php';
    finalizeOrderPayment($ref); // no-op if already paid, cheap if not
}

$db     = db();
$order  = null;
$receipt = null;

if ($ref) {
    // Try receipt code first
    $stmt = $db->prepare("SELECT r.*, o.customer_name, o.customer_phone, o.order_ref, o.items, o.delivery_zone, o.delivery_fee, o.subtotal, o.total_amount, o.currency AS order_currency, o.paid_at as order_paid_at, o.created_at as order_created_at, o.confirmed_at, o.nomba_txn_ref, pl.title as link_title, pl.slug, pl.accent_color, pl.link_type, u.name as merchant_name FROM receipts r JOIN orders o ON r.order_id=o.id JOIN payment_links pl ON o.link_id=pl.id JOIN users u ON o.user_id=u.id WHERE r.receipt_code=? AND r.is_valid=1 LIMIT 1");
    $stmt->execute([$ref]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        $stmt = $db->prepare("SELECT o.*, r.receipt_code, pl.title as link_title, pl.slug, pl.accent_color, pl.link_type, u.name as merchant_name FROM orders o LEFT JOIN receipts r ON o.id=r.order_id JOIN payment_links pl ON o.link_id=pl.id JOIN users u ON o.user_id=u.id WHERE o.order_ref=? AND (o.status='paid' OR o.status='completed') LIMIT 1");
        $stmt->execute([$ref]);
        $receipt = $stmt->fetch();
        if ($receipt) {
            $receipt['order_created_at'] = $receipt['created_at'];
            $receipt['order_paid_at']    = $receipt['paid_at'];
            $receipt['order_currency']   = $receipt['currency'] ?? 'NGN';
        }
    }
}

$isValid     = $receipt && !empty($receipt['receipt_code']);
$accentColor = ($isValid && !empty($receipt['accent_color'])) ? $receipt['accent_color'] : '#4F43D4';
$verifyUrl   = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// A receipt is for the buyer (and anyone they forward it to), so every amount
// on it is in the currency the buyer actually paid in, not the merchant's naira.
$rc = $isValid ? linkCurrency($receipt['order_currency'] ?? 'NGN') : 'NGN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isValid ? 'Payment Verified — ' . htmlspecialchars($receipt['receipt_code']) : 'Receipt Not Found' ?> — Kweek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p800:#2E2690;--p900:#1C1660;
  --n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;
  --success:#16A34A;--success-bg:#DCFCE7;--danger:#DC2626;--danger-bg:#FEE2E2;
  --font-display:'Space Grotesk',sans-serif;--font-body:'Space Grotesk',sans-serif;--font-mono:'Space Mono',monospace;
  --r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px;
  --accent: <?= htmlspecialchars($accentColor) ?>;
}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--n50);min-height:100vh;padding:24px 16px 48px;-webkit-font-smoothing:antialiased}

/* PRELOADER — same reusable zoom-icon pattern as pay.php, tinted to this
   payment's accent color rather than fixed brand purple */
#kweek-preloader{position:fixed;inset:0;background:var(--n0);z-index:99999;display:flex;align-items:center;justify-content:center;transition:opacity .5s ease,visibility .5s ease}
#kweek-preloader.kw-hide{opacity:0;visibility:hidden;pointer-events:none}
#kweek-preloader .kw-mark{width:56px;height:56px;border-radius:14px;background:var(--accent);display:flex;align-items:center;justify-content:center;animation:kwZoom 1.1s ease-in-out infinite}
#kweek-preloader .kw-mark svg{width:32px;height:32px}
@keyframes kwZoom{0%{transform:scale(.78);opacity:.7}50%{transform:scale(1.12);opacity:1}100%{transform:scale(.78);opacity:.7}}

.wrap{max-width:480px;margin:0 auto;opacity:0;animation:kwRise .5s cubic-bezier(.22,.68,0,1) .1s forwards}
@keyframes kwRise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.kweek-nav{display:flex;align-items:center;justify-content:center;margin-bottom:28px}
.kweek-logo{display:flex;align-items:center;gap:8px;text-decoration:none}
.kweek-logo-mark{width:26px;height:26px;border-radius:7px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.kweek-logo-mark svg{width:15px;height:15px}
.kweek-logo-word{font-family:var(--font-display);font-weight:700;font-size:18px;color:var(--n900);letter-spacing:-.3px}

/* ==========================================================================
   VALID RECEIPT — leans into the "verified receipt" identity: a merchant
   accent strip, a rotated ink-stamp seal, and dashed tear-style rules
   between sections instead of plain borders.
   ========================================================================== */
.receipt-card{background:var(--n0);border-radius:var(--r-xl);overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.06);position:relative}
.receipt-accent-bar{height:5px;background:var(--accent)}

.receipt-header{background:var(--success-bg);border-bottom:1px solid #BBF7D0;padding:28px;text-align:center;position:relative;overflow:hidden}
.receipt-icon{width:64px;height:64px;border-radius:50%;background:var(--n0);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:30px;margin:0 auto 14px;box-shadow:0 2px 12px rgba(22,163,74,.2)}
.receipt-header h2{font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--n900);margin-bottom:6px}
.receipt-code{font-family:var(--font-mono);font-size:13px;color:var(--success);font-weight:700;background:rgba(22,163,74,.1);padding:4px 12px;border-radius:var(--r-full);display:inline-flex;align-items:center;gap:8px;margin-top:4px}
.receipt-code button{background:none;border:none;cursor:pointer;color:var(--success);font-size:13px;padding:0;display:flex}

/* rotated stamp, tucked in the header corner like an ink seal */
.receipt-stamp{
  position:absolute; top:14px; right:-6px; transform:rotate(8deg);
  border:2px solid var(--success); color:var(--success);
  font-family:var(--font-mono); font-size:10px; font-weight:700; letter-spacing:1.5px;
  padding:5px 10px; border-radius:6px; background:rgba(255,255,255,.5);
  white-space:nowrap;
}

.receipt-body{padding:24px}
.r-section{margin-bottom:20px;padding-bottom:20px;border-bottom:1.5px dashed var(--n200)}
.r-section:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.r-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--n400);margin-bottom:14px}
.r-row{display:flex;justify-content:space-between;align-items:center;font-size:14px;margin-bottom:8px;gap:8px}
.r-row:last-child{margin-bottom:0}
.r-key{color:var(--n500)}
.r-val{font-weight:600;color:var(--n900);text-align:right}
.r-mono{font-family:var(--font-mono)}
.r-amount{font-family:var(--font-display);font-size:32px;font-weight:700;color:var(--n900);letter-spacing:-1px;text-align:center;padding:16px 0 4px}
.r-amount-label{text-align:center;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--n400);margin-bottom:2px}

.r-breakdown{margin-top:4px;padding-top:12px;border-top:1.5px dashed var(--n200);display:flex;flex-direction:column;gap:6px}
.r-breakdown .r-row{font-size:13px}
.r-breakdown .r-row.total{font-size:14px;font-weight:700;color:var(--n900)}

/* timeline */
.r-timeline-item{display:flex;gap:10px;align-items:flex-start}
.r-timeline-dot{width:20px;height:20px;border-radius:50%;background:var(--success-bg);color:var(--success);display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;margin-top:1px}
.r-timeline-connector{width:1.5px;height:14px;background:var(--n200);margin-left:9.5px;border-left:1.5px dashed var(--n200);background:none}
.r-timeline-label{font-size:13px;font-weight:600;color:var(--n900)}
.r-timeline-time{font-size:11.5px;color:var(--n500)}

/* QR verify block */
.r-qr-wrap{display:flex;align-items:center;gap:14px;background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:14px}
.r-qr-wrap img{width:64px;height:64px;border-radius:8px;flex-shrink:0;background:#fff}
.r-qr-text{font-size:12px;color:var(--n500);line-height:1.5}
.r-qr-text strong{color:var(--n900)}

/* action buttons */
.r-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
.btn-action{display:inline-flex;align-items:center;gap:7px;border-radius:var(--r-md);padding:11px 18px;font-family:var(--font-body);font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;border:1.5px solid transparent}
.btn-action.primary{background:var(--p600);color:#fff}
.btn-action.primary:hover{background:var(--p500)}
.btn-action.ghost{background:var(--n0);color:var(--n600);border-color:var(--n200)}
.btn-action.ghost:hover{border-color:var(--p500);color:var(--p600)}

.receipt-footer{background:var(--n50);border-top:1px solid var(--n200);padding:18px 24px;text-align:center}
.receipt-footer p{font-size:12px;color:var(--n500);line-height:1.6}
.receipt-footer strong{color:var(--p600)}

.tag-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--n500);background:var(--n100);padding:3px 10px;border-radius:var(--r-full);text-transform:capitalize}

/* ==========================================================================
   INVALID / NOT FOUND
   ========================================================================== */
.invalid-card{background:var(--n0);border-radius:var(--r-xl);padding:40px 24px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.invalid-icon{width:64px;height:64px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;color:var(--danger);font-size:28px;margin:0 auto 16px}

.r-input{width:100%;font-family:var(--font-body);font-size:14px;color:var(--n900);background:var(--n50);border:1.5px solid transparent;border-bottom:1.5px solid var(--n200);border-radius:var(--r-md);padding:12px 14px;outline:none;transition:all .15s}
.r-input::placeholder{color:var(--n400)}
.r-input:focus{background:#fff;border-color:var(--p500);box-shadow:0 0 0 4px rgba(100,87,232,.15)}
.r-submit{background:var(--p600);color:#fff;border:none;border-radius:var(--r-md);padding:12px 16px;cursor:pointer;font-family:var(--font-body);font-weight:700;font-size:14px;transition:background .2s}
.r-submit:hover{background:var(--p500)}

@media print{
  body{background:#fff;padding:0}
  #kweek-preloader,.kweek-nav,.r-actions,.no-print{display:none!important}
  .wrap{max-width:100%;opacity:1!important;animation:none!important;transform:none!important}
  .receipt-card{box-shadow:none;border:1px solid #ddd}
}
</style>
</head>
<body>

<div id="kweek-preloader">
  <div class="kw-mark">
    <svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/><rect x="14" y="36" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/></svg>
  </div>
</div>

<div class="wrap">
  <div class="kweek-nav">
    <a href="/" class="kweek-logo">
      <span class="kweek-logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/><rect x="14" y="36" width="36" height="4" fill="<?= htmlspecialchars($accentColor) ?>"/></svg></span>
      <span class="kweek-logo-word">kweek</span>
    </a>
  </div>

  <?php if ($isValid): ?>
  <div class="receipt-card" id="receipt-card">
    <div class="receipt-accent-bar"></div>
    <div class="receipt-header">
      <div class="receipt-stamp">VERIFIED ✓</div>
      <div class="receipt-icon"><i class="ti ti-circle-check"></i></div>
      <h2>Payment Verified</h2>
      <p style="font-size:14px;color:var(--n600)">This payment is genuine and verified by Kweek</p>
      <div class="receipt-code">
        <?= htmlspecialchars($receipt['receipt_code']) ?>
        <button onclick="copyText('<?= htmlspecialchars(addslashes($receipt['receipt_code'])) ?>','Receipt code')" title="Copy receipt code"><i class="ti ti-copy"></i></button>
      </div>
    </div>
    <div class="receipt-body">
      <!-- Amount + breakdown -->
      <div>
        <div class="r-amount-label">Amount Paid</div>
        <div class="r-amount"><?= formatMoney($receipt['total_amount'], $rc) ?></div>
        <?php if (($receipt['delivery_fee'] ?? 0) > 0): ?>
        <div class="r-breakdown">
          <div class="r-row"><span class="r-key">Subtotal</span><span class="r-val"><?= formatMoney($receipt['subtotal'], $rc) ?></span></div>
          <div class="r-row"><span class="r-key">Delivery<?= !empty($receipt['delivery_zone']) ? ' (' . htmlspecialchars($receipt['delivery_zone']) . ')' : '' ?></span><span class="r-val"><?= formatMoney($receipt['delivery_fee'], $rc) ?></span></div>
          <div class="r-row total"><span>Total</span><span><?= formatMoney($receipt['total_amount'], $rc) ?></span></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Payment details -->
      <div class="r-section">
        <div class="r-section-title">Payment Details</div>
        <div class="r-row"><span class="r-key">For</span><span class="r-val"><?= htmlspecialchars($receipt['link_title']) ?></span></div>
        <div class="r-row"><span class="r-key">Merchant</span><span class="r-val"><?= htmlspecialchars($receipt['merchant_name']) ?></span></div>
        <div class="r-row"><span class="r-key">Paid at</span><span class="r-val"><?= nigeriaTime($receipt['order_paid_at'] ?? $receipt['paid_at']) ?></span></div>
        <div class="r-row">
          <span class="r-key">Order Ref</span>
          <span class="r-val r-mono" style="font-size:12px;display:inline-flex;align-items:center;gap:6px">
            <?= htmlspecialchars($receipt['order_ref']) ?>
            <button onclick="copyText('<?= htmlspecialchars(addslashes($receipt['order_ref'])) ?>','Order ref')" style="background:none;border:none;cursor:pointer;color:var(--n400);padding:0;display:flex"><i class="ti ti-copy" style="font-size:13px"></i></button>
          </span>
        </div>
        <?php if (!empty($receipt['nomba_txn_ref'])): ?>
        <div class="r-row"><span class="r-key">Transaction Ref</span><span class="r-val r-mono" style="font-size:12px"><?= htmlspecialchars($receipt['nomba_txn_ref']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($receipt['link_type']) && $receipt['link_type'] !== 'simple'): ?>
        <div class="r-row"><span class="r-key">Order Type</span><span class="r-val"><span class="tag-pill"><?= htmlspecialchars(str_replace('_',' ',$receipt['link_type'])) ?></span></span></div>
        <?php endif; ?>
      </div>

      <!-- Customer details -->
      <div class="r-section">
        <div class="r-section-title">Customer</div>
        <div class="r-row"><span class="r-key">Name</span><span class="r-val"><?= htmlspecialchars($receipt['customer_name']) ?></span></div>
        <div class="r-row"><span class="r-key">Phone</span><span class="r-val"><?= htmlspecialchars($receipt['customer_phone']) ?></span></div>
      </div>

      <!-- Items -->
      <?php if ($receipt['items']):
        $items = json_decode($receipt['items'], true);
        if ($items):
      ?>
      <div class="r-section">
        <div class="r-section-title">Items</div>
        <?php foreach ($items as $item): ?>
        <div class="r-row">
          <span class="r-key"><?= htmlspecialchars($item['name'] ?? '') ?><?= ($item['qty']??1) > 1 ? ' x' . $item['qty'] : '' ?></span>
          <span class="r-val"><?= formatMoney(($item['price']??0) * ($item['qty']??1), $rc) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; endif; ?>

      <!-- Timeline -->
      <?php
        $timeline = [];
        if (!empty($receipt['order_created_at'])) $timeline[] = ['label'=>'Order placed', 'time'=>$receipt['order_created_at'], 'icon'=>'shopping-cart'];
        if (!empty($receipt['confirmed_at']))     $timeline[] = ['label'=>'Confirmed by merchant', 'time'=>$receipt['confirmed_at'], 'icon'=>'check'];
        if (!empty($receipt['order_paid_at']))    $timeline[] = ['label'=>'Payment received', 'time'=>$receipt['order_paid_at'], 'icon'=>'circle-check'];
      ?>
      <?php if (!empty($timeline)): ?>
      <div class="r-section">
        <div class="r-section-title">Timeline</div>
        <?php foreach ($timeline as $i => $t): ?>
        <div class="r-timeline-item">
          <div class="r-timeline-dot"><i class="ti ti-<?= $t['icon'] ?>"></i></div>
          <div>
            <div class="r-timeline-label"><?= $t['label'] ?></div>
            <div class="r-timeline-time"><?= nigeriaTime($t['time']) ?></div>
          </div>
        </div>
        <?php if ($i < count($timeline)-1): ?><div class="r-timeline-connector"></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Scan to verify -->
      <div class="r-section">
        <div class="r-section-title">Scan to Verify</div>
        <div class="r-qr-wrap">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&margin=6&data=<?= urlencode($verifyUrl) ?>" alt="Scan to verify this receipt">
          <div class="r-qr-text">Anyone can scan this code to independently confirm this payment on <strong>Kweek</strong> — useful if you're forwarding a screenshot.</div>
        </div>
      </div>

      <!-- Actions -->
      <div class="r-actions no-print">
        <button class="btn-action primary" onclick="navigator.share ? navigator.share({title:'Payment Receipt',text:'Payment verified by Kweek',url:window.location.href}) : copyText(window.location.href,'Receipt link')">
          <i class="ti ti-share"></i> Share
        </button>
        <button class="btn-action ghost" onclick="window.print()">
          <i class="ti ti-printer"></i> Print / Save PDF
        </button>
        <?php if (!empty($receipt['slug'])): ?>
        <a href="/pay/<?= urlencode($receipt['slug']) ?>" class="btn-action ghost">
          <i class="ti ti-repeat"></i> Buy Again
        </a>
        <?php endif; ?>
      </div>
    </div>
    <div class="receipt-footer">
      <p>This receipt was verified by <strong>Kweek</strong>. Any payment not appearing here has not been received by the merchant. If you have questions, contact <strong>support@kweek.ng</strong></p>
    </div>
  </div>

  <?php else: ?>
  <div class="invalid-card">
    <div class="invalid-icon"><i class="ti ti-alert-triangle"></i></div>
    <h2 style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--n900);margin-bottom:8px">Receipt Not Found</h2>
    <p style="font-size:14px;color:var(--n500);line-height:1.6;margin-bottom:20px">
      <?php if ($ref): ?>
      No verified payment was found for <strong><?= htmlspecialchars($ref) ?></strong>. This could mean the payment was not completed or the receipt code is incorrect.
      <?php else: ?>
      Enter a valid receipt code or order reference to verify a payment.
      <?php endif; ?>
    </p>

    <!-- Search box -->
    <form method="GET" style="display:flex;flex-direction:column;gap:10px;max-width:360px;margin:0 auto">
      <input type="text" name="ref" class="r-input" placeholder="Enter receipt code or order ref" value="<?= htmlspecialchars($ref) ?>">
      <button type="submit" class="r-submit">Verify</button>
    </form>

    <div style="margin-top:28px;padding-top:20px;border-top:1.5px dashed var(--n200)">
      <p style="font-size:13px;color:var(--n500)">If you believe you've been sent a fake payment alert, contact Kweek support at <a href="mailto:support@kweek.ng" style="color:var(--p600);font-weight:600">support@kweek.ng</a></p>
    </div>
  </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:24px;font-size:12px;color:var(--n500)" class="no-print">
    <a href="/" style="color:var(--p600);text-decoration:none;font-weight:600">Powered by Kweek</a> · The payment OS for Nigerian merchants
  </div>
</div>

<script>
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('kweek-preloader').classList.add('kw-hide'), 500);
});

function copyText(text, label) {
  navigator.clipboard.writeText(text).then(() => toast((label || 'Text') + ' copied'));
}
function toast(msg) {
  let el = document.getElementById('kw-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'kw-toast';
    el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:var(--n900);color:#fff;font-family:var(--font-body);font-size:13px;font-weight:600;padding:10px 18px;border-radius:999px;z-index:10000;opacity:0;transition:opacity .2s;pointer-events:none';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.opacity = '1';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.opacity = '0'; }, 1800);
}
</script>
</body>
</html>