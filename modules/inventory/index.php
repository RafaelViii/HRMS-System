<?php
/**
 * Inventory System - Dashboard / Landing Page
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Inventory Dashboard';

// Gather summary stats
$totalItems = (int)($pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE")->fetchColumn() ?: 0);
$lowStockCount = (int)($pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand <= reorder_level")->fetchColumn() ?: 0);
$outOfStock = (int)($pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand = 0")->fetchColumn() ?: 0);
$totalValue = (float)($pdo->query("SELECT COALESCE(SUM(qty_on_hand * cost_price), 0) FROM inv_items WHERE is_active = TRUE")->fetchColumn() ?: 0);
$totalCategories = (int)($pdo->query("SELECT COUNT(*) FROM inv_categories WHERE is_active = TRUE")->fetchColumn() ?: 0);
$totalSuppliers = (int)($pdo->query("SELECT COUNT(*) FROM inv_suppliers WHERE is_active = TRUE")->fetchColumn() ?: 0);

// Today's sales
$todaySales = (float)($pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM inv_transactions WHERE txn_type = 'sale' AND status = 'completed' AND DATE(created_at) = CURRENT_DATE")->fetchColumn() ?: 0);
$todayTxnCount = (int)($pdo->query("SELECT COUNT(*) FROM inv_transactions WHERE txn_type = 'sale' AND status = 'completed' AND DATE(created_at) = CURRENT_DATE")->fetchColumn() ?: 0);

// Expiring soon (within 30 days)
$expiringSoon = (int)($pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND expiry_date IS NOT NULL AND expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND expiry_date > CURRENT_DATE")->fetchColumn() ?: 0);
$alreadyExpired = (int)($pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE")->fetchColumn() ?: 0);

// Low stock items list
$lowStockItems = $pdo->query("SELECT i.id, i.sku, i.name, i.qty_on_hand, i.reorder_level, i.unit, c.name AS category_name
    FROM inv_items i LEFT JOIN inv_categories c ON c.id = i.category_id
    WHERE i.is_active = TRUE AND i.qty_on_hand <= i.reorder_level
    ORDER BY i.qty_on_hand ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Recent transactions
$recentTxns = $pdo->query("SELECT t.id, t.txn_number, t.txn_type, t.total_amount, t.payment_method, t.status, t.created_at, u.full_name AS cashier
    FROM inv_transactions t LEFT JOIN users u ON u.id = t.created_by
    ORDER BY t.created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// Recent stock movements
$recentMovements = $pdo->query("SELECT m.id, m.movement_type, m.quantity, m.created_at, i.name AS item_name, i.sku, u.full_name AS user_name
    FROM inv_stock_movements m
    JOIN inv_items i ON i.id = m.item_id
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Inventory Dashboard</h1>
      <p class="text-sm text-gray-500 mt-1">Inventory and point-of-sale overview</p>
    </div>
    <div class="flex gap-2">
      <a href="<?= BASE_URL ?>/modules/inventory/pos" class="btn btn-primary text-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
        Open POS
      </a>
      <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="btn btn-outline text-sm">
        Manage Inventory
      </a>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Items</div>
      <div class="mt-1 text-xl font-semibold text-gray-900"><?= number_format($totalItems) ?></div>
      <div class="mt-1 text-xs text-gray-400"><?= $totalCategories ?> categories</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Inventory Value</div>
      <div class="mt-1 text-xl font-semibold text-gray-900">P<?= number_format($totalValue, 2) ?></div>
      <div class="mt-1 text-xs text-gray-400">Based on cost price</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Today's Sales</div>
      <div class="mt-1 text-xl font-semibold text-emerald-600">P<?= number_format($todaySales, 2) ?></div>
      <div class="mt-1 text-xs text-gray-400"><?= $todayTxnCount ?> transactions</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Low Stock Alerts</div>
      <div class="mt-1 text-xl font-semibold <?= $lowStockCount > 0 ? 'text-amber-600' : 'text-gray-900' ?>"><?= $lowStockCount ?></div>
      <div class="mt-1 text-xs text-gray-400"><?= $outOfStock ?> out of stock</div>
    </div>
  </div>

  <!-- Alert Banners -->
  <?php if ($alreadyExpired > 0): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 flex items-center gap-3">
    <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm text-red-700"><strong><?= $alreadyExpired ?></strong> item(s) have already expired. <a href="<?= BASE_URL ?>/modules/inventory/inventory?stock=expired" class="underline font-medium">View expired items</a></span>
  </div>
  <?php endif; ?>
  <?php if ($expiringSoon > 0): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 flex items-center gap-3">
    <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm text-amber-700"><strong><?= $expiringSoon ?></strong> item(s) expiring within 30 days. <a href="<?= BASE_URL ?>/modules/inventory/inventory?stock=expiring" class="underline font-medium">View expiring items</a></span>
  </div>
  <?php endif; ?>

  <!-- Two Column Layout -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Low Stock Items -->
    <div class="bg-white rounded-xl border">
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900">Low Stock Items</h2>
        <a href="<?= BASE_URL ?>/modules/inventory/inventory?stock=low" class="text-xs text-blue-600 hover:underline">View all</a>
      </div>
      <?php if (empty($lowStockItems)): ?>
        <div class="px-4 py-8 text-center text-sm text-gray-400">All items are adequately stocked.</div>
      <?php else: ?>
        <div class="divide-y max-h-80 overflow-y-auto">
          <?php foreach ($lowStockItems as $li): ?>
          <div class="px-4 py-2.5 flex items-center justify-between hover:bg-gray-50">
            <div>
              <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($li['name']) ?></div>
              <div class="text-xs text-gray-500"><?= htmlspecialchars($li['sku']) ?> <?= $li['category_name'] ? '/ ' . htmlspecialchars($li['category_name']) : '' ?></div>
            </div>
            <div class="text-right">
              <div class="text-sm font-bold <?= $li['qty_on_hand'] == 0 ? 'text-red-600' : 'text-amber-600' ?>">
                <?= $li['qty_on_hand'] ?> <?= htmlspecialchars($li['unit']) ?>
              </div>
              <div class="text-xs text-gray-400">Reorder: <?= $li['reorder_level'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white rounded-xl border">
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900">Recent Transactions</h2>
        <a href="<?= BASE_URL ?>/modules/inventory/transactions" class="text-xs text-blue-600 hover:underline">View all</a>
      </div>
      <?php if (empty($recentTxns)): ?>
        <div class="px-4 py-8 text-center text-sm text-gray-400">No transactions yet.</div>
      <?php else: ?>
        <div class="divide-y max-h-80 overflow-y-auto">
          <?php foreach ($recentTxns as $tx): ?>
          <div class="px-4 py-2.5 flex items-center justify-between hover:bg-gray-50">
            <div>
              <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($tx['txn_number']) ?></div>
              <div class="text-xs text-gray-500"><?= htmlspecialchars($tx['cashier'] ?? 'System') ?> -- <?= date('M d, h:i A', strtotime($tx['created_at'])) ?></div>
            </div>
            <div class="text-right">
              <div class="text-sm font-bold <?= $tx['txn_type'] === 'return' ? 'text-red-600' : 'text-emerald-600' ?>">
                <?= $tx['txn_type'] === 'return' ? '-' : '' ?>P<?= number_format((float)$tx['total_amount'], 2) ?>
              </div>
              <div class="text-xs">
                <?php
                  $statusColors = ['completed'=>'text-emerald-600','voided'=>'text-red-600','refunded'=>'text-amber-600'];
                  $sc = $statusColors[$tx['status']] ?? 'text-gray-500';
                ?>
                <span class="<?= $sc ?> capitalize"><?= htmlspecialchars($tx['status']) ?></span>
                <span class="text-gray-400 ml-1"><?= htmlspecialchars($tx['payment_method']) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Stock Movements -->
  <div class="bg-white rounded-xl border">
    <div class="px-4 py-3 border-b flex items-center justify-between">
      <h2 class="font-semibold text-gray-900">Recent Stock Movements</h2>
      <a href="<?= BASE_URL ?>/modules/inventory/movements" class="text-xs text-blue-600 hover:underline">View all</a>
    </div>
    <?php if (empty($recentMovements)): ?>
      <div class="px-4 py-8 text-center text-sm text-gray-400">No stock movements recorded.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th class="px-4 py-2 text-left">Date</th>
              <th class="px-4 py-2 text-left">Item</th>
              <th class="px-4 py-2 text-left">Type</th>
              <th class="px-4 py-2 text-right">Qty</th>
              <th class="px-4 py-2 text-left">By</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <?php foreach ($recentMovements as $mv): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-500"><?= date('M d, h:i A', strtotime($mv['created_at'])) ?></td>
              <td class="px-4 py-2">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($mv['item_name']) ?></div>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($mv['sku']) ?></div>
              </td>
              <td class="px-4 py-2">
                <?php
                  $typeColors = ['receipt'=>'bg-emerald-100 text-emerald-700','sale'=>'bg-blue-100 text-blue-700','adjustment'=>'bg-amber-100 text-amber-700','return'=>'bg-purple-100 text-purple-700','transfer'=>'bg-cyan-100 text-cyan-700','disposal'=>'bg-red-100 text-red-700','initial'=>'bg-gray-100 text-gray-700'];
                  $tc = $typeColors[$mv['movement_type']] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $tc ?>"><?= htmlspecialchars(ucfirst($mv['movement_type'])) ?></span>
              </td>
              <td class="px-4 py-2 text-right font-medium <?= in_array($mv['movement_type'], ['sale','disposal','transfer']) ? 'text-red-600' : 'text-emerald-600' ?>">
                <?= in_array($mv['movement_type'], ['sale','disposal','transfer']) ? '-' : '+' ?><?= abs($mv['quantity']) ?>
              </td>
              <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($mv['user_name'] ?? 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Quick Links -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <a href="<?= BASE_URL ?>/modules/inventory/categories" class="bg-white rounded-xl border p-4 hover:bg-gray-50 transition group">
      <div class="text-sm font-medium text-gray-900 group-hover:text-blue-600">Categories</div>
      <div class="text-xs text-gray-500 mt-1">Manage item categories</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/inventory/suppliers" class="bg-white rounded-xl border p-4 hover:bg-gray-50 transition group">
      <div class="text-sm font-medium text-gray-900 group-hover:text-blue-600">Suppliers</div>
      <div class="text-xs text-gray-500 mt-1"><?= $totalSuppliers ?> active suppliers</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/inventory/locations" class="bg-white rounded-xl border p-4 hover:bg-gray-50 transition group">
      <div class="text-sm font-medium text-gray-900 group-hover:text-blue-600">Locations</div>
      <div class="text-xs text-gray-500 mt-1">Storage rooms &amp; shelves</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/inventory/reports" class="bg-white rounded-xl border p-4 hover:bg-gray-50 transition group">
      <div class="text-sm font-medium text-gray-900 group-hover:text-blue-600">Reports</div>
      <div class="text-xs text-gray-500 mt-1">Analytics &amp; exports</div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
