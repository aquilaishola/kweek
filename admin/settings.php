<?php
require_once __DIR__ . '/includes/admin_functions.php';
require_once __DIR__ . '/../includes/currency.php'; // usdNgnRate() / setUsdNgnRate() — adjust path if yours lives elsewhere
requireAdminLogin();
requireAdminCapability('manage_settings');

$pageTitle  = 'Settings';
$activePage = 'settings';
$db         = db();
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'update_setting') {
        $key = post('key');
        $value = post('value');
        // Never let this screen touch the per-user notification-prefs rows —
        // those are managed by the merchant app itself, not hand-edited here.
        if (str_starts_with($key, 'notif_prefs_user_')) {
            $error = 'That key is managed automatically and can\'t be edited here.';
        } else {
            $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = ?")->execute([$value, $key]);
            flash('success', 'Updated "' . $key . '".');
            redirect('/admin/settings.php');
        }
    }

    elseif (in_array($action, ['update_usd_rate', 'update_ghs_rate'], true)) {
        $fxCur   = $action === 'update_ghs_rate' ? 'GHS' : 'USD';
        $newRate = (float) str_replace(',', '', (string) post('usd_rate'));

        // Who's making the change (recorded by setUsdNgnRate). Falls back to 0 if we can't tell.
        $adminId = 0;
        if (function_exists('currentAdmin') && ($a = currentAdmin())) {
            $adminId = (int) ($a['id'] ?? 0);
        } elseif (!empty($_SESSION['admin_id'])) {
            $adminId = (int) $_SESSION['admin_id'];
        }

        try {
            if (setNgnRate($fxCur, $newRate, $adminId)) {
                flash('success', $fxCur . ' rate saved. New ' . currencyNoun($fxCur) . ' orders will use ₦' . number_format($newRate, 2) . ' per ' . currencySymbol($fxCur) . '1.');
                redirect('/admin/settings.php');
            } else {
                $error = 'That rate doesn\'t look right. Enter the naira value of $1 (between 1 and 100,000).';
            }
        } catch (Throwable $e) {
            $error = 'Could not save the rate. Make sure migration-usd-rate.sql has been run.';
        }
    }

    elseif ($action === 'create_setting') {
        $key = trim(post('new_key'));
        $value = post('new_value');
        if ($key === '' || !preg_match('/^[a-z0-9_.\-]+$/i', $key)) {
            $error = 'Key must be letters, numbers, dots, dashes or underscores only.';
        } elseif (str_starts_with($key, 'notif_prefs_user_')) {
            $error = 'That key prefix is reserved.';
        } else {
            $chk = $db->prepare("SELECT id FROM settings WHERE `key` = ?");
            $chk->execute([$key]);
            if ($chk->fetch()) {
                $error = 'A setting with that key already exists.';
            } else {
                $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)")->execute([$key, $value]);
                flash('success', 'Created "' . $key . '".');
                redirect('/admin/settings.php');
            }
        }
    }

    elseif ($action === 'delete_setting') {
        $key = post('key');
        if (!str_starts_with($key, 'notif_prefs_user_')) {
            $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$key]);
            flash('success', 'Deleted "' . $key . '".');
        }
        redirect('/admin/settings.php');
    }
}

$rows = $db->query("
    SELECT * FROM settings
    WHERE `key` NOT LIKE 'notif\\_prefs\\_user\\_%'
    ORDER BY `key` ASC
")->fetchAll();

$hiddenCount = (int) $db->query("SELECT COUNT(*) FROM settings WHERE `key` LIKE 'notif\\_prefs\\_user\\_%'")->fetchColumn();

// USD → NGN rate (lives in platform_settings, read by the checkout flow via usdNgnRate())
$currentRate   = 0.0;
$rateUpdatedAt = null;
try {
    $currentRate = (float) usdNgnRate(true);
    $stmt = $db->prepare("SELECT updated_at FROM platform_settings WHERE setting_key = 'usd_ngn_rate' LIMIT 1");
    $stmt->execute();
    $rateUpdatedAt = $stmt->fetchColumn() ?: null;
} catch (Throwable $e) {
    $error = $error ?: 'The platform_settings table is missing. Run migration-usd-rate.sql first.';
}

$ghsRate = ngnRate('GHS', true);

include __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
<div style="display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:var(--r-lg);margin-bottom:20px;font-size:13px;background:var(--danger-bg);border:1px solid rgba(220,38,38,.2);color:var(--danger)">
  <i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="detail-grid">

<div class="table-wrap">
  <div class="table-head">
    <span class="table-title"><?= count($rows) ?> Setting<?= count($rows)!==1?'s':'' ?></span>
    <?php if ($hiddenCount > 0): ?><span style="font-size:12px;color:var(--muted)"><?= $hiddenCount ?> per-user notification rows hidden</span><?php endif; ?>
  </div>
  <?php if (empty($rows)): ?>
  <div class="empty-state"><div class="empty-title">No settings yet</div><div class="empty-sub">Add your first one on the right.</div></div>
  <?php else: ?>
  <div style="padding:16px;display:flex;flex-direction:column;gap:14px">
    <?php foreach ($rows as $s): ?>
    <form method="POST" style="border:1px solid var(--line);border-radius:var(--r-lg);padding:14px 16px;background:var(--surface)">
      <?= csrfField() ?><input type="hidden" name="action" value="update_setting"><input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
        <span style="font-family:var(--font-mono,monospace);font-size:13px;font-weight:700;color:var(--ink)"><?= htmlspecialchars($s['key']) ?></span>
        <span style="font-size:11px;color:var(--muted)">Updated <?= $s['updated_at'] ? timeAgo($s['updated_at']) : '—' ?></span>
      </div>
      <textarea name="value" class="form-textarea" rows="2" style="margin-bottom:8px"><?= htmlspecialchars($s['value'] ?? '') ?></textarea>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-purple btn-sm">Save</button>
        <button type="submit" form="del-<?= md5($s['key']) ?>" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger-bg)" onclick="return confirm('Delete \'<?= htmlspecialchars(addslashes($s['key'])) ?>\'?')">Delete</button>
      </div>
    </form>
    <form method="POST" id="del-<?= md5($s['key']) ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete_setting"><input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>"></form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div style="display:flex;flex-direction:column;gap:20px">

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Dollar Exchange Rate</div>
    <div class="card-sub" style="margin-bottom:16px">The rate merchants are credited at when a customer pays in dollars. It's locked onto each order when it's placed, so changing it never affects orders already in progress.</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_usd_rate">
      <div class="form-group">
        <label class="form-label">Naira for $1</label>
        <input type="text" name="usd_rate" class="form-input" inputmode="decimal" autocomplete="off" required
               placeholder="e.g. 1500.00"
               value="<?= $currentRate > 0 ? htmlspecialchars(number_format($currentRate, 2, '.', '')) : '' ?>">
      </div>
      <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center">Save Rate</button>
    </form>
    <div style="margin-top:14px;padding:12px 14px;border-radius:var(--r-lg);background:var(--surface);border:1px solid var(--line);font-size:12.5px;color:var(--muted);line-height:1.6">
      <?php if ($currentRate > 0): ?>
        Current: <strong style="color:var(--ink)">$1 = ₦<?= number_format($currentRate, 2) ?></strong><br>
        $100 credits <strong style="color:var(--ink)">₦<?= number_format($currentRate * 100, 2) ?></strong> before fees.
        <?php if ($rateUpdatedAt): ?><br>Last changed <?= timeAgo($rateUpdatedAt) ?><?php endif; ?>
      <?php else: ?>
        No rate set yet, so dollar links are switched off until you save one.
      <?php endif; ?>
    </div>
    <div style="margin-top:10px;font-size:11.5px;color:var(--muted);line-height:1.6">Tip: merchants get paid at this rate while the gateway converts at its own. Keeping yours slightly below the gateway's covers the gap and short-term movement.</div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">Cedi Exchange Rate</div>
    <div class="card-sub" style="margin-bottom:16px">Naira credited to merchants for each GH₵1 paid. Ghana cedi links stay switched off until you save a rate.</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_ghs_rate">
      <div class="form-group">
        <label class="form-label">Naira for GH₵1</label>
        <input type="text" name="usd_rate" class="form-input" inputmode="decimal" autocomplete="off" required
               placeholder="e.g. 100.00"
               value="<?= $ghsRate > 0 ? htmlspecialchars(number_format($ghsRate, 2, '.', '')) : '' ?>">
      </div>
      <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center">Save Rate</button>
    </form>
    <div style="margin-top:14px;font-size:12.5px;color:var(--muted);line-height:1.6">
      <?php if ($ghsRate > 0): ?>
        Current: <strong style="color:var(--ink)">GH₵1 = ₦<?= number_format($ghsRate, 2) ?></strong>
      <?php else: ?>
        No rate set yet, so cedi links are switched off.
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:4px">New Setting</div>
    <div class="card-sub" style="margin-bottom:16px">Free-form key/value — used wherever your code reads from the <code>settings</code> table.</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="create_setting">
      <div class="form-group">
        <label class="form-label">Key</label>
        <input type="text" name="new_key" class="form-input" placeholder="e.g. maintenance_mode" required>
      </div>
      <div class="form-group">
        <label class="form-label">Value</label>
        <textarea name="new_value" class="form-textarea" rows="3" placeholder="e.g. 0"></textarea>
      </div>
      <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center">Create Setting</button>
    </form>
  </div>

</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>