<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
require_once __DIR__ . '/includes/mailer.php';
requireLogin();

$pageTitle  = 'Withdraw';
$activePage = 'withdraw';
$user          = currentUser();
$uid           = $user['id'];
$db            = db();
$error         = '';
$withdrawalFee = withdrawalFeeFor($user['plan']);

// ── AJAX: Resolve bank account ────────────────────────────────
// Kept here (rather than moved into kyc.php) because kyc.php's "Verify"
// button calls this exact endpoint to resolve an account name while
// linking a payout account. It's read-only — it never touches wallet
// balance or initiates a transfer — so it's safe to keep even though
// withdraw.php's own form no longer takes manual bank entry.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resolve_account') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $accNum   = preg_replace('/\D/', '', post('account_number'));
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

// A withdrawal can only ever go to the payout account on file. That data
// can live in one of two places depending on how it was saved:
//   - payout_bank_code / payout_account_number / payout_account_name /
//     payout_bank_name — the newer columns, populated by kyc.php and
//     marked trustworthy via payout_verified once an admin approves.
//   - bank_code / account_number / account_name / bank_name — the
//     original columns. Accounts opened before the payout_* columns
//     existed have their data here instead (saved by the old withdraw
//     flow after a successful transfer), and payout_verified was never
//     set for them since that flag didn't exist yet.
// Resolve payout_* first, falling back to the legacy columns so accounts
// like these aren't treated as "no account on file". A legacy-only
// account counts as verified once identity is (it was already approved
// under the old model, before payout_verified was introduced).
function resolveUserPayoutAccount(array $user): array {
    $bankCode    = $user['payout_bank_code']      ?: ($user['bank_code'] ?? null);
    $bankName    = $user['payout_bank_name']      ?: ($user['bank_name'] ?? null);
    $accountNum  = $user['payout_account_number'] ?: ($user['account_number'] ?? null);
    $accountName = $user['payout_account_name']   ?: ($user['account_name'] ?? null);

    $hasAccount   = !empty($bankCode) && !empty($accountNum) && !empty($accountName);
    $isLegacyOnly = empty($user['payout_bank_code']) && !empty($user['bank_code']);
    $isVerified   = $hasAccount && (!empty($user['payout_verified']) || ($isLegacyOnly && !empty($user['bvn_verified'])));

    return [
        'bank_code'      => $bankCode,
        'bank_name'      => $bankName,
        'account_number' => $accountNum,
        'account_name'   => $accountName,
        'has_account'    => $hasAccount,
        'is_verified'    => $isVerified,
    ];
}

// Put a withdrawal's money back in the wallet after Bachs definitively
// refused the payout (nothing was sent). The refund goes through
// creditWallet() so it shows up in the user's transactions like any other
// credit. Safe to call twice: the status flip only matches while the
// withdrawal is still open and has no Bachs payout id, so a repeat can
// never refund twice. (creditWallet's last argument is false because we
// already own the transaction here, same as finalizeOrderPayment.)
function refundFailedWithdrawal(PDO $db, int $withdrawalId, int $uid, float $amount, string $reference): bool {
    $db->beginTransaction();
    try {
        $mark = $db->prepare("UPDATE withdrawals SET status='failed', processed_at=NOW() WHERE id=? AND status IN ('pending','processing') AND nomba_ref IS NULL");
        $mark->execute([$withdrawalId]);
        if ($mark->rowCount() !== 1) {
            $db->rollBack();
            return false;
        }

        $credited = creditWallet(
            $uid,
            $amount,
            0,
            "Refund for failed withdrawal $reference",
            generateRef('RFD'),
            null,
            $reference,
            false
        );
        if ($credited === false) {
            throw new RuntimeException('creditWallet refused the refund');
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('refundFailedWithdrawal error: ' . $e->getMessage());
        return false;
    }
}

$payoutAccount     = resolveUserPayoutAccount($user);
$hasVerifiedPayout = $payoutAccount['is_verified'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'withdraw') {
    verifyCsrf();

    $amount = abs((float)post('amount'));

    // Always pull the destination from the resolved payout record — never
    // from the request — so a tampered POST body can't redirect funds to
    // an arbitrary account.
    $bankCode  = $payoutAccount['bank_code'] ?? '';
    $accNumber = $payoutAccount['account_number'] ?? '';
    $accName   = $payoutAccount['account_name'] ?? '';
    $bankName  = $payoutAccount['bank_name'] ?? '';

    if (!empty($user['payout_locked'])) {
        $error = $user['payout_locked_reason'] ?: 'Payouts are locked on this account. Contact support.';
    } elseif (empty($user['bvn_verified'])) {
        $error = 'Complete identity verification before withdrawing.';
    } elseif (!$hasVerifiedPayout) {
        $error = 'Add and verify a payout account in Settings before withdrawing.';
    } elseif ($amount < 500) {
        $error = 'Minimum withdrawal is ' . formatNaira(500) . '.';
    } elseif ($amount > (float)$user['wallet_balance']) {
        $error = 'Insufficient balance. Your wallet has ' . formatNaira($user['wallet_balance']) . '.';
    }

    if (empty($error)) {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['wd_otp']     = hash('sha256', $otp);
        $_SESSION['wd_otp_exp'] = time() + 300; // 5 minutes
        $_SESSION['wd_pending'] = [
            'amount'         => $amount,
            'bank_code'      => $bankCode,
            'account_number' => $accNumber,
            'account_name'   => $accName,
            'bank_name'      => $bankName,
        ];

        if (function_exists('sendWithdrawalOtpEmail')) {
            sendWithdrawalOtpEmail($user, $otp, $amount);
        } else {
            error_log('sendWithdrawalOtpEmail() is missing from mailer.php — withdrawal code was not emailed. See mailer_withdrawal_otp_addition.php');
        }

        $_SESSION['wd_demo_otp'] = $otp;
    }
}

// ── WITHDRAWAL STEP 2: verify the code, then actually transfer ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'confirm_withdrawal_otp') {
    verifyCsrf();

    if (empty($_SESSION['wd_otp']) || empty($_SESSION['wd_pending'])) {
        $error = 'Session expired. Please start the withdrawal again.';
    } elseif (time() > ($_SESSION['wd_otp_exp'] ?? 0)) {
        $error = 'Code expired. Please start the withdrawal again.';
        unset($_SESSION['wd_otp'], $_SESSION['wd_pending'], $_SESSION['wd_otp_exp'], $_SESSION['wd_demo_otp']);
    } else {
        $entered = preg_replace('/\D/', '', post('otp'));
        if (!hash_equals($_SESSION['wd_otp'], hash('sha256', $entered))) {
            $error = 'Incorrect code. Please try again.';
        } else {
            $pending   = $_SESSION['wd_pending'];
            $amount    = $pending['amount'];
            $bankCode  = $pending['bank_code'];
            $accNumber = $pending['account_number'];
            $accName   = $pending['account_name'];
            $bankName  = $pending['bank_name'];

            // Re-check everything in case it changed since step 1 — balance,
            // lock status, and that the payout account on file still matches
            // what step 1 captured (it shouldn't have changed mid-flow, but
            // if it did — e.g. a payout-change request came in — refuse
            // rather than silently send to a stale destination).
            $freshStmt = $db->prepare("SELECT wallet_balance, payout_locked, payout_locked_reason, bvn_verified, payout_verified, payout_bank_code, payout_bank_name, payout_account_number, payout_account_name, bank_code, bank_name, account_number, account_name FROM users WHERE id = ?");
            $freshStmt->execute([$uid]);
            $fresh = $freshStmt->fetch();
            $freshPayout = $fresh ? resolveUserPayoutAccount($fresh) : null;

            if (!$fresh) {
                $error = 'Something went wrong. Please try again.';
            } elseif (!empty($fresh['payout_locked'])) {
                $error = $fresh['payout_locked_reason'] ?: 'Payouts are locked on this account. Contact support.';
            } elseif (!$freshPayout['is_verified']
                || $freshPayout['bank_code'] !== $bankCode
                || $freshPayout['account_number'] !== $accNumber) {
                $error = 'Your payout account changed. Please start the withdrawal again.';
                unset($_SESSION['wd_otp'], $_SESSION['wd_pending'], $_SESSION['wd_otp_exp'], $_SESSION['wd_demo_otp']);
            } elseif ($amount > (float)$fresh['wallet_balance']) {
                $error = 'Insufficient balance. Your wallet has ' . formatNaira($fresh['wallet_balance']) . '.';
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

                    $db->commit();

                    // Send the money. The wallet is already debited, so what we
                    // do next depends on exactly what Bachs told us:
                    //   accepted  -> keep it as 'processing' until the webhook says paid/failed
                    //   rejected  -> nothing was sent, so give the money back
                    //   uncertain -> it may have been sent, so leave it 'processing' for review
                    $sendFailed = false;
                    try {
                        $nomba  = new Nomba();
                        $result = $nomba->transfer([
                            'amount'        => $net,
                            'bankCode'      => $bankCode,
                            'accountNumber' => $accNumber,
                            'reference'     => $ref,   // also the Idempotency-Key
                            'userId'        => $uid,   // lets us reuse the saved Bachs destination
                        ]);
                        $nombaRef  = $result['data']['id'] ?? null;
                        $txnStatus = strtoupper($result['data']['status'] ?? '') === 'COMPLETED' ? 'success' : 'processing';
                        // The payout webhook can beat us here (sandbox payouts can finish
                        // instantly). Only move the status forward from 'pending', so a
                        // 'success' or 'failed' the webhook already set is never overwritten.
                        $db->prepare("UPDATE withdrawals SET nomba_ref=COALESCE(nomba_ref, ?), status=IF(status='pending', ?, status), processed_at=NOW() WHERE id=?")
                           ->execute([$nombaRef, $txnStatus, $withdrawalId]);
                    } catch (BachsPayoutRejected $e) {
                        error_log("Withdrawal $ref rejected by Bachs [{$e->errorCode}]: " . $e->getMessage());
                        if (refundFailedWithdrawal($db, $withdrawalId, $uid, (float)$amount, $ref)) {
                            $sendFailed = true;
                            $error = 'We couldn\'t send this withdrawal right now, and your wallet has not been charged. Please try again shortly or contact support.';
                        } else {
                            // Refund didn't apply cleanly. Leave it flagged for manual review.
                            error_log("Withdrawal $ref: refund after rejection did NOT apply, needs manual review.");
                        }
                    } catch (Throwable $e) {
                        // Includes BachsPayoutUncertain. Do NOT refund: the payout may exist.
                        error_log("Withdrawal $ref: payout outcome unknown, left as processing for review: " . $e->getMessage());
                    }

                    unset($_SESSION['wd_otp'], $_SESSION['wd_pending'], $_SESSION['wd_otp_exp'], $_SESSION['wd_demo_otp']);

                    if (!$sendFailed) {
                        notify($uid, 'withdrawal', 'Withdrawal Initiated', formatNaira($amount) . ' withdrawal to ' . $bankName . ' is being processed.', 'cash', '/transactions.php');
                        flash('success', 'Withdrawal of ' . formatNaira($net) . ' initiated. Funds arrive within minutes.');
                        redirect('/withdraw.php');
                    }

                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Withdrawal failed: ' . $e->getMessage();
                }
            }
        }
    }
}

// ── RESEND / CANCEL CODE ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend_withdrawal_otp') {
    verifyCsrf();
    if (!empty($_SESSION['wd_pending'])) {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['wd_otp']     = hash('sha256', $otp);
        $_SESSION['wd_otp_exp'] = time() + 300;
        if (function_exists('sendWithdrawalOtpEmail')) {
            sendWithdrawalOtpEmail($user, $otp, $_SESSION['wd_pending']['amount']);
        }
        $_SESSION['wd_demo_otp'] = $otp;
        flash('info', 'A new code has been sent to your email.');
    }
    redirect('/withdraw.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'cancel_withdrawal_otp') {
    unset($_SESSION['wd_otp'], $_SESSION['wd_pending'], $_SESSION['wd_otp_exp'], $_SESSION['wd_demo_otp']);
    redirect('/withdraw.php');
}

$awaitingWdOtp = !empty($_SESSION['wd_otp']) && !empty($_SESSION['wd_pending']);

// Recent withdrawals
$stmt = $db->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$uid]);
$withdrawals = $stmt->fetchAll();

// Refresh user to get latest balance / payout status
$uStmt = $db->prepare("SELECT * FROM users WHERE id=?");
$uStmt->execute([$uid]);
$user = $uStmt->fetch();
$payoutAccount     = resolveUserPayoutAccount($user);
$hasVerifiedPayout = $payoutAccount['is_verified'];

include __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:20px">
  <h1 style="font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px">Withdraw Funds</h1>
  <p style="font-size:14px;color:var(--muted);margin-top:4px">Transfer your earnings to your verified payout account.</p>
</div>

<div class="balance-card">
  <div class="balance-label">Available Balance</div>
  <div class="balance-amount"><?= formatNaira($user['wallet_balance']) ?></div>
  <div class="balance-note">Withdrawal fee: <?= formatNaira($withdrawalFee) ?> per transaction &nbsp;·&nbsp; Minimum: <?= formatNaira(500) ?></div>
</div>

<?php if (!$user['bvn_verified']): ?>
<div class="notice-box warning">
  <i class="ti ti-alert-triangle"></i>
  <div>
    <div class="notice-title">Identity Verification Required</div>
    <div class="notice-sub">You must verify your identity before withdrawing funds. <a href="/kyc.php">Verify now →</a></div>
  </div>
</div>
<?php elseif (!empty($user['payout_locked'])): ?>
<div class="notice-box danger">
  <i class="ti ti-lock"></i>
  <div>
    <div class="notice-title">Payouts Locked</div>
    <div class="notice-sub"><?= htmlspecialchars($user['payout_locked_reason'] ?: 'Contact support to have your account reviewed.') ?></div>
  </div>
</div>
<?php elseif (!$hasVerifiedPayout && !$payoutAccount['has_account']): ?>
<div class="notice-box warning">
  <i class="ti ti-building-bank"></i>
  <div>
    <div class="notice-title">No Payout Account Linked</div>
    <div class="notice-sub">Add and verify a payout account before withdrawing. <a href="/settings.php?tab=payout">Link account →</a></div>
  </div>
</div>
<?php elseif (!$hasVerifiedPayout): ?>
<div class="notice-box warning">
  <i class="ti ti-clock"></i>
  <div>
    <div class="notice-title">Payout Account Pending Review</div>
    <div class="notice-sub">Your submitted account (<?= htmlspecialchars($payoutAccount['bank_name']) ?> · <?= htmlspecialchars($payoutAccount['account_number']) ?>) is awaiting approval before you can withdraw.</div>
  </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="notice-box danger">
  <i class="ti ti-alert-circle"></i>
  <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<div class="wd-grid">

  <!-- LEFT: FORM, or the OTP confirmation step -->
  <?php if ($awaitingWdOtp): $pending = $_SESSION['wd_pending']; ?>
  <div class="card">
    <div style="text-align:center;margin-bottom:24px">
      <div class="wd-otp-icon"><i class="ti ti-mail-check"></i></div>
      <div class="card-title">Confirm Withdrawal</div>
      <div class="card-sub">We've emailed a 6-digit code to <?= htmlspecialchars(maskEmail($user['email'])) ?> to confirm this withdrawal of <strong style="color:var(--ink)"><?= formatNaira($pending['amount']) ?></strong> to <?= htmlspecialchars($pending['bank_name']) ?> (<?= htmlspecialchars($pending['account_name']) ?>).</div>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="confirm_withdrawal_otp">
      <div class="form-group">
        <label class="form-label">6-digit code</label>
        <input type="text" name="otp" class="form-input" placeholder="000000" maxlength="6"
          style="font-size:24px;font-weight:700;letter-spacing:8px;text-align:center;font-family:var(--font-display)"
          inputmode="numeric" pattern="[0-9]{6}" autofocus required>
      </div>
      <button type="submit" class="btn btn-purple btn-lg" style="width:100%;justify-content:center">
        <i class="ti ti-check"></i> Confirm Withdrawal
      </button>
    </form>
    <div style="display:flex;justify-content:center;gap:20px;margin-top:16px">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="resend_withdrawal_otp">
        <button type="submit" class="wd-link-btn"><i class="ti ti-refresh"></i> Resend code</button>
      </form>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="cancel_withdrawal_otp">
        <button type="submit" class="wd-link-btn" style="color:var(--danger)"><i class="ti ti-x"></i> Cancel</button>
      </form>
    </div>
  </div>

  <?php else: ?>
  <div class="card">
    <div class="card-title" style="margin-bottom:20px">Withdrawal Details</div>

    <!-- Destination is always the verified payout account — read-only here.
         Changing it happens only in Settings → Payout Details, which forces
         a fresh identity/email verification before it takes effect. -->
    <div class="form-group">
      <label class="form-label">Payout Account</label>
      <?php if ($hasVerifiedPayout): ?>
      <div class="payout-account-box">
        <div class="payout-account-label"><i class="ti ti-shield-check"></i> Verified Payout Account</div>
        <div class="payout-account-name"><?= htmlspecialchars($payoutAccount['account_name']) ?></div>
        <div class="payout-account-sub"><?= htmlspecialchars($payoutAccount['bank_name']) ?> · <?= htmlspecialchars($payoutAccount['account_number']) ?></div>
      </div>
      <div class="form-hint">Withdrawals can only go to this account. <a href="/settings.php?tab=payout">Change it in Settings →</a></div>
      <?php else: ?>
      <?php if ($payoutAccount['has_account']): ?>
      <div class="payout-account-box" style="opacity:.75">
        <div class="payout-account-label"><i class="ti ti-clock"></i> Pending Review</div>
        <div class="payout-account-name"><?= htmlspecialchars($payoutAccount['account_name']) ?></div>
        <div class="payout-account-sub"><?= htmlspecialchars($payoutAccount['bank_name']) ?> · <?= htmlspecialchars($payoutAccount['account_number']) ?></div>
      </div>
      <div class="form-hint">This account is awaiting approval — withdrawals unlock once it's approved.</div>
      <?php else: ?>
      <div class="notice-box warning" style="margin-bottom:0">
        <i class="ti ti-building-bank"></i>
        <span>No verified payout account yet. <a href="/settings.php?tab=payout">Add one in Settings →</a></span>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <form method="POST" id="wd-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="withdraw">

      <?php
      $canWithdraw = $hasVerifiedPayout && $user['bvn_verified'] && empty($user['payout_locked']) && (float)$user['wallet_balance'] >= 500;
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

      <?php if ($hasVerifiedPayout && (float)$user['wallet_balance'] < 500): ?>
      <div class="notice-box warning" style="margin-bottom:16px">
        <i class="ti ti-alert-triangle"></i>
        <span>You need at least <?= formatNaira(500) ?> in your wallet to withdraw. Your current balance is <?= formatNaira($user['wallet_balance']) ?>.</span>
      </div>
      <?php endif; ?>

      <!-- QUICK AMOUNTS -->
      <?php if ($canWithdraw): ?>
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
      <?php endif; ?>

      <!-- NET DISPLAY -->
      <div class="net-display" id="net-display" style="display:none">
        You receive: <strong id="net-amount">₦0.00</strong>
        <span style="color:var(--muted-2);margin-left:6px">(after <?= formatNaira($withdrawalFee) ?> fee)</span>
      </div>

      <button type="submit" class="btn btn-purple btn-lg" id="wd-btn"
        style="width:100%;justify-content:center;margin-top:4px"
        <?= $canWithdraw ? '' : 'disabled' ?>>
        <i class="ti ti-cash"></i>
        <span id="wd-btn-text">Withdraw Now</span>
      </button>
    </form>
  </div>
  <?php endif; ?>

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
            <span style="font-family:var(--font-mono);font-size:11px;color:var(--muted)">
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
            <div style="font-size:13px;font-weight:600;color:var(--ink)">
              <?= htmlspecialchars($w['bank_name']) ?>
            </div>

            <div style="font-size:11px;color:var(--muted)">
              <?= htmlspecialchars($w['account_number']) ?>
            </div>

            <div style="font-size:11px;color:var(--muted-2)">
              <?= htmlspecialchars($w['account_name']) ?>
            </div>
          </td>

          <td>
            <span class="badge badge-<?= $statusColor ?>">
              <?= ucfirst($w['status']) ?>
            </span>
          </td>

          <td style="font-size:12px;color:var(--muted);white-space:nowrap">
            <?= nigeriaTime($w['created_at']) ?>
          </td>

        </tr>

        <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>

</div>

<style>
.wd-grid{ display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; }
@media(max-width:768px){ .wd-grid{ grid-template-columns:1fr; } }

.balance-card{ background:linear-gradient(135deg,#2A2270 0%,#4F43D4 65%,#6457E8 100%); border:1px solid rgba(255,255,255,.12); border-radius:var(--r-lg); padding:24px; position:relative; overflow:hidden; margin-bottom:20px; box-shadow:0 12px 32px -12px rgba(79,67,212,.5); }
.balance-card::before{ content:''; position:absolute; top:-60px; right:-60px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.08); pointer-events:none; }
.balance-label{ font-size:12px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:rgba(255,255,255,.65); margin-bottom:8px; }
.balance-amount{ font-family:var(--font-display); font-size:36px; font-weight:800; color:#fff; letter-spacing:-1px; margin-bottom:6px; word-break:break-all; }
.balance-note{ font-size:12px; color:rgba(255,255,255,.65); }

.notice-box{ display:flex; align-items:flex-start; gap:10px; padding:13px 16px; border-radius:var(--r-lg); margin-bottom:20px; font-size:13px; line-height:1.5; }
.notice-box i{ font-size:17px; flex-shrink:0; margin-top:1px; }
.notice-box.warning{ background:var(--warning-bg); border:1px solid rgba(217,119,6,.25); color:var(--ink); }
.notice-box.warning i{ color:var(--warning); }
.notice-box.danger{ background:var(--danger-bg); border:1px solid rgba(220,38,38,.2); color:var(--danger); }
.notice-title{ font-size:14px; font-weight:700; color:var(--ink); margin-bottom:2px; }
.notice-sub{ font-size:13px; color:var(--muted); }
.notice-sub a{ color:var(--purple-600); font-weight:700; text-decoration:none; }

.quick-btns{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.quick-btn{ padding:6px 14px; border-radius:9999px; border:1.5px solid var(--line); background:var(--paper); font-family:var(--font-body); font-size:13px; font-weight:600; color:var(--ink); cursor:pointer; transition:all .15s; }
.quick-btn:hover{ border-color:var(--purple-500); color:var(--purple-600); background:var(--purple-50); }

.payout-account-box{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r-lg); padding:16px; }
.payout-account-label{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--muted-2); display:flex; align-items:center; gap:6px; margin-bottom:8px; }
.payout-account-name{ font-size:15px; font-weight:700; color:var(--ink); }
.payout-account-sub{ font-size:13px; color:var(--muted); margin-top:2px; }

.net-display{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r-lg); padding:12px 16px; margin-bottom:16px; font-size:13px; color:var(--muted); }
.net-display strong{ color:var(--ink); font-size:15px; }

.wd-otp-icon{ width:56px; height:56px; border-radius:50%; background:var(--purple-50); border:1px solid var(--purple-100); color:var(--purple-600); display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 14px; }
.wd-link-btn{ background:none; border:none; font-size:13px; font-weight:600; cursor:pointer; font-family:var(--font-body); color:var(--purple-600); display:inline-flex; align-items:center; gap:5px; }
.wd-link-btn:hover{ text-decoration:underline; }

.table-wrap{ overflow-x:auto; -webkit-overflow-scrolling:touch; background:var(--paper); border:1.5px solid var(--line); border-radius:var(--r-lg); }
.table-wrap table{ min-width:640px; width:100%; border-collapse:collapse; }
.table-wrap thead th{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); text-align:left; padding:12px 16px; border-bottom:1px solid var(--line); white-space:nowrap; }
.table-wrap tbody td{ padding:13px 16px; border-bottom:1px solid var(--line); font-size:13px; color:var(--ink); }
.table-wrap tbody tr:last-child td{ border-bottom:none; }
.table-head{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); }
.table-title{ font-size:14px; font-weight:700; color:var(--ink); }
.td-bold{ font-weight:600; color:var(--ink); }
</style>

<script>
const FEE = <?= $withdrawalFee ?>;

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
  if (!display) return;
  if (amt >= 500) {
    display.style.display = 'block';
    netEl.textContent = fmt(net);
    btnText.textContent = 'Withdraw ' + fmt(amt);
  } else {
    display.style.display = 'none';
    btnText.textContent = 'Withdraw Now';
  }
}

document.getElementById('wd-form')?.addEventListener('submit', function(e) {
  const btn = document.getElementById('wd-btn');
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Processing...';
  btn.disabled = true;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>