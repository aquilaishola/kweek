<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
requireLogin();

$user          = currentUser();
$uid           = $user['id'];
$db            = db();
$error         = '';
$withdrawalFee = withdrawalFeeFor($user['plan']);

// ── AJAX: Resolve bank account ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resolve_account') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $accNum  = preg_replace('/\D/', '', post('account_number'));
        $bankCode = clean(post('bank_code'));
        if (strlen($accNum) !== 10 || empty($bankCode)) {
            echo json_encode(['success' => false, 'error' => 'Enter a 10-digit account number and select a bank.']);
            exit;
        }
        $nomba  = new Nomba();
        $result = $nomba->resolveAccount($accNum, $bankCode);
        $name   = $result['data']['accountName']
            ?? $result['data']['account_name']
            ?? $result['accountName']
            ?? null;
        if ($name) {
            echo json_encode(['success' => true, 'name' => $name]);
        } else {
            error_log('Account resolve response: ' . json_encode($result));
            echo json_encode(['success' => false, 'error' => 'Could not verify account. Check details and try again.']);
        }
    } catch (Throwable $e) {
        error_log('resolveAccount error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Verification failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── WITHDRAWAL SUBMIT ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'withdraw') {
    verifyCsrf();

    $amount    = abs((float)post('amount'));
    $bankCode  = clean(post('bank_code'));
    $accNumber = preg_replace('/\D/', '', post('account_number'));
    $accName   = clean(post('account_name'));
    $bankName  = clean(post('bank_name'));

    if (!$user['bvn_verified']) {
        $error = 'Complete BVN verification before withdrawing.';
    } elseif ($amount < 500) {
        $error = 'Minimum withdrawal is ' . formatNaira(500) . '.';
    } elseif ($amount > (float)$user['wallet_balance']) {
        $error = 'Insufficient balance. Your wallet has ' . formatNaira($user['wallet_balance']) . '.';
    } elseif (strlen($accNumber) !== 10) {
        $error = 'Enter a valid 10-digit account number.';
    } elseif (empty($bankCode)) {
        $error = 'Select a bank.';
    } elseif (empty($accName)) {
        $error = 'Verify your account name first before withdrawing.';
    } else {
        $net = $amount - $withdrawalFee;
        $ref = generateRef('WDR');

        $db->beginTransaction();
        try {
            $debitOk = debitWallet(
    $uid,
    $amount,
    "Withdrawal to $bankName $accNumber",
    $ref,
    null,   
    null,   
    false   
);

            if (!$debitOk) throw new RuntimeException('Insufficient balance');

            $db->prepare("INSERT INTO withdrawals (user_id,amount,fee,net_amount,bank_name,bank_code,account_number,account_name,reference,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')")
               ->execute([$uid, $amount, $withdrawalFee, $net, $bankName, $bankCode, $accNumber, $accName, $ref]);

            $withdrawalId = (int)$db->lastInsertId();

            // Save bank details for convenience
            $db->prepare("UPDATE users SET bank_name=?,bank_code=?,account_number=?,account_name=? WHERE id=?")
               ->execute([$bankName, $bankCode, $accNumber, $accName, $uid]);

            $db->commit();

            // Initiate Nomba transfer
            try {
                $nomba  = new Nomba();
                $result = $nomba->transfer([
                    'amount'        => $net,
                    'bankCode'      => $bankCode,
                    'accountNumber' => $accNumber,
                    'accountName'   => $accName,
                    'narration'     => 'Kweek withdrawal',
                    'reference'     => $ref,
                ]);
                $nombaRef  = $result['data']['id'] ?? $result['data']['transactionReference'] ?? null;
                $txnStatus = strtoupper($result['data']['status'] ?? '') === 'SUCCESS' ? 'success' : 'processing';
                $db->prepare("UPDATE withdrawals SET nomba_ref=?,status=?,processed_at=NOW() WHERE id=?")
                   ->execute([$nombaRef, $txnStatus, $withdrawalId]);
            } catch (Throwable $e) {
                error_log("Nomba transfer error: " . $e->getMessage());
            }

            notify($uid, 'withdrawal', 'Withdrawal Initiated', formatNaira($amount) . ' withdrawal to ' . $bankName . ' is being processed.', 'cash', '/transactions.php');
            flash('success', 'Withdrawal of ' . formatNaira($net) . ' initiated. Funds arrive within minutes.');
            redirect('/withdraw.php');

        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Withdrawal failed: ' . $e->getMessage();
        }
    }
}

// ── LOAD BANKS ────────────────────────────────────────────────
$banks = [];
try {
    // session cache
    if (!empty($_SESSION['nomba_banks_cached'])) {
        $banks = $_SESSION['nomba_banks_cached'];
    } else {
        $nomba       = new Nomba();
        $banksResult = $nomba->getBanks();
        $banks       = $banksResult['data'] ?? [];
        if (!empty($banks)) {
            $_SESSION['nomba_banks_cached'] = $banks;
            $_SESSION['nomba_banks_exp']    = time() + 86400;
        }
    }
} catch (Throwable $e) {
    error_log('Failed to load banks: ' . $e->getMessage());
    // fallback
    $banks = [
        ['bankCode'=>'044','bankName'=>'Access Bank'],
        ['bankCode'=>'023','bankName'=>'Citibank'],
        ['bankCode'=>'050','bankName'=>'EcoBank'],
        ['bankCode'=>'011','bankName'=>'First Bank'],
        ['bankCode'=>'214','bankName'=>'First City Monument Bank'],
        ['bankCode'=>'058','bankName'=>'Guaranty Trust Bank'],
        ['bankCode'=>'030','bankName'=>'Heritage Bank'],
        ['bankCode'=>'301','bankName'=>'Jaiz Bank'],
        ['bankCode'=>'082','bankName'=>'Keystone Bank'],
        ['bankCode'=>'526','bankName'=>'Kuda Bank'],
        ['bankCode'=>'076','bankName'=>'Polaris Bank'],
        ['bankCode'=>'039','bankName'=>'Stanbic IBTC'],
        ['bankCode'=>'232','bankName'=>'Sterling Bank'],
        ['bankCode'=>'032','bankName'=>'Union Bank'],
        ['bankCode'=>'033','bankName'=>'United Bank for Africa'],
        ['bankCode'=>'215','bankName'=>'Unity Bank'],
        ['bankCode'=>'035','bankName'=>'Wema Bank'],
        ['bankCode'=>'057','bankName'=>'Zenith Bank'],
        ['bankCode'=>'999992','bankName'=>'OPay'],
        ['bankCode'=>'999991','bankName'=>'PalmPay'],
        ['bankCode'=>'000017','bankName'=>'Moniepoint'],
    ];
}

$normalizedBanks = [];
foreach ($banks as $b) {
    $normalizedBanks[] = [
        'bankCode' => $b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '',
        'bankName' => $b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? '',
    ];
}
usort($normalizedBanks, fn($a,$b) => strcmp($a['bankName'], $b['bankName']));

// Recent withdrawals
$stmt = $db->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$uid]);
$withdrawals = $stmt->fetchAll();

// Refresh user to get latest balance
$uStmt = $db->prepare("SELECT * FROM users WHERE id=?");
$uStmt->execute([$uid]);
$user = $uStmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<style>

/* ── WITHDRAW LAYOUT ────────────────────────────────────────── */
.wd-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start}
@media(max-width:768px){.wd-grid{grid-template-columns:1fr}}

.balance-card{background:var(--p900);border-radius:var(--r-xl);padding:24px;position:relative;overflow:hidden;margin-bottom:20px}
.balance-card::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(100,87,232,.2);pointer-events:none}
.balance-label{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--p300);margin-bottom:8px}
.balance-amount{font-family:var(--font-display);font-size:36px;font-weight:800;color:var(--n0);letter-spacing:-1px;margin-bottom:6px;word-break:break-all}
.balance-note{font-size:12px;color:var(--p400)}

/* Quick amount buttons */
.quick-btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.quick-btn{padding:6px 14px;border-radius:var(--r-full);border:1.5px solid var(--n200);background:var(--n0);font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--n700);cursor:pointer;transition:all .2s}
.quick-btn:hover{border-color:var(--p400);color:var(--p600);background:var(--p50)}

/* Verified account name */
.acc-verified{display:flex;align-items:center;gap:8px;background:var(--success-bg);border:1px solid #BBF7D0;border-radius:var(--r-lg);padding:12px 16px;margin-bottom:16px}
.acc-verified i{font-size:18px;color:var(--success);flex-shrink:0}
.acc-verified-name{font-size:14px;font-weight:700;color:var(--success)}

/* Net display */
.net-display{background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--n600)}
.net-display strong{color:var(--n900);font-size:15px}
</style>

<h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px;margin-bottom:4px">Withdraw Funds</h1>
<p style="font-size:14px;color:var(--n500);margin-bottom:20px">Transfer your earnings to any Nigerian bank account instantly.</p>

<!-- BALANCE CARD -->
<div class="balance-card">
  <div class="balance-label">Available Balance</div>
  <div class="balance-amount"><?= formatNaira($user['wallet_balance']) ?></div>
  <div class="balance-note">Withdrawal fee: <?= formatNaira($withdrawalFee) ?> per transaction &nbsp;·&nbsp; Minimum: <?= formatNaira(500) ?></div>
</div>

<?php if (!$user['bvn_verified']): ?>
<div style="background:var(--warning-bg);border:1px solid #FDE68A;border-radius:var(--r-lg);padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px">
  <i class="ti ti-alert-triangle" style="font-size:20px;color:var(--warning);flex-shrink:0;margin-top:1px"></i>
  <div>
    <div style="font-size:14px;font-weight:700;color:var(--n900);margin-bottom:2px">BVN Verification Required</div>
    <div style="font-size:13px;color:var(--n600)">You must verify your BVN before withdrawing funds. <a href="/kyc.php" style="color:var(--p600);font-weight:700">Verify now →</a></div>
  </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div style="background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:13px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--danger)">
  <i class="ti ti-alert-circle" style="flex-shrink:0;font-size:16px;margin-top:1px"></i>
  <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<div class="wd-grid">

  <!-- LEFT: FORM -->
  <div class="card">
    <div class="card-title" style="margin-bottom:20px">Withdrawal Details</div>
    <form method="POST" id="wd-form">
      <?= csrfField() ?>
      <input type="hidden" name="action"       value="withdraw">
      <input type="hidden" name="account_name" id="hidden-acc-name" value="<?= htmlspecialchars($user['account_name'] ?? '') ?>">
      <input type="hidden" name="bank_name"    id="hidden-bank-name" value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>">

     <?php
$canWithdraw = (float)$user['wallet_balance'] >= 500;
$maxWithdraw = floor($user['wallet_balance']);
?>

<!-- AMOUNT -->
<div class="form-group">
  <label class="form-label">Amount (₦)</label>
  <div class="form-input-wrap">
    <i class="ti ti-currency-naira inp-icon"></i>
    <input type="number" name="amount" id="wd-amount" class="form-input"
      placeholder="Enter amount" min="500"
      <?= $canWithdraw ? 'max="' . $maxWithdraw . '"' : '' ?>
      step="1" required oninput="refreshNet(this.value)"
      <?= $canWithdraw ? '' : 'disabled' ?>>
  </div>
</div>

<?php if (!$canWithdraw): ?>
<div style="background:var(--warning-bg);border:1px solid #FDE68A;border-radius:var(--r-lg);padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--n700)">
  <i class="ti ti-alert-triangle" style="color:var(--warning);margin-right:6px"></i>
  You need at least <?= formatNaira(500) ?> in your wallet to withdraw. Your current balance is <?= formatNaira($user['wallet_balance']) ?>.
</div>
<?php endif; ?>

      <!-- QUICK AMOUNTS -->
      <div class="quick-btns">
        <?php foreach ([1000,5000,10000,20000,50000] as $q): ?>
        <?php if ($q <= $user['wallet_balance']): ?>
        <button type="button" class="quick-btn" onclick="setAmt(<?= $q ?>)">₦<?= number_format($q) ?></button>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($user['wallet_balance'] >= 500): ?>
        <button type="button" class="quick-btn" onclick="setAmt(<?= floor($user['wallet_balance']) ?>)">Max</button>
        <?php endif; ?>
      </div>

      <!-- NET DISPLAY -->
      <div class="net-display" id="net-display" style="display:none">
        You receive: <strong id="net-amount">₦0.00</strong>
        <span style="color:var(--n400);margin-left:6px">(after <?= formatNaira($withdrawalFee) ?> fee)</span>
      </div>

      <!-- BANK -->
      <div class="form-group">
        <label class="form-label">Bank</label>
        <select name="bank_code" id="bank-select" class="form-select" onchange="onBankChange(this)" required>
          <option value="">— Select bank —</option>
          <?php foreach ($normalizedBanks as $bank): ?>
          <option value="<?= htmlspecialchars($bank['bankCode']) ?>"
                  data-name="<?= htmlspecialchars($bank['bankName']) ?>"
                  <?= ($user['bank_code'] ?? '') === $bank['bankCode'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($bank['bankName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- ACCOUNT NUMBER -->
      <div class="form-group">
        <label class="form-label">Account Number</label>
        <div style="display:flex;gap:8px">
          <input type="text" name="account_number" id="acc-number" class="form-input"
            placeholder="10-digit account number" maxlength="10" inputmode="numeric"
            value="<?= htmlspecialchars($user['account_number'] ?? '') ?>"
            oninput="if(this.value.replace(/\D/g,'').length===10) autoVerify()" style="flex:1">
          <button type="button" id="verify-btn" onclick="verifyAccount()" class="btn btn-outline btn-md">
            <i class="ti ti-search"></i> Verify
          </button>
        </div>
      </div>

      <!-- VERIFIED NAME -->
      <div class="acc-verified" id="acc-verified-box" style="display:<?= $user['account_name'] ? 'flex' : 'none' ?>">
        <i class="ti ti-circle-check"></i>
        <span class="acc-verified-name" id="acc-verified-name"><?= htmlspecialchars($user['account_name'] ?? '') ?></span>
      </div>

<button type="submit" class="btn btn-purple btn-lg" id="wd-btn"
  style="width:100%;justify-content:center;margin-top:4px"
  <?= (!$user['bvn_verified'] || !$canWithdraw) ? 'disabled' : '' ?>>
        <i class="ti ti-cash"></i>
        <span id="wd-btn-text">Withdraw Now</span>
      </button>
    </form>
  </div>

<!-- RIGHT: HISTORY -->
  <div class="table-wrap">
    <div class="table-head">
      <span class="table-title">
        <?= count($withdrawals) ?> Withdrawal<?= count($withdrawals) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <?php if (empty($withdrawals)): ?>
      <div class="empty-state">
        <div class="empty-icon">
          <i class="ti ti-history"></i>
        </div>
        <div class="empty-title">No withdrawals yet</div>
        <div class="empty-sub">
          Your withdrawal history will appear here.
        </div>
      </div>

    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Reference</th>
            <th>Amount</th>
            <th>Fee</th>
            <th>Net</th>
            <th>Bank</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>

        <tbody>
        <?php foreach ($withdrawals as $w):

          $statusColor = [
            'success'    => 'success',
            'failed'     => 'danger',
            'pending'    => 'warning',
            'processing' => 'purple'
          ][$w['status']] ?? 'neutral';

        ?>

        <tr>

          <td>
            <span style="font-family:monospace;font-size:11px;color:var(--n500)">
              <?= htmlspecialchars(substr($w['reference'], 0, 18)) ?>...
            </span>
          </td>

          <td class="td-bold">
            <?= formatNaira($w['amount']) ?>
          </td>

          <td style="color:var(--danger);font-size:13px">
            <?= formatNaira($w['fee']) ?>
          </td>

          <td style="font-weight:700;color:var(--success)">
            <?= formatNaira($w['net_amount']) ?>
          </td>

          <td>
            <div style="font-size:13px;font-weight:600;color:var(--n800)">
              <?= htmlspecialchars($w['bank_name']) ?>
            </div>

            <div style="font-size:11px;color:var(--n500)">
              <?= htmlspecialchars($w['account_number']) ?>
            </div>

            <div style="font-size:11px;color:var(--n400)">
              <?= htmlspecialchars($w['account_name']) ?>
            </div>
          </td>

          <td>
            <span class="badge badge-<?= $statusColor ?>">
              <?= ucfirst($w['status']) ?>
            </span>
          </td>

          <td style="font-size:12px;color:var(--n500);white-space:nowrap">
            <?= nigeriaTime($w['created_at']) ?>
          </td>

        </tr>

        <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>

</div>

<script>
const CSRF = '<?= csrfToken() ?>';
const FEE  = <?= $withdrawalFee ?>;

function fmt(n) {
  return '₦' + parseFloat(n||0).toLocaleString('en-NG', {minimumFractionDigits:2});
}

function setAmt(v) {
  document.getElementById('wd-amount').value = v;
  refreshNet(v);
}

function refreshNet(val) {
  const amt    = parseFloat(val||0);
  const net    = Math.max(0, amt - FEE);
  const display = document.getElementById('net-display');
  const netEl   = document.getElementById('net-amount');
  const btnText = document.getElementById('wd-btn-text');
  if (amt >= 500) {
    display.style.display = 'block';
    netEl.textContent = fmt(net);
    btnText.textContent = 'Withdraw ' + fmt(amt);
  } else {
    display.style.display = 'none';
    btnText.textContent = 'Withdraw Now';
  }
}

function onBankChange(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('hidden-bank-name').value = opt.dataset.name || '';
  // Clear verified name when bank changes
  document.getElementById('hidden-acc-name').value = '';
  document.getElementById('acc-verified-box').style.display = 'none';
}

let autoVerifyTimer;
function autoVerify() {
  clearTimeout(autoVerifyTimer);
  autoVerifyTimer = setTimeout(verifyAccount, 600);
}

async function verifyAccount() {
  const accNum  = document.getElementById('acc-number').value.replace(/\D/g,'');
  const bankSel = document.getElementById('bank-select');
  const bankCode = bankSel.value;

  if (accNum.length !== 10 || !bankCode) {
    if (!bankCode) showToast('info','Select bank','Please select a bank first.');
    return;
  }

  const btn = document.getElementById('verify-btn');
  btn.innerHTML = '<div style="width:14px;height:14px;border-radius:50%;border:2px solid var(--n300);border-top-color:var(--p600);animation:spin .7s linear infinite;display:inline-block"></div>';
  btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action','resolve_account');
    fd.append('account_number', accNum);
    fd.append('bank_code', bankCode);
    fd.append('csrf_token', CSRF);

    const res  = await fetch('/withdraw', {method:'POST', body:fd});
    const data = await res.json();

    if (data.success && data.name) {
      document.getElementById('hidden-acc-name').value = data.name;
      document.getElementById('acc-verified-name').textContent = data.name;
      document.getElementById('acc-verified-box').style.display = 'flex';
      showToast('success','Account verified', data.name);
    } else {
      document.getElementById('hidden-acc-name').value = '';
      document.getElementById('acc-verified-box').style.display = 'none';
      showToast('error','Not verified', data.error || 'Could not verify account.');
    }
  } catch(e) {
    showToast('error','Error','Network error. Try again.');
  }

  btn.innerHTML = '<i class="ti ti-search"></i> Verify';
  btn.disabled = false;
}

document.getElementById('wd-form').addEventListener('submit', function(e) {
  const name = document.getElementById('hidden-acc-name').value.trim();
  if (!name) {
    e.preventDefault();
    showToast('error','Verify account','Please verify your account name before withdrawing.');
    return;
  }
  const btn = document.getElementById('wd-btn');
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Processing...';
  btn.disabled = true;
});

window.scrollTo({
    top: Math.max(0, window.scrollY - 200),
    behavior: 'smooth'
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>