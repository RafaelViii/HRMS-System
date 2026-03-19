<?php
/**
 * Restock Checklist - Inventory Module
 * Lists all products with current stock status. Admin can add new stock quantities
 * and update product cost. Filtered by All / Low Stock / Out of Stock.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canManage = user_has_access($uid, 'inventory', 'inventory_items', 'manage');

// Handle POST - Restock items
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    
    $items = $_POST['items'] ?? [];
    $restocked = 0;
    $costUpdated = 0;

    foreach ($items as $itemId => $data) {
        $itemId = (int)$itemId;
        $newQty = (int)($data['quantity'] ?? 0);
        $newCost = isset($data['cost']) && $data['cost'] !== '' ? (float)$data['cost'] : null;

        if ($newQty <= 0 && $newCost === null) continue;

        try {
            $pdo->beginTransaction();

            // Get current item data
            $stmt = $pdo->prepare("SELECT id, name, sku, qty_on_hand, cost_price FROM inv_items WHERE id = :id AND is_active = TRUE");
            $stmt->execute([':id' => $itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) { $pdo->rollBack(); continue; }

            $updates = [];
            $updateParams = [':id' => $itemId];

            // Add stock
            if ($newQty > 0) {
                $updates[] = "qty_on_hand = qty_on_hand + :qty";
                $updateParams[':qty'] = $newQty;

                // Record stock movement
                $mvStmt = $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, unit_cost, notes, created_by) VALUES (:item, 'receipt', :qty, :cost, :notes, :uid)");
                $mvStmt->execute([
                    ':item' => $itemId,
                    ':qty' => $newQty,
                    ':cost' => $newCost ?? $item['cost_price'],
                    ':notes' => 'Restock via Restock Checklist',
                    ':uid' => $uid,
                ]);

                $restocked++;
            }

            // Update cost
            if ($newCost !== null && $newCost != (float)$item['cost_price']) {
                $updates[] = "cost_price = :cost";
                $updateParams[':cost'] = $newCost;
                $costUpdated++;

                action_log('inventory', 'update_cost', 'success', [
                    'item_id' => $itemId,
                    'item_name' => $item['name'],
                    'old_cost' => $item['cost_price'],
                    'new_cost' => $newCost,
                ]);
            }

            if (!empty($updates)) {
                $updates[] = "updated_at = NOW()";
                $sql = "UPDATE inv_items SET " . implode(', ', $updates) . " WHERE id = :id";
                $pdo->prepare($sql)->execute($updateParams);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $ex) {}
            sys_log('INV-RESTOCK-001', 'Restock failed: ' . $e->getMessage(), ['item_id' => $itemId]);
        }
    }

    $msg = [];
    if ($restocked > 0) $msg[] = "$restocked item(s) restocked";
    if ($costUpdated > 0) $msg[] = "$costUpdated cost(s) updated";
    if (!empty($msg)) {
        flash_success(implode(', ', $msg) . '.');
    } else {
        flash_error('No changes were made. Enter quantities or costs to restock.');
    }

    header('Location: ' . BASE_URL . '/modules/inventory/restock');
    exit;
}

// Filters
$search = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['category'] ?? 0);
$stockFilter = $_GET['stock'] ?? 'all';

$where = ["i.is_active = TRUE"];
$params = [];

if ($search !== '') {
    $where[] = "(i.name ILIKE :q OR i.sku ILIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($catFilter > 0) {
    $where[] = "i.category_id = :cat";
    $params[':cat'] = $catFilter;
}
if ($stockFilter === 'low') {
    $where[] = "i.qty_on_hand > 0 AND i.qty_on_hand <= i.reorder_level";
} elseif ($stockFilter === 'out') {
    $where[] = "i.qty_on_hand = 0";
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT i.id, i.sku, i.name, i.qty_on_hand, i.cost_price, i.reorder_level, i.unit, i.expiry_date,
               c.name as category_name,
               COALESCE(SUM(CASE WHEN sm.movement_type = 'sale' AND sm.created_at >= (CURRENT_DATE - INTERVAL '30 days') THEN ABS(sm.quantity) ELSE 0 END), 0) as monthly_sold
        FROM inv_items i
        LEFT JOIN inv_categories c ON c.id = i.category_id
        LEFT JOIN inv_stock_movements sm ON sm.item_id = i.id
        {$whereClause}
        GROUP BY i.id, i.sku, i.name, i.qty_on_hand, i.cost_price, i.reorder_level, i.unit, i.expiry_date, c.name
        ORDER BY i.qty_on_hand ASC, i.name ASC
        LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $items = [];
}

// Fetch categories for filter dropdown
$categories = [];
try {
    $categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$pageTitle = 'Restock Checklist';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Restock Checklist</h1>
            <p class="text-sm text-slate-500 mt-0.5">Quickly add stock to products without creating a purchase order</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="btn btn-outline text-sm">← Inventory</a>
            <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders" class="btn btn-primary text-sm">
                <svg class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Purchase Orders
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card card-body">
        <form method="get" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search products..." 
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <select name="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary text-sm">Search</button>
        </form>
    </div>

    <!-- Stock Filter Pills -->
    <div class="flex gap-2">
        <a href="<?= BASE_URL ?>/modules/inventory/restock?stock=all<?= $search ? '&q=' . urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-full text-sm font-medium transition <?= $stockFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50' ?>">
            All
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/restock?stock=low<?= $search ? '&q=' . urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-full text-sm font-medium transition <?= $stockFilter === 'low' ? 'bg-amber-500 text-white' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50' ?>">
            Low Stock
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/restock?stock=out<?= $search ? '&q=' . urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-full text-sm font-medium transition <?= $stockFilter === 'out' ? 'bg-red-500 text-white' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50' ?>">
            Out of Stock
        </a>
    </div>

    <!-- Restock Form -->
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="space-y-4">
            <?php if (empty($items)): ?>
                <div class="card card-body text-center py-12 text-sm text-slate-500">No items found matching your criteria.</div>
            <?php endif; ?>

            <?php foreach ($items as $item): ?>
            <div class="card card-body">
                <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($item['name']) ?></h3>
                            <?php if ($item['qty_on_hand'] == 0): ?>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">Out of Stock</span>
                            <?php elseif ($item['qty_on_hand'] <= $item['reorder_level']): ?>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-700">Low Stock</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($item['category_name'] ?? $item['sku']) ?></p>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4 text-center flex-shrink-0">
                        <div>
                            <p class="text-xs text-slate-400">Monthly Sold</p>
                            <p class="text-sm font-bold text-slate-900"><?= (int)$item['monthly_sold'] ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Current Stock</p>
                            <p class="text-sm font-bold <?= $item['qty_on_hand'] == 0 ? 'text-red-600' : ($item['qty_on_hand'] <= $item['reorder_level'] ? 'text-amber-600' : 'text-emerald-600') ?>">
                                <?= (int)$item['qty_on_hand'] ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Previous Cost</p>
                            <p class="text-sm font-bold text-slate-900">₱<?= number_format((float)$item['cost_price'], 2) ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 flex-shrink-0 min-w-[280px]">
                        <div>
                            <label class="text-xs text-slate-400">New Cost (Per Item)</label>
                            <input type="number" step="0.01" min="0" name="items[<?= $item['id'] ?>][cost]" 
                                   placeholder="₱0.00"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">New Stock (Qty)</label>
                            <input type="number" min="0" name="items[<?= $item['id'] ?>][quantity]" 
                                   value="0"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($items)): ?>
        <div class="mt-6 flex justify-center">
            <button type="submit" class="btn btn-primary px-8" data-confirm="Apply all restock changes? Stock movements will be recorded.">
                Apply Restock Changes
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
