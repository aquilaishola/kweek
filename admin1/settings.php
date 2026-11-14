<?php
$pageTitle = 'Platform Settings';
$activePage = 'settings';
require_once __DIR__ . '/includes/layout-top.php';

if ($admin['role'] !== 'super_admin') {
    flash('error', "Only super admins can access settings.");
    redirect('/admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    if ($action === 'save') {
        $key = clean(post('key'));
        $value = post('value');
        if ($key !== '') {
            $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
               ->execute([$key, $value]);
            flash('success', 'Setting saved.');
        }
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        $db->prepare("DELETE FROM settings WHERE id=?")->execute([$id]);
        flash('success', 'Setting removed.');
    }
    redirect('/admin/settings.php');
}

$settings = $db->query("SELECT * FROM settings ORDER BY `key` ASC")->fetchAll();
?>

<div class="card" style="margin-bottom:20px">
  <div style="font-weight:700;font-size:14px;margin-bottom:14px">Add / Update Setting</div>
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
    <?= csrfField() ?><input type="hidden" name="action" value="save">
    <input type="text" name="key" class="filter-input" placeholder="Key (e.g. maintenance_mode)" required style="flex:1;min-width:180px">
    <input type="text" name="value" class="filter-input" placeholder="Value" style="flex:2;min-width:220px">
    <button type="submit" class="btn btn-purple btn-sm"><i class="ti ti-device-floppy"></i> Save</button>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>Key</th><th>Value</th><th>Last Updated</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($settings)): ?>
      <tr class="empty-row"><td colspan="4">No custom settings configured yet.</td></tr>
      <?php else: foreach ($settings as $s): ?>
      <tr>
        <td class="mono td-bold"><?= htmlspecialchars($s['key']) ?></td>
        <td class="td-muted" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['value']) ?></td>
        <td class="td-muted"><?= timeAgo($s['updated_at']) ?></td>
        <td>
          <form method="POST" onsubmit="return confirm('Delete this setting?')">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="ti ti-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:20px;background:var(--p50);border-color:var(--p50)">
  <div style="display:flex;gap:10px;align-items:flex-start">
    <i class="ti ti-info-circle" style="color:var(--p600);font-size:18px;margin-top:1px"></i>
    <div style="font-size:12.5px;color:var(--n600);line-height:1.6">
      Fee percentages, plan caps, and withdrawal fees are defined as PHP constants in your config file (<span class="mono">FEE_FREE</span>, <span class="mono">CAP_STARTER</span>, etc.) — not here. This panel is for miscellaneous runtime flags stored in the <span class="mono">settings</span> table.
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>