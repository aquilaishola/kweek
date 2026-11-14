<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'API & Webhooks';
$activePage = 'api-webhooks';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// API access is a Business-plan feature per the settings/billing copy.
$hasApiAccess = $user['plan'] === 'business';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$hasApiAccess) {
        flash('error', 'API access is available on the Business plan.');
        redirect('/api-webhooks.php');
    }
    $action = post('action');

    // ── GENERATE API KEY ──────────────────────────────────────
    if ($action === 'generate_api_key') {
        // One active key at a time — generating a new one revokes the old.
        $db->prepare("UPDATE api_keys SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL")->execute([$uid]);

        $plainKey = 'kw_live_' . bin2hex(random_bytes(20));
        $prefix   = substr($plainKey, 0, 14);
        $hash     = hash('sha256', $plainKey);

        $db->prepare("INSERT INTO api_keys (user_id,label,key_prefix,key_hash) VALUES (?,?,?,?)")
           ->execute([$uid, 'Live Key', $prefix, $hash]);

        // Stash the plaintext in the session so it can be shown exactly
        // once, right after this redirect — never stored anywhere as
        // plaintext, and never shown again after this page reload.
        $_SESSION['new_api_key'] = $plainKey;
        flash('success', 'New API key generated. Copy it now — you won\'t be able to see it again.');
        redirect('/api-webhooks.php');
    }

    // ── REVOKE API KEY ─────────────────────────────────────────
    if ($action === 'revoke_api_key') {
        $keyId = (int)post('key_id');
        $db->prepare("UPDATE api_keys SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL")
           ->execute([$keyId, $uid]);
        flash('success', 'API key revoked.');
        redirect('/api-webhooks.php');
    }

    // ── SAVE WEBHOOK URL ───────────────────────────────────────
    if ($action === 'save_webhook') {
        $webhookUrl = trim(post('webhook_url'));
        if ($webhookUrl === '') {
            $db->prepare("UPDATE users SET webhook_url=NULL WHERE id=?")->execute([$uid]);
            flash('success', 'Webhook URL removed.');
        } elseif (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
            flash('error', 'Enter a valid HTTPS webhook URL.');
        } else {
            $db->prepare("UPDATE users SET webhook_url=? WHERE id=?")->execute([$webhookUrl, $uid]);
            $db->prepare("UPDATE users SET webhook_secret = COALESCE(NULLIF(webhook_secret,''), ?) WHERE id = ?")
   ->execute([bin2hex(random_bytes(24)), $uid]);
            flash('success', 'Webhook URL saved.');
        }
        redirect('/api-webhooks.php');
    }

    // ── REGENERATE WEBHOOK SECRET ──────────────────────────────
    if ($action === 'regenerate_webhook_secret') {
        $secret = bin2hex(random_bytes(24));
        $db->prepare("UPDATE users SET webhook_secret=? WHERE id=?")->execute([$secret, $uid]);
        flash('success', 'Webhook secret regenerated — update your endpoint to verify with the new secret.');
        redirect('/api-webhooks.php');
    }

    // ── SEND TEST WEBHOOK ──────────────────────────────────────
    if ($action === 'send_test_webhook') {
        $freshUser = $db->prepare("SELECT webhook_url, webhook_secret FROM users WHERE id=?");
        $freshUser->execute([$uid]);
        $wh = $freshUser->fetch();

        if (empty($wh['webhook_url'])) {
            flash('error', 'Set a webhook URL before sending a test event.');
        } else {
            $payload = json_encode([
                'event'   => 'test.ping',
                'data'    => ['message' => 'This is a test webhook from Kweek'],
                'sent_at' => date('c'),
            ]);
            $signature = hash_hmac('sha256', $payload, $wh['webhook_secret'] ?: '');

            $responseCode = null;
            $status = 'failed';
            if (function_exists('curl_init')) {
                $ch = curl_init($wh['webhook_url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Kweek-Signature: ' . $signature],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                curl_exec($ch);
                $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
                curl_close($ch);
                $status = ($responseCode && $responseCode >= 200 && $responseCode < 300) ? 'success' : 'failed';
            }

            $db->prepare("INSERT INTO webhook_deliveries (user_id,event_type,url,payload,response_code,status) VALUES (?,?,?,?,?,?)")
               ->execute([$uid, 'test.ping', $wh['webhook_url'], $payload, $responseCode, $status]);

            flash(
                $status === 'success' ? 'success' : 'error',
                $status === 'success' ? "Test webhook delivered (HTTP $responseCode)." : 'Test webhook failed' . ($responseCode ? " (HTTP $responseCode)." : ' — could not reach that URL.')
            );
        }
        redirect('/api-webhooks.php');
    }
}

// One-time plaintext API key reveal (set immediately after generation above)
$newApiKeyPlaintext = null;
if (!empty($_SESSION['new_api_key'])) {
    $newApiKeyPlaintext = $_SESSION['new_api_key'];
    unset($_SESSION['new_api_key']);
}

$apiKeys = [];
$webhookDeliveries = [];
if ($hasApiAccess) {
    $keyStmt = $db->prepare("SELECT * FROM api_keys WHERE user_id=? ORDER BY created_at DESC");
    $keyStmt->execute([$uid]);
    $apiKeys = $keyStmt->fetchAll();

    $delStmt = $db->prepare("SELECT * FROM webhook_deliveries WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $delStmt->execute([$uid]);
    $webhookDeliveries = $delStmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<style>
.aw-header{margin-bottom:24px}
.aw-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px}
.aw-header p{font-size:14px;color:var(--muted);margin-top:4px}

.aw-key-reveal{background:var(--purple-50);border:1.5px dashed var(--purple-300);border-radius:var(--r-lg);padding:16px;margin-bottom:20px}
.aw-key-reveal-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--purple-600);margin-bottom:8px}
.aw-key-mono{font-family:var(--font-mono);font-size:13.5px;color:var(--ink);background:var(--paper);border:1px solid var(--purple-100);border-radius:var(--r-md);padding:10px 12px;word-break:break-all;display:flex;align-items:center;justify-content:space-between;gap:10px}
.aw-key-mono button{background:none;border:none;cursor:pointer;color:var(--purple-600);flex-shrink:0}

.aw-secret-row{display:flex;align-items:center;gap:8px;font-family:var(--font-mono);font-size:13px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:var(--r-md);padding:10px 12px}
.aw-secret-row button{background:none;border:none;cursor:pointer;color:var(--muted-2);flex-shrink:0}

.aw-code-block{background:var(--n900,#0F0E1C);color:#E5E1F9;font-family:var(--font-mono);font-size:12.5px;line-height:1.7;border-radius:var(--r-md);padding:16px;overflow-x:auto;white-space:pre}

.aw-upsell{background:linear-gradient(135deg,#2A2270 0%,#4F43D4 65%,#6457E8 100%);border-radius:var(--r-lg);padding:36px 28px;text-align:center;color:#fff}
.aw-upsell i{font-size:34px;margin-bottom:14px;display:block}
.aw-upsell h2{font-family:var(--font-display);font-size:20px;font-weight:800;margin-bottom:8px}
.aw-upsell p{font-size:14px;color:rgba(255,255,255,.75);max-width:420px;margin:0 auto 20px;line-height:1.6}
</style>

<div class="aw-header">
  <h1>API &amp; Webhooks</h1>
  <p>Integrate Kweek with your own systems — generate a key and point a webhook at your server.</p>
</div>

<?php if (!$hasApiAccess): ?>
<div class="aw-upsell">
  <i class="ti ti-plug-connected"></i>
  <h2>API access is a Business plan feature</h2>
  <p>Generate API keys, configure webhooks for order and payment events, and integrate Kweek directly into your own systems.</p>
  <a href="/settings.php?tab=billing" class="btn btn-white btn-md"><i class="ti ti-arrow-up"></i> Upgrade to Business</a>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:20px;max-width:720px">

  <!-- API KEY -->
  <div class="card">
    <div class="card-title" style="margin-bottom:4px">API Key</div>
    <div class="card-sub" style="margin-bottom:20px">Use this key to authenticate requests to the Kweek API.</div>

    <?php if ($newApiKeyPlaintext): ?>
    <div class="aw-key-reveal">
      <div class="aw-key-reveal-label"><i class="ti ti-alert-triangle"></i> Copy this now — it won't be shown again</div>
      <div class="aw-key-mono">
        <span id="new-key-text"><?= htmlspecialchars($newApiKeyPlaintext) ?></span>
        <button type="button" onclick="copyText('<?= htmlspecialchars(addslashes($newApiKeyPlaintext)) ?>','API key')"><i class="ti ti-copy"></i></button>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($apiKeys)): ?>
    <div class="empty-state" style="padding:32px 16px">
      <div class="empty-title">No API key yet</div>
      <div class="empty-sub">Generate one to start making authenticated requests.</div>
    </div>
    <?php else: ?>
    <div class="table-wrap" style="margin-bottom:16px">
      <table>
        <thead><tr><th>Key</th><th>Status</th><th>Created</th><th>Last Used</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($apiKeys as $k): $isRevoked = !empty($k['revoked_at']); ?>
        <tr>
          <td class="td-bold" style="font-family:var(--font-mono);font-size:12.5px"><?= htmlspecialchars($k['key_prefix']) ?>…</td>
          <td><span class="badge badge-<?= $isRevoked ? 'neutral' : 'success' ?>"><?= $isRevoked ? 'Revoked' : 'Active' ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= nigeriaTime($k['created_at']) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= $k['last_used_at'] ? nigeriaTime($k['last_used_at']) : 'Never' ?></td>
          <td>
            <?php if (!$isRevoked): ?>
            <form method="POST" onsubmit="return confirm('Revoke this API key? Anything using it will stop working immediately.')">
              <?= csrfField() ?>
              <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
              <button type="submit" name="action" value="revoke_api_key" class="btn btn-outline btn-sm"><i class="ti ti-ban"></i> Revoke</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <form method="POST" onsubmit="return confirm('<?= empty($apiKeys) ? '' : 'This replaces your current key — anything using the old one will break. ' ?>Generate a new API key?')">
      <?= csrfField() ?>
      <button type="submit" name="action" value="generate_api_key" class="btn btn-purple btn-md">
        <i class="ti ti-key"></i> Generate <?= empty($apiKeys) ? 'API Key' : 'New Key' ?>
      </button>
    </form>
  </div>

  <!-- WEBHOOK -->
  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Webhook Endpoint</div>
    <div class="card-sub" style="margin-bottom:20px">Kweek will POST order and payment events to this URL as they happen.</div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_webhook">
      <div class="form-group">
        <label class="form-label">Webhook URL</label>
        <div class="form-input-wrap">
          <i class="ti ti-webhook inp-icon"></i>
          <input type="url" name="webhook_url" class="form-input" placeholder="https://yourapp.com/webhooks/kweek" value="<?= htmlspecialchars($user['webhook_url'] ?? '') ?>">
        </div>
        <div class="form-hint">Must be HTTPS. Leave blank and save to remove it.</div>
      </div>
      <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-check"></i> Save Webhook URL</button>
    </form>

    <div style="margin:20px 0;border-top:1px solid var(--line)"></div>

    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label">Signing Secret</label>
      <div class="aw-secret-row">
        <span id="webhook-secret-text" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" data-revealed="0">
          <?= !empty($user['webhook_secret']) ? str_repeat('•', 8) : 'Not generated yet' ?>
        </span>
        <?php if (!empty($user['webhook_secret'])): ?>
        <button type="button" onclick="toggleSecret('<?= htmlspecialchars(addslashes($user['webhook_secret'])) ?>')" title="Show/hide"><i class="ti ti-eye" id="secret-eye"></i></button>
        <button type="button" onclick="copyText('<?= htmlspecialchars(addslashes($user['webhook_secret'])) ?>','Webhook secret')" title="Copy"><i class="ti ti-copy"></i></button>
        <?php endif; ?>
      </div>
      <div class="form-hint">Use this to verify the <code>X-Kweek-Signature</code> header (HMAC-SHA256 of the raw payload).</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="POST" onsubmit="return confirm('Regenerate the webhook secret? You\'ll need to update your endpoint.')">
        <?= csrfField() ?>
        <button type="submit" name="action" value="regenerate_webhook_secret" class="btn btn-outline btn-md"><i class="ti ti-refresh"></i> Regenerate Secret</button>
      </form>
      <form method="POST">
        <?= csrfField() ?>
        <button type="submit" name="action" value="send_test_webhook" class="btn btn-outline btn-md"><i class="ti ti-send"></i> Send Test Webhook</button>
      </form>
    </div>

    <?php if (!empty($webhookDeliveries)): ?>
    <div style="margin-top:20px">
      <div class="card-sub" style="margin-bottom:10px;font-weight:600;color:var(--ink)">Recent Deliveries</div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Event</th><th>Status</th><th>HTTP</th><th>Sent</th></tr></thead>
          <tbody>
          <?php foreach ($webhookDeliveries as $d): ?>
          <tr>
            <td class="td-bold" style="font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($d['event_type']) ?></td>
            <td><span class="badge badge-<?= $d['status']==='success'?'success':($d['status']==='failed'?'danger':'warning') ?>"><?= ucfirst($d['status']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= $d['response_code'] ?: '—' ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= timeAgo($d['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- QUICK START -->
  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Quick Start</div>
    <div class="card-sub" style="margin-bottom:14px">Base URL: <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/api/v1</code> · every request needs an <code>Authorization: Bearer</code> header.</div>
    <div class="aw-code-block">curl https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/api/v1/orders \
  -H "Authorization: Bearer kw_live_••••••••••••••••••••••••"</div>

    <div style="margin-top:18px">
      <div class="card-sub" style="margin-bottom:8px;font-weight:600;color:var(--ink)">Available Endpoints</div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/me</td><td style="font-size:13px">Account info &amp; wallet balance</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/orders</td><td style="font-size:13px">List orders (supports <code>?status=</code>, <code>?page=</code>, <code>?per_page=</code>)</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/orders/{order_ref}</td><td style="font-size:13px">Single order, full detail</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/links</td><td style="font-size:13px">List payment links</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/links/{id_or_slug}</td><td style="font-size:13px">Single payment link</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">POST</td><td style="font-family:var(--font-mono);font-size:12px">/links</td><td style="font-size:13px">Create a payment link (JSON body: <code>title</code>, <code>amount</code>)</td></tr>
            <tr><td class="td-bold" style="font-family:var(--font-mono);font-size:12px">GET</td><td style="font-family:var(--font-mono);font-size:12px">/transactions</td><td style="font-size:13px">List transactions</td></tr>
          </tbody>
        </table>
      </div>
      <div class="form-hint" style="margin-top:8px">Rate limit: 120 requests/minute per key. Creating a payment link respects your plan's link limit, same as the dashboard.</div>
    </div>
  </div>
  
  <?php include __DIR__ . '/includes/integration-docs.php'; ?>

</div>
<?php endif; ?>

<script>
function copyText(text, label) {
  navigator.clipboard.writeText(text).then(() => showToast('success', 'Copied', (label || 'Text') + ' copied to clipboard'));
}
function toggleSecret(fullSecret) {
  const el = document.getElementById('webhook-secret-text');
  const eye = document.getElementById('secret-eye');
  const revealed = el.dataset.revealed === '1';
  el.textContent = revealed ? '••••••••' : fullSecret;
  el.dataset.revealed = revealed ? '0' : '1';
  eye.className = 'ti ' + (revealed ? 'ti-eye' : 'ti-eye-off');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>