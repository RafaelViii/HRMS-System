<?php
/**
 * Inventory Item - Detail View
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');
$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT i.*, c.name AS category_name, s.name AS supplier_name, l.name AS location_name
    FROM inv_items i
    LEFT JOIN inv_categories c ON c.id = i.category_id
    LEFT JOIN inv_suppliers s ON s.id = i.supplier_id
    LEFT JOIN inv_locations l ON l.id = i.location_id
    WHERE i.id = :id");
$st->execute([':id' => $id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    flash_error('Item not found.');
    header('Location: ' . BASE_URL . '/modules/inventory/inventory');
    exit;
}

$pageTitle = $item['name'];

// Stock movements for this item
$movements = $pdo->prepare("SELECT m.*, u.full_name AS user_name FROM inv_stock_movements m LEFT JOIN users u ON u.id = m.created_by WHERE m.item_id = :id ORDER BY m.created_at DESC LIMIT 50");
$movements->execute([':id' => $id]);
$movements = $movements->fetchAll(PDO::FETCH_ASSOC);

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_qty']) && csrf_verify($_POST['csrf'] ?? '')) {
    require_access('inventory', 'inventory_items', 'write');
    $adjQty = (int)$_POST['adjust_qty'];
    $adjNotes = trim($_POST['adjust_notes'] ?? '');
    $adjType = $_POST['adjust_type'] ?? 'adjustment';

    if ($adjQty == 0) {
        flash_error('Adjustment quantity cannot be zero.');
    } else {
        $newQty = $item['qty_on_hand'] + $adjQty;
        $effectiveChange = $adjQty;
        if ($newQty < 0) {
            $effectiveChange = -$item['qty_on_hand']; // actual change when clamped
            $newQty = 0;
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE inv_items SET qty_on_hand = :qty, updated_at = NOW() WHERE id = :id")
                ->execute([':qty' => $newQty, ':id' => $id]);

            $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, notes, created_by) VALUES (:iid, :type, :qty, :notes, :uid)")
                ->execute([':iid' => $id, ':type' => $adjType, ':qty' => $effectiveChange, ':notes' => $adjNotes ?: 'Manual adjustment', ':uid' => $uid]);

            $pdo->commit();

            action_log('inventory', 'stock_adjustment', 'success', ['item_id' => $id, 'qty_change' => $effectiveChange, 'new_qty' => $newQty]);
            flash_success('Stock adjusted. New quantity: ' . $newQty);
            header('Location: ' . BASE_URL . '/modules/inventory/item_view?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sys_log('INV-ADJUST', 'Stock adjustment failed: ' . $e->getMessage(), ['module' => 'inventory', 'file' => __FILE__, 'line' => __LINE__]);
            flash_error('Failed to adjust stock. See system logs.');
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
  <div class="flex items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="p-2 rounded hover:bg-gray-100 text-gray-500">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="flex-1">
      <h1 class="text-xl font-semibold text-gray-900"><?= htmlspecialchars($item['name']) ?></h1>
      <p class="text-sm text-gray-500">SKU: <?= htmlspecialchars($item['sku']) ?> <?= $item['barcode'] ? '| Barcode: ' . htmlspecialchars($item['barcode']) : '' ?></p>
    </div>
    <?php if ($canWrite): ?>
    <a href="<?= BASE_URL ?>/modules/inventory/item_form?id=<?= $id ?>" class="btn btn-outline text-sm">Edit</a>
    <?php endif; ?>
  </div>

  <!-- Item Details Card -->
  <div class="bg-white rounded-xl border">
    <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-y md:divide-y-0">
      <div class="p-4">
        <div class="text-xs text-gray-500 uppercase">Stock On Hand</div>
        <div class="mt-1 text-xl font-semibold <?= $item['qty_on_hand'] == 0 ? 'text-red-600' : ($item['qty_on_hand'] <= $item['reorder_level'] ? 'text-amber-600' : 'text-gray-900') ?>">
          <?= $item['qty_on_hand'] ?> <span class="text-sm font-normal text-gray-500"><?= htmlspecialchars($item['unit']) ?></span>
        </div>
        <div class="text-xs text-gray-400">Reorder at: <?= $item['reorder_level'] ?></div>
      </div>
      <div class="p-4">
        <div class="text-xs text-gray-500 uppercase">Cost Price</div>
        <div class="mt-1 text-xl font-semibold text-gray-900">P<?= number_format((float)$item['cost_price'], 2) ?></div>
      </div>
      <div class="p-4">
        <div class="text-xs text-gray-500 uppercase">Selling Price</div>
        <div class="mt-1 text-xl font-semibold text-gray-900">P<?= number_format((float)$item['selling_price'], 2) ?></div>
      </div>
      <div class="p-4">
        <div class="text-xs text-gray-500 uppercase">Stock Value</div>
        <div class="mt-1 text-xl font-semibold text-gray-900">P<?= number_format($item['qty_on_hand'] * (float)$item['cost_price'], 2) ?></div>
      </div>
    </div>
    <div class="border-t px-4 py-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
      <div><span class="text-gray-500">Category:</span> <span class="text-gray-900"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></span></div>
      <div><span class="text-gray-500">Supplier:</span> <span class="text-gray-900"><?= htmlspecialchars($item['supplier_name'] ?? 'None') ?></span></div>
      <div><span class="text-gray-500">Location:</span> <span class="text-gray-900"><?= htmlspecialchars($item['location_name'] ?? 'None') ?></span></div>
      <div>
        <span class="text-gray-500">Expiry:</span>
        <?php if ($item['expiry_date']): ?>
          <?php $exp = strtotime($item['expiry_date']); $expClass = $exp < time() ? 'text-red-600 font-medium' : ($exp < time()+30*86400 ? 'text-amber-600 font-medium' : 'text-gray-900'); ?>
          <span class="<?= $expClass ?>"><?= date('M d, Y', $exp) ?></span>
        <?php else: ?>
          <span class="text-gray-400">N/A</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($item['generic_name'] || $item['description']): ?>
    <div class="border-t px-4 py-4 text-sm space-y-1">
      <?php if ($item['generic_name']): ?>
        <div><span class="text-gray-500">Generic Name:</span> <?= htmlspecialchars($item['generic_name']) ?></div>
      <?php endif; ?>
      <?php if ($item['description']): ?>
        <div><span class="text-gray-500">Description:</span> <?= htmlspecialchars($item['description']) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Stock Adjustment -->
  <?php if ($canWrite): ?>
  <div class="bg-white rounded-xl border p-4">
    <h2 class="font-semibold text-gray-900 mb-3">Stock Adjustment</h2>
    <form method="POST" class="flex flex-col md:flex-row gap-3">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
      <select name="adjust_type" class="input-text">
        <option value="adjustment">Adjustment</option>
        <option value="receipt">Received Stock</option>
        <option value="disposal">Disposal</option>
        <option value="return">Return</option>
        <option value="transfer">Transfer Out</option>
      </select>
      <input type="number" name="adjust_qty" placeholder="Qty (+/-)" class="input-text w-32" required />
      <input type="text" name="adjust_notes" placeholder="Reason / notes" class="input-text flex-1" />
      <button type="submit" class="btn btn-primary text-sm">Apply</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Movement History -->
  <div class="bg-white rounded-xl border">
    <div class="px-4 py-3 border-b">
      <h2 class="font-semibold text-gray-900">Stock Movement History</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">Date</th>
            <th class="px-4 py-2 text-left">Type</th>
            <th class="px-4 py-2 text-right">Quantity</th>
            <th class="px-4 py-2 text-left">Notes</th>
            <th class="px-4 py-2 text-left">By</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($movements)): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No movements recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($movements as $mv): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-500"><?= date('M d, Y h:i A', strtotime($mv['created_at'])) ?></td>
              <td class="px-4 py-2">
                <?php
                  $typeColors = ['receipt'=>'bg-emerald-100 text-emerald-700','sale'=>'bg-blue-100 text-blue-700','adjustment'=>'bg-amber-100 text-amber-700','return'=>'bg-purple-100 text-purple-700','transfer'=>'bg-cyan-100 text-cyan-700','disposal'=>'bg-red-100 text-red-700','initial'=>'bg-gray-100 text-gray-700'];
                  $tc = $typeColors[$mv['movement_type']] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $tc ?>"><?= ucfirst($mv['movement_type']) ?></span>
              </td>
              <td class="px-4 py-2 text-right font-medium <?= $mv['quantity'] < 0 ? 'text-red-600' : 'text-emerald-600' ?>">
                <?= $mv['quantity'] > 0 ? '+' : '' ?><?= $mv['quantity'] ?>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($mv['notes'] ?? '-') ?></td>
              <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($mv['user_name'] ?? 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
