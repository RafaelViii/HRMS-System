<?php
/**
 * Inventory - Transaction History
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'read');

$pdo = get_db_conn();
$pageTitle = 'Transaction History';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canManage = user_has_access($uid, 'inventory', 'pos_transactions', 'manage');

// Handle void
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_id']) && csrf_verify($_POST['csrf'] ?? '')) {
    $authz = ensure_action_authorized('inventory', 'void_transaction', 'manage');
    if ($authz['ok']) {
        $vid = (int)$_POST['void_id'];
        $reason = trim($_POST['void_reason'] ?? 'Voided by manager');

        // Check if already voided to prevent double-void
        $chkStmt = $pdo->prepare("SELECT status FROM inv_transactions WHERE id = :id");
        $chkStmt->execute([':id' => $vid]);
        $txnStatus = $chkStmt->fetchColumn();

        if ($txnStatus === 'voided') {
            flash_error('Transaction has already been voided.');
        } else {
            try {
                $pdo->beginTransaction();

                // Restore stock
                $txnItems = $pdo->prepare("SELECT item_id, quantity FROM inv_transaction_items WHERE txn_id = :id");
                $txnItems->execute([':id' => $vid]);
                foreach ($txnItems->fetchAll(PDO::FETCH_ASSOC) as $ti) {
                    $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand + :qty, updated_at = NOW() WHERE id = :id")
                        ->execute([':qty' => $ti['quantity'], ':id' => $ti['item_id']]);
                    $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (:iid, 'return', :qty, 'void', :txn, :notes, :uid)")
                        ->execute([':iid' => $ti['item_id'], ':qty' => $ti['quantity'], ':txn' => $vid, ':notes' => "Void: $reason", ':uid' => $uid]);
                }

                $pdo->prepare("UPDATE inv_transactions SET status = 'voided', voided_by = :uid, voided_at = NOW(), void_reason = :reason WHERE id = :id")
                    ->execute([':uid' => $uid, ':reason' => $reason, ':id' => $vid]);

                $pdo->commit();
                action_log('inventory', 'void_transaction', 'success', ['txn_id' => $vid, 'reason' => $reason]);
                flash_success('Transaction voided and stock restored.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                sys_log('INV-VOID', 'Void transaction failed: ' . $e->getMessage(), ['module' => 'inventory', 'file' => __FILE__, 'line' => __LINE__]);
                flash_error('Failed to void transaction. See system logs.');
            }
        }
    } else {
        flash_error('Authorization required.');
    }
    header('Location: ' . BASE_URL . '/modules/inventory/transactions');
    exit;
}

// Filters
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];
if ($dateFrom) { $where[] = "t.created_at >= :df"; $params[':df'] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $where[] = "t.created_at <= :dt"; $params[':dt'] = $dateTo . ' 23:59:59'; }
if ($statusFilter) { $where[] = "t.status = :st"; $params[':st'] = $statusFilter; }
if ($search) { $where[] = "(t.txn_number ILIKE :q OR t.customer_name ILIKE :q)"; $params[':q'] = '%'.$search.'%'; }

$whereClause = implode(' AND ', $where);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Parameterized count query
$stc = $pdo->prepare("SELECT COUNT(*) FROM inv_transactions t WHERE $whereClause");
$stc->execute($params);
$totalCount = (int)$stc->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

$sql = "SELECT t.*, u.full_name AS cashier_name, vu.full_name AS voided_by_name
    FROM inv_transactions t
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN users vu ON vu.id = t.voided_by
    WHERE $whereClause
    ORDER BY t.created_at DESC
    LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$transactions = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Transaction History</h1>
      <p class="text-sm text-gray-500"><?= number_format($totalCount) ?> transactions found</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/inventory/pos" class="btn btn-primary">New Sale</a>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-xl border p-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search Txn# or customer..." class="input-text flex-1" />
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="input-text" />
      <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="input-text" />
      <select name="status" class="input-text">
        <option value="">All Status</option>
        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="voided" <?= $statusFilter === 'voided' ? 'selected' : '' ?>>Voided</option>
        <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
      </select>
      <button type="submit" class="btn btn-outline">Filter</button>
    </form>
  </div>

  <!-- Transactions Table -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-3 text-left">Transaction #</th>
            <th class="px-4 py-3 text-left">Date</th>
            <th class="px-4 py-3 text-left">Customer</th>
            <th class="px-4 py-3 text-left">Cashier</th>
            <th class="px-4 py-3 text-left">Payment</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-center">Status</th>
            <th class="px-4 py-3 text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($transactions)): ?>
            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No transactions found.</td></tr>
          <?php else: ?>
            <?php foreach ($transactions as $tx): ?>
            <tr class="hover:bg-gray-50 <?= $tx['status'] === 'voided' ? 'opacity-60' : '' ?>">
              <td class="px-4 py-2.5 font-mono text-xs"><?= htmlspecialchars($tx['txn_number']) ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= date('M d, Y h:i A', strtotime($tx['created_at'])) ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= htmlspecialchars($tx['customer_name'] ?: '-') ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= htmlspecialchars($tx['cashier_name'] ?? '-') ?></td>
              <td class="px-4 py-2.5 text-gray-600 capitalize"><?= htmlspecialchars($tx['payment_method']) ?></td>
              <td class="px-4 py-2.5 text-right font-medium">P<?= number_format((float)$tx['total_amount'], 2) ?></td>
              <td class="px-4 py-2.5 text-center">
                <?php
                  $statusBg = ['completed'=>'bg-emerald-100 text-emerald-700','voided'=>'bg-red-100 text-red-700','refunded'=>'bg-amber-100 text-amber-700'];
                  $sb = $statusBg[$tx['status']] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $sb ?>"><?= ucfirst($tx['status']) ?></span>
              </td>
              <td class="px-4 py-2.5 text-center">
                <div class="flex items-center justify-center gap-1">
                  <a href="<?= BASE_URL ?>/modules/inventory/transaction_view?id=<?= $tx['id'] ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-blue-600" title="View">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  </a>
                  <?php if ($canManage && $tx['status'] === 'completed'): ?>
                  <form method="POST" class="inline" onsubmit="return confirm('Void this transaction? Stock will be restored.')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
                    <input type="hidden" name="void_id" value="<?= $tx['id'] ?>" />
                    <input type="hidden" name="void_reason" value="Manager void" />
                    <button type="submit" class="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-600" title="Void">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/></svg>
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
