<?php
/**
 * Inventory Item - Create / Edit Form
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'write');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$pageTitle = $isEdit ? 'Edit Item' : 'Add Item';

$item = null;
if ($isEdit) {
    $st = $pdo->prepare("SELECT * FROM inv_items WHERE id = :id AND is_active = TRUE");
    $st->execute([':id' => $editId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        flash_error('Item not found.');
        header('Location: ' . BASE_URL . '/modules/inventory/inventory');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $data = [
        'sku'           => trim($_POST['sku'] ?? ''),
        'barcode'       => trim($_POST['barcode'] ?? '') ?: null,
        'name'          => trim($_POST['name'] ?? ''),
        'generic_name'  => trim($_POST['generic_name'] ?? '') ?: null,
        'description'   => trim($_POST['description'] ?? '') ?: null,
        'category_id'   => ((int)($_POST['category_id'] ?? 0)) ?: null,
        'supplier_id'   => ((int)($_POST['supplier_id'] ?? 0)) ?: null,
        'location_id'   => ((int)($_POST['location_id'] ?? 0)) ?: null,
        'unit'          => trim($_POST['unit'] ?? 'pcs'),
        'cost_price'    => round((float)($_POST['cost_price'] ?? 0), 2),
        'selling_price' => round((float)($_POST['selling_price'] ?? 0), 2),
        'reorder_level' => (int)($_POST['reorder_level'] ?? 10),
        'expiry_date'   => trim($_POST['expiry_date'] ?? '') ?: null,
    ];

    // Validation
    $errors = [];
    if ($data['sku'] === '') $errors[] = 'SKU is required.';
    if ($data['name'] === '') $errors[] = 'Item name is required.';
    if ($data['cost_price'] < 0) $errors[] = 'Cost price must be non-negative.';
    if ($data['selling_price'] < 0) $errors[] = 'Selling price must be non-negative.';

    // Check SKU uniqueness
    if (!$errors) {
        $skuCheck = $pdo->prepare("SELECT id FROM inv_items WHERE sku = :sku AND id != :id AND is_active = TRUE");
        $skuCheck->execute([':sku' => $data['sku'], ':id' => $editId]);
        if ($skuCheck->fetch()) {
            $errors[] = 'SKU already exists.';
        }
    }

    if ($errors) {
        flash_error(implode(' ', $errors));
    } else {
        if ($isEdit) {
            $sql = "UPDATE inv_items SET sku=:sku, barcode=:barcode, name=:name, generic_name=:generic_name,
                    description=:description, category_id=:category_id, supplier_id=:supplier_id, location_id=:location_id,
                    unit=:unit, cost_price=:cost_price, selling_price=:selling_price, reorder_level=:reorder_level,
                    expiry_date=:expiry_date, updated_at=NOW() WHERE id=:id";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($data, [':id' => $editId]));
            action_log('inventory', 'update_item', 'success', ['item_id' => $editId]);
            flash_success('Item updated.');
        } else {
            $initialQty = max(0, (int)($_POST['initial_qty'] ?? 0));
            $sql = "INSERT INTO inv_items (sku, barcode, name, generic_name, description, category_id, supplier_id, location_id,
                    unit, cost_price, selling_price, reorder_level, qty_on_hand, expiry_date, created_by)
                    VALUES (:sku, :barcode, :name, :generic_name, :description, :category_id, :supplier_id, :location_id,
                    :unit, :cost_price, :selling_price, :reorder_level, :qty, :expiry_date, :uid)";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($data, [':qty' => $initialQty, ':uid' => $uid]));
            $newId = (int)$pdo->lastInsertId();

            // Record initial stock movement
            if ($initialQty > 0) {
                $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, unit_cost, notes, created_by) VALUES (:iid, 'initial', :qty, :cost, 'Initial stock on creation', :uid)")
                    ->execute([':iid' => $newId, ':qty' => $initialQty, ':cost' => $data['cost_price'], ':uid' => $uid]);
            }

            action_log('inventory', 'create_item', 'success', ['item_id' => $newId]);
            flash_success('Item created.');
        }
        header('Location: ' . BASE_URL . '/modules/inventory/inventory');
        exit;
    }
}

$categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM inv_suppliers WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM inv_locations WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-4">
  <div class="flex items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="p-2 rounded hover:bg-gray-100 text-gray-500">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <h1 class="text-xl font-semibold text-gray-900"><?= $pageTitle ?></h1>
  </div>

  <form method="POST" class="bg-white rounded-xl border divide-y">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />

    <div class="p-6 space-y-4">
      <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Basic Information</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SKU <span class="text-red-500">*</span></label>
          <input type="text" name="sku" value="<?= htmlspecialchars($item['sku'] ?? $_POST['sku'] ?? '') ?>" class="input-text w-full" required />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Barcode</label>
          <input type="text" name="barcode" value="<?= htmlspecialchars($item['barcode'] ?? $_POST['barcode'] ?? '') ?>" class="input-text w-full" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Item Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" value="<?= htmlspecialchars($item['name'] ?? $_POST['name'] ?? '') ?>" class="input-text w-full" required />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Generic Name</label>
          <input type="text" name="generic_name" value="<?= htmlspecialchars($item['generic_name'] ?? $_POST['generic_name'] ?? '') ?>" class="input-text w-full" placeholder="e.g. Paracetamol 500mg" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea name="description" rows="2" class="input-text w-full"><?= htmlspecialchars($item['description'] ?? $_POST['description'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="p-6 space-y-4">
      <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Classification</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
          <select name="category_id" class="input-text w-full">
            <option value="">-- None --</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= $c['id'] ?>" <?= ($item['category_id'] ?? $_POST['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
          <select name="supplier_id" class="input-text w-full">
            <option value="">-- None --</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= $s['id'] ?>" <?= ($item['supplier_id'] ?? $_POST['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Storage Location</label>
          <select name="location_id" class="input-text w-full">
            <option value="">-- None --</option>
            <?php foreach ($locations as $loc): ?>
              <option value="<?= $loc['id'] ?>" <?= ($item['location_id'] ?? $_POST['location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="p-6 space-y-4">
      <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Pricing & Stock</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
          <select name="unit" class="input-text w-full">
            <?php
              $units = ['pcs','box','bottle','pack','vial','tube','roll','bag','set','kit','tablet','capsule','sachet','ampule','liter','ml','kg','g'];
              $selUnit = $item['unit'] ?? $_POST['unit'] ?? 'pcs';
              foreach ($units as $u): ?>
              <option value="<?= $u ?>" <?= $selUnit === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price</label>
          <input type="number" step="0.01" min="0" name="cost_price" value="<?= htmlspecialchars($item['cost_price'] ?? $_POST['cost_price'] ?? '0') ?>" class="input-text w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Selling Price</label>
          <input type="number" step="0.01" min="0" name="selling_price" value="<?= htmlspecialchars($item['selling_price'] ?? $_POST['selling_price'] ?? '0') ?>" class="input-text w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
          <input type="number" min="0" name="reorder_level" value="<?= htmlspecialchars($item['reorder_level'] ?? $_POST['reorder_level'] ?? '10') ?>" class="input-text w-full" />
        </div>
        <?php if (!$isEdit): ?>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Initial Quantity</label>
          <input type="number" min="0" name="initial_qty" value="<?= htmlspecialchars($_POST['initial_qty'] ?? '0') ?>" class="input-text w-full" />
        </div>
        <?php endif; ?>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
          <input type="date" name="expiry_date" value="<?= htmlspecialchars($item['expiry_date'] ?? $_POST['expiry_date'] ?? '') ?>" class="input-text w-full" />
        </div>
      </div>
    </div>

    <div class="p-6 flex items-center justify-end gap-3">
      <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="btn btn-outline text-sm">Cancel</a>
      <button type="submit" class="btn btn-primary text-sm"><?= $isEdit ? 'Update Item' : 'Create Item' ?></button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
