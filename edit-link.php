<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/upload_helpers.php';
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

// The link's currency is fixed when it's created (changing it later would turn
// a ₦5,000 link into a $5,000 one), so the edit page just follows it.
$currency  = linkCurrency($link['currency'] ?? 'NGN');
$curSym    = currencySymbol($currency);
$curIcon   = currencyIcon($currency);
$curLabel  = currencyLabel($currency);
$minAmt    = minAmountFor($currency);
$minText   = preg_replace('/\.00$/', '', formatMoney($minAmt, $currency)); // "₦1,000.00" reads better as "₦1,000"
$fxRate    = $currency !== 'NGN' ? ngnRate($currency) : 0.0;               // for the "you'll receive" preview

// Fetch existing items
$itemStmt = $db->prepare("SELECT * FROM link_items WHERE link_id = ? ORDER BY sort_order, id");
$itemStmt->execute([$linkId]);
$existingItems = $itemStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = clean(post('title'));
    $description = clean(post('description'));

    // Only simple links have an amount field on this page. For every other type
    // keep whatever is stored (installment links keep their total).
    if ($link['link_type'] === 'simple') {
        $amount = post('amount') ? abs((float)post('amount')) : null;
    } else {
        $amount = $link['amount'];
    }

    $requireConf = !empty($_POST['require_confirmation']) ? 1 : 0;
    $redirectUrl = filter_var(post('redirect_url'), FILTER_VALIDATE_URL) ? post('redirect_url') : null;
    $accentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', post('accent_color')) ? post('accent_color') : '#4F43D4';
    $status      = in_array(post('status'), ['active','paused']) ? post('status') : 'active';
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
    $isPaidPlan = ($user['plan'] ?? 'free') !== 'free';
    $logoUrl = $link['logo_url'] ?? null;
    if (!empty($_POST['remove_logo'])) {
        $logoUrl = null;
    } elseif ($isPaidPlan && !empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleLogoUpload($_FILES['logo'], $uid);
        if ($uploaded === false) {
            $errors[] = 'Logo must be a JPG, PNG, or WEBP image under 8MB.';
        } else {
            $logoUrl = $uploaded;
        }
    }

    // Delivery zones
    $zoneNames = $_POST['zone_name'] ?? [];
    $zoneFees  = $_POST['zone_fee']  ?? [];
    $zones = [];
    foreach ($zoneNames as $i => $name) {
        if (trim($name)) $zones[] = ['name' => clean($name), 'fee' => abs((float)($zoneFees[$i] ?? 0))];
    }

    // Menu items.
    // Every field of a row shares one row key (items[KEY][...] and item_image[KEY]),
    // so deleting or adding rows in the browser can never shift one row's photo or
    // availability onto a different row. Order in the form = sort order saved.
    $validKeepImages = array_filter(array_column($existingItems, 'image_url'));
    $itemRows   = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $itemImages = $_FILES['item_image'] ?? null;
    $newItems   = [];
    foreach ($itemRows as $k => $row) {
        if (!is_array($row)) continue;
        $name  = trim((string)($row['name'] ?? ''));
        $price = (float)($row['price'] ?? 0);
        if ($name === '' || $price <= 0) continue;

        // Only keep a photo that really belongs to this link
        $imageUrl = $row['keep_image'] ?? null;
        if ($imageUrl !== null && !in_array($imageUrl, $validKeepImages, true)) $imageUrl = null;

        if ($itemImages && !empty($itemImages['name'][$k]) && ($itemImages['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadedImg = handleItemImageUpload([
                'name'     => $itemImages['name'][$k],
                'type'     => $itemImages['type'][$k],
                'tmp_name' => $itemImages['tmp_name'][$k],
                'error'    => $itemImages['error'][$k],
                'size'     => $itemImages['size'][$k],
            ], $uid);
            if ($uploadedImg) $imageUrl = $uploadedImg;
        }

        $newItems[] = [
            'name'         => clean($name),
            'price'        => $price,
            'description'  => clean($row['desc'] ?? ''),
            'is_available' => !empty($row['avail']) ? 1 : 0,
            'image_url'    => $imageUrl ?: null,
        ];
    }

    if (strlen($title) < 3) $errors[] = 'Title must be at least 3 characters.';
    if ($link['link_type'] === 'simple' && $amount !== null && $amount > 0 && $amount < $minAmt) {
        $errors[] = 'Minimum payment amount is ' . formatMoney($minAmt, $currency) . '.';
    }

    if (empty($errors)) {
        // Update main link (currency is intentionally not touched)
        $db->prepare("UPDATE payment_links SET title=?,description=?,amount=?,require_confirmation=?,redirect_url=?,delivery_zones=?,accent_color=?,logo_url=?,success_title=?,success_message=?,success_buttons=?,show_receipt_button=?,chat_enabled=?,status=?,expires_at=?,updated_at=NOW() WHERE id=?")
           ->execute([
               $title, $description, $amount, $requireConf, $redirectUrl,
               !empty($zones) ? json_encode($zones) : null,
               $accentColor, $logoUrl,
               $successTitle ?: null, $successMessage ?: null,
               !empty($successButtons) ? json_encode($successButtons) : null,
               $showReceiptBtn, $chatEnabled,
               $status, $expiresAt, $linkId
           ]);

        // Delete all old items and re-insert
        if (in_array($link['link_type'], ['menu', 'group'])) {
            $db->prepare("DELETE FROM link_items WHERE link_id = ?")->execute([$linkId]);
            foreach ($newItems as $idx => $item) {
                $db->prepare("INSERT INTO link_items (link_id, name, description, price, image_url, is_available, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$linkId, $item['name'], $item['description'], $item['price'], $item['image_url'], $item['is_available'], $idx]);
            }
        }

        flash('success', 'Payment link updated successfully.');
        redirect('/payment-links.php');
    }
}

$zones        = $link['delivery_zones'] ? json_decode($link['delivery_zones'], true) : [];
$existingZones = $zones;
$existingSuccessButtons = $link['success_buttons'] ? json_decode($link['success_buttons'], true) : [];
$isPaidPlan = ($user['plan'] ?? 'free') !== 'free';

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px">
  <div style="margin-bottom:20px">
    <a href="/payment-links.php" style="display:inline-flex;align-items:center;gap:5px;font-size:13px;color:var(--muted);text-decoration:none;font-weight:500;margin-bottom:10px">
      <i class="ti ti-arrow-left"></i> Back to links
    </a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h1 style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--ink);letter-spacing:-.3px">Edit Link</h1>
        <p style="font-size:13px;color:var(--muted);margin-top:2px"><?= SITE_URL ?>/pay/<?= htmlspecialchars($link['slug']) ?></p>
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

  <form method="POST" id="edit-form" enctype="multipart/form-data">
    <?= csrfField() ?>

    <!-- STATUS & BASIC INFO -->
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <div class="card-title">Basic Information</div>
        <select name="status" style="font-family:var(--font-body);font-size:13px;font-weight:600;border:1.5px solid var(--line);border-radius:var(--r-md);padding:7px 12px;background:var(--paper);cursor:pointer;outline:none">
          <option value="active" <?= $link['status']==='active'?'selected':'' ?>>Active</option>
          <option value="paused" <?= $link['status']==='paused'?'selected':'' ?>>Paused</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Link Title</label>
        <input type="text" name="title" class="form-input" value="<?= htmlspecialchars($link['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Description <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <textarea name="description" class="form-textarea"><?= htmlspecialchars($link['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Currency</label>
        <div style="display:flex;align-items:center;gap:10px;padding:11px 14px;background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-md);font-size:14px;color:var(--ink)">
          <i class="ti <?= $curIcon ?>" style="font-size:17px;color:var(--muted-2)"></i>
          <span style="font-weight:600"><?= htmlspecialchars($curLabel) ?></span>
          <i class="ti ti-lock" style="font-size:14px;color:var(--muted-2);margin-left:auto"></i>
        </div>
        <div class="form-hint">Set when the link was created and can't be changed. To switch currency, create a new link.</div>
      </div>
    </div>

    <!-- AMOUNT (simple links only) -->
    <?php if ($link['link_type'] === 'simple'): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:14px">Payment Amount</div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Amount (<?= $curSym ?>)</label>
        <div class="form-input-wrap">
          <i class="ti <?= $curIcon ?> inp-icon"></i>
          <input type="number" name="amount" id="amount-input" class="form-input" value="<?= htmlspecialchars($link['amount'] ?? '') ?>" min="<?= $minAmt ?>" step="0.01" placeholder="Leave empty to let customer enter amount">
        </div>
        <div class="form-hint">Minimum amount: <?= $minText ?>.</div>
        <?php if ($currency !== 'NGN'): ?>
        <div class="payout-preview" data-for="amount-input" style="display:none"></div>
        <?php endif; ?>
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
      <div style="background:var(--warning-bg);border:1px solid rgba(217,119,6,.25);border-radius:var(--r-lg);padding:12px 14px;margin-bottom:14px;font-size:13px;color:var(--ink);display:flex;gap:8px;align-items:flex-start">
        <i class="ti ti-alert-triangle" style="color:var(--warning);flex-shrink:0;margin-top:1px"></i>
        <span>A customer's total order (items + delivery) must come to at least <?= $minText ?>.</span>
      </div>
      <div id="items-container">
        <?php foreach ($existingItems as $idx => $item): ?>
        <div class="menu-item-row" style="background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);padding:14px;margin-bottom:10px">
          <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;margin-bottom:8px">
            <input type="text" name="items[<?= $idx ?>][name]" class="form-input" placeholder="Item name" value="<?= htmlspecialchars($item['name']) ?>" required>
            <div class="form-input-wrap">
              <i class="ti <?= $curIcon ?> inp-icon" style="top:12px;transform:none"></i>
              <input type="number" name="items[<?= $idx ?>][price]" class="form-input" placeholder="Price" min="0" step="0.01" value="<?= htmlspecialchars($item['price']) ?>">
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="text" name="items[<?= $idx ?>][desc]" class="form-input" style="flex:1" placeholder="Full description (optional)" value="<?= htmlspecialchars($item['description'] ?? '') ?>">
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted);cursor:pointer;white-space:nowrap">
              <input type="checkbox" name="items[<?= $idx ?>][avail]" value="1" <?= $item['is_available']?'checked':'' ?> style="accent-color:var(--purple-600)"> Available
            </label>
            <button type="button" onclick="this.closest('.menu-item-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="ti ti-trash"></i>
            </button>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
            <?php if (!empty($item['image_url'])): ?>
            <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" style="width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid var(--line)">
            <input type="hidden" name="items[<?= $idx ?>][keep_image]" value="<?= htmlspecialchars($item['image_url']) ?>">
            <?php endif; ?>
            <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;cursor:pointer">
              <i class="ti ti-photo"></i> <span><?= !empty($item['image_url']) ? 'Replace photo' : 'Add photo' ?></span>
              <input type="file" name="item_image[<?= $idx ?>]" accept="image/png,image/jpeg,image/webp" style="font-size:11px">
            </label>
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
          <div class="card-sub" style="margin-top:2px">Auto-added to checkout total (in <?= currencyNoun($currency) ?>)</div>
        </div>
        <button type="button" onclick="addZone()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add</button>
      </div>
      <div id="zones-container">
        <?php foreach ($existingZones as $z): ?>
        <div class="zone-row" style="display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center">
          <input type="text" name="zone_name[]" class="form-input" value="<?= htmlspecialchars($z['name']) ?>" placeholder="Zone name">
          <div class="form-input-wrap">
            <i class="ti <?= $curIcon ?> inp-icon"></i>
            <input type="number" name="zone_fee[]" class="form-input" value="<?= htmlspecialchars($z['fee']) ?>" placeholder="Fee" min="0">
          </div>
          <button type="button" onclick="this.closest('.zone-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center">
            <i class="ti ti-trash"></i>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- SUCCESS / THANK-YOU PAGE -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="margin-bottom:4px">Success Page</div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">What the buyer sees right after they pay. Leave blank for the default "Payment received" page.</p>
      <div class="form-group">
        <label class="form-label">Heading <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <input type="text" name="success_title" class="form-input" placeholder="e.g. Thanks for your order!" value="<?= htmlspecialchars($link['success_title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Message <span style="color:var(--muted-2);font-weight:400">(optional)</span></label>
        <textarea name="success_message" class="form-textarea" placeholder="e.g. We'll start preparing your order right away."><?= htmlspecialchars($link['success_message'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Buttons <span style="color:var(--muted-2);font-weight:400">(optional — up to 3)</span></label>
        <div id="success-btns-container">
          <?php if (!empty($existingSuccessButtons)): foreach ($existingSuccessButtons as $b): ?>
          <div class="success-btn-row" style="display:grid;grid-template-columns:1fr 1.6fr auto;gap:8px;margin-bottom:8px">
            <input type="text" name="success_btn_label[]" class="form-input" value="<?= htmlspecialchars($b['label']) ?>" placeholder="Button text">
            <input type="url" name="success_btn_url[]" class="form-input" value="<?= htmlspecialchars($b['url']) ?>" placeholder="https://...">
            <button type="button" onclick="this.closest('.success-btn-row').remove()" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
          </div>
          <?php endforeach; else: ?>
          <div class="success-btn-row" style="display:grid;grid-template-columns:1fr 1.6fr auto;gap:8px;margin-bottom:8px">
            <input type="text" name="success_btn_label[]" class="form-input" placeholder="Button text e.g. Join our group">
            <input type="url" name="success_btn_url[]" class="form-input" placeholder="https://...">
            <button type="button" onclick="this.closest('.success-btn-row').remove()" class="btn btn-outline btn-sm" style="padding:10px;color:var(--danger);border-color:var(--danger)"><i class="ti ti-trash"></i></button>
          </div>
          <?php endif; ?>
        </div>
        <button type="button" onclick="addSuccessBtn()" class="btn btn-outline btn-sm"><i class="ti ti-plus"></i> Add Button</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);margin-top:14px;cursor:pointer">
        <input type="checkbox" name="show_receipt_button" value="1" <?= ($link['show_receipt_button'] ?? 1) ? 'checked' : '' ?> style="accent-color:var(--purple-600);width:16px;height:16px">
        Also show a "View Receipt" button on the success page
      </label>
    </div>

    <!-- ADVANCED -->
    <div class="card" style="margin-bottom:16px">
      <details <?= $link['require_confirmation'] || $link['redirect_url'] || $link['expires_at'] ? 'open' : '' ?>>
        <summary style="font-family:var(--font-display);font-size:14px;font-weight:700;color:var(--ink);cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between">
          Advanced Settings <i class="ti ti-chevron-down" style="font-size:16px;color:var(--muted)"></i>
        </summary>
        <div style="margin-top:16px;display:flex;flex-direction:column;gap:14px">
          <!-- Live chat toggle -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line)">
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--ink)">Enable live chat on checkout</div>
              <div style="font-size:12px;color:var(--muted);margin-top:2px">Buyers can message you from this payment page</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:12px">
              <input type="checkbox" name="chat_enabled" value="1" <?= ($link['chat_enabled'] ?? 1) ? 'checked' : '' ?>
                style="position:absolute;opacity:0;width:0;height:0"
                onchange="const k=this.nextElementSibling;k.style.background=this.checked?'var(--purple-600)':'var(--line)';k.querySelector('div').style.transform=this.checked?'translateX(20px)':'translateX(0)'">
              <div style="width:44px;height:24px;border-radius:12px;background:<?= ($link['chat_enabled'] ?? 1) ? 'var(--purple-600)' : 'var(--line)' ?>;transition:background .2s;position:relative">
                <div style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s;transform:<?= ($link['chat_enabled'] ?? 1) ? 'translateX(20px)' : 'translateX(0)' ?>"></div>
              </div>
            </label>
          </div>
          <!-- Custom logo (Pro/Business) -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Checkout Logo <?php if(!$isPaidPlan): ?><span style="color:var(--muted-2);font-weight:400">(Pro &amp; Business plans)</span><?php endif; ?></label>
            <?php if ($isPaidPlan): ?>
              <?php if (!empty($link['logo_url'])): ?>
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <img src="<?= htmlspecialchars($link['logo_url']) ?>" alt="" style="width:40px;height:40px;border-radius:10px;object-fit:cover;border:1px solid var(--line)">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--danger);cursor:pointer">
                  <input type="checkbox" name="remove_logo" value="1" style="accent-color:var(--danger)"> Remove current logo
                </label>
              </div>
              <?php endif; ?>
              <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-input" style="padding:10px 14px">
              <div class="form-hint">PNG/JPG/WEBP, any size up to 8MB — automatically resized for fast loading. Leave empty to keep the current logo.</div>
            <?php else: ?>
              <div class="notice-box warning"><i class="ti ti-lock"></i><span>Upgrade to Pro or Business to upload a custom logo for this checkout page.</span></div>
            <?php endif; ?>
          </div>
          <!-- Order confirmation -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line)">
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--ink)">Require order confirmation</div>
              <div style="font-size:12px;color:var(--muted);margin-top:2px">Review orders before customers pay</div>
            </div>
            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0;margin-left:12px">
              <input type="checkbox" name="require_confirmation" value="1" <?= $link['require_confirmation']?'checked':'' ?>
                style="position:absolute;opacity:0;width:0;height:0"
                onchange="const k=this.nextElementSibling;k.style.background=this.checked?'var(--purple-600)':'var(--line)';k.querySelector('div').style.transform=this.checked?'translateX(20px)':'translateX(0)'">
              <div style="width:44px;height:24px;border-radius:12px;background:<?= $link['require_confirmation']?'var(--purple-600)':'var(--line)' ?>;transition:background .2s;position:relative">
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
              <input type="color" name="accent_color" value="<?= htmlspecialchars($link['accent_color'] ?? '#4F43D4') ?>" style="width:44px;height:40px;border-radius:var(--r-md);border:1.5px solid var(--line);padding:2px;cursor:pointer">
              <span style="font-size:13px;color:var(--muted)">Customer checkout page accent color</span>
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
.form-input-wrap .inp-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--muted-2);pointer-events:none}
.form-input-wrap .form-input{padding-left:34px}
.form-textarea{font-family:var(--font-body);font-size:14px;color:var(--ink);background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-md);padding:10px 12px;outline:none;transition:all .2s;width:100%;resize:vertical;min-height:80px}
.form-textarea:focus{background:var(--paper);border-color:var(--purple-500);box-shadow:0 0 0 3px var(--purple-50)}
.notice-box{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:var(--r-lg);font-size:13px;line-height:1.5}
.notice-box i{font-size:15px;flex-shrink:0;margin-top:1px}
.notice-box.warning{background:var(--warning-bg);border:1px solid rgba(217,119,6,.25);color:var(--ink)}
.notice-box.warning i{color:var(--warning)}
.payout-preview{margin-top:10px;padding:11px 14px;border-radius:var(--r-lg);background:var(--purple-50);border:1px solid var(--purple-100);font-size:13px;color:var(--ink);line-height:1.55}
.payout-preview small{display:block;margin-top:2px;font-size:11.5px;color:var(--muted)}
</style>
<script>
// This link's currency icon and dollar rate (both fixed for the page)
const CUR_ICON = '<?= $curIcon ?>';
const FX_RATE = <?= json_encode($fxRate) ?>;
const CUR_SYM = <?= json_encode($curSym) ?>;

// Next free row key for new menu rows. Each row's fields all share one key
// (items[KEY][...], item_image[KEY]) so removing rows never misaligns anything.
let itemKey = <?= max(1000, count($existingItems) + 1) ?>;

function addItem() {
  const k = itemKey++;
  const c = document.getElementById('items-container');
  const row = document.createElement('div');
  row.className = 'menu-item-row';
  row.style.cssText = 'background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);padding:14px;margin-bottom:10px';
  row.innerHTML = `
  <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;margin-bottom:8px">
    <input type="text" name="items[${k}][name]" class="form-input" placeholder="Item name" required>
    <div class="form-input-wrap"><i class="ti ${CUR_ICON} inp-icon" style="top:12px;transform:none"></i><input type="number" name="items[${k}][price]" class="form-input" placeholder="Price" min="0" step="0.01"></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <input type="text" name="items[${k}][desc]" class="form-input" style="flex:1" placeholder="Full description (optional)">
    <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted);cursor:pointer;white-space:nowrap"><input type="checkbox" name="items[${k}][avail]" value="1" checked style="accent-color:var(--purple-600)"> Available</label>
    <button type="button" onclick="this.closest('.menu-item-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ti ti-trash"></i></button>
  </div>
  <div style="margin-top:8px">
    <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;cursor:pointer">
      <i class="ti ti-photo"></i> <span>Add photo</span>
      <input type="file" name="item_image[${k}]" accept="image/png,image/jpeg,image/webp" style="font-size:11px">
    </label>
  </div>`;
  c.appendChild(row);
}

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

function addZone() {
  const c = document.getElementById('zones-container');
  const row = document.createElement('div');
  row.className = 'zone-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 140px auto;gap:10px;margin-bottom:8px;align-items:center';
  row.innerHTML = `<input type="text" name="zone_name[]" class="form-input" placeholder="Zone name"><div class="form-input-wrap"><i class="ti ${CUR_ICON} inp-icon"></i><input type="number" name="zone_fee[]" class="form-input" placeholder="Fee" min="0"></div><button type="button" onclick="this.closest('.zone-row').remove()" style="width:32px;height:32px;border-radius:var(--r-sm);border:1px solid var(--danger);background:var(--danger-bg);color:var(--danger);cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="ti ti-trash"></i></button>`;
  c.appendChild(row);
}

// ── "You'll receive" preview for dollar amounts ─────────────
// Merchants are credited in naira at the platform rate, so show that figure.
function fmtNgn(n) {
  return '₦' + n.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function updatePayoutPreviews() {
  document.querySelectorAll('.payout-preview').forEach(box => {
    const input = document.getElementById(box.dataset.for);
    const amt = input ? parseFloat(input.value) : NaN;
    if (!(amt > 0) || !(FX_RATE > 0)) { box.style.display = 'none'; return; }
    box.innerHTML = 'You\'ll receive about <strong>' + fmtNgn(amt * FX_RATE) + '</strong>'
      + '<small>At ' + CUR_SYM + '1 = ' + fmtNgn(FX_RATE) + ', before fees. The rate is locked in when each order is placed.</small>';
    box.style.display = 'block';
  });
}
document.querySelectorAll('.payout-preview').forEach(box => {
  const input = document.getElementById(box.dataset.for);
  if (input) input.addEventListener('input', updatePayoutPreviews);
});
updatePayoutPreviews();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>