<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat_helpers.php';
requireLogin();

$pageTitle  = 'Chats';
$activePage = 'chats';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$threads = chatThreadsForMerchant($db, $uid);

include __DIR__ . '/includes/header.php';
?>

<style>
.ch-header{margin-bottom:20px}
.ch-header h1{font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px}
.ch-header p{font-size:14px;color:var(--muted);margin-top:4px}

.ch-layout{display:grid;grid-template-columns:300px 1fr;gap:0;background:var(--paper);border:1.5px solid var(--line);border-radius:var(--r-lg);overflow:hidden;height:calc(100vh - 220px);min-height:480px}
@media(max-width:820px){.ch-layout{grid-template-columns:1fr;height:auto}
  .ch-threads{max-height:280px}
  .ch-conv{min-height:420px}
}

.ch-threads{border-right:1px solid var(--line);overflow-y:auto;background:var(--surface)}
.ch-thread{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line);cursor:pointer;transition:background .15s}
.ch-thread:hover,.ch-thread.active{background:var(--purple-50)}
.ch-thread-av{width:38px;height:38px;border-radius:50%;background:var(--purple-100);color:var(--purple-600);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
.ch-thread-info{flex:1;min-width:0}
.ch-thread-name{font-size:13.5px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-thread-email{font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.ch-thread-link{font-size:11.5px;color:var(--purple-600);font-weight:600;margin-bottom:2px}
.ch-thread-last{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-thread-badge{min-width:18px;height:18px;border-radius:9px;background:var(--purple-600);color:#fff;font-size:10.5px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0}
.ch-empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);gap:10px;text-align:center;padding:40px}
.ch-empty-state i{font-size:36px;color:var(--muted-2)}

.ch-conv{display:flex;flex-direction:column;background:var(--paper)}
.ch-conv-head{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}
.ch-conv-who{min-width:0}
.ch-conv-name{font-size:14px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-conv-email{font-size:12px;color:var(--purple-600);text-decoration:none;display:inline-flex;align-items:center;gap:4px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-conv-email:hover{text-decoration:underline}
.ch-conv-link{font-size:11.5px;color:var(--muted)}
.ch-conv-body{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:8px;background:var(--surface)}
.ch-msg{max-width:70%;padding:9px 13px;border-radius:var(--r-lg);font-size:13px;line-height:1.5}
.ch-msg.mine{align-self:flex-end;background:var(--purple-600);color:#fff;border-bottom-right-radius:4px}
.ch-msg.theirs{align-self:flex-start;background:var(--paper);border:1px solid var(--line);color:var(--ink);border-bottom-left-radius:4px}
.ch-conv-input{display:flex;gap:8px;padding:14px;border-top:1px solid var(--line)}
.ch-conv-input input{flex:1;font-family:var(--font-body);font-size:13.5px;border:1.5px solid var(--line);border-radius:var(--r-full);padding:10px 16px;outline:none}
.ch-conv-input input:focus{border-color:var(--purple-500)}
</style>

<div class="ch-header">
  <h1>Chats</h1>
  <p>Reply to buyers messaging you from your payment links, live.</p>
</div>

<div class="ch-layout">
  <div class="ch-threads" id="ch-threads">
    <?php if (empty($threads)): ?>
    <div class="ch-empty-state" style="height:auto;padding:32px 16px">
      <i class="ti ti-message-circle-2"></i>
      <div style="font-size:13px">No conversations yet. Once a buyer messages you from a payment page, it'll show up here.</div>
    </div>
    <?php else: foreach ($threads as $t): ?>
    <div class="ch-thread"
         data-link="<?= (int)$t['link_id'] ?>"
         data-token="<?= htmlspecialchars($t['customer_token']) ?>"
         data-name="<?= htmlspecialchars($t['customer_name']) ?>"
         data-email="<?= htmlspecialchars($t['customer_email'] ?? '') ?>"
         data-linktitle="<?= htmlspecialchars($t['link_title']) ?>"
         onclick="openThread(this)">
      <div class="ch-thread-av"><?= htmlspecialchars(strtoupper(mb_substr($t['customer_name'] ?: 'A', 0, 1))) ?></div>
      <div class="ch-thread-info">
        <div class="ch-thread-link"><?= htmlspecialchars($t['link_title']) ?></div>
        <div class="ch-thread-name"><?= htmlspecialchars($t['customer_name']) ?></div>
        <?php if (!empty($t['customer_email'])): ?>
        <div class="ch-thread-email"><?= htmlspecialchars($t['customer_email']) ?></div>
        <?php endif; ?>
        <div class="ch-thread-last"><?= $t['last_sender']==='merchant'?'You: ':'' ?><?= htmlspecialchars(mb_strimwidth($t['last_message'], 0, 40, '…')) ?></div>
      </div>
      <?php if ($t['unread'] > 0): ?><div class="ch-thread-badge"><?= (int)$t['unread'] ?></div><?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="ch-conv" id="ch-conv">
    <div class="ch-empty-state">
      <i class="ti ti-message-circle-2"></i>
      <div>Select a conversation to start replying</div>
    </div>
  </div>
</div>

<script>
const CHATS_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
let activeLink = null, activeToken = null, activeAfterId = 0, pollTimer = null;

// Small helper: builds an element and sets text safely (never as HTML).
// Visitors type their own name and email, so we treat them as plain text.
function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function openThread(row) {
  document.querySelectorAll('.ch-thread').forEach(t => t.classList.remove('active'));
  row.classList.add('active');

  activeLink    = row.dataset.link;
  activeToken   = row.dataset.token;
  activeAfterId = 0;

  const name      = row.dataset.name || 'Anonymous visitor';
  const email     = row.dataset.email || '';
  const linkTitle = row.dataset.linktitle || '';

  const conv = document.getElementById('ch-conv');
  conv.innerHTML = '';

  // Header: who is writing, and how to reach them
  const head = el('div', 'ch-conv-head');
  head.appendChild(el('div', 'ch-thread-av', name.charAt(0).toUpperCase()));

  const who = el('div', 'ch-conv-who');
  who.appendChild(el('div', 'ch-conv-name', name));
  if (email) {
    const mail = el('a', 'ch-conv-email');
    mail.href = 'mailto:' + encodeURIComponent(email).replace('%40', '@');
    mail.appendChild(el('i', 'ti ti-mail'));
    mail.appendChild(document.createTextNode(email));
    who.appendChild(mail);
  }
  if (linkTitle) who.appendChild(el('div', 'ch-conv-link', 'About: ' + linkTitle));
  head.appendChild(who);
  conv.appendChild(head);

  // Messages
  const body = el('div', 'ch-conv-body');
  body.id = 'ch-conv-body';
  conv.appendChild(body);

  // Reply box
  const inputRow = el('div', 'ch-conv-input');
  const input = document.createElement('input');
  input.type = 'text';
  input.id = 'ch-msg-input';
  input.placeholder = 'Type a reply...';
  input.addEventListener('keydown', e => { if (e.key === 'Enter') sendReply(); });
  const sendBtn = el('button', 'btn btn-purple btn-md');
  sendBtn.type = 'button';
  sendBtn.appendChild(el('i', 'ti ti-send'));
  sendBtn.addEventListener('click', sendReply);
  inputRow.appendChild(input);
  inputRow.appendChild(sendBtn);
  conv.appendChild(inputRow);

  loadThreadMessages();
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(loadThreadMessages, 4000);
}

function renderMsg(m) {
  const body = document.getElementById('ch-conv-body');
  if (!body) return;
  const div = el('div', 'ch-msg ' + (m.sender_type === 'merchant' ? 'mine' : 'theirs'), m.message);
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
}

function loadThreadMessages() {
  if (!activeLink) return;
  fetch(`/chat-poll.php?link_id=${activeLink}&after=${activeAfterId}&role=merchant&token=${encodeURIComponent(activeToken)}`)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      data.messages.forEach(m => {
        renderMsg(m);
        activeAfterId = Math.max(activeAfterId, parseInt(m.id));
      });
    })
    .catch(() => {});
}

function sendReply() {
  const input = document.getElementById('ch-msg-input');
  const msg = input.value.trim();
  if (!msg || !activeLink) return;

  const fd = new FormData();
  fd.append('link_id', activeLink);
  fd.append('token', activeToken);
  fd.append('role', 'merchant');
  fd.append('message', msg);
  fd.append('csrf_token', CHATS_CSRF_TOKEN);

  fetch('/chat-send', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        renderMsg({ sender_type: 'merchant', message: msg });
        activeAfterId = Math.max(activeAfterId, parseInt(data.id));
        input.value = '';
      }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>