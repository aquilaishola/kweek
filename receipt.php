<?php
require_once __DIR__ . '/includes/functions.php';

$ref = clean(get('ref', ''));

$db     = db();
$order  = null;
$receipt = null;

if ($ref) {
    // Try receipt code first
    $stmt = $db->prepare("SELECT r.*, o.customer_name, o.customer_phone, o.order_ref, o.items, o.delivery_zone, o.delivery_fee, o.subtotal, o.total_amount, o.paid_at as order_paid_at, pl.title as link_title, pl.slug, u.name as merchant_name FROM receipts r JOIN orders o ON r.order_id=o.id JOIN payment_links pl ON o.link_id=pl.id JOIN users u ON o.user_id=u.id WHERE r.receipt_code=? AND r.is_valid=1 LIMIT 1");
    $stmt->execute([$ref]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        $stmt = $db->prepare("SELECT o.*, r.receipt_code, pl.title as link_title, pl.slug, u.name as merchant_name FROM orders o LEFT JOIN receipts r ON o.id=r.order_id JOIN payment_links pl ON o.link_id=pl.id JOIN users u ON o.user_id=u.id WHERE o.order_ref=? AND (o.status='paid' OR o.status='completed') LIMIT 1");
        $stmt->execute([$ref]);
        $receipt = $stmt->fetch();
    }
}

$isValid = $receipt && !empty($receipt['receipt_code']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isValid ? 'Payment Verified — ' . htmlspecialchars($receipt['receipt_code']) : 'Receipt Not Found' ?> — Kweek</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--p400:#7B6FEE;--p500:#6457E8;--p600:#4F43D4;--p800:#2E2690;--p900:#1C1660;--n0:#fff;--n50:#F9F9FB;--n100:#F2F1F6;--n200:#E4E3EE;--n400:#9896B0;--n500:#6E6C88;--n600:#4A4862;--n800:#1E1C32;--n900:#0F0E1C;--success:#16A34A;--success-bg:#DCFCE7;--danger:#DC2626;--danger-bg:#FEE2E2;--font-display:'Sora',sans-serif;--font-body:'Inter',sans-serif;--r-md:12px;--r-lg:16px;--r-xl:24px;--r-full:9999px}
body{font-family:var(--font-body);background:var(--n50);min-height:100vh;padding:24px 16px 48px;-webkit-font-smoothing:antialiased}
#preloader{position:fixed;inset:0;background:var(--n900);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:20px;transition:opacity .5s,visibility .5s}
#preloader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pre-logo{font-family:var(--font-display);font-size:28px;font-weight:800;color:var(--n0);letter-spacing:-1px}
.pre-logo .acc{color:var(--p400)}
.pre-ring{width:40px;height:40px;border-radius:50%;border:3px solid rgba(255,255,255,.1);border-top-color:var(--p500);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.wrap{max-width:480px;margin:0 auto}
.kweek-nav{display:flex;align-items:center;justify-content:center;margin-bottom:28px}
.kweek-logo{font-family:var(--font-display);font-weight:800;font-size:22px;color:var(--n900);text-decoration:none;letter-spacing:-.5px}
.kweek-logo .acc{color:var(--p600)}

/* VALID RECEIPT */
.receipt-card{background:var(--n0);border-radius:var(--r-xl);overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.receipt-header{background:var(--success-bg);border-bottom:1px solid #BBF7D0;padding:28px;text-align:center}
.receipt-icon{width:64px;height:64px;border-radius:50%;background:var(--n0);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:30px;margin:0 auto 14px;box-shadow:0 2px 12px rgba(22,163,74,.2)}
.receipt-header h2{font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:6px}
.receipt-code{font-family:monospace;font-size:13px;color:var(--success);font-weight:700;background:rgba(22,163,74,.1);padding:4px 12px;border-radius:var(--r-full);display:inline-block;margin-top:4px}

.receipt-body{padding:24px}
.r-section{margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--n200)}
.r-section:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.r-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--n400);margin-bottom:14px}
.r-row{display:flex;justify-content:space-between;align-items:center;font-size:14px;margin-bottom:8px;gap:8px}
.r-row:last-child{margin-bottom:0}
.r-key{color:var(--n500)}
.r-val{font-weight:600;color:var(--n900);text-align:right}
.r-amount{font-family:var(--font-display);font-size:28px;font-weight:800;color:var(--n900);letter-spacing:-1px;text-align:center;padding:16px 0}

.receipt-footer{background:var(--n50);border-top:1px solid var(--n200);padding:18px 24px;text-align:center}
.receipt-footer p{font-size:12px;color:var(--n500);line-height:1.6}
.receipt-footer strong{color:var(--p600)}

/* INVALID */
.invalid-card{background:var(--n0);border-radius:var(--r-xl);padding:40px 24px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.invalid-icon{width:64px;height:64px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;color:var(--danger);font-size:28px;margin:0 auto 16px}

.btn-share{display:inline-flex;align-items:center;gap:7px;background:var(--p600);color:#fff;border:none;border-radius:var(--r-md);padding:12px 20px;font-family:var(--font-body);font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;margin-top:16px}

</style>
</head>
<body>
<div id="preloader"><div class="pre-logo">Kw<span class="acc">ee</span>k</div><div class="pre-ring"></div></div>

<div class="wrap">
  <div class="kweek-nav"><a href="/" class="kweek-logo">Kw<span class="acc">ee</span>k</a></div>

  <?php if ($isValid): ?>
  <div class="receipt-card">
    <div class="receipt-header">
      <div class="receipt-icon"><i class="ti ti-circle-check"></i></div>
      <h2>Payment Verified</h2>
      <p style="font-size:14px;color:var(--n600)">This payment is genuine and verified by Kweek</p>
      <div class="receipt-code"><?= htmlspecialchars($receipt['receipt_code']) ?></div>
    </div>
    <div class="receipt-body">
      <!-- Amount -->
      <div class="r-amount"><?= formatNaira($receipt['total_amount']) ?></div>

      <!-- Payment details -->
      <div class="r-section">
        <div class="r-section-title">Payment Details</div>
        <div class="r-row"><span class="r-key">For</span><span class="r-val"><?= htmlspecialchars($receipt['link_title']) ?></span></div>
        <div class="r-row"><span class="r-key">Merchant</span><span class="r-val"><?= htmlspecialchars($receipt['merchant_name']) ?></span></div>
        <div class="r-row"><span class="r-key">Paid at</span><span class="r-val"><?= nigeriaTime($receipt['order_paid_at'] ?? $receipt['paid_at']) ?></span></div>
        <div class="r-row"><span class="r-key">Order Ref</span><span class="r-val" style="font-family:monospace;font-size:12px"><?= htmlspecialchars($receipt['order_ref']) ?></span></div>
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
          <span class="r-val"><?= formatNaira(($item['price']??0) * ($item['qty']??1)) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (($receipt['delivery_fee'] ?? 0) > 0): ?>
        <div class="r-row"><span class="r-key">Delivery (<?= htmlspecialchars($receipt['delivery_zone']) ?>)</span><span class="r-val"><?= formatNaira($receipt['delivery_fee']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php endif; endif; ?>

      <!-- Share -->
      <div style="text-align:center">
        <button class="btn-share" onclick="navigator.share ? navigator.share({title:'Payment Receipt',text:'Payment verified by Kweek',url:window.location.href}) : navigator.clipboard.writeText(window.location.href).then(()=>alert('Link copied!'))">
          <i class="ti ti-share"></i> Share Receipt
        </button>
      </div>
    </div>
    <div class="receipt-footer">
      <p>This receipt was verified by <strong>Kweek</strong>. Any payment not appearing here has not been received by the merchant. If you have questions, contact <strong>support@kweek.ng</strong></p>
    </div>
  </div>

  <?php else: ?>
  <div class="invalid-card">
    <div class="invalid-icon"><i class="ti ti-alert-triangle"></i></div>
    <h2 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);margin-bottom:8px">Receipt Not Found</h2>
    <p style="font-size:14px;color:var(--n500);line-height:1.6;margin-bottom:20px">
      <?php if ($ref): ?>
      No verified payment was found for <strong><?= htmlspecialchars($ref) ?></strong>. This could mean the payment was not completed or the receipt code is incorrect.
      <?php else: ?>
      Enter a valid receipt code or order reference to verify a payment.
      <?php endif; ?>
    </p>

    <!-- Search box -->
    <form method="GET" style="display:flex;flex-direction:column;gap:8px;max-width:360px;margin:0 auto">
      <input type="text" name="ref" placeholder="Enter receipt code or order ref" value="<?= htmlspecialchars($ref) ?>"
        style="flex:1;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px;outline:none;background:var(--n50)">
      <button type="submit" style="background:var(--p600);color:#fff;border:none;border-radius:var(--r-md);padding:11px 16px;cursor:pointer;font-family:var(--font-body);font-weight:600;font-size:14px">Verify</button>
    </form>

    <div style="margin-top:28px;padding-top:20px;border-top:1px solid var(--n200)">
      <p style="font-size:13px;color:var(--n500)">If you believe you've been sent a fake payment alert, contact Kweek support at <a href="mailto:support@kweek.ng" style="color:var(--p600);font-weight:600">support@kweek.ng</a></p>
    </div>
  </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:24px;font-size:12px;color:var(--n500)">
    <a href="/" style="color:var(--p600);text-decoration:none;font-weight:600">Powered by Kweek</a> · The payment OS for Nigerian merchants
  </div>
</div>

<script>window.addEventListener('load',()=>setTimeout(()=>document.getElementById('preloader').classList.add('hide'),800))</script>
</body>
</html>