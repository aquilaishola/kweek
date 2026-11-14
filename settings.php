<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nomba.php';
requireLogin();

$pageTitle  = 'Settings';
$activePage = 'settings';
$user       = currentUser();  
$uid        = $user['id'];
$viewer     = sessionUser();   
$viewerId   = $viewer['id'];
$db         = db();

// Same fallback resolution withdraw.php uses: prefer the newer payout_*
// columns, fall back to the legacy bank_*/account_* columns for accounts
// linked before payout_* existed (data saved by the old withdraw flow).
// A legacy-only account counts as verified once identity is, since it was
// already approved back when payout_verified didn't exist yet.
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

$tab = in_array(get('tab'), ['profile','security','billing','payout','notifications']) ? get('tab') : 'profile';

if (($tab === 'billing' || $tab === 'payout') && isTeamMember($viewer)) {
    $tab = 'profile';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    // ── PROFILE UPDATE ────────────────────────────────────────
    if ($action === 'update_profile') {
        $name  = clean(post('name'));
        $phone = preg_replace('/\D/', '', post('phone'));
        $errors = [];

        // Name is locked once this person's own identity is verified — it
        // must keep matching what was checked against their KYC documents,
        // otherwise a verified user could rename themselves post-approval
        // and undermine the payout-account name-match check above.
        if (!empty($viewer['bvn_verified']) && $name !== $viewer['name']) {
            $errors[] = "Your name is locked after identity verification and can't be changed here. Contact support if it needs correcting.";
        }

        if (strlen($name) < 2) $errors[] = 'Name is too short.';
        if (strlen($phone) < 10) $errors[] = 'Enter a valid phone number.';

        $chk = $db->prepare("SELECT id FROM users WHERE phone=? AND id!=?");
        $chk->execute([$phone, $viewerId]);
        if ($chk->fetch()) $errors[] = 'That phone number is already in use.';

        if (empty($errors)) {
            $db->prepare("UPDATE users SET name=?, phone=? WHERE id=?")->execute([$name, $phone, $viewerId]);
            flash('success', 'Profile updated successfully.');
        } else {
            flash('error', implode(' ', $errors));
        }
        redirect('/settings.php?tab=profile');
    }

    // ── PASSWORD CHANGE ───────────────────────────────────────
    if ($action === 'change_password') {
        $current = post('current_password');
        $new     = post('new_password');
        $confirm = post('confirm_password');

        if (!password_verify($current, $viewer['password'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'Passwords do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $viewerId]);
            flash('success', 'Password changed successfully.');
        }
        redirect('/settings.php?tab=security');
    }

    // ── PAYOUT: request a change to the linked account ────────────
    // Doesn't touch bvn_verified — identity stays verified. Clears both
    // the newer payout_* columns AND the legacy bank_*/account_* columns
    // (an account might be sitting in either, per resolveUserPayoutAccount
    // above) so the fallback doesn't keep resolving to the old account
    // after this. The payout panel below then shows the link-a-new-account
    // form right on this page.
    if ($action === 'request_payout_change' && !isTeamMember($viewer)) {
        if (!empty($user['payout_locked'])) {
            flash('error', 'Payouts are locked on this account. Contact support.');
            redirect('/settings.php?tab=payout');
        }
        $db->prepare("
            UPDATE users SET
                payout_verified = 0, payout_verified_at = NULL,
                payout_bank_code = NULL, payout_bank_name = NULL,
                payout_account_number = NULL, payout_account_name = NULL,
                bank_code = NULL, bank_name = NULL,
                account_number = NULL, account_name = NULL
            WHERE id = ?
        ")->execute([$uid]);
        flash('info', 'Link a new payout account below to continue.');
        redirect('/settings.php?tab=payout');
    }

    if ($action === 'link_payout_account' && !isTeamMember($viewer)) {
        if (!empty($user['payout_locked'])) {
            flash('error', 'Payouts are locked on this account. Contact support.');
            redirect('/settings.php?tab=payout');
        }
        if (empty($user['bvn_verified'])) {
            flash('error', 'Verify your identity before linking a payout account.');
            redirect('/kyc.php');
        }

        $bankCode    = clean(post('bank_code'));
        $accountNum  = preg_replace('/\D/', '', post('account_number'));
        $accountName = clean(post('account_name'));

        $errors = [];
        if (strlen($accountNum) !== 10) $errors[] = 'Enter a valid 10-digit account number.';
        if (empty($bankCode)) $errors[] = 'Select a bank.';
        if (empty($accountName)) $errors[] = 'Verify your account name before saving.';

        $bankName = null;
        if (empty($errors)) {
            $nomba = new Nomba();
            $banksResult = $nomba->getBanks();
            foreach (($banksResult['data'] ?? []) as $b) {
                $code = trim($b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '');
                if ($code === $bankCode) {
                    $bankName = rtrim(trim($b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? ''), '.');
                    break;
                }
            }
            if (empty($bankName)) $errors[] = 'Could not resolve the selected bank. Please try again.';
        }

        if (empty($errors)) {
            $kycStmt = $db->prepare("SELECT full_name, kyc_first_name, kyc_last_name FROM user_kyc WHERE user_id = ? LIMIT 1");
            $kycStmt->execute([$uid]);
            $kycRow  = $kycStmt->fetch();
            $kycName = trim($kycRow['full_name'] ?? '')
                ?: trim(($kycRow['kyc_first_name'] ?? '') . ' ' . ($kycRow['kyc_last_name'] ?? ''));

            if (empty($kycName) || !namesReasonablyMatch($kycName, $accountName)) {
                $errors[] = "This account name doesn't match your verified identity. Payout accounts must be in your own name — contact support if this is a mistake.";
            }
        }

        if (empty($errors)) {
            $db->prepare("
                UPDATE users SET
                    payout_bank_code = ?, payout_bank_name = ?, payout_account_number = ?, payout_account_name = ?,
                    payout_verified = 1, payout_verified_at = NOW()
                WHERE id = ?
            ")->execute([$bankCode, $bankName, $accountNum, $accountName, $uid]);

            notify($uid, 'payout_linked', 'Payout Account Linked', 'Your payout account (' . $bankName . ') has been linked and verified. You can now withdraw.', 'building-bank', '/withdraw.php');
            flash('success', 'Payout account linked and verified.');
        } else {
            flash('error', implode(' ', $errors));
        }
        redirect('/settings.php?tab=payout');
    }

    // ── NOTIFICATION PREFS ────────────────────────────────────
    if ($action === 'update_notifications') {
        $notifKeys = ['payment_received','order_pending','withdrawal_done','low_balance','weekly_summary'];
        $prefs = [];
        foreach ($notifKeys as $k) {
            $prefs[$k] = !empty($_POST['notif_' . $k]) ? 1 : 0;
        }
        $prefsJson = json_encode($prefs);
        $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
           ->execute(['notif_prefs_user_' . $viewerId, $prefsJson, $prefsJson]);
        flash('success', 'Notification preferences saved.');
        redirect('/settings.php?tab=notifications');
    }
}

// Confirm a just-completed subscription checkout on redirect landing —
// Bachs appends ?checkout_id= to the success_url, so this fires the moment
// the customer comes back from paying, without waiting on the webhook.
if ($tab === 'billing' && !empty($_GET['checkout_id'])) {
    require_once __DIR__ . '/includes/payment.php';
    $stmt = $db->prepare("SELECT reference FROM subscriptions WHERE nomba_checkout_id = ? LIMIT 1");
    $stmt->execute([$_GET['checkout_id']]);
    $subRef = $stmt->fetchColumn();
    if ($subRef) {
        finalizeSubscriptionPayment($subRef);
    }
}

// Subscription info — needed by the billing panel, computed unconditionally
// now that every tab panel renders on every load (tabs switch client-side).
$subStmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1");
$subStmt->execute([$uid]);
$subscription = $subStmt->fetch();

$planCatalog = [
    'starter' => [
        'label'    => 'Starter',
        'monthly'  => PRICE_STARTER_MONTHLY,
        'annual'   => PRICE_STARTER_ANNUAL,
        'fee'      => FEE_STARTER,
        'features' => [
            'Up to 5 active payment links',
            '1.2% transaction fee',
            '₦750,000 monthly volume cap',
            '₦50 withdrawal fee',
            'No "Powered by Kweek" branding',
        ],
    ],
    'pro' => [
        'label'    => 'Pro',
        'monthly'  => PRICE_PRO_MONTHLY,
        'annual'   => PRICE_PRO_ANNUAL,
        'fee'      => FEE_PRO,
        'popular'  => true,
        'features' => [
            'Unlimited payment links',
            '0.8% transaction fee',
            '₦5,000,000 monthly volume cap',
            'Free withdrawals',
            'No "Powered by Kweek" branding',
            'Priority support',
            'Analytics'
        ],
    ],
    'business' => [
        'label'    => 'Business',
        'monthly'  => PRICE_BUSINESS_MONTHLY,
        'annual'   => PRICE_BUSINESS_ANNUAL,
        'fee'      => FEE_BUSINESS,
        'features' => [
            'Unlimited payment links',
            '0.5% transaction fee',
            'Unlimited monthly volume',
            'Free withdrawals',
            'No "Powered by Kweek" branding',
            'Priority support',
            'APIs and Webhooks',
            'Advanced Analytics'
        ],
    ],
];
$planRank = ['free' => 0, 'starter' => 1, 'pro' => 2, 'business' => 3];
$eligiblePlans = array_filter($planCatalog, fn($key) => $planRank[$key] > $planRank[$user['plan']], ARRAY_FILTER_USE_KEY);

$billingStmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$billingStmt->execute([$uid]);
$bills = $billingStmt->fetchAll();

// Notification prefs — needed by the notifications panel, also unconditional now.
$prefStmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
$prefStmt->execute(['notif_prefs_user_' . $viewerId]);
$savedPrefs = json_decode($prefStmt->fetchColumn() ?: '{}', true);

$notifItems = [
  ['payment_received', 'New Payment',         'Get notified every time a customer pays you.',              'ti-bell-ringing', true],
  ['order_pending',    'Order Needs Confirmation', 'Alert when an order is waiting for your review.',       'ti-clock-check',  true],
  ['withdrawal_done',  'Withdrawal Processed', 'Alert when funds land in your bank account.',               'ti-building-bank',true],
  ['low_balance',      'Low Balance Warning',  'Alert when your wallet balance falls below ₦1,000.',        'ti-alert-triangle',false],
  ['weekly_summary',   'Weekly Summary Email', 'Receive a weekly email summary of your sales and earnings.','ti-chart-bar',    true],
];

// Bank list for the payout-linking form on the Payout tab — only fetched
// when it's actually needed (identity verified, no account on file at
// all yet — checked via the same fallback resolution used everywhere
// else, not just the payout_* columns).
$payoutAccount = resolveUserPayoutAccount($user);
$payoutBanks = [];
if (!isTeamMember($viewer) && !empty($user['bvn_verified']) && !$payoutAccount['has_account'] && empty($user['payout_locked'])) {
    $nomba = new Nomba();
    $banksResult = $nomba->getBanks();
    $seen = [];
    foreach (($banksResult['data'] ?? []) as $b) {
        $code = trim($b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '');
        $name = trim($b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? '');
        if ($code === '' || isset($seen[$code])) continue;
        $seen[$code] = true;
        $payoutBanks[] = ['bankCode' => $code, 'bankName' => rtrim($name, '.')];
    }
    usort($payoutBanks, fn($a, $b) => strcmp($a['bankName'], $b['bankName']));
}

include __DIR__ . '/includes/header.php';
?>
<div style="max-width:720px">
  <div style="margin-bottom:24px">
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-.5px">Settings</h1>
    <p style="font-size:14px;color:var(--muted);margin-top:4px">Manage your account, security and billing.</p>
  </div>

  <div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:28px;overflow-x:auto">
    <?php
    $settingsTabs = [
      'profile'       => ['Profile',        'ti-user'],
      'security'      => ['Security',       'ti-lock'],
      'billing'       => ['Billing & Plan', 'ti-credit-card'],
      'payout'        => ['Payout Details', 'ti-building-bank'],
      'notifications' => ['Notifications',  'ti-bell'],
    ];
    if (isTeamMember($viewer)) { unset($settingsTabs['billing']); unset($settingsTabs['payout']); }
    foreach ($settingsTabs as $t => [$label, $icon]): ?>
    <button type="button" class="settings-tab-btn" data-tab="<?= $t ?>" onclick="switchSettingsTab('<?= $t ?>')"
       style="display:inline-flex;align-items:center;gap:7px;padding:12px 20px;font-size:14px;font-weight:600;background:none;border:none;cursor:pointer;font-family:var(--font-body);border-bottom:2px solid <?= $tab===$t?'var(--purple-600)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab===$t?'var(--purple-600)':'var(--muted)' ?>;white-space:nowrap;transition:color .2s">
      <i class="ti <?= $icon ?>"></i><?= $label ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- ── PROFILE PANEL ─────────────────────────────────────── -->
  <div class="settings-panel" data-panel="profile" style="<?= $tab==='profile'?'':'display:none' ?>">
  <div class="card">
    <div class="card-title" style="margin-bottom:20px">Profile Information</div>

    <div style="display:flex;align-items:center;gap:16px;margin-bottom:28px;padding-bottom:24px;border-bottom:1px solid var(--line)">
      <div style="width:72px;height:72px;border-radius:50%;background:var(--purple-600);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff;font-family:var(--font-display);flex-shrink:0">
        <?= strtoupper(substr($viewer['name'],0,2)) ?>
      </div>
      <div>
        <div style="font-size:16px;font-weight:700;color:var(--ink)"><?= htmlspecialchars($viewer['name']) ?></div>
        <div style="font-size:13px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($viewer['email']) ?></div>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <?php if (isTeamMember($viewer)): ?>
          <span class="badge badge-purple"><i class="ti ti-users"></i> <?= ucfirst(teamRole($viewer)) ?> on <?= htmlspecialchars($user['name']) ?>'s account</span>
          <?php else: ?>
          <span class="badge badge-purple"><?= ucfirst($user['plan']) ?> Plan</span>
          <span class="badge badge-<?= $user['bvn_verified']?'success':'warning' ?>">
            <i class="ti ti-<?= $user['bvn_verified']?'shield-check':'shield-exclamation' ?>"></i>
            Identity <?= $user['bvn_verified']?'Verified':'Unverified' ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_profile">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <div class="form-input-wrap">
            <i class="ti ti-user inp-icon"></i>
            <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($viewer['name']) ?>" required
              <?= !empty($viewer['bvn_verified']) ? 'readonly style="opacity:.6;cursor:not-allowed"' : '' ?>>
          </div>
          <?php if (!empty($viewer['bvn_verified'])): ?>
          <div class="form-hint">Name is locked after identity verification. Contact support if it needs correcting.</div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <div class="form-input-wrap">
            <i class="ti ti-phone inp-icon"></i>
            <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($viewer['phone']) ?>" required>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <div class="form-input-wrap">
          <i class="ti ti-mail inp-icon"></i>
          <input type="email" class="form-input" value="<?= htmlspecialchars($viewer['email']) ?>" disabled style="opacity:.6;cursor:not-allowed">
        </div>
        <div class="form-hint">Email cannot be changed. Contact support if needed.</div>
      </div>
      <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-check"></i> Save Changes</button>
    </form>
  </div>
  </div>

  <!-- ── SECURITY PANEL ────────────────────────────────────── -->
  <div class="settings-panel" data-panel="security" style="<?= $tab==='security'?'':'display:none' ?>">
  <div style="display:flex;flex-direction:column;gap:20px">
    <div class="card">
      <div class="card-title" style="margin-bottom:4px">Change Password</div>
      <div class="card-sub" style="margin-bottom:20px">Use a strong password of at least 8 characters.</div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <div class="form-input-wrap">
            <i class="ti ti-lock inp-icon"></i>
            <input type="password" name="current_password" class="form-input" required placeholder="Your current password">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <div class="form-input-wrap">
              <i class="ti ti-lock-check inp-icon"></i>
              <input type="password" name="new_password" class="form-input" required placeholder="Min. 8 characters">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <div class="form-input-wrap">
              <i class="ti ti-lock-check inp-icon"></i>
              <input type="password" name="confirm_password" class="form-input" required placeholder="Repeat new password">
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-lock"></i> Update Password</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title" style="margin-bottom:4px">Active Sessions</div>
      <div class="card-sub" style="margin-bottom:20px">You are currently signed in on this device.</div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line)">
        <div style="display:flex;align-items:center;gap:12px">
          <i class="ti ti-device-laptop" style="font-size:22px;color:var(--muted)"></i>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--ink)">Current Device</div>
            <div style="font-size:12px;color:var(--muted)">Active now · <?= clientIp() ?></div>
          </div>
        </div>
        <span class="badge badge-success">Active</span>
      </div>
    </div>

    <?php if (isTeamMember($viewer)): ?>
    <div class="card">
      <div class="card-title" style="margin-bottom:4px">Leaving the Team</div>
      <div class="card-sub">To be removed from <?= htmlspecialchars($user['name']) ?>'s account, ask them to remove you from the Team page.</div>
    </div>
    <?php else: ?>
    <div class="card" style="border-color:var(--danger-bg)">
      <div class="card-title" style="margin-bottom:4px;color:var(--danger)">Danger Zone</div>
      <div class="card-sub" style="margin-bottom:16px">These actions are irreversible. Proceed with caution.</div>
      <button onclick="if(confirm('Are you sure you want to close your account? All your data will be permanently deleted.')) location.href='/settings.php?action=close_account'" class="btn btn-danger btn-md">
        <i class="ti ti-trash"></i> Close Account
      </button>
    </div>
    <?php endif; ?>
  </div>
  </div>

  <!-- ── BILLING PANEL — owner only, see TEAM_CAPABILITIES ──── -->
  <?php if (!isTeamMember($viewer)): ?>
  <div class="settings-panel" data-panel="billing" style="<?= $tab==='billing'?'':'display:none' ?>">
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Current plan — dedicated classes, not the generic .card, so nothing
         elsewhere can silently override this dark/branded treatment. -->
    <div class="plan-card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:8px">Current Plan</div>
          <div style="font-family:var(--font-display);font-size:28px;font-weight:800;color:#fff;letter-spacing:-.5px;text-transform:capitalize"><?= $user['plan'] ?></div>
          <?php if ($subscription): ?>
          <div style="font-size:13px;color:rgba(255,255,255,.7);margin-top:6px">
            <?= $subscription['status']==='trial'?'Trial ends':'Renews' ?>: <strong style="color:#fff"><?= nigeriaTime($subscription['ends_at']) ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
          <span class="plan-status-badge"><?= ucfirst($subscription['status'] ?? 'active') ?></span>
          <?php if ($user['plan'] !== 'business'): ?>
          <a href="#upgrade" class="btn btn-white btn-sm"><i class="ti ti-arrow-up"></i> Upgrade Plan</a>
          <?php endif; ?>
        </div>
      </div>

      <div id="plan-limits-grid" class="plan-limits-grid">
        <?php
       $limits = [
  ['Payment Links', $user['plan']==='free' ? '1' : (linkLimitFor($user['plan']) === PHP_INT_MAX ? 'Unlimited' : linkLimitFor($user['plan'])), 'ti-link'],
  ['Monthly Cap',   formatNaira(monthlyCapFor($user['plan'])), 'ti-trending-up'],
  ['Tx Fee', feeLabelFor($user['plan']) . '%', 'ti-percentage'],
  ['Withdrawal Fee', withdrawalFeeFor($user['plan']) > 0 ? formatNaira(withdrawalFeeFor($user['plan'])) : 'Free', 'ti-cash'],
];
        foreach ($limits as [$label,$val,$icon]):
        ?>
        <div class="plan-limit-item">
          <i class="ti <?= $icon ?>" style="font-size:20px;color:rgba(255,255,255,.75);margin-bottom:6px;display:block"></i>
          <div style="font-family:var(--font-display);font-size:16px;font-weight:700;color:#fff"><?= $val ?></div>
          <div style="font-size:11px;color:rgba(255,255,255,.55);margin-top:2px"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($user['plan'] === 'business' || $user['plan'] === 'pro'): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.12)">
        <?php if ($user['plan'] === 'business'): ?>
        <a href="/api-webhooks.php" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:#fff;background:rgba(255,255,255,.14);padding:7px 12px;border-radius:var(--r-full);text-decoration:none"><i class="ti ti-plug-connected"></i> API &amp; Webhooks</a>
        <?php endif; ?>
        <a href="/team.php" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:#fff;background:rgba(255,255,255,.14);padding:7px 12px;border-radius:var(--r-full);text-decoration:none"><i class="ti ti-users"></i> Manage Team</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($eligiblePlans)): ?>
    <div id="upgrade" class="upgrade-plans-grid" style="--plan-cols:<?= count($eligiblePlans) ?>">
      <?php foreach ($eligiblePlans as $planKey => $p): ?>
      <div class="upgrade-plan-card <?= !empty($p['popular']) ? 'popular' : '' ?>">
        <?php if (!empty($p['popular'])): ?>
        <div class="upgrade-plan-badge">Most Popular</div>
        <?php endif; ?>
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted-2);margin-bottom:8px"><?= $p['label'] ?> Plan</div>
        <div style="font-family:var(--font-display);font-size:30px;font-weight:800;color:var(--ink);letter-spacing:-1px;margin-bottom:4px">
          ₦<?= number_format($p['monthly']) ?> <span style="font-size:13px;font-weight:400;color:var(--muted)">/month</span>
        </div>
        <ul style="list-style:none;padding:0;margin:16px 0;display:flex;flex-direction:column;gap:8px">
          <?php foreach ($p['features'] as $feat): ?>
          <li style="font-size:13px;color:var(--ink);display:flex;align-items:flex-start;gap:6px">
            <i class="ti ti-check" style="color:var(--success);font-size:15px;margin-top:1px;flex-shrink:0"></i><?= $feat ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <a href="#" class="btn <?= !empty($p['popular']) ? 'btn-purple' : 'btn-outline' ?> btn-md" style="width:100%;justify-content:center" onclick="startUpgrade('<?= $planKey ?>','monthly');return false;">
          <i class="ti ti-arrow-up"></i> Upgrade to <?= $p['label'] ?>
        </a>
        <a href="#" style="display:block;text-align:center;font-size:12px;color:var(--muted);margin-top:8px" onclick="startUpgrade('<?= $planKey ?>','annual');return false;">
          or ₦<?= number_format($p['annual']) ?>/year <span style="color:var(--success);font-weight:600">(save ~17%)</span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Billing history -->
    <div class="table-wrap">
      <div class="table-head"><span class="table-title">Billing History</span></div>
      <?php if (empty($bills)): ?>
      <div class="empty-state" style="padding:40px 20px">
        <div class="empty-title">No billing history</div>
        <div class="empty-sub">Subscription payments will appear here.</div>
      </div>
      <?php else: ?>
      <table>
        <thead><tr><th>Plan</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Period</th></tr></thead>
        <tbody>
        <?php foreach ($bills as $b): ?>
        <tr>
          <td class="td-bold" style="text-transform:capitalize"><?= $b['plan'] ?></td>
          <td style="text-transform:capitalize"><?= $b['billing_cycle'] ?></td>
          <td class="td-bold"><?= formatNaira($b['amount']) ?></td>
          <td><span class="badge badge-<?= $b['status']==='active'||$b['status']==='trial'?'success':'neutral' ?>"><?= ucfirst($b['status']) ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= date('M j Y',strtotime($b['starts_at'])) ?> — <?= date('M j Y',strtotime($b['ends_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  </div>
  <?php endif; ?>

  <!-- ── PAYOUT PANEL — owner only ─────────────────────────── -->
  <?php if (!isTeamMember($viewer)): ?>
  <div class="settings-panel" data-panel="payout" style="<?= $tab==='payout'?'':'display:none' ?>">
  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Payout Account</div>
    <div class="card-sub" style="margin-bottom:20px">Withdrawals only ever go to this account. It must be in your verified identity name.</div>

    <?php if (!empty($user['payout_locked'])): ?>
      <div class="notice-box danger">
        <i class="ti ti-lock"></i>
        <div>
          <div class="notice-title">Payouts Locked</div>
          <div class="notice-sub"><?= htmlspecialchars($user['payout_locked_reason'] ?: 'Contact support to have your account reviewed.') ?></div>
        </div>
      </div>

    <?php elseif (empty($user['bvn_verified'])): ?>
      <div class="notice-box warning">
        <i class="ti ti-alert-triangle"></i>
        <div>
          <div class="notice-title">Verification Not Started</div>
          <div class="notice-sub">You need to verify your identity before you can link a payout account. <a href="/kyc.php">Start verification →</a></div>
        </div>
      </div>

    <?php elseif (!$payoutAccount['has_account']): ?>
      <div class="notice-box warning">
        <i class="ti ti-building-bank"></i>
        <div>
          <div class="notice-title">Link a Payout Account</div>
          <div class="notice-sub">Your identity is verified — add a bank account below in your own name to unlock withdrawals.</div>
        </div>
      </div>

      <form method="POST" id="payout-link-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="link_payout_account">
        <input type="hidden" name="account_name" id="payout-hidden-acc-name" value="">

        <div class="form-group">
          <label class="form-label">Bank</label>
          <select name="bank_code" id="payout-bank-select" class="form-select" onchange="onPayoutBankChange()" required>
            <option value="">— Select bank —</option>
            <?php foreach ($payoutBanks as $bank): ?>
            <option value="<?= htmlspecialchars($bank['bankCode']) ?>" data-name="<?= htmlspecialchars($bank['bankName']) ?>">
              <?= htmlspecialchars($bank['bankName']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Account Number</label>
          <div style="display:flex;gap:8px">
            <input type="text" name="account_number" id="payout-acc-number" class="form-input"
              placeholder="10-digit account number" maxlength="10" inputmode="numeric" style="flex:1"
              oninput="if(this.value.replace(/\D/g,'').length===10) verifyPayoutAccount()">
            <button type="button" id="payout-verify-btn" onclick="verifyPayoutAccount()" class="btn btn-outline btn-md">
              <i class="ti ti-search"></i> Verify
            </button>
          </div>
        </div>

        <div class="acc-verified" id="payout-acc-verified-box" style="display:none">
          <i class="ti ti-circle-check"></i>
          <span class="acc-verified-name" id="payout-acc-verified-name"></span>
        </div>

        <button type="submit" class="btn btn-purple btn-md" id="payout-submit-btn">
          <i class="ti ti-check"></i> Link Account
        </button>
      </form>

    <?php elseif (!$payoutAccount['is_verified']): ?>
      <div class="payout-account-box" style="margin-bottom:16px;opacity:.8">
        <div class="payout-account-label"><i class="ti ti-clock"></i> Pending Review</div>
        <div class="payout-account-name"><?= htmlspecialchars($payoutAccount['account_name']) ?></div>
        <div class="payout-account-sub"><?= htmlspecialchars($payoutAccount['bank_name']) ?> · <?= htmlspecialchars($payoutAccount['account_number']) ?></div>
      </div>
      <div class="notice-box warning">
        <i class="ti ti-clock"></i>
        <div>
          <div class="notice-title">Awaiting Approval</div>
          <div class="notice-sub">This account is on file and awaiting review. Withdrawals unlock automatically once it's approved.</div>
        </div>
      </div>

    <?php else: ?>
      <div class="payout-account-box" style="margin-bottom:16px">
        <div class="payout-account-label"><i class="ti ti-shield-check"></i> Verified Payout Account</div>
        <div class="payout-account-name"><?= htmlspecialchars($payoutAccount['account_name']) ?></div>
        <div class="payout-account-sub"><?= htmlspecialchars($payoutAccount['bank_name']) ?> · <?= htmlspecialchars($payoutAccount['account_number']) ?></div>
        <?php if (!empty($user['payout_verified_at'])): ?>
        <div class="payout-account-sub" style="margin-top:6px">Linked <?= nigeriaTime($user['payout_verified_at']) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" onsubmit="return confirm('This will unlink your current payout account. You\'ll need to link and verify a new one before you can withdraw again. Continue?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="request_payout_change">
        <button type="submit" class="btn btn-outline btn-md"><i class="ti ti-refresh"></i> Change Payout Account</button>
      </form>
    <?php endif; ?>
  </div>
  </div>
  <?php endif; ?>

  <!-- ── NOTIFICATIONS PANEL ────────────────────────────────── -->
  <div class="settings-panel" data-panel="notifications" style="<?= $tab==='notifications'?'':'display:none' ?>">
  <?php
    $notifGroups = [
      'Payments & Orders' => ['payment_received', 'order_pending', 'withdrawal_done', 'low_balance'],
      'Reports'           => ['weekly_summary'],
    ];
    $notifMeta = [];
    foreach ($notifItems as [$key, $label, $desc, $icon, $defaultOn]) {
        $notifMeta[$key] = compact('label', 'desc', 'icon', 'defaultOn');
    }
    $notifColors = [
      'payment_received' => 'success', 'withdrawal_done' => 'success',
      'order_pending'    => 'warning', 'low_balance'      => 'warning',
      'weekly_summary'   => 'purple',
    ];
  ?>
  
  <form method="POST" id="notif-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_notifications">
    
    <div class="card" style="margin-bottom:16px">
  <div class="card-title" style="margin-bottom:2px">Push Notifications</div>
  <div class="card-sub" style="margin-bottom:14px">Get alerts on this device even when Kweek isn't open — works after installing Kweek to your home screen.</div>
 
  <div class="notif-item-row" style="border-bottom:none;padding-bottom:0">
    <div class="notif-item-icon notif-ic-purple"><i class="ti ti-device-mobile-bolt"></i></div>
    <div class="notif-item-text">
      <div class="notif-item-label" id="push-status-label">Checking status…</div>
      <div class="notif-item-desc" id="push-status-desc">This device isn't linked for push alerts yet.</div>
    </div>
    <div style="flex-shrink:0">
      <button type="button" class="btn btn-outline btn-sm" id="push-toggle-btn" onclick="handlePushToggle()" disabled>
        <i class="ti ti-loader-2"></i> Checking…
      </button>
    </div>
  </div>
</div>

    <?php foreach ($notifGroups as $groupLabel => $keys): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:2px"><?= $groupLabel ?></div>
      <div class="card-sub" style="margin-bottom:6px">
        <?= $groupLabel === 'Reports' ? 'Periodic summaries, not real-time alerts.' : 'Real-time alerts as things happen on your account.' ?>
      </div>
      <?php foreach ($keys as $key):
        $meta = $notifMeta[$key];
        $isOn = isset($savedPrefs[$key]) ? (bool)$savedPrefs[$key] : $meta['defaultOn'];
        $toggleId = 'toggle-' . $key;
        $knobId   = 'knob-' . $key;
        $nc = $notifColors[$key] ?? 'purple';
      ?>
      <div class="notif-item-row">
        <div class="notif-item-icon notif-ic-<?= $nc ?>"><i class="ti <?= $meta['icon'] ?>"></i></div>
        <div class="notif-item-text">
          <div class="notif-item-label"><?= $meta['label'] ?></div>
          <div class="notif-item-desc"><?= $meta['desc'] ?></div>
        </div>
        <div style="flex-shrink:0">
          <input type="hidden" name="notif_<?= $key ?>" value="0">
          <label style="display:inline-flex;align-items:center;cursor:pointer;gap:10px">
            <input type="checkbox"
              name="notif_<?= $key ?>"
              value="1"
              id="cb-<?= $key ?>"
              <?= $isOn ? 'checked' : '' ?>
              style="position:absolute;opacity:0;width:0;height:0"
              onchange="kweekToggle('<?= $toggleId ?>','<?= $knobId ?>',this.checked)">
            <div id="<?= $toggleId ?>" class="notif-toggle-track" style="background:<?= $isOn ? 'var(--purple-600)' : 'var(--line)' ?>">
              <div id="<?= $knobId ?>" class="notif-toggle-knob" style="transform:<?= $isOn ? 'translateX(20px)' : 'translateX(0)' ?>"></div>
            </div>
            <span class="notif-toggle-label" id="label-<?= $key ?>"><?= $isOn ? 'On' : 'Off' ?></span>
          </label>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit" class="btn btn-purple btn-md">
        <i class="ti ti-check"></i> Save Preferences
      </button>
      <button type="button" class="btn btn-outline btn-md" onclick="toggleAll(true)">
        <i class="ti ti-bell"></i> Enable All
      </button>
      <button type="button" class="btn btn-outline btn-md" onclick="toggleAll(false)">
        <i class="ti ti-bell-off"></i> Disable All
      </button>
    </div>
  </form>
  </div>

</div>

<style>
@media (max-width: 480px) {
  #plan-limits-grid { grid-template-columns: 1fr !important; }
}
@media (max-width: 640px) {
  .upgrade-plans-grid { grid-template-columns: 1fr !important; }
}
@media (max-width: 680px) {
  #plan-limits-grid { grid-template-columns: repeat(2,1fr) !important; }
}

/* ── notification list items ─────────────────────────────────── */
.notif-item-row{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
.notif-item-row:last-child{border-bottom:none}
.notif-item-icon{width:38px;height:38px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.notif-ic-success{background:var(--success-bg);color:var(--success)}
.notif-ic-warning{background:var(--warning-bg);color:var(--warning)}
.notif-ic-purple{background:var(--purple-50);color:var(--purple-600)}
.notif-item-text{flex:1;min-width:160px}
.notif-item-label{font-size:14px;font-weight:600;color:var(--ink);margin-bottom:2px}
.notif-item-desc{font-size:12px;color:var(--muted);line-height:1.5}
.notif-toggle-track{width:44px;height:24px;border-radius:12px;transition:background .25s;position:relative;cursor:pointer}
.notif-toggle-knob{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .25s}
.notif-toggle-label{font-size:13px;font-weight:600;color:var(--ink);min-width:30px;display:inline-block}

/* ── payout account box — mirrors the read-only card on withdraw.php ── */
.payout-account-box{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r-lg); padding:16px; }
.payout-account-label{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--muted-2); display:flex; align-items:center; gap:6px; margin-bottom:8px; }
.payout-account-name{ font-size:15px; font-weight:700; color:var(--ink); }
.payout-account-sub{ font-size:13px; color:var(--muted); margin-top:2px; }

.notice-box{ display:flex; align-items:flex-start; gap:10px; padding:13px 16px; border-radius:var(--r-lg); margin-bottom:20px; font-size:13px; line-height:1.5; }
.notice-box i{ font-size:16px; flex-shrink:0; margin-top:1px; }
.notice-box.warning{ background:var(--warning-bg); border:1px solid rgba(217,119,6,.25); color:var(--ink); }
.notice-box.warning i{ color:var(--warning); }
.notice-box.danger{ background:var(--danger-bg); border:1px solid rgba(220,38,38,.2); color:var(--danger); }

.acc-verified{ display:flex; align-items:center; gap:8px; background:var(--success-bg); border:1px solid rgba(30,158,90,.25); border-radius:var(--r-lg); padding:12px 16px; margin-bottom:16px; }
.acc-verified i{ font-size:18px; color:var(--success); flex-shrink:0; }
.acc-verified-name{ font-size:14px; font-weight:700; color:var(--success); }

.plan-card{ border-radius:var(--r-lg); padding:24px; background:linear-gradient(135deg,#2A2270 0%,#4F43D4 65%,#6457E8 100%); border:1px solid rgba(255,255,255,.12); box-shadow:0 12px 32px -12px rgba(79,67,212,.5); }
.plan-status-badge{ display:inline-flex; align-items:center; padding:4px 10px; border-radius:var(--r-full); font-size:12px; font-weight:600; background:rgba(255,255,255,.14); color:#fff; }
.plan-limits-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-top:24px; padding-top:20px; border-top:1px solid rgba(255,255,255,.1); }
.plan-limit-item{ text-align:center; padding:12px; background:rgba(255,255,255,.07); border-radius:var(--r-lg); }

.upgrade-plans-grid{ display:grid; grid-template-columns:repeat(var(--plan-cols,3),1fr); gap:16px; }
.upgrade-plan-card{ background:var(--paper); border:1.5px solid var(--line); border-radius:var(--r-lg); padding:22px; position:relative; }
.upgrade-plan-card.popular{ border-color:var(--purple-300); }
.upgrade-plan-badge{ position:absolute; top:-13px; left:20px; background:var(--purple-600); color:#fff; font-size:11px; font-weight:700; padding:4px 12px; border-radius:var(--r-full); }
 
.table-wrap{ overflow-x:auto; -webkit-overflow-scrolling:touch; background:var(--paper); border:1.5px solid var(--line); border-radius:var(--r-lg); }
.table-wrap table{ min-width:600px; width:100%; border-collapse:collapse; }
.table-wrap thead th{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); text-align:left; padding:12px 16px; border-bottom:1px solid var(--line); white-space:nowrap; }
.table-wrap tbody td{ padding:13px 16px; border-bottom:1px solid var(--line); font-size:13px; color:var(--ink); white-space:nowrap; }
.table-wrap tbody tr:last-child td{ border-bottom:none; }
.table-head{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); }
.table-title{ font-size:14px; font-weight:700; color:var(--ink); }
.td-bold{ font-weight:600; color:var(--ink); }

@media (max-width:480px){
  .notif-item-row{ flex-wrap:wrap; }
  .notif-item-text{ min-width:0; flex:1 1 calc(100% - 52px); }
  .notif-item-row > div:last-child{
    flex:0 0 calc(100% - 52px);
    margin-left:52px;
    margin-top:8px;
    box-sizing:border-box;
  }
}
}
</style>

<script>
async function startUpgrade(plan, cycle) {
  try {
    const fd = new FormData();
    fd.append('plan', plan);
    fd.append('cycle', cycle);
    fd.append('csrf_token', '<?= csrfToken() ?>');

    const res  = await fetch('/upgrade', {method: 'POST', body: fd});
    const data = await res.json();

    if (data.success && data.checkoutUrl) {
      window.location.href = data.checkoutUrl;
    } else {
      showToast('error', 'Upgrade failed', data.error || 'Please try again.');
    }
  } catch (e) {
    showToast('error', 'Error', 'Network error. Try again.');
  }
}

function kweekToggle(trackId, knobId, isOn) {
  const track = document.getElementById(trackId);
  const knob  = document.getElementById(knobId);
  const key   = trackId.replace('toggle-','');
  const lbl   = document.getElementById('label-' + key);
  if (track) track.style.background = isOn ? 'var(--purple-600)' : 'var(--line)';
  if (knob)  knob.style.transform   = isOn ? 'translateX(20px)' : 'translateX(0)';
  if (lbl)   lbl.textContent        = isOn ? 'On' : 'Off';
}

async function verifyPayoutAccount() {
  const accNum   = document.getElementById('payout-acc-number').value.replace(/\D/g,'');
  const bankCode = document.getElementById('payout-bank-select').value;
  if (accNum.length !== 10 || !bankCode) {
    if (!bankCode) showToast('info','Select bank','Please select a bank first.');
    return;
  }

  const btn = document.getElementById('payout-verify-btn');
  btn.disabled = true;
  btn.innerHTML = '<div style="width:14px;height:14px;border-radius:50%;border:2px solid var(--line);border-top-color:var(--purple-600);animation:spin .7s linear infinite;display:inline-block"></div>';

  try {
    const fd = new FormData();
    fd.append('action', 'resolve_account');
    fd.append('account_number', accNum);
    fd.append('bank_code', bankCode);
    fd.append('csrf_token', '<?= csrfToken() ?>');

    const res  = await fetch('/withdraw', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.name) {
      document.getElementById('payout-hidden-acc-name').value = data.name;
      document.getElementById('payout-acc-verified-name').textContent = data.name;
      document.getElementById('payout-acc-verified-box').style.display = 'flex';
      showToast('success', 'Account verified', data.name);
    } else {
      document.getElementById('payout-hidden-acc-name').value = '';
      document.getElementById('payout-acc-verified-box').style.display = 'none';
      showToast('error', 'Not verified', data.error || 'Could not verify account.');
    }
  } catch (e) {
    showToast('error', 'Error', 'Network error. Try again.');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="ti ti-search"></i> Verify';
}

function onPayoutBankChange() {
  document.getElementById('payout-hidden-acc-name').value = '';
  document.getElementById('payout-acc-verified-box').style.display = 'none';
}

document.getElementById('payout-link-form')?.addEventListener('submit', function(e) {
  if (!document.getElementById('payout-hidden-acc-name').value.trim()) {
    e.preventDefault();
    showToast('error', 'Verify account', 'Please verify your account name before saving.');
    return;
  }
  const btn = document.getElementById('payout-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<div style="width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite;display:inline-block"></div> Linking...';
});

function toggleAll(isOn) {
  document.querySelectorAll('#notif-form input[type="checkbox"]').forEach(cb => {
    if (cb.checked !== isOn) cb.click();
  });
}

function switchSettingsTab(tab) {
  document.querySelectorAll('.settings-panel').forEach(p => {
    p.style.display = (p.dataset.panel === tab) ? '' : 'none';
  });
  document.querySelectorAll('.settings-tab-btn').forEach(b => {
    const active = b.dataset.tab === tab;
    b.style.color = active ? 'var(--purple-600)' : 'var(--muted)';
    b.style.borderBottomColor = active ? 'var(--purple-600)' : 'transparent';
  });
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  window.history.pushState({ tab }, '', url);
}
window.addEventListener('popstate', function () {
  const tab = new URL(window.location).searchParams.get('tab') || 'profile';
  switchSettingsTab(tab);
});
</script>

<script>
async function refreshPushUI() {
  const btn   = document.getElementById('push-toggle-btn');
  const label = document.getElementById('push-status-label');
  const desc  = document.getElementById('push-status-desc');
 
  if (!window.kwPush || !window.kwPush.supported) {
    label.textContent = 'Not supported';
    desc.textContent  = 'This browser doesn\'t support push notifications. Try Chrome, Edge, or installing Kweek to your home screen on iOS 16.4+.';
    btn.style.display = 'none';
    return;
  }
 
  const enabled = await window.kwPush.isEnabled();
  if (enabled) {
    label.textContent = 'Enabled on this device';
    desc.textContent  = 'You\'ll get push alerts for new orders, payments, and chat messages here.';
    btn.innerHTML = '<i class="ti ti-bell-off"></i> Turn Off';
    btn.classList.remove('btn-purple');
    btn.classList.add('btn-outline');
  } else {
    label.textContent = 'Not enabled';
    desc.textContent  = 'Turn this on to get alerts on this device even when Kweek is closed.';
    btn.innerHTML = '<i class="ti ti-bell"></i> Enable';
    btn.classList.remove('btn-outline');
    btn.classList.add('btn-purple');
  }
  btn.disabled = false;
}
 
async function handlePushToggle() {
  const btn = document.getElementById('push-toggle-btn');
  btn.disabled = true;
  btn.innerHTML = '<div style="width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:currentColor;animation:spin .7s linear infinite;display:inline-block"></div>';
 
  const currentlyEnabled = await window.kwPush.isEnabled();
  let ok;
  if (currentlyEnabled) {
    ok = await window.kwPush.disable();
  } else {
    ok = await window.kwPush.enable();
    if (!ok && Notification.permission === 'denied') {
      showToast('error', 'Blocked', 'Notifications are blocked for this site in your browser settings — enable them there first.');
    } else if (!ok) {
      showToast('error', 'Could not enable', 'Something went wrong turning on push notifications.');
    }
  }
 
  if (ok) showToast('success', currentlyEnabled ? 'Turned off' : 'Turned on', currentlyEnabled ? 'Push notifications disabled on this device.' : 'You\'ll get push alerts on this device now.');
  await refreshPushUI();
}
 
document.addEventListener('DOMContentLoaded', refreshPushUI);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>