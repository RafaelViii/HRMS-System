<?php
/**
 * Inventory - Reports & Analytics
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_reports', 'read');

$pdo = get_db_conn();
$pageTitle = 'Inventory Reports';

$report = $_GET['report'] ?? 'overview';

// Date range defaults
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Overview metrics
if ($report === 'overview') {
    $totalItems = (int)$pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active")->fetchColumn();
    $totalValue = (float)$pdo->query("SELECT COALESCE(SUM(cost_price * qty_on_hand), 0) FROM inv_items WHERE is_active")->fetchColumn();
    $totalSaleValue = (float)$pdo->query("SELECT COALESCE(SUM(selling_price * qty_on_hand), 0) FROM inv_items WHERE is_active")->fetchColumn();
    $lowStock = (int)$pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active AND qty_on_hand <= reorder_level")->fetchColumn();
    $outOfStock = (int)$pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active AND qty_on_hand <= 0")->fetchColumn();
    $expiringSoon = (int)$pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active AND expiry_date IS NOT NULL AND expiry_date BETWEEN NOW() AND NOW() + INTERVAL '30 days'")->fetchColumn();
    $expired = (int)$pdo->query("SELECT COUNT(*) FROM inv_items WHERE is_active AND expiry_date IS NOT NULL AND expiry_date < NOW()")->fetchColumn();

    // Sales this period
    $stSales = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM inv_transactions WHERE status = 'completed' AND transaction_date BETWEEN :f AND :t");
    $stSales->execute([':f' => $dateFrom . ' 00:00:00', ':t' => $dateTo . ' 23:59:59']);
    $periodSales = (float)$stSales->fetchColumn();

    $stSalesCount = $pdo->prepare("SELECT COUNT(*) FROM inv_transactions WHERE status = 'completed' AND transaction_date BETWEEN :f AND :t");
    $stSalesCount->execute([':f' => $dateFrom . ' 00:00:00', ':t' => $dateTo . ' 23:59:59']);
    $periodSalesCount = (int)$stSalesCount->fetchColumn();

    // Daily sales for chart
    $stDaily = $pdo->prepare("
        SELECT DATE(transaction_date) AS day, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
        FROM inv_transactions WHERE status = 'completed' AND transaction_date BETWEEN :f AND :t
        GROUP BY DATE(transaction_date) ORDER BY day
    ");
    $stDaily->execute([':f' => $dateFrom . ' 00:00:00', ':t' => $dateTo . ' 23:59:59']);
    $dailySales = $stDaily->fetchAll(PDO::FETCH_ASSOC);

    // Top selling items
    $stTop = $pdo->prepare("
        SELECT i.name, i.sku, SUM(ti.quantity) AS total_qty, SUM(ti.line_total) AS total_revenue
        FROM inv_transaction_items ti
        JOIN inv_items i ON i.id = ti.item_id
        JOIN inv_transactions t ON t.id = ti.txn_id
        WHERE t.status = 'completed' AND t.transaction_date BETWEEN :f AND :t
        GROUP BY i.id, i.name, i.sku ORDER BY total_qty DESC LIMIT 10
    ");
    $stTop->execute([':f' => $dateFrom . ' 00:00:00', ':t' => $dateTo . ' 23:59:59']);
    $topItems = $stTop->fetchAll(PDO::FETCH_ASSOC);

    // Category breakdown
    $stCat = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorized') AS cat_name, COUNT(DISTINCT i.id) AS item_count,
               COALESCE(SUM(i.cost_price * i.qty_on_hand), 0) AS stock_value
        FROM inv_items i LEFT JOIN inv_categories c ON c.id = i.category_id WHERE i.is_active
        GROUP BY c.name ORDER BY stock_value DESC
    ");
    $stCat->execute();
    $catBreakdown = $stCat->fetchAll(PDO::FETCH_ASSOC);
}

// Stock valuation report
if ($report === 'valuation') {
    $orderBy = $_GET['sort'] ?? 'value_desc';
    $orderSql = match($orderBy) {
        'name' => 'i.name ASC',
        'qty' => 'i.qty_on_hand DESC',
        'cost' => 'i.cost_price DESC',
        'value_asc' => 'stock_value ASC',
        default => 'stock_value DESC',
    };
    $stVal = $pdo->prepare("
        SELECT i.*, c.name AS category_name, l.name AS location_name,
               (i.cost_price * i.qty_on_hand) AS stock_value
        FROM inv_items i
        LEFT JOIN inv_categories c ON c.id = i.category_id
        LEFT JOIN inv_locations l ON l.id = i.location_id
        WHERE i.is_active
        ORDER BY $orderSql
    ");
    $stVal->execute();
    $valuationItems = $stVal->fetchAll(PDO::FETCH_ASSOC);
    $totalValuation = array_sum(array_column($valuationItems, 'stock_value'));
}

// Expiry report
if ($report === 'expiry') {
    $stExp = $pdo->prepare("
        SELECT i.*, c.name AS category_name, l.name AS location_name
        FROM inv_items i
        LEFT JOIN inv_categories c ON c.id = i.category_id
        LEFT JOIN inv_locations l ON l.id = i.location_id
        WHERE i.is_active AND i.expiry_date IS NOT NULL
        ORDER BY i.expiry_date ASC
    ");
    $stExp->execute();
    $expiryItems = $stExp->fetchAll(PDO::FETCH_ASSOC);
}

// Movement analysis
if ($report === 'movements') {
    $stMA = $pdo->prepare("
        SELECT i.name, i.sku,
               COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END), 0) AS total_in,
               COALESCE(SUM(CASE WHEN m.quantity < 0 THEN ABS(m.quantity) ELSE 0 END), 0) AS total_out,
               COALESCE(SUM(m.quantity), 0) AS net_change,
               i.qty_on_hand AS current_stock
        FROM inv_items i
        JOIN inv_stock_movements m ON m.item_id = i.id
        WHERE i.is_active AND m.created_at BETWEEN :f AND :t
        GROUP BY i.id, i.name, i.sku, i.qty_on_hand
        ORDER BY total_out DESC
    ");
    $stMA->execute([':f' => $dateFrom . ' 00:00:00', ':t' => $dateTo . ' 23:59:59']);
    $movementAnalysis = $stMA->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Inventory Reports</h1>
      <p class="text-sm text-gray-500">Analytics and reporting for inventory operations</p>
    </div>
  </div>

  <!-- Report navigation tabs -->
  <div class="bg-white rounded-xl border">
    <div class="flex border-b overflow-x-auto">
      <?php
        $tabs = [
          'overview' => 'Overview',
          'valuation' => 'Stock Valuation',
          'expiry' => 'Expiry Tracking',
          'movements' => 'Movement Analysis',
        ];
        foreach ($tabs as $key => $label):
          $isActive = $report === $key;
      ?>
      <a href="?report=<?= $key ?>&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>"
         class="px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 <?= $isActive ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if (in_array($report, ['overview', 'movements'])): ?>
    <div class="p-4 border-b bg-gray-50">
      <form method="GET" class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="report" value="<?= htmlspecialchars($report) ?>" />
        <label class="text-sm text-gray-600">From:</label>
        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="input-text" />
        <label class="text-sm text-gray-600">To:</label>
        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="input-text" />
        <button type="submit" class="btn btn-primary">Apply</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($report === 'overview'): ?>
  <!-- Overview Report -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xl font-semibold text-gray-900"><?= number_format($totalItems) ?></div>
      <div class="text-xs text-gray-500 mt-1">Total Items</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xl font-semibold text-gray-900">P<?= number_format($totalValue, 2) ?></div>
      <div class="text-xs text-gray-500 mt-1">Stock Value (Cost)</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xl font-semibold text-emerald-600">P<?= number_format($periodSales, 2) ?></div>
      <div class="text-xs text-gray-500 mt-1">Period Sales (<?= $periodSalesCount ?> txns)</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xl font-semibold <?= $lowStock > 0 ? 'text-amber-600' : 'text-gray-900' ?>"><?= $lowStock ?></div>
      <div class="text-xs text-gray-500 mt-1">Low Stock Items</div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="bg-white rounded-xl border p-4">
      <div class="text-lg font-bold text-red-600"><?= $outOfStock ?></div>
      <div class="text-xs text-gray-500">Out of Stock</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-lg font-bold text-amber-600"><?= $expiringSoon ?></div>
      <div class="text-xs text-gray-500">Expiring (30 days)</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-lg font-bold text-red-600"><?= $expired ?></div>
      <div class="text-xs text-gray-500">Expired</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-lg font-bold text-blue-600">P<?= number_format($totalSaleValue, 2) ?></div>
      <div class="text-xs text-gray-500">Stock Value (Retail)</div>
    </div>
  </div>

  <!-- Daily Sales Chart -->
  <?php if (!empty($dailySales)): ?>
  <div class="bg-white rounded-xl border p-4">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Daily Sales</h3>
    <div style="position:relative; width:100%; height:250px;">
      <canvas id="dailySalesChart"
        data-chart="bar"
        data-labels='<?= json_encode(array_column($dailySales, 'day')) ?>'
        data-datasets='<?= json_encode([[
          "label" => "Revenue (P)",
          "data" => array_map(fn($r) => (float)$r['total'], $dailySales),
          "backgroundColor" => "rgba(59,130,246,0.5)",
          "borderColor" => "rgb(59,130,246)",
          "borderWidth" => 1
        ]]) ?>'
      ></canvas>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Top Selling Items -->
    <div class="bg-white rounded-xl border">
      <div class="px-4 py-3 border-b"><h3 class="text-sm font-semibold text-gray-700">Top Selling Items</h3></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
            <th class="px-4 py-2 text-left">Item</th>
            <th class="px-4 py-2 text-right">Qty Sold</th>
            <th class="px-4 py-2 text-right">Revenue</th>
          </tr></thead>
          <tbody class="divide-y">
            <?php if (empty($topItems)): ?>
              <tr><td colspan="3" class="px-4 py-4 text-center text-gray-400">No sales in this period.</td></tr>
            <?php else: foreach ($topItems as $ti): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                  <div class="font-medium text-gray-900"><?= htmlspecialchars($ti['name']) ?></div>
                  <div class="text-xs text-gray-400"><?= htmlspecialchars($ti['sku']) ?></div>
                </td>
                <td class="px-4 py-2 text-right font-medium"><?= number_format((int)$ti['total_qty']) ?></td>
                <td class="px-4 py-2 text-right text-emerald-600 font-medium">P<?= number_format((float)$ti['total_revenue'], 2) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Category Breakdown -->
    <div class="bg-white rounded-xl border">
      <div class="px-4 py-3 border-b"><h3 class="text-sm font-semibold text-gray-700">Stock by Category</h3></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
            <th class="px-4 py-2 text-left">Category</th>
            <th class="px-4 py-2 text-right">Items</th>
            <th class="px-4 py-2 text-right">Stock Value</th>
          </tr></thead>
          <tbody class="divide-y">
            <?php foreach ($catBreakdown as $cb): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-medium text-gray-900"><?= htmlspecialchars($cb['cat_name']) ?></td>
                <td class="px-4 py-2 text-right"><?= number_format((int)$cb['item_count']) ?></td>
                <td class="px-4 py-2 text-right text-blue-600 font-medium">P<?= number_format((float)$cb['stock_value'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($report === 'valuation'): ?>
  <!-- Stock Valuation Report -->
  <div class="bg-white rounded-xl border">
    <div class="px-4 py-3 border-b flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Stock Valuation - Total: P<?= number_format($totalValuation, 2) ?></h3>
      <div class="flex flex-wrap gap-2 text-xs">
        <a href="?report=valuation&sort=value_desc" class="px-2 py-1 rounded <?= ($orderBy ?? 'value_desc') === 'value_desc' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:bg-gray-100' ?>">Value (High)</a>
        <a href="?report=valuation&sort=name" class="px-2 py-1 rounded <?= ($orderBy ?? '') === 'name' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:bg-gray-100' ?>">Name</a>
        <a href="?report=valuation&sort=qty" class="px-2 py-1 rounded <?= ($orderBy ?? '') === 'qty' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:bg-gray-100' ?>">Quantity</a>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
          <th class="px-4 py-2 text-left">Item</th>
          <th class="px-4 py-2 text-left">Category</th>
          <th class="px-4 py-2 text-left">Location</th>
          <th class="px-4 py-2 text-right">On Hand</th>
          <th class="px-4 py-2 text-right">Cost Price</th>
          <th class="px-4 py-2 text-right">Sell Price</th>
          <th class="px-4 py-2 text-right">Stock Value</th>
          <th class="px-4 py-2 text-right">Margin</th>
        </tr></thead>
        <tbody class="divide-y">
          <?php foreach ($valuationItems as $vi):
            $margin = $vi['selling_price'] > 0 ? (($vi['selling_price'] - $vi['cost_price']) / $vi['selling_price']) * 100 : 0;
          ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2">
                <a href="<?= BASE_URL ?>/modules/inventory/item_view?id=<?= $vi['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars($vi['name']) ?></a>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($vi['sku']) ?></div>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($vi['category_name'] ?? '-') ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($vi['location_name'] ?? '-') ?></td>
              <td class="px-4 py-2 text-right font-medium"><?= number_format((int)$vi['qty_on_hand']) ?></td>
              <td class="px-4 py-2 text-right">P<?= number_format((float)$vi['cost_price'], 2) ?></td>
              <td class="px-4 py-2 text-right">P<?= number_format((float)$vi['selling_price'], 2) ?></td>
              <td class="px-4 py-2 text-right font-bold text-blue-600">P<?= number_format((float)$vi['stock_value'], 2) ?></td>
              <td class="px-4 py-2 text-right <?= $margin > 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= number_format($margin, 1) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-gray-50 font-bold">
          <tr>
            <td colspan="6" class="px-4 py-2 text-right">Total Stock Value:</td>
            <td class="px-4 py-2 text-right text-blue-700">P<?= number_format($totalValuation, 2) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($report === 'expiry'): ?>
  <!-- Expiry Tracking -->
  <div class="bg-white rounded-xl border">
    <div class="px-4 py-3 border-b"><h3 class="text-sm font-semibold text-gray-700">Items with Expiry Dates</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
          <th class="px-4 py-2 text-left">Item</th>
          <th class="px-4 py-2 text-left">Category</th>
          <th class="px-4 py-2 text-left">Location</th>
          <th class="px-4 py-2 text-right">On Hand</th>
          <th class="px-4 py-2 text-left">Expiry Date</th>
          <th class="px-4 py-2 text-left">Status</th>
          <th class="px-4 py-2 text-right">Stock Value</th>
        </tr></thead>
        <tbody class="divide-y">
          <?php if (empty($expiryItems)): ?>
            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No items with expiry dates.</td></tr>
          <?php else: foreach ($expiryItems as $ei):
            $expDate = new DateTime($ei['expiry_date']);
            $now = new DateTime();
            $daysUntil = (int)$now->diff($expDate)->format('%r%a');
            if ($daysUntil < 0) { $statusCls = 'bg-red-100 text-red-700'; $statusLabel = 'Expired'; }
            elseif ($daysUntil <= 30) { $statusCls = 'bg-amber-100 text-amber-700'; $statusLabel = $daysUntil . ' days left'; }
            elseif ($daysUntil <= 90) { $statusCls = 'bg-yellow-100 text-yellow-700'; $statusLabel = $daysUntil . ' days left'; }
            else { $statusCls = 'bg-emerald-100 text-emerald-700'; $statusLabel = $daysUntil . ' days left'; }
          ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2">
                <a href="<?= BASE_URL ?>/modules/inventory/item_view?id=<?= $ei['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars($ei['name']) ?></a>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($ei['sku']) ?></div>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($ei['category_name'] ?? '-') ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($ei['location_name'] ?? '-') ?></td>
              <td class="px-4 py-2 text-right font-medium"><?= number_format((int)$ei['qty_on_hand']) ?></td>
              <td class="px-4 py-2 text-gray-700"><?= date('M d, Y', strtotime($ei['expiry_date'])) ?></td>
              <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $statusCls ?>"><?= $statusLabel ?></span></td>
              <td class="px-4 py-2 text-right font-medium">P<?= number_format((float)$ei['cost_price'] * (int)$ei['qty_on_hand'], 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($report === 'movements'): ?>
  <!-- Movement Analysis -->
  <div class="bg-white rounded-xl border">
    <div class="px-4 py-3 border-b"><h3 class="text-sm font-semibold text-gray-700">Stock Movement Analysis (<?= date('M d', strtotime($dateFrom)) ?> - <?= date('M d, Y', strtotime($dateTo)) ?>)</h3></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
          <th class="px-4 py-2 text-left">Item</th>
          <th class="px-4 py-2 text-right">Total In</th>
          <th class="px-4 py-2 text-right">Total Out</th>
          <th class="px-4 py-2 text-right">Net Change</th>
          <th class="px-4 py-2 text-right">Current Stock</th>
        </tr></thead>
        <tbody class="divide-y">
          <?php if (empty($movementAnalysis)): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No movements in this period.</td></tr>
          <?php else: foreach ($movementAnalysis as $ma): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($ma['name']) ?></div>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($ma['sku']) ?></div>
              </td>
              <td class="px-4 py-2 text-right text-emerald-600 font-medium">+<?= number_format((int)$ma['total_in']) ?></td>
              <td class="px-4 py-2 text-right text-red-600 font-medium">-<?= number_format((int)$ma['total_out']) ?></td>
              <td class="px-4 py-2 text-right font-bold <?= (int)$ma['net_change'] >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">
                <?= (int)$ma['net_change'] >= 0 ? '+' : '' ?><?= number_format((int)$ma['net_change']) ?>
              </td>
              <td class="px-4 py-2 text-right font-medium text-gray-700"><?= number_format((int)$ma['current_stock']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
