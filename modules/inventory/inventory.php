<?php
/**
 * Inventory - Main inventory list with groupings, bulk & manual add
 * Replaces the old items.php with richer grouping/filtering and dual add paths
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Inventory';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');
$canManage = user_has_access($uid, 'inventory', 'inventory_items', 'manage');

// Handle deactivation
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
    header('Location: ' . BASE_URL . '/modules/inventory/inventory');
    exit;
}

// Filters
$search = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['category'] ?? 0);
$supplierFilter = (int)($_GET['supplier'] ?? 0);
$locationFilter = (int)($_GET['location'] ?? 0);
$stockFilter = $_GET['stock'] ?? '';
$groupBy = $_GET['group'] ?? 'none';
$view = $_GET['view'] ?? 'table';

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
if ($supplierFilter > 0) {
    $where[] = "i.supplier_id = :sup";
    $params[':sup'] = $supplierFilter;
}
if ($locationFilter > 0) {
    $where[] = "i.location_id = :loc";
    $params[':loc'] = $locationFilter;
}
if ($stockFilter === 'low') {
    $where[] = "i.qty_on_hand <= i.reorder_level AND i.qty_on_hand > 0";
} elseif ($stockFilter === 'out') {
    $where[] = "i.qty_on_hand = 0";
} elseif ($stockFilter === 'expired') {
    $where[] = "i.expiry_date IS NOT NULL AND i.expiry_date < CURRENT_DATE";
} elseif ($stockFilter === 'expiring') {
    $where[] = "i.expiry_date IS NOT NULL AND i.expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND i.expiry_date >= CURRENT_DATE";
} elseif ($stockFilter === 'adequate') {
    $where[] = "i.qty_on_hand > i.reorder_level";
}

$whereClause = implode(' AND ', $where);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$stCount = $pdo->prepare("SELECT COUNT(*) FROM inv_items i WHERE $whereClause");
$stCount->execute($params);
$totalCount = (int)$stCount->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

// Group ordering
$orderClause = match($groupBy) {
    'category' => 'c.name ASC NULLS LAST, i.name ASC',
    'supplier' => 's.name ASC NULLS LAST, i.name ASC',
    'location' => 'l.name ASC NULLS LAST, i.name ASC',
    default    => 'i.name ASC',
};

$sql = "SELECT i.*, c.name AS category_name, s.name AS supplier_name, l.name AS location_name
    FROM inv_items i
    LEFT JOIN inv_categories c ON c.id = i.category_id
    LEFT JOIN inv_suppliers s ON s.id = i.supplier_id
    LEFT JOIN inv_locations l ON l.id = i.location_id
    WHERE $whereClause
    ORDER BY $orderClause
    LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$items = $st->fetchAll(PDO::FETCH_ASSOC);

// Summary counts for badges
$summarySt = $pdo->query("SELECT
    COUNT(*) FILTER (WHERE is_active) AS total,
    COUNT(*) FILTER (WHERE is_active AND qty_on_hand <= reorder_level AND qty_on_hand > 0) AS low,
    COUNT(*) FILTER (WHERE is_active AND qty_on_hand = 0) AS out_of_stock,
    COUNT(*) FILTER (WHERE is_active AND expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE) AS expired,
    COUNT(*) FILTER (WHERE is_active AND expiry_date IS NOT NULL AND expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND expiry_date >= CURRENT_DATE) AS expiring
    FROM inv_items");
$summary = $summarySt->fetch(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM inv_suppliers WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM inv_locations WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <!-- Page Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Inventory</h1>
      <p class="text-sm text-gray-500"><?= number_format($totalCount) ?> items found</p>
    </div>
    <?php if ($canWrite): ?>
    <div class="flex gap-2">
      <a href="<?= BASE_URL ?>/modules/inventory/item_form" class="btn btn-primary text-sm">
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Add Item
      </a>
      <a href="<?= BASE_URL ?>/modules/inventory/bulk_import" class="btn btn-outline text-sm">
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        Bulk Import
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Stock Status Badges -->
  <div class="flex flex-wrap gap-2">
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => '', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
      All <span class="ml-1 opacity-75"><?= number_format($summary['total']) ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'adequate', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === 'adequate' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?>">
      Adequate
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'low', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === 'low' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100' ?>">
      Low Stock <span class="ml-1 opacity-75"><?= $summary['low'] ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'out', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === 'out' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' ?>">
      Out of Stock <span class="ml-1 opacity-75"><?= $summary['out_of_stock'] ?></span>
    </a>
    <?php if ($summary['expiring'] > 0): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'expiring', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === 'expiring' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100' ?>">
      Expiring Soon <span class="ml-1 opacity-75"><?= $summary['expiring'] ?></span>
    </a>
    <?php endif; ?>
    <?php if ($summary['expired'] > 0): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'expired', 'page' => 1])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $stockFilter === 'expired' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' ?>">
      Expired <span class="ml-1 opacity-75"><?= $summary['expired'] ?></span>
    </a>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-xl border p-4">
    <form method="GET" class="space-y-3">
      <?php if ($view !== 'table') : ?><input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>" /><?php endif; ?>
      <div class="flex flex-col md:flex-row gap-3">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, SKU, barcode, generic name..." class="input-text flex-1" />
        <button type="submit" class="btn btn-outline text-sm">Search</button>
        <?php if ($search || $catFilter || $supplierFilter || $locationFilter || $stockFilter): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="btn btn-outline text-sm text-gray-500">Clear</a>
        <?php endif; ?>
      </div>
      <div class="flex flex-col md:flex-row gap-3">
        <select name="category" class="input-text" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="supplier" class="input-text" onchange="this.form.submit()">
          <option value="">All Suppliers</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $supplierFilter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="location" class="input-text" onchange="this.form.submit()">
          <option value="">All Locations</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>" <?= $locationFilter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="group" class="input-text" onchange="this.form.submit()">
          <option value="none" <?= $groupBy === 'none' ? 'selected' : '' ?>>No Grouping</option>
          <option value="category" <?= $groupBy === 'category' ? 'selected' : '' ?>>Group by Category</option>
          <option value="supplier" <?= $groupBy === 'supplier' ? 'selected' : '' ?>>Group by Supplier</option>
          <option value="location" <?= $groupBy === 'location' ? 'selected' : '' ?>>Group by Location</option>
        </select>
      </div>
    </form>
  </div>

  <!-- Items Table with Grouping -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-3 text-left">SKU</th>
            <th class="px-4 py-3 text-left">Item Name</th>
            <?php if ($groupBy !== 'category'): ?><th class="px-4 py-3 text-left hidden md:table-cell">Category</th><?php endif; ?>
            <?php if ($groupBy !== 'supplier'): ?><th class="px-4 py-3 text-left hidden lg:table-cell">Supplier</th><?php endif; ?>
            <?php if ($groupBy !== 'location'): ?><th class="px-4 py-3 text-left hidden lg:table-cell">Location</th><?php endif; ?>
            <th class="px-4 py-3 text-right">Cost</th>
            <th class="px-4 py-3 text-right">Price</th>
            <th class="px-4 py-3 text-right">Stock</th>
            <th class="px-4 py-3 text-left hidden md:table-cell">Expiry</th>
            <th class="px-4 py-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($items)): ?>
            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">No items found.</td></tr>
          <?php else:
            $currentGroup = null;
            $groupField = match($groupBy) { 'category' => 'category_name', 'supplier' => 'supplier_name', 'location' => 'location_name', default => null };
            $colSpan = 10 - ($groupBy !== 'none' ? 1 : 0);
            foreach ($items as $item):
              // Group header row
              if ($groupField !== null):
                $groupVal = $item[$groupField] ?? 'Uncategorized';
                if ($groupVal !== $currentGroup):
                  $currentGroup = $groupVal;
          ?>
            <tr class="bg-gray-100">
              <td colspan="<?= $colSpan ?>" class="px-4 py-2 text-xs font-bold text-gray-700 uppercase tracking-wide">
                <?= htmlspecialchars($currentGroup) ?>
              </td>
            </tr>
          <?php endif; endif; ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2.5 font-mono text-xs text-gray-600"><?= htmlspecialchars($item['sku']) ?></td>
              <td class="px-4 py-2.5">
                <a href="<?= BASE_URL ?>/modules/inventory/item_view?id=<?= $item['id'] ?>" class="font-medium text-gray-900 hover:text-blue-600"><?= htmlspecialchars($item['name']) ?></a>
                <?php if ($item['generic_name']): ?>
                  <div class="text-xs text-gray-400"><?= htmlspecialchars($item['generic_name']) ?></div>
                <?php endif; ?>
              </td>
              <?php if ($groupBy !== 'category'): ?><td class="px-4 py-2.5 text-gray-600 hidden md:table-cell"><?= htmlspecialchars($item['category_name'] ?? '-') ?></td><?php endif; ?>
              <?php if ($groupBy !== 'supplier'): ?><td class="px-4 py-2.5 text-gray-600 hidden lg:table-cell"><?= htmlspecialchars($item['supplier_name'] ?? '-') ?></td><?php endif; ?>
              <?php if ($groupBy !== 'location'): ?><td class="px-4 py-2.5 text-gray-600 hidden lg:table-cell"><?= htmlspecialchars($item['location_name'] ?? '-') ?></td><?php endif; ?>
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
              <td class="px-4 py-2.5 hidden md:table-cell">
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

    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between text-sm">
      <div class="text-gray-500">Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalCount) ?> items)</div>
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
