<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pageTitle  = 'Platform Settings';
$activePage = 'settings';
$db         = db();

// Get all settings
$allSettings = [];
$rows = $db->query("SELECT `key`, `value` FROM settings")->fetchAll();
foreach ($rows as $r) $allSettings[$r['key']] = $r['value'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $fields = [
        'site_name', 'site_url', 'support_email',
        'free_tx_fee', 'pro_tx_fee', 'business_tx_fee',
        'withdrawal_fee', 'free_monthly_cap', 'pro_monthly_cap',
        'maintenance_mode', 'new_signups',
    ];
    foreach ($fields as $key) {
        $val = clean(post($key));
        $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$key,$val,$val]);
    }
    flash('success', 'Settings saved successfully.');
    redirect('/admin/settings.php');
}

$s = $allSettings; // shorthand
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">
  <div style="margin-bottom:20px">
    <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Platform Settings</h1>
    <p style="font-size:13px;color:var(--n500);margin-top:2px">Control all Kweek platform configuration from here.</p>
  </div>

  <form method="POST">
    <?= csrfField() ?>

    <!-- GENERAL -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-title" style="margin-bottom:16px">General</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Site Name</label>
          <input type="text" name="site_name" class="form-input" value="<?= htmlspecialchars($s['site_name']??'Kweek') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Site URL</label>
          <input type="url" name="site_url" class="form-input" value="<?= htmlspecialchars($s['site_url']??'https://kweek.ng') ?>">
        </div>
      </div>
      <div class="form-group" style="margin-top:14px;margin-bottom:0">
        <label class="form-label">Support Email</label>
        <input type="email" name="support_email" class="form-input" value="<?= htmlspecialchars($s['support_email']??'support@kweek.ng') ?>">
      </div>
    </div>

    <!-- FEES -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-title" style="margin-bottom:4px">Transaction Fees</div>
      <div class="card-sub" style="margin-bottom:16px">Percentage fee deducted from each payment before crediting merchant wallet.</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        <?php foreach ([
          ['free_tx_fee',     'Free Plan (%)',     $s['free_tx_fee']     ?? '1.5'],
          ['pro_tx_fee',      'Pro Plan (%)',      $s['pro_tx_fee']      ?? '0.8'],
          ['business_tx_fee', 'Business Plan (%)', $s['business_tx_fee'] ?? '0.5'],
        ] as [$name, $label, $val]): ?>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label"><?= $label ?></label>
          <input type="number" name="<?= $name ?>" class="form-input" value="<?= htmlspecialchars($val) ?>" min="0" max="10" step="0.1">
        </div>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin-top:14px;margin-bottom:0;max-width:220px">
        <label class="form-label">Withdrawal Fee (₦ flat)</label>
        <input type="number" name="withdrawal_fee" class="form-input" value="<?= htmlspecialchars($s['withdrawal_fee']??'100') ?>" min="0" step="1">
      </div>
    </div>

    <!-- CAPS -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-title" style="margin-bottom:4px">Monthly Transaction Caps</div>
      <div class="card-sub" style="margin-bottom:16px">Maximum total payments a merchant can receive per month on each plan (₦).</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Free Plan Cap (₦)</label>
          <input type="number" name="free_monthly_cap" class="form-input" value="<?= htmlspecialchars($s['free_monthly_cap']??'200000') ?>" min="0">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Pro Plan Cap (₦)</label>
          <input type="number" name="pro_monthly_cap" class="form-input" value="<?= htmlspecialchars($s['pro_monthly_cap']??'5000000') ?>" min="0">
        </div>
      </div>
    </div>

    <!-- TOGGLES -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-title" style="margin-bottom:16px">Platform Controls</div>
      <?php foreach ([
        ['maintenance_mode', 'Maintenance Mode',   'Put the entire platform in maintenance mode. All pages will show a maintenance message to merchants and customers.', 'danger'],
        ['new_signups',      'Allow New Signups',  'Enable or disable new merchant registrations. Disable this to pause growth temporarily.', 'success'],
      ] as [$key, $label, $desc, $color]): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--n50);border-radius:var(--r-lg);border:1px solid var(--n200);margin-bottom:10px;gap:16px">
        <div style="flex:1">
          <div style="font-size:14px;font-weight:700;color:var(--n900)"><?= $label ?></div>
          <div style="font-size:12px;color:var(--n500);margin-top:3px;line-height:1.5"><?= $desc ?></div>
        </div>
        <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0">
          <?php $isOn = ($s[$key]??'0') === '1'; ?>
          <input type="hidden" name="<?= $key ?>" value="0">
          <input type="checkbox" name="<?= $key ?>" value="1" <?= $isOn?'checked':'' ?>
            style="position:absolute;opacity:0;width:0;height:0"
            onchange="const t=this.nextElementSibling;t.style.background=this.checked?'var(--<?= $color ?>)':'var(--n300)';t.querySelector('div').style.transform=this.checked?'translateX(20px)':'translateX(0)'">
          <div style="width:44px;height:24px;border-radius:12px;background:<?= $isOn?'var(--'.$color.')':'var(--n300)' ?>;transition:background .2s;position:relative">
            <div style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s;transform:<?= $isOn?'translateX(20px)':'translateX(0)' ?>"></div>
          </div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- API KEYS INFO -->
    <div class="card" style="margin-bottom:18px;border-color:var(--p100);background:var(--p50)">
      <div class="card-title" style="margin-bottom:8px;color:var(--p800)">API Configuration</div>
      <div style="font-size:13px;color:var(--p700);line-height:1.6">
        Nomba API credentials are configured in <code style="background:var(--p100);padding:2px 6px;border-radius:4px;font-size:12px">config/db.php</code>.
        Current environment: <strong><?= strtoupper(NOMBA_ENV) ?></strong>.
        To switch to live, change <code style="background:var(--p100);padding:2px 6px;border-radius:4px;font-size:12px">NOMBA_ENV</code> from <code>test</code> to <code>live</code>.
      </div>
      <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px">
        <div style="background:var(--n0);border-radius:var(--r-md);padding:10px 12px;border:1px solid var(--p100)">
          <div style="color:var(--n500);margin-bottom:3px">Parent Account ID</div>
          <div style="font-family:monospace;font-weight:600;color:var(--n800);font-size:11px"><?= htmlspecialchars(substr(NOMBA_ACCOUNT_ID,0,20))?>...</div>
        </div>
        <div style="background:var(--n0);border-radius:var(--r-md);padding:10px 12px;border:1px solid var(--p100)">
          <div style="color:var(--n500);margin-bottom:3px">Sub Account ID</div>
          <div style="font-family:monospace;font-weight:600;color:var(--n800);font-size:11px"><?= htmlspecialchars(substr(NOMBA_SUB_ACCOUNT_ID,0,20))?>...</div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-purple btn-md" style="min-width:160px">
      <i class="ti ti-check"></i> Save All Settings
    </button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
