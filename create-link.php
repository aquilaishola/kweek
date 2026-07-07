<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle  = 'Create Payment Link';
$activePage = 'links';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();

// Plan check
$limit = linkLimitFor($user['plan']);
if ($limit !== PHP_INT_MAX) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status='active'");
    $countStmt->execute([$uid]);
    $activeCount = (int)$countStmt->fetchColumn();

    if ($activeCount >= $limit) {
        $nextPlan = $user['plan'] === 'free' ? 'Starter' : ($user['plan'] === 'starter' ? 'Pro' : 'Business');
        flash('error', ucfirst($user['plan']) . " plan allows only $limit active payment link" . ($limit > 1 ? 's' : '') . ". Upgrade to $nextPlan for more.");
        redirect('/payment-links.php');
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = clean(post('title'));
    $description = clean(post('description'));
    $linkType    = in_array(post('link_type'),['simple','menu','installment','group'])?post('link_type'):'simple';
    $amount      = post('amount') ? abs((float)post('amount')) : null;
    
    $amount = null;

if ($linkType === 'simple') {
    $amount = abs((float)post('simple_amount'));
}

if ($linkType === 'installment') {
    $amount = abs((float)post('installment_amount'));
}

    $requireConf = !empty($_POST['require_confirmation']) ? 1 : 0;
    $redirectUrl = filter_var(post('redirect_url'),FILTER_VALIDATE_URL) ? post('redirect_url') : null;
    $accentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', post('accent_color')) ? post('accent_color') : '#4F43D4';
    $expiresAt   = post('expires_at') ? date('Y-m-d H:i:s', strtotime(post('expires_at'))) : null;

    // Delivery zones
    $zoneNames  = $_POST['zone_name']  ?? [];
    $zoneFees   = $_POST['zone_fee']   ?? [];
    $zones = [];
    foreach ($zoneNames as $i => $name) {
        if (trim($name)) $zones[] = ['name'=>clean($name),'fee'=>abs((float)($zoneFees[$i]??0))];
    }

    // Menu items
    $itemNames  = $_POST['item_name']  ?? [];
    $itemPrices = $_POST['item_price'] ?? [];
    $itemDescs  = $_POST['item_desc']  ?? [];
    $items = [];
    foreach ($itemNames as $i => $name) {
        if (trim($name) && isset($itemPrices[$i]) && $itemPrices[$i] > 0) {
            $items[] = ['name'=>clean($name),'price'=>abs((float)$itemPrices[$i]),'description'=>clean($itemDescs[$i]??'')];
        }
    }

    // Installment config
    $installmentCount  = max(2, (int)post('installment_count', 2));
    $installmentConfig = null;
    if ($linkType === 'installment' && $amount > 0) {
        $perInstallment = round($amount / $installmentCount, 2);
        $installmentConfig = ['count'=>$installmentCount,'per_installment'=>$perInstallment,'total'=>$amount];
    }

    // Validate
    if (strlen($title) < 3) $errors[] = 'Link title must be at least 3 characters.';
    if ($linkType === 'simple' && empty($amount)) $errors[] = 'Enter an amount for a simple payment link.';
    if ($linkType === 'menu' && empty($items)) $errors[] = 'Add at least one menu item.';
    if ($linkType === 'installment' && empty($amount)) $errors[] = 'Enter the total amount for installment plan.';

    if (empty($errors)) {
        $slug = generateSlug($title);

        $db->prepare("INSERT INTO payment_links (user_id,title,slug,description,link_type,amount,require_confirmation,redirect_url,delivery_zones,installment_config,accent_color,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$uid,$title,$slug,$description,$linkType,$amount,$requireConf,$redirectUrl,
                      !empty($zones)?json_encode($zones):null,
                      $installmentConfig?json_encode($installmentConfig):null,
                      $accentColor,$expiresAt]);

        $linkId = (int)$db->lastInsertId();

        // Insert menu items
        foreach ($items as $item) {
            $db->prepare("INSERT INTO link_items (link_id,name,description,price) VALUES (?,?,?,?)")
               ->execute([$linkId,$item['name'],$item['description'],$item['price']]);
        }

        flash('success','Payment link created! Share it on WhatsApp to start collecting payments.');
        redirect('/payment-links.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
    .type-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
    width:100%;
}

.type-opt{
    width:100%;
    box-sizing:border-box;
}

@media (max-width:768px){
    .type-grid{
        grid-template-columns:1fr;
    }
}
</style>
<div style="max-width:740px">
  <div style="margin-bottom:24px">
    <a href="/payment-links.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--n500);text-decoration:none;font-weight:500;margin-bottom:12px">
      <i class="ti ti-arrow-left"></i> Back to links
    </a>
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--n900);letter-spacing:-.5px">Create Payment Link</h1>
    <p style="font-size:14px;color:var(--n500);margin-top:4px">Fill in the details below and share your link on WhatsApp in seconds.</p>
  </div>

  <?php if (!empty($errors)): ?>
  <div style="background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:14px 16px;margin-bottom:20px">
    <?php foreach ($errors as $e): ?>
    <p style="font-size:13px;color:var(--danger);display:flex;align-items:center;gap:6px;margin-top:4px"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="create-form">
    <?= csrfField() ?>

    <!-- LINK TYPE -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-title" style="margin-bottom:16px">Link Type</div>
      <div id="type-grid" class="type-grid">
        <?php foreach ([
          ['simple','ti-currency-naira','Simple Payment','Fixed amount, one-time payment link.'],
          ['menu','ti-list','Menu / Products','Multiple items customers can choose from.'],
          ['installment','ti-credit-card-pay','Installment Plan','Split a total into scheduled payments.'],
          ['group','ti-users-group','Group Order','Multiple people pay individually via one link.'],
        ] as [$val,$icon,$label,$desc]): ?>
        <label class="type-opt <?= post('link_type','simple')===$val?'selected':'' ?>" onclick="selectType(this,'<?= $val ?>')">
          <input type="radio" name="link_type" value="<?= $val ?>" <?= post('link_type','simple')===$val?'checked':'' ?> style="position:absolute;opacity:0">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="width:40px;height:40px;border-radius:var(--r-md);background:var(--p50);display:flex;align-items:center;justify-content:center;color:var(--p600);font-size:20px;flex-shrink:0;border:1px solid var(--p100)"><i class="ti <?= $icon ?>"></i></div>
            <div>
              <div style="font-size:14px;font-weight:700;color:var(--n900);margin-bottom:3px"><?= $label ?></div>
              <div style="font-size:12px;color:var(--n500);line-height:1.5"><?= $desc ?></div>
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- BASIC INFO -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-title" style="margin-bottom:16px">Basic Information</div>
      <div class="form-group">
        <label class="form-label">Link Title <span style="color:var(--danger)">*</span></label>
        <div class="form-input-wrap">
          <i class="ti ti-tag inp-icon"></i>
          <input type="text" name="title" class="form-input" placeholder="e.g. Jollof Rice Order, Solar Panel Payment" value="<?= htmlspecialchars(post('title')) ?>" required oninput="updateSlugPreview(this.value)">
        </div>
        <div class="form-hint">Your link will be: <strong id="slug-preview"><?= SITE_URL ?>/pay/your-link-title</strong></div>
      </div>
      <div class="form-group">
        <label class="form-label">Description <span style="color:var(--n400);font-weight:400">(optional)</span></label>
        <textarea name="description" class="form-textarea" placeholder="Tell customers what this payment is for..."><?= htmlspecialchars(post('description')) ?></textarea>
      </div>
    </div>

    <!-- SIMPLE AMOUNT -->
    <div class="card type-section" id="section-simple" style="margin-bottom:20px;<?= post('link_type','simple')!=='simple'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:16px">Payment Amount</div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Amount (₦) <span style="color:var(--danger)">*</span></label>
        <div class="form-input-wrap">
          <i class="ti ti-currency-naira inp-icon"></i>
          <input type="number"  name="simple_amount"class="form-input" placeholder="0.00" min="100" step="0.01" value="<?= htmlspecialchars(post('amount')) ?>">
        </div>
        <div class="form-hint">Minimum amount: ₦100. Leave empty to let customers enter their own amount.</div>
      </div>
    </div>

    <!-- MENU ITEMS -->
    <div class="card type-section" id="section-menu" style="margin-bottom:20px;<?= post('link_type')!=='menu'?'display:none':'' ?>">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <div class="card-title">Menu Items</div>
        <button type="button" onclick="addItem()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Item</button>
      </div>
      <div id="items-container">
        <div class="menu-item-row" style="display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start">
          <div>
            <input type="text" name="item_name[]" class="form-input" placeholder="Item name (e.g. Jollof Rice)" style="margin-bottom:6px">
            <input type="text" name="item_desc[]" class="form-input" placeholder="Description (optional)">
          </div>
          <div class="form-input-wrap">
            <i class="ti ti-currency-naira inp-icon" style="top:14px;transform:none"></i>
            <input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01">
          </div>
          <button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger);margin-top:0"><i class="ti ti-trash"></i></button>
        </div>
      </div>
    </div>

    <!-- INSTALLMENT -->
    <div class="card type-section" id="section-installment" style="margin-bottom:20px;<?= post('link_type')!=='installment'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:16px">Installment Configuration</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Total Amount (₦)</label>
          <div class="form-input-wrap">
            <i class="ti ti-currency-naira inp-icon"></i>
            <input type="number" name="installment_amount" class="form-input" placeholder="Total e.g. 350000" min="100" step="0.01">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Number of Installments</label>
          <select name="installment_count" class="form-select">
            <option value="2">2 payments</option>
            <option value="3">3 payments</option>
            <option value="4">4 payments</option>
            <option value="6">6 payments</option>
            <option value="12">12 payments</option>
          </select>
        </div>
      </div>
    </div>

    <!-- GROUP ORDER: same as menu for items -->
    <div class="card type-section" id="section-group" style="margin-bottom:20px;<?= post('link_type')!=='group'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:4px">Group Order</div>
      <p style="font-size:13px;color:var(--n500);margin-bottom:16px">Add items below. Each person in the group selects what they want and pays individually.</p>
      <div id="group-items-container">
        <div class="menu-item-row" style="display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start">
          <input type="text" name="item_name[]" class="form-input" placeholder="Item name">
          <div class="form-input-wrap">
            <i class="ti ti-currency-naira inp-icon"></i>
            <input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0">
          </div>
          <button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      <button type="button" onclick="addGroupItem()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Item</button>
    </div>

    <!-- DELIVERY ZONES -->
    <div class="card" style="margin-bottom:20px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div>
          <div class="card-title">Delivery Zones <span style="font-size:12px;font-weight:400;color:var(--n500)">(optional)</span></div>
          <div class="card-sub" style="margin-top:3px">Customers pick their zone at checkout and fee is added automatically.</div>
        </div>
        <button type="button" onclick="addZone()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Zone</button>
      </div>
      <div id="zones-container">
        <div class="zone-row" style="display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center">
          <input type="text" name="zone_name[]" class="form-input" placeholder="Zone name (e.g. Surulere)">
          <div class="form-input-wrap">
            <i class="ti ti-currency-naira inp-icon"></i>
            <input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0">
          </div>
          <button type="button" onclick="removeZone(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
        </div>
      </div>
    </div>

    <!-- ADVANCED SETTINGS -->
    <div class="card" style="margin-bottom:20px">
      <details>
        <summary style="font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--n900);cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between">
          Advanced Settings <i class="ti ti-chevron-down" style="font-size:18px;color:var(--n500)"></i>
        </summary>
        <div style="margin-top:20px;display:flex;flex-direction:column;gap:18px">
          <!-- Order confirmation toggle -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--n50);border-radius:var(--r-lg);border:1px solid var(--n200)">
            <div>
              <div style="font-size:14px;font-weight:600;color:var(--n900)">Require order confirmation</div>
              <div style="font-size:12px;color:var(--n500);margin-top:2px">You review and accept each order before the customer pays. Prevents payment for out-of-stock items.</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:16px">
              <input type="checkbox" name="require_confirmation" value="1" <?= post('require_confirmation')?'checked':'' ?> style="position:absolute;opacity:0;width:0;height:0" id="conf-toggle" onchange="document.getElementById('conf-label').style.background=this.checked?'var(--p600)':'var(--n300)'">
              <div id="conf-label" style="width:44px;height:24px;border-radius:12px;background:<?= post('require_confirmation')?'var(--p600)':'var(--n300)' ?>;transition:background .2s;position:relative">
                <div style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s" id="conf-knob"></div>
              </div>
            </label>
          </div>
          <!-- Redirect URL -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Redirect URL after payment <span style="color:var(--n400);font-weight:400">(optional)</span></label>
            <div class="form-input-wrap">
              <i class="ti ti-link inp-icon"></i>
              <input type="url" name="redirect_url" class="form-input" placeholder="https://yoursite.com/thank-you" value="<?= htmlspecialchars(post('redirect_url')) ?>">
            </div>
          </div>
          <!-- Expiry -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Link expiry date <span style="color:var(--n400);font-weight:400">(optional)</span></label>
            <div class="form-input-wrap">
              <i class="ti ti-calendar inp-icon"></i>
              <input type="datetime-local" name="expires_at" class="form-input" value="<?= htmlspecialchars(post('expires_at')) ?>">
            </div>
          </div>
          <!-- Accent color -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Accent Color</label>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="color" name="accent_color" value="<?= htmlspecialchars(post('accent_color','#4F43D4')) ?>" style="width:44px;height:44px;border-radius:var(--r-md);border:1.5px solid var(--n200);cursor:pointer;padding:3px">
              <span style="font-size:13px;color:var(--n500)">Customize your payment page accent color</span>
            </div>
          </div>
        </div>
      </details>
    </div>

    <!-- SUBMIT -->
    <div style="display:flex;gap:10px">
      <a href="/payment-links.php" class="btn btn-outline btn-lg">Cancel</a>
      <button type="submit" class="btn btn-purple btn-lg" id="submit-btn" style="flex:1;justify-content:center">
        <i class="ti ti-link"></i> Create Payment Link
      </button>
    </div>
  </form>
</div>

<style>
.type-opt{border:1.5px solid var(--n200);border-radius:var(--r-lg);padding:16px;cursor:pointer;transition:all .2s;position:relative}
.type-opt:hover{border-color:var(--p300)}
.type-opt.selected{border-color:var(--p600);background:var(--p50)}
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--n400);pointer-events:none}
.form-input-wrap .form-input{padding-left:38px}
.form-textarea{font-family:var(--font-body);font-size:14px;color:var(--n800);background:var(--n50);border:1.5px solid var(--n200);border-radius:var(--r-md);padding:11px 14px;outline:none;transition:all .2s;width:100%;resize:vertical;min-height:88px}
.form-textarea:focus{background:var(--n0);border-color:var(--p500);box-shadow:0 0 0 4px var(--p50)}
</style>
<script>
function selectType(el, type) {
    document.querySelectorAll('.type-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input').checked = true;

    document.querySelectorAll('.type-section').forEach(section => {
        section.style.display = 'none';

        section.querySelectorAll('input,select,textarea').forEach(input => {
            input.disabled = true;
        });
    });

    const active = document.getElementById('section-' + type);

    if (active) {
        active.style.display = 'block';

        active.querySelectorAll('input,select,textarea').forEach(input => {
            input.disabled = false;
        });
    }
}

function updateSlugPreview(val) {
  const slug = val.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  document.getElementById('slug-preview').textContent = '<?= SITE_URL ?>/pay/' + (slug||'your-link-title');
}

function addItem() {
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start';
  row.innerHTML = `<div><input type="text" name="item_name[]" class="form-input" placeholder="Item name" style="margin-bottom:6px"><input type="text" name="item_desc[]" class="form-input" placeholder="Description (optional)"></div><div class="form-input-wrap"><i class="ti ti-currency-naira inp-icon" style="top:14px;transform:none"></i><input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01"></div><button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger);margin-top:0"><i class="ti ti-trash"></i></button>`;
  document.getElementById('items-container').appendChild(row);
}

function addGroupItem() {
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:center';
  row.innerHTML = `<input type="text" name="item_name[]" class="form-input" placeholder="Item name"><div class="form-input-wrap"><i class="ti ti-currency-naira inp-icon"></i><input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0"></div><button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>`;
  document.getElementById('group-items-container').appendChild(row);
}

function removeItem(btn) {
  const rows = btn.closest('[id$="-container"]').querySelectorAll('.menu-item-row');
  if (rows.length > 1) btn.closest('.menu-item-row').remove();
  else showToast('info','Keep at least one','You need at least one item.');
}

function addZone() {
  const row = document.createElement('div');
  row.className = 'zone-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center';
  row.innerHTML = `<input type="text" name="zone_name[]" class="form-input" placeholder="Zone name"><div class="form-input-wrap"><i class="ti ti-currency-naira inp-icon"></i><input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0"></div><button type="button" onclick="removeZone(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>`;
  document.getElementById('zones-container').appendChild(row);
}

function removeZone(btn) { btn.closest('.zone-row').remove(); }

// Toggle knob
document.getElementById('conf-toggle').addEventListener('change', function() {
  document.getElementById('conf-knob').style.transform = this.checked ? 'translateX(20px)' : 'translateX(0)';
});

document.getElementById('create-form').addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  btn.innerHTML = '<div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite"></div> Creating...';
  btn.disabled = true;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
