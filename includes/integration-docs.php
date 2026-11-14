<?php /* "Accept payments on your website" docs card — included on api-webhooks.php (Business plan section) */
$_host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'kweek.ng');
?>
<div class="card">
  <div class="card-title" style="margin-bottom:4px">Accept payments on your website</div>
  <div class="card-sub" style="margin-bottom:18px">Send buyers to one of your Kweek payment links, prefilled. When they pay, Kweek calls your webhook so your site can confirm it.</div>

  <div class="card-sub" style="font-weight:600;color:var(--ink);margin-bottom:8px">1 · Send the buyer to your link</div>
  <div class="aw-code-block">https://<?= $_host ?>/pay/<strong>your-link-slug</strong>
  ?name=Ada%20Obi&amp;email=ada@example.com&amp;phone=08012345678
  &amp;amount=15000&amp;reference=ORDER-1042
  &amp;redirect_url=https%3A%2F%2Fmysite.com%2Forders%2F1042%2Fthanks
  &amp;sig=&lt;optional signature&gt;</div>
  <div class="table-wrap" style="margin-top:12px">
    <table>
      <thead><tr><th>Param</th><th>What it does</th></tr></thead>
      <tbody>
        <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">name · email · phone</td><td style="font-size:13px">Prefilled and locked (read-only) on the pay page.</td></tr>
        <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">amount</td><td style="font-size:13px">Only for open-amount links. Shown to the buyer, not editable. Fixed-price links ignore it.</td></tr>
        <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">reference</td><td style="font-size:13px">Your own order id. Returned to you as <code>client_reference</code> in the webhook and on the redirect.</td></tr>
        <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">redirect_url</td><td style="font-size:13px">Where to send the buyer after paying. It must be on the <strong>same domain</strong> as the Redirect URL saved on this link; otherwise the saved one is used.</td></tr>
        <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">sig</td><td style="font-size:13px">Optional but recommended. Proves the values came from your server (see below).</td></tr>
      </tbody>
    </table>
  </div>
  <div class="form-hint" style="margin-top:8px">Locked fields stop honest mistakes, but a URL can be edited by the buyer. Always check the <strong>amount</strong> and <strong>reference</strong> in the webhook against your own order, and sign your links.</div>

  <div class="card-sub" style="font-weight:600;color:var(--ink);margin:20px 0 8px">2 · Sign the link (server-side, never in the browser)</div>
  <div class="aw-code-block">// PHP — $secret is your Signing Secret above
$p   = ['amount'=>'15000','reference'=>'ORDER-1042','email'=>'ada@example.com',
        'phone'=>'08012345678','name'=>'Ada Obi','redirect_url'=>'https://mysite.com/orders/1042/thanks'];
$sig = hash_hmac('sha256', implode('|', [$slug, $p['amount']??'', $p['reference']??'', $p['email']??'',
                                          $p['phone']??'', $p['name']??'', $p['redirect_url']??'']), $secret);
$url = "https://<?= $_host ?>/pay/$slug?" . http_build_query($p + ['sig' => $sig]);

// Node
const s = [slug, p.amount, p.reference, p.email, p.phone, p.name, p.redirect_url].map(v => v ?? '').join('|');
const sig = require('crypto').createHmac('sha256', secret).update(s).digest('hex');</div>
  <div class="form-hint" style="margin-top:8px">Use exactly the values you put in the URL (empty string for any param you don't send). A wrong or missing-secret <code>sig</code> makes the page refuse to load.</div>

  <div class="card-sub" style="font-weight:600;color:var(--ink);margin:20px 0 8px">3 · Receive the webhook</div>
  <div class="aw-code-block">POST https://mysite.com/webhooks/kweek
X-Kweek-Event: payment.success
X-Kweek-Signature: &lt;hmac-sha256 of the raw body, hex&gt;

{ "id": "evt_ORD-20260930-A1B2C3", "event": "payment.success", "sent_at": "2026-09-30T14:02:11+01:00",
  "data": { "reference": "ORD-20260930-A1B2C3", "client_reference": "ORDER-1042", "status": "paid",
            "amount": 15000, "currency": "NGN", "paid_at": "2026-09-30T14:02:09+01:00",
            "customer": { "name": "Ada Obi", "email": "ada@example.com", "phone": "08012345678" },
            "signed_request": true } }</div>
  <div class="aw-code-block" style="margin-top:12px">// PHP — verify, check, fulfil, answer 200
$raw = file_get_contents('php://input');
if (!hash_equals(hash_hmac('sha256', $raw, $secret), $_SERVER['HTTP_X_KWEEK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
$e = json_decode($raw, true);
if ($e['event'] === 'payment.success') {
    $order = findOrder($e['data']['client_reference']);          // your order
    if ($order &amp;&amp; (float)$e['data']['amount'] === (float)$order->total &amp;&amp; $e['data']['currency'] === $order->currency) {
        markPaidOnce($order, $e['data']['reference']);            // safe to run twice
    }
}
http_response_code(200);</div>
  <div class="form-hint" style="margin-top:8px">Reply with any <code>2xx</code>. Anything else is retried after 1 min, 5 min, 30 min, 2 h and 12 h. The same event can arrive more than once, so make your handler idempotent (use <code>data.reference</code>). Don't rely on the buyer's redirect alone — the webhook is the proof of payment.</div>
</div>
