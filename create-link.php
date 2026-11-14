<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/upload_helpers.php';
requireLogin();

$pageTitle  = 'Create Payment Link';
$activePage = 'payment-links';
$user       = currentUser();
$uid        = $user['id'];
$db         = db();
$isPaidPlan = ($user['plan'] ?? 'free') !== 'free';

function generateRandomSlug(PDO $db, int $length = 6): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $db->prepare("SELECT id FROM payment_links WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
    } while ($stmt->fetch());
    return $slug;
}

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

    // Currency is a per-link choice. Anything unexpected quietly becomes naira.
    $currency = in_array(post('currency'), supportedCurrencies(), true) ? post('currency') : 'NGN';
    $minAmt   = minAmountFor($currency);

    $amount = null;
    if ($linkType === 'simple') {
        // An empty amount is intentional: it lets the payer choose what to pay.
        $amount = abs((float)post('simple_amount'));
    }
    if ($linkType === 'installment') {
        $amount = abs((float)post('installment_amount'));
    }

    $requireConf = !empty($_POST['require_confirmation']) ? 1 : 0;
    $redirectUrl = filter_var(post('redirect_url'),FILTER_VALIDATE_URL) ? post('redirect_url') : null;
    $accentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', post('accent_color')) ? post('accent_color') : '#4F43D4';
    $expiresAt   = post('expires_at') ? date('Y-m-d H:i:s', strtotime(post('expires_at'))) : null;
    $chatEnabled = !empty($_POST['chat_enabled']) ? 1 : 0;

    // ── Success / thank-you page ─────────────────────────────
    $successTitle   = clean(post('success_title'));
    $successMessage = clean(post('success_message'));
    $showReceiptBtn = !empty($_POST['show_receipt_button']) ? 1 : 0;

    $btnLabels = $_POST['success_btn_label'] ?? [];
    $btnUrls   = $_POST['success_btn_url']   ?? [];
    $successButtons = [];
    foreach ($btnLabels as $i => $lbl) {
        $lbl = clean($lbl);
        $url = filter_var($btnUrls[$i] ?? '', FILTER_VALIDATE_URL) ? $btnUrls[$i] : '';
        if ($lbl && $url) $successButtons[] = ['label' => $lbl, 'url' => $url];
    }
    $successButtons = array_slice($successButtons, 0, 3);

    // ── Logo upload (Pro / Business only) ────────────────────
    $logoUrl = null;
    if ($isPaidPlan && !empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logoUrl = handleLogoUpload($_FILES['logo'], $uid);
        if ($logoUrl === false) {
            $errors[] = 'Logo must be a JPG, PNG, or WEBP image under 8MB.';
        }
    }

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
    $itemImages = $_FILES['item_image'] ?? null;
    $items = [];
    foreach ($itemNames as $i => $name) {
        if (trim($name) && isset($itemPrices[$i]) && $itemPrices[$i] > 0) {
            $imageUrl = null;
            if ($itemImages && !empty($itemImages['name'][$i]) && $itemImages['error'][$i] === UPLOAD_ERR_OK) {
                $imageUrl = handleItemImageUpload([
                    'name'     => $itemImages['name'][$i],
                    'type'     => $itemImages['type'][$i],
                    'tmp_name' => $itemImages['tmp_name'][$i],
                    'error'    => $itemImages['error'][$i],
                    'size'     => $itemImages['size'][$i],
                ], $uid);
            }
            $items[] = ['name'=>clean($name),'price'=>abs((float)$itemPrices[$i]),'description'=>clean($itemDescs[$i]??''),'image_url'=>$imageUrl ?: null];
        }
    }

    // Installment config
    $installmentCount  = max(2, (int)post('installment_count', 2));
    $installmentConfig = null;
    $perInstallment    = ($linkType === 'installment' && $amount > 0) ? round($amount / $installmentCount, 2) : null;
    if ($linkType === 'installment' && $amount > 0) {
        $installmentConfig = ['count'=>$installmentCount,'per_installment'=>$perInstallment,'total'=>$amount];
    }

    // Validate
    if (strlen($title) < 3) $errors[] = 'Link title must be at least 3 characters.';
    if ($linkType === 'simple' && $amount > 0 && $amount < $minAmt) $errors[] = 'Minimum payment amount is ' . formatMoney($minAmt, $currency) . '.';
    if ($linkType === 'menu' && empty($items)) $errors[] = 'Add at least one menu item.';
    if ($linkType === 'group' && empty($items)) $errors[] = 'Add at least one item to the group order.';
    if ($linkType === 'installment' && empty($amount)) $errors[] = 'Enter the total amount for installment plan.';
    if ($linkType === 'installment' && $perInstallment !== null && $perInstallment < $minAmt) {
        $errors[] = 'Each installment must be at least ' . formatMoney($minAmt, $currency) . '. Increase the total amount or reduce the number of installments.';
    }

    if (empty($errors)) {
        // ── Authoritative, race-condition-safe limit check ──────────────
        // Runs inside a transaction with SELECT ... FOR UPDATE so two
        // near-simultaneous submits (double-click, double tab, retried
        // request) can't both pass the count check before either INSERT
        // commits. Without this, a starter-plan user could end up with
        // 6+ active links if two create requests landed at the same time.
        $db->beginTransaction();
        try {
            if ($limit !== PHP_INT_MAX) {
                $lockStmt = $db->prepare("SELECT COUNT(*) FROM payment_links WHERE user_id=? AND status='active' FOR UPDATE");
                $lockStmt->execute([$uid]);
                $currentActive = (int)$lockStmt->fetchColumn();

                if ($currentActive >= $limit) {
                    $db->rollBack();
                    $nextPlan = $user['plan'] === 'free' ? 'Starter' : ($user['plan'] === 'starter' ? 'Pro' : 'Business');
                    flash('error', ucfirst($user['plan']) . " plan allows only $limit active payment link" . ($limit > 1 ? 's' : '') . ". Upgrade to $nextPlan for more.");
                    redirect('/payment-links.php');
                }
            }

            // Free plan: random short code instead of a title-based slug
            // (custom branded slugs are a paid-plan feature).
            $slug = $isPaidPlan ? generateSlug($title) : generateRandomSlug($db);

            $db->prepare("INSERT INTO payment_links
                    (user_id,title,slug,description,link_type,amount,currency,require_confirmation,redirect_url,delivery_zones,installment_config,accent_color,logo_url,success_title,success_message,success_buttons,show_receipt_button,chat_enabled,expires_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$uid,$title,$slug,$description,$linkType,$amount,$currency,$requireConf,$redirectUrl,
                          !empty($zones)?json_encode($zones):null,
                          $installmentConfig?json_encode($installmentConfig):null,
                          $accentColor,$logoUrl,
                          $successTitle ?: null, $successMessage ?: null,
                          !empty($successButtons)?json_encode($successButtons):null,
                          $showReceiptBtn, $chatEnabled,
                          $expiresAt]);

            $linkId = (int)$db->lastInsertId();

            foreach ($items as $item) {
                $db->prepare("INSERT INTO link_items (link_id,name,description,price,image_url) VALUES (?,?,?,?,?)")
                   ->execute([$linkId,$item['name'],$item['description'],$item['price'],$item['image_url']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        flash('success','Payment link created! Share it on WhatsApp to start collecting payments.');
        redirect('/payment-links.php');
    }
}

// What the form should show right now (first visit, or after a validation error)
$formCurrency = in_array(post('currency'), supportedCurrencies(), true) ? post('currency') : 'NGN';
$formSym      = currencySymbol($formCurrency);
$formIcon     = currencyIcon($formCurrency);
$formMinText  = formatMoney(minAmountFor($formCurrency), $formCurrency);
$formMinText  = preg_replace('/\.00$/', '', $formMinText); // "₦1,000.00" reads better as "₦1,000"
$currencyJs    = currencyJsMap(); // symbols, icons, minimums and live rates for the page script

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
    <a href="/payment-links.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);text-decoration:none;font-weight:500;margin-bottom:12px">
      <i class="ti ti-arrow-left"></i> Back to links
    </a>
    <h1 style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--ink);letter-spacing:-.5px">Create Payment Link</h1>
    <p style="font-size:14px;color:var(--muted);margin-top:4px">Fill in the details below and share your link on WhatsApp in seconds.</p>
  </div>

  <?php if (!empty($errors)): ?>
  <div style="background:var(--danger-bg);border:1px solid #FECACA;border-radius:var(--r-lg);padding:14px 16px;margin-bottom:20px">
    <?php foreach ($errors as $e): ?>
    <p style="font-size:13px;color:var(--danger);display:flex;align-items:center;gap:6px;margin-top:4px"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="create-form" enctype="multipart/form-data">
    <?= csrfField() ?>

    <!-- LINK TYPE -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-title" style="margin-bottom:16px">Link Type</div>
      <div id="type-grid" class="type-grid">
        <?php foreach ([
          ['simple','ti-currency-naira','Simple Payment','Fixed amount, or let the payer choose.'],
          ['menu','ti-list','Menu / Products','Multiple items customers can choose from.'],
          ['installment','ti-credit-card-pay','Installment Plan','Split a total into scheduled payments.'],
          ['group','ti-users-group','Group Order','Multiple people pay individually via one link.'],
        ] as [$val,$icon,$label,$desc]): ?>
        <label class="type-opt <?= post('link_type','simple')===$val?'selected':'' ?>" onclick="selectType(this,'<?= $val ?>')">
          <input type="radio" name="link_type" value="<?= $val ?>" <?= post('link_type','simple')===$val?'checked':'' ?> style="position:absolute;opacity:0">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="width:40px;height:40px;border-radius:var(--r-md);background:var(--purple-50);display:flex;align-items:center;justify-content:center;color:var(--purple-600);font-size:20px;flex-shrink:0;border:1px solid var(--purple-100)"><i class="ti <?= $icon ?>"></i></div>
            <div>
              <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:3px"><?= $label ?></div>
              <div style="font-size:12px;color:var(--muted);line-height:1.5"><?= $desc ?></div>
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
          <input type="text" name="title" id="title-input" class="form-input" placeholder="e.g. Jollof Rice Order, Solar Panel Payment" value="<?= htmlspecialchars(post('title')) ?>" required oninput="updateSlugPreview(this.value)">
          <i class="ti ti-circle-check inp-status" id="title-status"></i>
        </div>
        <?php if ($isPaidPlan): ?>
          <div class="form-hint">Your link will be: <strong id="slug-preview"><?= rtrim(SITE_URL,'/') ?>/pay/your-link-title</strong></div>
        <?php else: ?>
          <div class="form-hint"><i class="ti ti-info-circle" style="font-size:13px;vertical-align:-2px"></i> <strong>Upgrade to a paid plan for a branded link.</strong></div>
        <?php endif; ?>
        <div class="field-msg" id="title-msg"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Description <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <textarea name="description" class="form-textarea" placeholder="Tell customers what this payment is for..."><?= htmlspecialchars(post('description')) ?></textarea>
      </div>
      <?php if (count(supportedCurrencies()) > 1): ?>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Currency</label>
        <select name="currency" id="currency-select" class="form-select" onchange="applyCurrency()">
          <?php foreach (supportedCurrencies() as $c): ?>
          <option value="<?= $c ?>" <?= $formCurrency===$c?'selected':'' ?>><?= htmlspecialchars(currencyLabel($c)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Customers pay in this currency. It applies to the whole link, including delivery fees. You are always paid out in naira.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- SIMPLE AMOUNT -->
    <div class="card type-section" id="section-simple" style="margin-bottom:20px;<?= post('link_type','simple')!=='simple'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:16px">Payment Amount</div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Amount (<span class="cur-sym"><?= $formSym ?></span>) <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <div class="form-input-wrap">
          <i class="ti <?= $formIcon ?> inp-icon cur-icon"></i>
          <input type="number" name="simple_amount" id="simple-amount-input" class="form-input" placeholder="0.00" step="0.01" value="<?= htmlspecialchars(post('simple_amount')) ?>">
        </div>
        <div class="form-hint">Minimum amount: <span class="min-text"><?= $formMinText ?></span>. Leave empty to let customers enter their own amount.</div>
        <div class="payout-preview" data-for="simple-amount-input" style="display:none"></div>
      </div>
    </div>

    <!-- MENU ITEMS -->
    <div class="card type-section" id="section-menu" style="margin-bottom:20px;<?= post('link_type')!=='menu'?'display:none':'' ?>">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <div class="card-title">Menu Items</div>
        <button type="button" onclick="addItem()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Item</button>
      </div>
      <div class="notice-box warning" style="margin-bottom:14px">
        <i class="ti ti-alert-triangle"></i>
        <span>The customer's total order (items + delivery) must come to at least <span class="min-text"><?= $formMinText ?></span>.</span>
      </div>
      <div id="items-container">
        <div class="menu-item-row" style="display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start">
          <div>
            <input type="text" name="item_name[]" class="form-input" placeholder="Item name (e.g. Jollof Rice)" style="margin-bottom:6px">
            <input type="text" name="item_desc[]" class="form-input" placeholder="Full description — shown when the buyer taps 'View details'" style="margin-bottom:6px">
            <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;cursor:pointer">
              <i class="ti ti-photo"></i> <span>Photo (optional)</span>
              <input type="file" name="item_image[]" accept="image/png,image/jpeg,image/webp" style="font-size:11px">
            </label>
          </div>
          <div class="form-input-wrap">
            <i class="ti <?= $formIcon ?> inp-icon cur-icon" style="top:14px;transform:none"></i>
            <input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01">
          </div>
          <button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger);margin-top:0"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      <div class="form-hint" style="margin-top:10px"><i class="ti ti-info-circle"></i> Add a photo and a fuller description so buyers can tap "View details" before choosing an item — useful when you have several similar products on one link.</div>
    </div>

    <!-- INSTALLMENT -->
    <div class="card type-section" id="section-installment" style="margin-bottom:20px;<?= post('link_type')!=='installment'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:16px">Installment Configuration</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Total Amount (<span class="cur-sym"><?= $formSym ?></span>)</label>
          <div class="form-input-wrap">
            <i class="ti <?= $formIcon ?> inp-icon cur-icon"></i>
            <input type="number" name="installment_amount" id="installment-amount-input" class="form-input" placeholder="Total e.g. 350000" step="0.01">
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
      <div class="form-hint" style="margin-top:8px">Each individual installment must come to at least <span class="min-text"><?= $formMinText ?></span>.</div>
      <div class="payout-preview" data-for="installment-amount-input" style="display:none"></div>
    </div>

    <!-- GROUP ORDER: same as menu for items -->
    <div class="card type-section" id="section-group" style="margin-bottom:20px;<?= post('link_type')!=='group'?'display:none':'' ?>">
      <div class="card-title" style="margin-bottom:4px">Group Order</div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Add items below. Each person in the group selects what they want and pays individually.</p>
      <div class="notice-box warning" style="margin-bottom:14px">
        <i class="ti ti-alert-triangle"></i>
        <span>Each person's total order must come to at least <span class="min-text"><?= $formMinText ?></span>.</span>
      </div>
      <div id="group-items-container">
        <div class="menu-item-row" style="display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start">
          <input type="text" name="item_name[]" class="form-input" placeholder="Item name">
          <div class="form-input-wrap">
            <i class="ti <?= $formIcon ?> inp-icon cur-icon"></i>
            <input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01">
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
          <div class="card-title">Delivery Zones <span style="font-size:12px;font-weight:400;color:var(--muted)">(optional)</span></div>
          <div class="card-sub" style="margin-top:3px">Customers pick their zone at checkout and fee is added automatically.</div>
        </div>
        <button type="button" onclick="addZone()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Zone</button>
      </div>
      <div id="zones-container">
        <div class="zone-row" style="display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center">
          <input type="text" name="zone_name[]" class="form-input" placeholder="Zone name (e.g. Surulere)">
          <div class="form-input-wrap">
            <i class="ti <?= $formIcon ?> inp-icon cur-icon"></i>
            <input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0">
          </div>
          <button type="button" onclick="removeZone(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
        </div>
      </div>
    </div>

    <!-- SUCCESS / THANK-YOU PAGE -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-title" style="margin-bottom:4px">Success Page</div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">What the buyer sees right after they pay. Leave blank to use the default "Payment received" page.</p>
      <div class="form-group">
        <label class="form-label">Heading <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <input type="text" name="success_title" class="form-input" placeholder="e.g. Thanks for your order!" value="<?= htmlspecialchars(post('success_title')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Message <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <textarea name="success_message" class="form-textarea" placeholder="e.g. We'll start preparing your order right away. You'll get a WhatsApp update once it's on the way."><?= htmlspecialchars(post('success_message')) ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Buttons <span style="color:var(--muted-2);font-weight:400">(optional — up to 3)</span></label>
        <div id="success-btns-container">
          <div class="success-btn-row" style="display:grid;grid-template-columns:1fr 1.6fr auto;gap:8px;margin-bottom:8px">
            <input type="text" name="success_btn_label[]" class="form-input" placeholder="Button text e.g. Join our group">
            <input type="url" name="success_btn_url[]" class="form-input" placeholder="https://...">
            <button type="button" onclick="this.closest('.success-btn-row').remove()" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
          </div>
        </div>
        <button type="button" onclick="addSuccessBtn()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Button</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);margin-top:14px;cursor:pointer">
        <input type="checkbox" name="show_receipt_button" value="1" <?= post('show_receipt_button','1')?'checked':'' ?> style="accent-color:var(--purple-600);width:16px;height:16px">
        Also show a "View Receipt" button on the success page
      </label>
    </div>

    <!-- ADVANCED SETTINGS -->
    <div class="card" style="margin-bottom:20px">
      <details>
        <summary style="font-family:var(--font-display);font-size:15px;font-weight:700;color:var(--ink);cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between">
          Advanced Settings <i class="ti ti-chevron-down" style="font-size:18px;color:var(--muted)"></i>
        </summary>
        <div style="margin-top:20px;display:flex;flex-direction:column;gap:18px">
          <!-- Live chat toggle -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line)">
            <div>
              <div style="font-size:14px;font-weight:600;color:var(--ink)">Enable live chat on checkout</div>
              <div style="font-size:12px;color:var(--muted);margin-top:2px">Buyers can message you from the payment page before or after paying (they share their name and email first); you reply from your Chats tab.</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:16px">
              <input type="checkbox" name="chat_enabled" value="1" <?= post('chat_enabled','1')?'checked':'' ?> style="position:absolute;opacity:0;width:0;height:0" id="chat-toggle" onchange="document.getElementById('chat-label').style.background=this.checked?'var(--purple-600)':'var(--line)';document.getElementById('chat-knob').style.transform=this.checked?'translateX(20px)':'translateX(0)'">
              <div id="chat-label" style="width:44px;height:24px;border-radius:12px;background:var(--purple-600);transition:background .2s;position:relative">
                <div id="chat-knob" style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s;transform:translateX(20px)"></div>
              </div>
            </label>
          </div>
          <!-- Custom logo (Pro/Business) -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Checkout Logo <?php if(!$isPaidPlan): ?><span style="color:var(--muted-2);font-weight:400">(Pro &amp; Business plans)</span><?php else: ?><span style="color:var(--muted-2);font-weight:400">(optional)</span><?php endif; ?></label>
            <?php if ($isPaidPlan): ?>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-input" style="padding:10px 14px">
            <div class="form-hint">Replaces the default initials avatar on your payment page. PNG/JPG/WEBP, any size up to 8MB — it's automatically resized for fast loading.</div>
            <?php else: ?>
            <div class="notice-box warning"><i class="ti ti-lock"></i><span>Upgrade to Pro or Business to upload a custom logo for your checkout page.</span></div>
            <?php endif; ?>
          </div>
          <!-- Order confirmation toggle -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line)">
            <div>
              <div style="font-size:14px;font-weight:600;color:var(--ink)">Require order confirmation</div>
              <div style="font-size:12px;color:var(--muted);margin-top:2px">You review and accept each order before the customer pays. Prevents payment for out-of-stock items.</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:16px">
              <input type="checkbox" name="require_confirmation" value="1" <?= post('require_confirmation')?'checked':'' ?> style="position:absolute;opacity:0;width:0;height:0" id="conf-toggle" onchange="document.getElementById('conf-label').style.background=this.checked?'var(--purple-600)':'var(--line)'">
              <div id="conf-label" style="width:44px;height:24px;border-radius:12px;background:<?= post('require_confirmation')?'var(--purple-600)':'var(--line)' ?>;transition:background .2s;position:relative">
                <div style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s" id="conf-knob"></div>
              </div>
            </label>
          </div>
          <!-- Redirect URL -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Redirect URL after payment <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
            <div class="form-input-wrap">
              <i class="ti ti-link inp-icon"></i>
              <input type="url" name="redirect_url" class="form-input" placeholder="https://yoursite.com/thank-you" value="<?= htmlspecialchars(post('redirect_url')) ?>">
            </div>
          </div>
          <!-- Expiry -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Link expiry date <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
            <div class="form-input-wrap">
              <i class="ti ti-calendar inp-icon"></i>
              <input type="datetime-local" name="expires_at" class="form-input" value="<?= htmlspecialchars(post('expires_at')) ?>">
            </div>
          </div>
          <!-- Accent color -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Accent Color</label>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="color" name="accent_color" value="<?= htmlspecialchars(post('accent_color','#4F43D4')) ?>" style="width:44px;height:44px;border-radius:var(--r-md);border:1.5px solid var(--line);cursor:pointer;padding:3px">
              <span style="font-size:13px;color:var(--muted)">Customize your payment page accent color</span>
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
.type-opt{border:1.5px solid var(--line);border-radius:var(--r-lg);padding:16px;cursor:pointer;transition:all .2s;position:relative}
.type-opt:hover{border-color:var(--purple-300)}
.type-opt.selected{border-color:var(--purple-600);background:var(--purple-50)}
.form-input-wrap{position:relative}
.form-input-wrap .inp-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--muted-2);pointer-events:none}
.form-input-wrap .form-input{padding-left:38px}
.form-input-wrap .inp-status{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:17px;display:none}
.form-input-wrap .inp-status.show-valid{display:block;color:var(--success)}
.form-input-wrap .inp-status.show-invalid{display:block;color:var(--danger)}
.field-msg{font-size:12px;min-height:14px;margin-top:4px}
.field-msg.err{color:var(--danger)}
.field-msg.ok{color:var(--success)}
.form-textarea{font-family:var(--font-body);font-size:14px;color:var(--ink);background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-md);padding:11px 14px;outline:none;transition:all .2s;width:100%;resize:vertical;min-height:88px}
.form-textarea:focus{background:var(--paper);border-color:var(--purple-500);box-shadow:0 0 0 4px var(--purple-50)}
.notice-box{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:var(--r-lg);font-size:13px;line-height:1.5}
.notice-box i{font-size:15px;flex-shrink:0;margin-top:1px}
.notice-box.warning{background:var(--warning-bg);border:1px solid rgba(217,119,6,.25);color:var(--ink)}
.notice-box.warning i{color:var(--warning)}
.payout-preview{margin-top:10px;padding:11px 14px;border-radius:var(--r-lg);background:var(--purple-50);border:1px solid var(--purple-100);font-size:13px;color:var(--ink);line-height:1.55}
.payout-preview small{display:block;margin-top:2px;font-size:11.5px;color:var(--muted)}
</style>
<script>
const IS_PAID_PLAN = <?= $isPaidPlan ? 'true' : 'false' ?>;

// ── CURRENCY ────────────────────────────────────────────────
// Icons, symbols and "minimum" hints follow the currency dropdown.
// Without the dropdown (USD switched off) everything stays in naira.
const CUR          = <?= json_encode($currencyJs) ?>; // symbols, icons, minimums and live rates
const MIN_AMOUNTS  = CUR.mins;
const CUR_SYMBOLS  = CUR.symbols;

function currentCurrency() {
  const sel = document.getElementById('currency-select');
  return sel && CUR.symbols[sel.value] ? sel.value : 'NGN';
}
function curIcon() {
  return CUR.icons[currentCurrency()];
}
function applyCurrency() {
  const cur = currentCurrency();
  document.querySelectorAll('.cur-icon').forEach(el => {
    el.classList.remove(...Object.values(CUR.icons));
    el.classList.add(curIcon());
  });
  document.querySelectorAll('.cur-sym').forEach(el => { el.textContent = CUR_SYMBOLS[cur]; });
  document.querySelectorAll('.min-text').forEach(el => {
    el.textContent = CUR_SYMBOLS[cur] + MIN_AMOUNTS[cur].toLocaleString('en-US');
  });
  updatePayoutPreviews();
}

// ── "You'll receive" preview for dollar and cedi amounts ────
// Merchants are credited in naira at the platform rate, so show that figure.
function fmtNgn(n) {
  return '₦' + n.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function updatePayoutPreviews() {
  document.querySelectorAll('.payout-preview').forEach(box => {
    const input = document.getElementById(box.dataset.for);
    const amt = input ? parseFloat(input.value) : NaN;
    const cur = currentCurrency(), rate = CUR.rates[cur] || 0;
    if (cur === 'NGN' || !(amt > 0) || !(rate > 0)) { box.style.display = 'none'; return; }
    box.innerHTML = 'You\'ll receive about <strong>' + fmtNgn(amt * rate) + '</strong>'
      + '<small>At ' + CUR.symbols[cur] + '1 = ' + fmtNgn(rate) + ', before fees. The rate is locked in when each order is placed.</small>';
    box.style.display = 'block';
  });
}
document.querySelectorAll('.payout-preview').forEach(box => {
  const input = document.getElementById(box.dataset.for);
  if (input) input.addEventListener('input', updatePayoutPreviews);
});

// ── LINK TYPE SECTIONS ──────────────────────────────────────
function setSectionState(activeType) {
  document.querySelectorAll('.type-section').forEach(section => {
    const on = section.id === 'section-' + activeType;
    section.style.display = on ? 'block' : 'none';
    // Hidden sections are disabled so their fields are never submitted.
    section.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !on; });
  });
}

function selectType(el, type) {
  document.querySelectorAll('.type-opt').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  el.querySelector('input').checked = true;
  setSectionState(type);
}

// On first load (and after a validation error) make sure only the visible
// section's fields are live. Otherwise hidden menu/group rows would be
// submitted alongside the real ones.
(function initSections() {
  const checked = document.querySelector('input[name="link_type"]:checked');
  setSectionState(checked ? checked.value : 'simple');
  applyCurrency();
})();

// live title validation + (paid-plan-only) slug preview
function updateSlugPreview(val) {
  const trimmed = val.trim();
  const statusEl = document.getElementById('title-status');
  const msgEl = document.getElementById('title-msg');

  if (trimmed.length === 0) {
    statusEl.className = 'ti inp-status';
    msgEl.textContent = ''; msgEl.className = 'field-msg';
  } else if (trimmed.length < 3) {
    statusEl.className = 'ti ti-alert-circle inp-status show-invalid';
    msgEl.textContent = `${3 - trimmed.length} more character${3 - trimmed.length === 1 ? '' : 's'} needed`;
    msgEl.className = 'field-msg err';
  } else {
    statusEl.className = 'ti ti-circle-check inp-status show-valid';
    msgEl.textContent = ''; msgEl.className = 'field-msg ok';
  }

  if (IS_PAID_PLAN) {
    const slugPreview = document.getElementById('slug-preview');
    if (slugPreview) {
      const slug = val.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
      slugPreview.textContent = '<?= rtrim(SITE_URL,"/") ?>/pay/' + (slug || 'your-link-title');
    }
  }
}

function addItem() {
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:start';
  row.innerHTML = `<div><input type="text" name="item_name[]" class="form-input" placeholder="Item name" style="margin-bottom:6px"><input type="text" name="item_desc[]" class="form-input" placeholder="Full description (optional)" style="margin-bottom:6px"><label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;cursor:pointer"><i class="ti ti-photo"></i> <span>Photo (optional)</span><input type="file" name="item_image[]" accept="image/png,image/jpeg,image/webp" style="font-size:11px"></label></div><div class="form-input-wrap"><i class="ti ${curIcon()} inp-icon cur-icon" style="top:14px;transform:none"></i><input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01"></div><button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger);margin-top:0"><i class="ti ti-trash"></i></button>`;
  document.getElementById('items-container').appendChild(row);
}

function addGroupItem() {
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 120px auto;gap:10px;margin-bottom:10px;align-items:center';
  row.innerHTML = `<input type="text" name="item_name[]" class="form-input" placeholder="Item name"><div class="form-input-wrap"><i class="ti ${curIcon()} inp-icon cur-icon"></i><input type="number" name="item_price[]" class="form-input" placeholder="Price" min="0" step="0.01"></div><button type="button" onclick="removeItem(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>`;
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
  row.innerHTML = `<input type="text" name="zone_name[]" class="form-input" placeholder="Zone name"><div class="form-input-wrap"><i class="ti ${curIcon()} inp-icon cur-icon"></i><input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0"></div><button type="button" onclick="removeZone(this)" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>`;
  document.getElementById('zones-container').appendChild(row);
}

function removeZone(btn) { btn.closest('.zone-row').remove(); }

function addSuccessBtn() {
  const c = document.getElementById('success-btns-container');
  if (c.querySelectorAll('.success-btn-row').length >= 3) {
    showToast('info','Limit reached','You can add up to 3 buttons.');
    return;
  }
  const row = document.createElement('div');
  row.className = 'success-btn-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 1.6fr auto;gap:8px;margin-bottom:8px';
  row.innerHTML = `<input type="text" name="success_btn_label[]" class="form-input" placeholder="Button text"><input type="url" name="success_btn_url[]" class="form-input" placeholder="https://..."><button type="button" onclick="this.closest('.success-btn-row').remove()" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>`;
  c.appendChild(row);
}

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