<?php
require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('manage_admins');

$pageTitle  = 'Admin Accounts';
$activePage = 'admins';
$db         = db();
$me         = currentAdmin();
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create_admin') {
        $name  = clean(post('name'));
        $email = strtolower(trim(post('email')));
        $role  = in_array(post('role'), ['super_admin','support','finance'], true) ? post('role') : 'support';
        $pass  = post('password');

        if (strlen($name) < 2) $error = 'Name is too short.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email.';
        elseif (strlen($pass) < 8) $error = 'Password must be at least 8 characters.';
        else {
            $chk = $db->prepare("SELECT id FROM admins WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'An admin with that email already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("INSERT INTO admins (name,email,password,role,is_active) VALUES (?,?,?,?,1)")
                   ->execute([$name, $email, $hash, $role]);
                flash('success', 'Admin account created for ' . $name . '.');
                redirect('/admin/admins.php');
            }
        }
    }

    elseif ($action === 'toggle_active') {
        $id = (int) post('admin_id');
        if ($id === (int) $me['id']) {
            flash('error', "You can't deactivate your own account.");
        } else {
            $stmt = $db->prepare("SELECT is_active FROM admins WHERE id = ?");
            $stmt->execute([$id]);
            $isActive = $stmt->fetchColumn();
            if ($isActive !== false) {
                $db->prepare("UPDATE admins SET is_active = ? WHERE id = ?")->execute([$isActive ? 0 : 1, $id]);
                flash('success', $isActive ? 'Admin deactivated.' : 'Admin reactivated.');
            }
        }
        redirect('/admin/admins.php');
    }

    elseif ($action === 'change_role') {
        $id   = (int) post('admin_id');
        $role = in_array(post('role'), ['super_admin','support','finance'], true) ? post('role') : null;
        if ($id === (int) $me['id']) {
            flash('error', "You can't change your own role.");
        } elseif ($role) {
            $db->prepare("UPDATE admins SET role = ? WHERE id = ?")->execute([$role, $id]);
            flash('success', 'Role updated.');
        }
        redirect('/admin/admins.php');
    }
}

$admins = $db->query("SELECT * FROM admins ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="detail-grid">

<div class="table-wrap">
  <div class="table-head"><span class="table-title"><?= count($admins) ?> Admin Account<?= count($admins)!==1?'s':'' ?></span></div>
  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
    <tr>
      <td class="td-bold"><?= htmlspecialchars($a['name']) ?><?= $a['id']===$me['id'] ? ' <span style="color:var(--muted);font-weight:400;font-size:12px">(you)</span>' : '' ?></td>
      <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($a['email']) ?></td>
      <td>
        <?php if ($a['id'] === $me['id']): ?>
          <span class="badge badge-purple"><?= htmlspecialchars(adminRoleLabel($a['role'])) ?></span>
        <?php else: ?>
        <form method="POST" style="display:inline-flex;gap:6px;align-items:center">
          <?= csrfField() ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
          <select name="role" class="form-select" style="min-width:120px;padding:6px 26px 6px 10px;font-size:12.5px" onchange="this.form.submit()">
            <?php foreach (['super_admin'=>'Super Admin','support'=>'Support','finance'=>'Finance'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= $a['role']===$val?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td><span class="badge badge-<?= $a['is_active']?'success':'neutral' ?>"><?= $a['is_active']?'Active':'Inactive' ?></span></td>
      <td style="font-size:12px;color:var(--muted)"><?= $a['last_login'] ? nigeriaTime($a['last_login']) : 'Never' ?></td>
      <td>
        <?php if ($a['id'] !== $me['id']): ?>
        <form method="POST" onsubmit="return confirm('<?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
          <?= csrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm" style="<?= $a['is_active'] ? 'color:var(--danger);border-color:var(--danger-bg)' : '' ?>"><?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="card-title" style="margin-bottom:4px">New Admin</div>
  <div class="card-sub" style="margin-bottom:16px">They'll be able to sign in at /admin/login.php immediately.</div>

  <?php if ($error): ?>
  <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:var(--r-lg);margin-bottom:14px;font-size:13px;background:var(--danger-bg);border:1px solid rgba(220,38,38,.2);color:var(--danger)">
    <i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST">
    <?= csrfField() ?><input type="hidden" name="action" value="create_admin">
    <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Temporary Password</label><input type="password" name="password" class="form-input" minlength="8" required></div>
    <div class="form-group">
      <label class="form-label">Role</label>
      <select name="role" class="form-select">
        <option value="support">Support — users, KYC review</option>
        <option value="finance">Finance — withdrawals, transactions, subscriptions</option>
        <option value="super_admin">Super Admin — everything</option>
      </select>
    </div>
    <button type="submit" class="btn btn-purple btn-md" style="width:100%;justify-content:center">Create Admin</button>
  </form>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
