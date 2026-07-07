<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Edit Payment Link';
$activePage = 'links';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

$linkId = (int)get('id');

// Fetch link (verify ownership)
$stmt = $db->prepare("SELECT * FROM payment_links WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$linkId, $uid]);
$link = $stmt->fetch();

if (!$link) {
    flash('error', 'Payment link not found.');
    redirect('/payment-links.php');
}

// Fetch existing items
$itemStmt = $db->prepare("SELECT * FROM link_items WHERE link_id = ? ORDER BY sort_order, id");
$itemStmt->execute([$linkId]);
$existingItems = $itemStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = clean(post('title'));
    $description = clean(post('description'));
    $amount      = post('amount') ? abs((float)post('amount')) : null;
    $requireConf = !empty($_POST['require_confirmation']) ? 1 : 0;
    $redirectUrl = filter_var(post('redirect_url'), FILTER_VALIDATE_URL) ? post('redirect_url') : null;
    $accentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', post('accent_color')) ? post('accent_color') : '#4F43D4';
    $status      = in_array(post('status'), ['active','paused']) ? post('status') : 'active';
    $expiresAt   = post('expires_at') ? date('Y-m-d H:i:s', strtotime(post('expires_at'))) : null;

    // Delivery zones
    $zoneNames = $_POST['zone_name'] ?? [];
    $zoneFees  = $_POST['zone_fee']  ?? [];
    $zones = [];
    foreach ($zoneNames as $i => $name) {
        if (trim($name)) $zones[] = ['name' => clean($name), 'fee' => abs((float)($zoneFees[$i] ?? 0))];
    }

    // Menu items
    $itemIds    = $_POST['item_id']    ?? [];
    $itemNames  = $_POST['item_name']  ?? [];
    $itemPrices = $_POST['item_price'] ?? [];
    $itemDescs  = $_POST['item_desc']  ?? [];
    $itemAvail  = $_POST['item_avail'] ?? [];
    $newItems   = [];
    foreach ($itemNames as $i => $name) {
        if (trim($name) && isset($itemPrices[$i]) && $itemPrices[$i] > 0) {
            $newItems[] = [
                'id'          => (int)($itemIds[$i] ?? 0),
                'name'        => clean($name),
                'price'       => abs((float)$itemPrices[$i]),
                'description' => clean($itemDescs[$i] ?? ''),
                'is_available'=> isset($itemAvail[$i]) ? 1 : 0,
            ];
        }
    }

    if (strlen($title) < 3) $errors[] = 'Title must be at least 3 characters.';

    if (empty($errors)) {
        // Update main link
        $db->prepare("UPDATE payment_links SET title=?,description=?,amount=?,require_confirmation=?,redirect_url=?,delivery_zones=?,accent_color=?,status=?,expires_at=?,updated_at=NOW() WHERE id=?")
           ->execute([
               $title, $description, $amount, $requireConf, $redirectUrl,
               !empty($zones) ? json_encode($zones) : null,
               $accentColor, $status, $expiresAt, $linkId
           ]);

        // Delete all old items and re-insert
        if (in_array($link['link_type'], ['menu', 'group'])) {
            $db->prepare("DELETE FROM link_items WHERE link_id = ?")->execute([$linkId]);
            foreach ($newItems as $idx => $item) {
                $db->prepare("INSERT INTO link_items (link_id, name, description, price, is_available, sort_order) VALUES (?,?,?,?,?,?)")
                   ->execute([$linkId, $item['name'], $item['description'], $item['price'], $item['is_available'], $idx]);
            }
        }

        flash('success', 'Payment link updated successfully.');
        redirect('/payment-links.php');
    }
}

$zones        = $link['delivery_zones'] ? json_decode($link['delivery_zones'], true) : [];
$existingZones = $zones;

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">
  <div style="margin-bottom:20px">
    <a href="/payment-links.php" style="display:inline-flex;align-items:center;gap:5px;font-size:13px;color:var(--n500);text-decoration:none;font-weight:500;margin-bottom:10px">
      <i class="ti ti-arrow-left"></i> Back to links
    </a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--n900);letter-spacing:-.3px">Edit Link</h1>
        <p style="font-size:13px;color:var(--n500);margin-top:2px"><?= SITE_URL ?>/pay/<?= htmlspecialchars($link['slug']) ?></p>
      </div>
      <a href="<?= SITE_URL ?>/pay/<?= htmlspecialchars($link['slug']) ?>" target="_blank" class="btn btn-outline btn-sm">
        <i class="ti ti-external-link"></i> Preview
      </a>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
  <div style="background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:12px 16px;margin-bottom:16px">
    <?php foreach ($errors as $e): ?>
    <p style="font-size:13px;color:var(--danger);display:flex;align-items:center;gap:5px"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="edit-form">
    <?= csrfField() ?>

    <!-- STATUS & BASIC INFO -->
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <div class="card-title">Basic Information</div>
        <select name="status" style="font-family:var(--font-body);font-size:13px;font-weight:600;border:1.5px solid var(--n200);border-radius:var(--r-md);padding:7px 12px;background:var(--n0);cursor:pointer;outline:none">
          <option value="active" <?= $link['status']==='active'?'selected':'' ?>>Active</option>
          <option value="paused" <?= $link['status']==='paused'?'selected':'' ?>>Paused</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Link Title</label>
        <input type="text" name="title" class="form-input" value="<?= htmlspecialchars($link['title']) ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Description <span style="color:var(--n400);font-weight:400">(optional)</span></label>
        <textarea name="description" class="form-textarea"><?= htmlspecialchars($link['description'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- AMOUNT (simple links only) -->
    <?php if ($link['link_type'] === 'simple'): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:14px">Payment Amount</div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Amount (₦)</label>
        <div class="form-input-wrap">
          <i class="ti ti-currency-naira inp-icon"></i>
          <input type="number" name="amount" class="form-input" value="<?= htmlspecialchars($link['amount'] ?? '') ?>" min="100" step="0.01" placeholder="Leave empty to let customer enter amount">
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- MENU ITEMS (menu/group links) -->
    <?php if (in_array($link['link_type'], ['menu', 'group'])): ?>
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div class="card-title">Menu Items</div>
        <button type="button" onclick="addItem()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Item</button>
      </div>
      <div id="items-container">
        <?php foreach ($existingItems as $idx => $item): ?>
        <div class="menu-item-row" style="background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:14px;margin-bottom:10px">
          <input type="hidden" name="item_id[]" value="<?= $item['id'] ?>">
          <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;margin-bottom:8px">
            <input type="text" name="item_name[]" class="form-input" placeholder="Item name" value="<?= htmlspecialchars($item['name']) ?>" required>
            <div class="form-input-wrap">
              <i class="ti ti-currency-naira inp-icon" style="top:12px;transform:none"></i>
              <input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01" value="<?= htmlspecialchars($item['price']) ?>">
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="text" name="item_desc[]" class="form-input" style="flex:1" placeholder="Description (optional)" value="<?= htmlspecialchars($item['description'] ?? '') ?>">
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--n600);cursor:pointer;white-space:nowrap">
              <input type="checkbox" name="item_avail[<?= $idx ?>]" value="1" <?= $item['is_available']?'checked':'' ?> style="accent-color:var(--p600)"> Available
            </label>
            <button type="button" onclick="this.closest('.menu-item-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="ti ti-trash"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- DELIVERY ZONES -->
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div>
          <div class="card-title">Delivery Zones</div>
          <div class="card-sub" style="margin-top:2px">Auto-added to checkout total</div>
        </div>
        <button type="button" onclick="addZone()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add</button>
      </div>
      <div id="zones-container">
        <?php foreach ($existingZones as $z): ?>
        <div class="zone-row" style="display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center">
          <input type="text" name="zone_name[]" class="form-input" value="<?= htmlspecialchars($z['name']) ?>" placeholder="Zone name">
          <div class="form-input-wrap">
            <i class="ti ti-currency-naira inp-icon"></i>
            <input type="number" name="zone_fee[]" class="form-input" value="<?= htmlspecialchars($z['fee']) ?>" placeholder="Fee" min="0">
          </div>
          <button type="button" onclick="this.closest('.zone-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center">
            <i class="ti ti-trash"></i>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ADVANCED -->
    <div class="card" style="margin-bottom:16px">
      <details <?= $link['require_confirmation'] || $link['redirect_url'] || $link['expires_at'] ? 'open' : '' ?>>
        <summary style="font-family:var(--font-display);font-size:14px;font-weight:700;color:var(--n900);cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between">
          Advanced Settings <i class="ti ti-chevron-down" style="font-size:16px;color:var(--n500)"></i>
        </summary>
        <div style="margin-top:16px;display:flex;flex-direction:column;gap:14px">
          <!-- Order confirmation -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:var(--n50);border-radius:var(--r-lg);border:1px solid var(--n200)">
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--n900)">Require order confirmation</div>
              <div style="font-size:12px;color:var(--n500);margin-top:2px">Review orders before customers pay</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:12px">
              <input type="checkbox" name="require_confirmation" value="1" <?= $link['require_confirmation']?'checked':'' ?>
                style="position:absolute;opacity:0;width:0;height:0"
                onchange="const k=this.nextElementSibling;k.style.background=this.checked?'var(--p600)':'var(--n300)';k.querySelector('div').style.transform=this.checked?'translateX(20px)':'translateX(0)'">
              <div style="width:44px;height:24px;border-radius:12px;background:<?= $link['require_confirmation']?'var(--p600)':'var(--n300)' ?>;transition:background .2s;position:relative">
                <div style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s;transform:<?= $link['require_confirmation']?'translateX(20px)':'translateX(0)' ?>"></div>
              </div>
            </label>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Redirect URL after payment</label>
            <input type="url" name="redirect_url" class="form-input" value="<?= htmlspecialchars($link['redirect_url'] ?? '') ?>" placeholder="https://yoursite.com/thank-you">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Link expiry</label>
            <input type="datetime-local" name="expires_at" class="form-input" value="<?= $link['expires_at'] ? date('Y-m-d\TH:i', strtotime($link['expires_at'])) : '' ?>">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Accent Color</label>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="color" name="accent_color" value="<?= htmlspecialchars($link['accent_color'] ?? '#4F43D4') ?>" style="width:44px;height:40px;border-radius:var(--r-md);border:1.5px solid var(--n200);padding:2px;cursor:pointer">
              <span style="font-size:13px;color:var(--n500)">Customer checkout page accent color</span>
            </div>
          </div>
        </div>
      </details>
    </div>

    <div style="display:flex;gap:10px">
      <a href="/payment-links.php" class="btn btn-outline btn-md">Cancel</a>
      <button type="submit" class="btn btn-purple btn-md" style="flex:1;justify-content:center">
        <i class="ti ti-check"></i> Save Changes
      </button>
    </div>
  </form>
</div>

<style>
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--n400);pointer-events:none}
.form-input-wrap .form-input{padding-left:34px}
.form-textarea{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:10px 12px;outline:none;transition:all .2s;width:100%;resize:vertical;min-height:80px}
.form-textarea:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 3px var(--p50)}
</style>
<script>
function addItem() {
  const c = document.getElementById('items-container');
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:14px;margin-bottom:10px';
  row.innerHTML = `<input type="hidden" name="item_id[]" value="0">
  <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;margin-bottom:8px">
    <input type="text" name="item_name[]" class="form-input" placeholder="Item name" required>
    <div class="form-input-wrap"><i class="ti ti-currency-naira inp-icon" style="top:12px;transform:none"></i><input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01"></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <input type="text" name="item_desc[]" class="form-input" style="flex:1" placeholder="Description (optional)">
    <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--n600);cursor:pointer;white-space:nowrap"><input type="checkbox" name="item_avail[]" value="1" checked style="accent-color:var(--p600)"> Available</label>
    <button type="button" onclick="this.closest('.menu-item-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ti ti-trash"></i></button>
  </div>`;
  c.appendChild(row);
}

function addZone() {
  const c = document.getElementById('zones-container');
  const row = document.createElement('div');
  row.className = 'zone-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center';
  row.innerHTML = `<input type="text" name="zone_name[]" class="form-input" placeholder="Zone name"><div class="form-input-wrap"><i class="ti ti-currency-naira inp-icon"></i><input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0"></div><button type="button" onclick="this.closest('.zone-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="ti ti-trash"></i></button>`;
  c.appendChild(row);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
