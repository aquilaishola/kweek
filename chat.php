<?php
require_once __DIR__ . '/includes/functions.php';

$db  = db();
$ref = clean(get('ref'));
$order = null;

if ($ref) {
    $stmt = $db->prepare("
        SELECT o.id, o.order_ref, o.customer_name, o.status, u.name AS merchant_name, u.id AS merchant_id
        FROM orders o JOIN users u ON o.user_id = u.id
        WHERE o.order_ref = ? LIMIT 1
    ");
    $stmt->execute([$ref]);
    $order = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat with <?= $order ? htmlspecialchars($order['merchant_name']) : 'Merchant' ?> — Kweek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --purple:#4F43D4;--purple-50:#F2F0FC;--purple-100:#E5E1F9;
  --ink:#15131F;--gray-700:#3F3C4A;--gray-500:#6B6879;--gray-300:#D6D3DE;--gray-100:#F0EFF5;
  --white:#fff;--font:'Space Grotesk',sans-serif;
}
body{font-family:var(--font);background:var(--gray-100);min-height:100vh;display:flex;flex-direction:column;color:var(--ink)}

.chat-header{background:var(--white);border-bottom:1px solid var(--gray-100);padding:14px 18px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:5}
.chat-av{width:38px;height:38px;border-radius:50%;background:var(--purple-100);color:var(--purple);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
.chat-header-name{font-size:14.5px;font-weight:700}
.chat-header-sub{font-size:12px;color:var(--gray-500)}

.chat-body{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px;max-width:560px;width:100%;margin:0 auto}
.msg-row{display:flex}
.msg-row.customer{justify-content:flex-end}
.msg-bubble{max-width:78%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;word-break:break-word}
.msg-row.merchant .msg-bubble{background:var(--white);border:1px solid var(--gray-100);border-bottom-left-radius:4px}
.msg-row.customer .msg-bubble{background:var(--purple);color:#fff;border-bottom-right-radius:4px}
.msg-time{font-size:10.5px;color:var(--gray-500);margin-top:3px;text-align:right}
.msg-row.merchant .msg-time{text-align:left}

.chat-empty{text-align:center;color:var(--gray-500);font-size:13px;padding:40px 20px}

.chat-input-bar{background:var(--white);border-top:1px solid var(--gray-100);padding:12px 14px;display:flex;gap:8px;max-width:560px;width:100%;margin:0 auto;position:sticky;bottom:0}
.chat-input-bar textarea{flex:1;resize:none;border:1.5px solid var(--gray-300);border-radius:12px;padding:10px 14px;font-family:var(--font);font-size:14px;outline:none;max-height:100px}
.chat-input-bar textarea:focus{border-color:var(--purple)}
.chat-send-btn{background:var(--purple);color:#fff;border:none;border-radius:50%;width:42px;height:42px;flex-shrink:0;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px}
.chat-send-btn:hover{background:#3D33B8}
.chat-send-btn:disabled{opacity:.5;cursor:not-allowed}

.error-box{max-width:420px;margin:80px auto;text-align:center;padding:24px}
.error-box i{font-size:40px;color:var(--gray-300);margin-bottom:14px;display:block}
.error-box h2{font-size:18px;margin-bottom:8px}
.error-box p{font-size:13.5px;color:var(--gray-500)}
</style>
</head>
<body>

<?php if (!$order): ?>
  <div class="error-box">
    <i class="ti ti-message-off"></i>
    <h2>Chat not found</h2>
    <p>This chat link isn't valid. Check the link your merchant sent you, or contact them directly.</p>
  </div>
<?php else: ?>

  <div class="chat-header">
    <div class="chat-av"><?= strtoupper(substr($order['merchant_name'], 0, 2)) ?></div>
    <div>
      <div class="chat-header-name"><?= htmlspecialchars($order['merchant_name']) ?></div>
      <div class="chat-header-sub">Order <?= htmlspecialchars($order['order_ref']) ?></div>
    </div>
  </div>

  <div class="chat-body" id="chat-body">
    <div class="chat-empty" id="chat-empty">Send a message to start the conversation.</div>
  </div>

  <div class="chat-input-bar">
    <textarea id="chat-input" placeholder="Type a message…" rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage();}"></textarea>
    <button class="chat-send-btn" id="chat-send-btn" onclick="sendMessage()"><i class="ti ti-send"></i></button>
  </div>

  <script>
    const ORDER_REF = <?= json_encode($order['order_ref']) ?>;
    let lastId = 0;
    let polling = false;

    function fmtTime(iso) {
      const d = new Date(iso.replace(' ', 'T'));
      return d.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' });
    }

    function renderMessage(m) {
      const empty = document.getElementById('chat-empty');
      if (empty) empty.remove();
      const body = document.getElementById('chat-body');
      const row = document.createElement('div');
      row.className = 'msg-row ' + (m.sender_type === 'customer' ? 'customer' : 'merchant');
      row.innerHTML = `<div><div class="msg-bubble"></div><div class="msg-time"></div></div>`;
      row.querySelector('.msg-bubble').textContent = m.message;
      row.querySelector('.msg-time').textContent = fmtTime(m.created_at);
      body.appendChild(row);
      lastId = Math.max(lastId, parseInt(m.id));
      body.scrollTop = body.scrollHeight;
    }

    async function poll() {
      if (polling) return;
      polling = true;
      try {
        const res = await fetch(`/order-chat.php?mode=customer&order_ref=${encodeURIComponent(ORDER_REF)}&after_id=${lastId}`);
        const data = await res.json();
        if (data.success) data.messages.forEach(renderMessage);
      } catch (e) { /* silent — will retry next tick */ }
      polling = false;
    }

    async function sendMessage() {
      const input = document.getElementById('chat-input');
      const btn = document.getElementById('chat-send-btn');
      const text = input.value.trim();
      if (!text) return;
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('mode', 'customer');
        fd.append('action', 'send');
        fd.append('order_ref', ORDER_REF);
        fd.append('message', text);
        const res = await fetch('/order-chat.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          input.value = '';
          await poll();
        } else {
          alert(data.error || 'Could not send message.');
        }
      } catch (e) {
        alert('Network error — try again.');
      }
      btn.disabled = false;
      input.focus();
    }

    poll();
    setInterval(poll, 4000);
  </script>

<?php endif; ?>
</body>
</html>
