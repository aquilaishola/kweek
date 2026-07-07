<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Settings';
$activePage = 'settings';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$tab = in_array(get('tab'), ['profile','security','billing','notifications']) ? get('tab') : 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    // ── PROFILE UPDATE ────────────────────────────────────────
    if ($action === 'update_profile') {
        $name  = clean(post('name'));
        $phone = preg_replace('/\D/', '', post('phone'));
        $errors = [];

        if (strlen($name) < 2) $errors[] = 'Name is too short.';
        if (strlen($phone) < 10) $errors[] = 'Enter a valid phone number.';

        // Check phone uniqueness
        $chk = $db->prepare("SELECT id FROM users WHERE phone=? AND id!=?");
        $chk->execute([$phone, $uid]);
        if ($chk->fetch()) $errors[] = 'That phone number is already in use.';

        if (empty($errors)) {
            $db->prepare("UPDATE users SET name=?, phone=? WHERE id=?")->execute([$name, $phone, $uid]);
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

        if (!password_verify($current, $user['password'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'Passwords do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
            flash('success', 'Password changed successfully.');
        }
        redirect('/settings.php?tab=security');
    }

    // ── NOTIFICATION PREFS ────────────────────────────────────
    if ($action === 'update_notifications') {
        $notifKeys = ['payment_received','order_pending','withdrawal_done','low_balance','weekly_summary'];
        $prefs = [];
        foreach ($notifKeys as $k) {
            $prefs[$k] = !empty($_POST['notif_' . $k]) ? 1 : 0;
        }
        // Store as JSON against user row (use settings table keyed by user)
        $prefsJson = json_encode($prefs);
        $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
           ->execute(['notif_prefs_user_' . $uid, $prefsJson, $prefsJson]);
        flash('success', 'Notification preferences saved.');
        redirect('/settings.php?tab=notifications');
    }
}

// Subscription info
$subStmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1");
$subStmt->execute([$uid]);
$subscription = $subStmt->fetch();

include __DIR__ . '/includes/header.php';
?>
<div style="max-width:720px">
  <div style="margin-bottom:24px">
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px">Settings</h1>
    <p style="font-size:14px;color:var(--n500);margin-top:4px">Manage your account, security and billing.</p>
  </div>

  <!-- TABS -->
  <div style="display:flex;gap:0;border-bottom:2px solid var(--n200);margin-bottom:28px;overflow-x:auto">
    <?php foreach ([
      'profile'       => ['Profile',       'ti-user'],
      'security'      => ['Security',      'ti-lock'],
      'billing'       => ['Billing & Plan','ti-credit-card'],
      'notifications' => ['Notifications', 'ti-bell'],
    ] as $t => [$label, $icon]): ?>
    <a href="?tab=<?= $t ?>"
       style="display:inline-flex;align-items:center;gap:7px;padding:12px 20px;font-size:14px;font-weight:600;text-decoration:none;border-bottom:2px solid <?= $tab===$t?'var(--p600)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab===$t?'var(--p600)':'var(--n500)' ?>;white-space:nowrap;transition:color .2s">
      <i class="ti <?= $icon ?>"></i><?= $label ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── PROFILE TAB ─────────────────────────────────────── -->
  <?php if ($tab === 'profile'): ?>
  <div class="card">
    <div class="card-title" style="margin-bottom:20px">Profile Information</div>

    <!-- Avatar -->
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:28px;padding-bottom:24px;border-bottom:1px solid var(--n200)">
      <div style="width:72px;height:72px;border-radius:50%;background:var(--p700);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:var(--n0);font-family:var(--font-display);flex-shrink:0">
        <?= strtoupper(substr($user['name'],0,2)) ?>
      </div>
      <div>
        <div style="font-size:16px;font-weight:700;color:var(--n900)"><?= htmlspecialchars($user['name']) ?></div>
        <div style="font-size:13px;color:var(--n500);margin-top:2px"><?= htmlspecialchars($user['email']) ?></div>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <span class="badge badge-purple"><?= ucfirst($user['plan']) ?> Plan</span>
          <span class="badge badge-<?= $user['bvn_verified']?'success':'warning' ?>">
            <i class="ti ti-<?= $user['bvn_verified']?'shield-check':'shield-exclamation' ?>"></i>
            BVN <?= $user['bvn_verified']?'Verified':'Unverified' ?>
          </span>
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
            <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <div class="form-input-wrap">
            <i class="ti ti-phone inp-icon"></i>
            <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone']) ?>" required>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <div class="form-input-wrap">
          <i class="ti ti-mail inp-icon"></i>
          <input type="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" disabled style="opacity:.6;cursor:not-allowed">
        </div>
        <div class="form-hint">Email cannot be changed. Contact support if needed.</div>
      </div>
      <button type="submit" class="btn btn-purple btn-md"><i class="ti ti-check"></i> Save Changes</button>
    </form>
  </div>

  <!-- ── SECURITY TAB ────────────────────────────────────── -->
  <?php elseif ($tab === 'security'): ?>
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
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--n50);border-radius:var(--r-lg);border:1px solid var(--n200)">
        <div style="display:flex;align-items:center;gap:12px">
          <i class="ti ti-device-laptop" style="font-size:22px;color:var(--n500)"></i>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--n900)">Current Device</div>
            <div style="font-size:12px;color:var(--n500)">Active now · <?= clientIp() ?></div>
          </div>
        </div>
        <span class="badge badge-success">Active</span>
      </div>
    </div>

    <div class="card" style="border-color:var(--danger-bg)">
      <div class="card-title" style="margin-bottom:4px;color:var(--danger)">Danger Zone</div>
      <div class="card-sub" style="margin-bottom:16px">These actions are irreversible. Proceed with caution.</div>
      <button onclick="if(confirm('Are you sure you want to close your account? All your data will be permanently deleted.')) location.href='/settings.php?action=close_account'" class="btn btn-danger btn-md">
        <i class="ti ti-trash"></i> Close Account
      </button>
    </div>
  </div>

  <!-- ── BILLING TAB ─────────────────────────────────────── -->
  <?php elseif ($tab === 'billing'): ?>
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Current plan -->
    <div class="card" style="background:var(--p900);border-color:var(--p800)">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--p300);margin-bottom:8px">Current Plan</div>
          <div style="font-family:var(--font-display);font-size:28px;font-weight:800;color:var(--n0);letter-spacing:-.5px;text-transform:capitalize"><?= $user['plan'] ?></div>
          <?php if ($subscription): ?>
          <div style="font-size:13px;color:var(--p300);margin-top:6px">
            <?= $subscription['status']==='trial'?'Trial ends':'Renews' ?>: <strong style="color:var(--n0)"><?= nigeriaTime($subscription['ends_at']) ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
          <span class="badge" style="background:var(--p700);color:var(--p200);font-size:12px">
            <?= ucfirst($subscription['status'] ?? 'active') ?>
          </span>
          <?php if ($user['plan'] !== 'business'): ?>
          <a href="#upgrade" class="btn btn-white btn-sm"><i class="ti ti-arrow-up"></i> Upgrade Plan</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Plan limits -->
    <div id="plan-limits-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:24px;padding-top:20px;border-top:1px solid var(--p800)">
        <?php
       $limits = [
  ['Payment Links', $user['plan']==='free' ? '1' : (linkLimitFor($user['plan']) === PHP_INT_MAX ? 'Unlimited' : linkLimitFor($user['plan'])), 'ti-link'],
  ['Monthly Cap',   formatNaira(monthlyCapFor($user['plan'])), 'ti-trending-up'],
['Tx Fee', feeLabelFor($user['plan']) . '%', 'ti-percentage'],
  ['Withdrawal Fee', withdrawalFeeFor($user['plan']) > 0 ? formatNaira(withdrawalFeeFor($user['plan'])) : 'Free', 'ti-cash'],
];
        foreach ($limits as [$label,$val,$icon]):
        ?>
        <div style="text-align:center;padding:12px;background:var(--p800);border-radius:var(--r-lg)">
          <i class="ti <?= $icon ?>" style="font-size:20px;color:var(--p300);margin-bottom:6px;display:block"></i>
          <div style="font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--n0)"><?= $val ?></div>
          <div style="font-size:11px;color:var(--p400);margin-top:2px"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php
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
        ],
    ],
];

$planRank = ['free' => 0, 'starter' => 1, 'pro' => 2, 'business' => 3];
$eligiblePlans = array_filter($planCatalog, fn($key) => $planRank[$key] > $planRank[$user['plan']], ARRAY_FILTER_USE_KEY);
?>

<?php if (!empty($eligiblePlans)): ?>
<div id="upgrade" style="display:grid;grid-template-columns:repeat(<?= count($eligiblePlans) ?>,1fr);gap:16px">
  <?php foreach ($eligiblePlans as $planKey => $p): ?>
  <div class="card" style="<?= !empty($p['popular']) ? 'border-color:var(--p300);position:relative' : '' ?>">
    <?php if (!empty($p['popular'])): ?>
    <div style="position:absolute;top:-13px;left:20px;background:var(--p600);color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:var(--r-full)">Most Popular</div>
    <?php endif; ?>
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--n400);margin-bottom:8px"><?= $p['label'] ?> Plan</div>
    <div style="font-family:var(--font-display);font-size:30px;font-weight:800;color:var(--n900);letter-spacing:-1px;margin-bottom:4px">
      ₦<?= number_format($p['monthly']) ?> <span style="font-size:13px;font-weight:400;color:var(--n500)">/month</span>
    </div>
    <ul style="list-style:none;padding:0;margin:16px 0;display:flex;flex-direction:column;gap:8px">
      <?php foreach ($p['features'] as $feat): ?>
      <li style="font-size:13px;color:var(--n600);display:flex;align-items:flex-start;gap:6px">
        <i class="ti ti-check" style="color:var(--success);font-size:15px;margin-top:1px;flex-shrink:0"></i><?= $feat ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <a href="#" class="btn <?= !empty($p['popular']) ? 'btn-purple' : 'btn-outline' ?> btn-md" style="width:100%;justify-content:center" onclick="startUpgrade('<?= $planKey ?>','monthly');return false;">
      <i class="ti ti-arrow-up"></i> Upgrade to <?= $p['label'] ?>
    </a>
    <a href="#" style="display:block;text-align:center;font-size:12px;color:var(--n500);margin-top:8px" onclick="startUpgrade('<?= $planKey ?>','annual');return false;">
      or ₦<?= number_format($p['annual']) ?>/year <span style="color:var(--success);font-weight:600">(save ~17%)</span>
    </a>
  </div>
  <?php endforeach; ?>
</div>

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
</script>
<?php endif; ?>

    <!-- Billing history -->
    <div class="table-wrap">
      <div class="table-head"><span class="table-title">Billing History</span></div>
      <?php
      $billingStmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
      $billingStmt->execute([$uid]);
      $bills = $billingStmt->fetchAll();
      ?>
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
          <td style="font-size:12px;color:var(--n500)"><?= date('M j Y',strtotime($b['starts_at'])) ?> — <?= date('M j Y',strtotime($b['ends_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── NOTIFICATIONS TAB ──────────────────────────────── -->
  <?php elseif ($tab === 'notifications'):
    // Load saved prefs
    $prefStmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $prefStmt->execute(['notif_prefs_user_' . $uid]);
    $savedPrefs = json_decode($prefStmt->fetchColumn() ?: '{}', true);

    $notifItems = [
      ['payment_received', 'New Payment',         'Get notified every time a customer pays you.',              'ti-bell-ringing', true],
      ['order_pending',    'Order Needs Confirmation', 'Alert when an order is waiting for your review.',       'ti-clock-check',  true],
      ['withdrawal_done',  'Withdrawal Processed', 'Alert when funds land in your bank account.',               'ti-building-bank',true],
      ['low_balance',      'Low Balance Warning',  'Alert when your wallet balance falls below ₦1,000.',        'ti-alert-triangle',false],
      ['weekly_summary',   'Weekly Summary Email', 'Receive a weekly email summary of your sales and earnings.','ti-chart-bar',    true],
    ];
  ?>
  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Notification Preferences</div>
    <div class="card-sub" style="margin-bottom:24px">Control exactly what alerts you receive from Kweek.</div>
    <form method="POST" id="notif-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_notifications">
      <?php foreach ($notifItems as $idx => [$key, $label, $desc, $icon, $defaultOn]):
        // Use saved pref, else default
        $isOn = isset($savedPrefs[$key]) ? (bool)$savedPrefs[$key] : $defaultOn;
        $toggleId = 'toggle-' . $key;
        $knobId   = 'knob-' . $key;
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0;border-bottom:1px solid var(--n200);flex-wrap:wrap">
        <div style="display:flex;align-items:flex-start;gap:12px;flex:1;min-width:200px">
          <div style="width:36px;height:36px;border-radius:var(--r-md);background:var(--p50);border:1px solid var(--p100);display:flex;align-items:center;justify-content:center;color:var(--p600);font-size:17px;flex-shrink:0">
            <i class="ti <?= $icon ?>"></i>
          </div>
          <div>
            <div style="font-size:14px;font-weight:600;color:var(--n900);margin-bottom:2px"><?= $label ?></div>
            <div style="font-size:12px;color:var(--n500);line-height:1.5"><?= $desc ?></div>
          </div>
        </div>
        <div style="flex-shrink:0">
          <!-- Hidden checkbox that form actually submits -->
          <input type="hidden" name="notif_<?= $key ?>" value="0">
          <label style="display:inline-flex;align-items:center;cursor:pointer;gap:10px">
            <input type="checkbox"
              name="notif_<?= $key ?>"
              value="1"
              id="cb-<?= $key ?>"
              <?= $isOn ? 'checked' : '' ?>
              style="position:absolute;opacity:0;width:0;height:0"
              onchange="kweekToggle('<?= $toggleId ?>','<?= $knobId ?>',this.checked)">
            <!-- Visual toggle -->
           <div id="<?= $toggleId ?>"
  style="width:44px;height:24px;border-radius:12px;background:<?= $isOn ? 'var(--p600)' : 'var(--n300)' ?>;transition:background .25s;position:relative;cursor:pointer">
  <div id="<?= $knobId ?>"
    style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .25s;transform:<?= $isOn ? 'translateX(20px)' : 'translateX(0)' ?>">
  </div>
</div>
            <span style="font-size:13px;font-weight:600;color:var(--n600);min-width:30px" id="label-<?= $key ?>"><?= $isOn ? 'On' : 'Off' ?></span>
          </label>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:24px;display:flex;gap:10px;flex-wrap:wrap">
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
    if (track) track.style.background = isOn ? 'var(--p600)' : 'var(--n300)';
    if (knob)  knob.style.transform   = isOn ? 'translateX(20px)' : 'translateX(0)';
    if (lbl)   lbl.textContent        = isOn ? 'On' : 'Off';
  }

  function toggleAll(isOn) {
    document.querySelectorAll('#notif-form input[type="checkbox"]').forEach(cb => {
      if (cb.checked !== isOn) cb.click();
    });
  }
  </script>
  <?php endif; ?>
</div>

<style>
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}
.form-input-wrap .form-input{padding-left:38px}
@media (max-width: 480px) {
  #plan-limits-grid { grid-template-columns: 1fr !important; }
}
 @media (max-width: 640px) {
  #upgrade { grid-template-columns: 1fr !important; }
}
@media (max-width: 680px) {
  #plan-limits-grid { grid-template-columns: repeat(2,1fr) !important; }
}
@media (max-width: 480px) {
  #plan-limits-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>