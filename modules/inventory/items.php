<?php
/**
 * Redirect: items.php → inventory.php (renamed)
 */
require_once __DIR__ . '/../../includes/config.php';
header('Location: ' . BASE_URL . '/modules/inventory/inventory' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Inventory Items';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');
$canManage = user_has_access($uid, 'inventory', 'inventory_items', 'manage');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && csrf_verify($_POST['csrf'] ?? '')) {
    $authz = ensure_action_authorized('inventory', 'delete_item', 'manage');
    if ($authz['ok']) {
        $did = (int)$_POST['delete_id'];
        $pdo->prepare("UPDATE inv_items SET is_active = FALSE, updated_at = NOW() WHERE id = :id")->execute([':id' => $did]);
        action_log('inventory', 'deactivate_item', 'success', ['item_id' => $did]);
        flash_success('Item deactivated successfully.');
    } else {
        flash_error('Authorization failed.');
    }
    header('Location: ' . BASE_URL . '/modules/inventory/items');
    exit;
}

// Filters
$search = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['category'] ?? 0);
$filter = $_GET['filter'] ?? '';

$where = ["i.is_active = TRUE"];
$params = [];

if ($search !== '') {
    $where[] = "(i.name ILIKE :q OR i.sku ILIKE :q OR i.barcode ILIKE :q OR i.generic_name ILIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($catFilter > 0) {
    $where[] = "i.category_id = :cat";
    $params[':cat'] = $catFilter;
}
if ($filter === 'low_stock') {
    $where[] = "i.qty_on_hand <= i.reorder_level AND i.qty_on_hand > 0";
} elseif ($filter === 'out_of_stock') {
    $where[] = "i.qty_on_hand = 0";
} elseif ($filter === 'expired') {
    $where[] = "i.expiry_date IS NOT NULL AND i.expiry_date < CURRENT_DATE";
} elseif ($filter === 'expiring') {
    $where[] = "i.expiry_date IS NOT NULL AND i.expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND i.expiry_date >= CURRENT_DATE";
}

$whereClause = implode(' AND ', $where);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM inv_items i WHERE $whereClause";
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$totalCount = (int)$stCount->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

$sql = "SELECT i.*, c.name AS category_name, s.name AS supplier_name, l.name AS location_name
    FROM inv_items i
    LEFT JOIN inv_categories c ON c.id = i.category_id
    LEFT JOIN inv_suppliers s ON s.id = i.supplier_id
    LEFT JOIN inv_locations l ON l.id = i.location_id
    WHERE $whereClause
    ORDER BY i.name ASC
    LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Inventory Items</h1>
      <p class="text-sm text-gray-500"><?= number_format($totalCount) ?> items found</p>
    </div>
    <?php if ($canWrite): ?>
    <a href="<?= BASE_URL ?>/modules/inventory/item_form" class="btn btn-primary text-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Add Item
    </a>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-xl border p-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, SKU, barcode..." class="input-text flex-1" />
      <select name="category" class="input-text">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="filter" class="input-text">
        <option value="">All Stock</option>
        <option value="low_stock" <?= $filter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
        <option value="out_of_stock" <?= $filter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
        <option value="expired" <?= $filter === 'expired' ? 'selected' : '' ?>>Expired</option>
        <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
      </select>
      <button type="submit" class="btn btn-outline text-sm">Filter</button>
      <?php if ($search || $catFilter || $filter): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/items" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Items Table -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-3 text-left">SKU</th>
            <th class="px-4 py-3 text-left">Item Name</th>
            <th class="px-4 py-3 text-left">Category</th>
            <th class="px-4 py-3 text-left">Location</th>
            <th class="px-4 py-3 text-right">Cost</th>
            <th class="px-4 py-3 text-right">Price</th>
            <th class="px-4 py-3 text-right">Stock</th>
            <th class="px-4 py-3 text-left">Expiry</th>
            <th class="px-4 py-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($items)): ?>
            <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">No items found.</td></tr>
          <?php else: ?>
            <?php foreach ($items as $item): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2.5 font-mono text-xs text-gray-600"><?= htmlspecialchars($item['sku']) ?></td>
              <td class="px-4 py-2.5">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></div>
                <?php if ($item['generic_name']): ?>
                  <div class="text-xs text-gray-400"><?= htmlspecialchars($item['generic_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2.5 text-gray-600"><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= htmlspecialchars($item['location_name'] ?? '-') ?></td>
              <td class="px-4 py-2.5 text-right text-gray-600">P<?= number_format((float)$item['cost_price'], 2) ?></td>
              <td class="px-4 py-2.5 text-right text-gray-900 font-medium">P<?= number_format((float)$item['selling_price'], 2) ?></td>
              <td class="px-4 py-2.5 text-right">
                <?php
                  $stockClass = 'text-gray-900';
                  if ($item['qty_on_hand'] == 0) $stockClass = 'text-red-600 font-bold';
                  elseif ($item['qty_on_hand'] <= $item['reorder_level']) $stockClass = 'text-amber-600 font-bold';
                ?>
                <span class="<?= $stockClass ?>"><?= $item['qty_on_hand'] ?></span>
                <span class="text-xs text-gray-400"><?= htmlspecialchars($item['unit']) ?></span>
              </td>
              <td class="px-4 py-2.5">
                <?php if ($item['expiry_date']): ?>
                  <?php
                    $exp = strtotime($item['expiry_date']);
                    $now = time();
                    $expClass = 'text-gray-600';
                    if ($exp < $now) $expClass = 'text-red-600 font-medium';
                    elseif ($exp < $now + 30*86400) $expClass = 'text-amber-600 font-medium';
                  ?>
                  <span class="text-xs <?= $expClass ?>"><?= date('M d, Y', $exp) ?></span>
                <?php else: ?>
                  <span class="text-xs text-gray-400">-</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2.5 text-center">
                <div class="flex items-center justify-center gap-1">
                  <a href="<?= BASE_URL ?>/modules/inventory/item_view?id=<?= $item['id'] ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-blue-600" title="View">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  </a>
                  <?php if ($canWrite): ?>
                  <a href="<?= BASE_URL ?>/modules/inventory/item_form?id=<?= $item['id'] ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-blue-600" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  </a>
                  <?php endif; ?>
                  <?php if ($canManage): ?>
                  <form method="POST" class="inline" data-confirm="Deactivate this item?" data-confirm-auth data-module="inventory" data-action="delete_item" data-level="manage">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
                    <input type="hidden" name="delete_id" value="<?= $item['id'] ?>" />
                    <button type="submit" class="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-600" title="Deactivate">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between text-sm">
      <div class="text-gray-500">Page <?= $page ?> of <?= $totalPages ?></div>
      <div class="flex gap-1">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1 rounded border hover:bg-white">Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1 rounded border hover:bg-white">Next</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
