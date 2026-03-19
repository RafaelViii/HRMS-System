<?php
/**
 * Inventory - Stock Movements Log
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Stock Movements';

$search = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$where = ["1=1"];
$params = [];
if ($search) { $where[] = "(i.name ILIKE :q OR i.sku ILIKE :q)"; $params[':q'] = '%'.$search.'%'; }
if ($typeFilter) { $where[] = "m.movement_type = :type"; $params[':type'] = $typeFilter; }
if ($dateFrom) { $where[] = "m.created_at >= :df"; $params[':df'] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $where[] = "m.created_at <= :dt"; $params[':dt'] = $dateTo . ' 23:59:59'; }

$whereClause = implode(' AND ', $where);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$stc = $pdo->prepare("SELECT COUNT(*) FROM inv_stock_movements m JOIN inv_items i ON i.id = m.item_id WHERE $whereClause");
$stc->execute($params);
$totalCount = (int)$stc->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

$sql = "SELECT m.*, i.name AS item_name, i.sku, u.full_name AS user_name
    FROM inv_stock_movements m
    JOIN inv_items i ON i.id = m.item_id
    LEFT JOIN users u ON u.id = m.created_by
    WHERE $whereClause
    ORDER BY m.created_at DESC
    LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$movements = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div>
    <h1 class="text-xl font-semibold text-gray-900">Stock Movements</h1>
    <p class="text-sm text-gray-500"><?= number_format($totalCount) ?> movements found</p>
  </div>

  <div class="bg-white rounded-xl border p-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search item..." class="input-text flex-1" />
      <select name="type" class="input-text">
        <option value="">All Types</option>
        <option value="receipt" <?= $typeFilter === 'receipt' ? 'selected' : '' ?>>Receipt</option>
        <option value="sale" <?= $typeFilter === 'sale' ? 'selected' : '' ?>>Sale</option>
        <option value="adjustment" <?= $typeFilter === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
        <option value="return" <?= $typeFilter === 'return' ? 'selected' : '' ?>>Return</option>
        <option value="disposal" <?= $typeFilter === 'disposal' ? 'selected' : '' ?>>Disposal</option>
        <option value="transfer" <?= $typeFilter === 'transfer' ? 'selected' : '' ?>>Transfer</option>
        <option value="initial" <?= $typeFilter === 'initial' ? 'selected' : '' ?>>Initial</option>
      </select>
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="input-text" />
      <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="input-text" />
      <button type="submit" class="btn btn-outline">Filter</button>
    </form>
  </div>

  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-3 text-left">Date</th>
            <th class="px-4 py-3 text-left">Item</th>
            <th class="px-4 py-3 text-left">Type</th>
            <th class="px-4 py-3 text-right">Quantity</th>
            <th class="px-4 py-3 text-right">Unit Cost</th>
            <th class="px-4 py-3 text-left">Notes</th>
            <th class="px-4 py-3 text-left">By</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($movements)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No movements found.</td></tr>
          <?php else: ?>
            <?php foreach ($movements as $mv): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-500 text-xs"><?= date('M d, Y h:i A', strtotime($mv['created_at'])) ?></td>
              <td class="px-4 py-2">
                <a href="<?= BASE_URL ?>/modules/inventory/item_view?id=<?= $mv['item_id'] ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars($mv['item_name']) ?></a>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($mv['sku']) ?></div>
              </td>
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
              <td class="px-4 py-2 text-right text-gray-600"><?= $mv['unit_cost'] !== null ? 'P'.number_format((float)$mv['unit_cost'], 2) : '-' ?></td>
              <td class="px-4 py-2 text-gray-600 max-w-xs truncate"><?= htmlspecialchars($mv['notes'] ?? '-') ?></td>
              <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($mv['user_name'] ?? 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between text-sm">
      <div class="text-gray-500">Page <?= $page ?> of <?= $totalPages ?></div>
      <div class="flex gap-1">
        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1 rounded border hover:bg-white">Prev</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1 rounded border hover:bg-white">Next</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
