<?php
/**
 * Manual Inventory Update - Sensitive Operation
 * Requires password re-entry for security. Creates immutable audit log.
 * Only accessible by Admin/Accountant Head roles.
 * Purpose: Correct stock counts when external changes occur outside the system.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'manage');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$currentUser = current_user();
$isAuthorized = false;

// POST: Verify password first, then apply changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }

    $action = $_POST['action'] ?? '';

    if ($action === 'verify_password') {
        // Step 1: Verify password to gain access
        $password = $_POST['password'] ?? '';
        
        try {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userRow && password_verify($password, $userRow['password_hash'])) {
                $_SESSION['manual_inv_authorized'] = time();
                $_SESSION['manual_inv_token'] = bin2hex(random_bytes(16));
                flash_success('Access granted. You may now make manual adjustments. This session is being logged.');
                
                action_log('inventory', 'manual_update_access', 'success', [
                    'user_id' => $uid,
                    'user_name' => $currentUser['full_name'] ?? 'Unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            } else {
                flash_error('Incorrect password. Access denied.');
                action_log('inventory', 'manual_update_access', 'failed', [
                    'user_id' => $uid,
                    'reason' => 'wrong_password',
                ]);
            }
        } catch (Throwable $e) {
            flash_error('Authentication error.');
        }

        header('Location: ' . BASE_URL . '/modules/inventory/manual_update');
        exit;
    }

    if ($action === 'save_changes') {
        // Check authorization session
        if (empty($_SESSION['manual_inv_authorized']) || (time() - $_SESSION['manual_inv_authorized']) > 900) {
            flash_error('Your manual update session has expired (15 minutes). Please verify your password again.');
            unset($_SESSION['manual_inv_authorized'], $_SESSION['manual_inv_token']);
            header('Location: ' . BASE_URL . '/modules/inventory/manual_update');
            exit;
        }

        $editDate = $_POST['edit_date'] ?? date('Y-m-d');
        $items = $_POST['items'] ?? [];
        $changesApplied = 0;

        foreach ($items as $itemId => $data) {
            $itemId = (int)$itemId;
            $newSales = isset($data['sales']) && $data['sales'] !== '' ? (int)$data['sales'] : null;
            $newStock = isset($data['stock_adjust']) && $data['stock_adjust'] !== '' ? (int)$data['stock_adjust'] : null;

            if ($newSales === null && $newStock === null) continue;

            try {
                $pdo->beginTransaction();

                // Get current item data
                $stmt = $pdo->prepare("SELECT id, name, sku, qty_on_hand, cost_price, selling_price FROM inv_items WHERE id = :id AND is_active = TRUE");
                $stmt->execute([':id' => $itemId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) { $pdo->rollBack(); continue; }

                $oldQty = (int)$item['qty_on_hand'];
                $changes = [];

                // Apply sales edit (subtract from stock)
                if ($newSales !== null && $newSales > 0) {
                    $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand - :qty, updated_at = NOW() WHERE id = :id")
                         ->execute([':qty' => $newSales, ':id' => $itemId]);

                    // Record sale movement
                    $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, unit_cost, notes, created_by, created_at) VALUES (:item, 'sale', :qty, :cost, :notes, :uid, :date)")
                         ->execute([
                             ':item' => $itemId,
                             ':qty' => -$newSales,
                             ':cost' => $item['selling_price'],
                             ':notes' => "Manual sales entry for " . date('M d, Y', strtotime($editDate)),
                             ':uid' => $uid,
                             ':date' => $editDate . ' ' . date('H:i:s'),
                         ]);

                    $changes[] = "sales: $newSales units";
                }

                // Apply stock adjustment
                if ($newStock !== null && $newStock != 0) {
                    $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand + :qty, updated_at = NOW() WHERE id = :id")
                         ->execute([':qty' => $newStock, ':id' => $itemId]);

                    $mvType = $newStock > 0 ? 'adjustment' : 'adjustment';
                    $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, unit_cost, notes, created_by, created_at) VALUES (:item, :type, :qty, :cost, :notes, :uid, :date)")
                         ->execute([
                             ':item' => $itemId,
                             ':type' => $mvType,
                             ':qty' => $newStock,
                             ':cost' => $item['cost_price'],
                             ':notes' => "Manual stock adjustment for " . date('M d, Y', strtotime($editDate)),
                             ':uid' => $uid,
                             ':date' => $editDate . ' ' . date('H:i:s'),
                         ]);

                    $changes[] = "stock adjust: " . ($newStock > 0 ? '+' : '') . "$newStock";
                }

                // Create immutable audit log entry
                if (!empty($changes)) {
                    $newQtyStmt = $pdo->prepare("SELECT qty_on_hand FROM inv_items WHERE id = :id");
                    $newQtyStmt->execute([':id' => $itemId]);
                    $newQty = (int)$newQtyStmt->fetchColumn();

                    audit('inventory.manual_update', 'Manual inventory adjustment', [
                        'item_id' => $itemId,
                        'item_name' => $item['name'],
                        'item_sku' => $item['sku'],
                        'edit_date' => $editDate,
                        'old_qty' => $oldQty,
                        'new_qty' => $newQty,
                        'changes' => implode(', ', $changes),
                        'performed_by' => $currentUser['full_name'] ?? 'Unknown',
                        'performed_by_id' => $uid,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);

                    $changesApplied++;
                }

                $pdo->commit();
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $ex) {}
                sys_log('INV-MANUAL-001', 'Manual update failed: ' . $e->getMessage(), ['item_id' => $itemId]);
            }
        }

        if ($changesApplied > 0) {
            flash_success("$changesApplied item(s) updated manually. All changes have been logged.");
        } else {
            flash_error('No changes were applied.');
        }

        header('Location: ' . BASE_URL . '/modules/inventory/manual_update');
        exit;
    }
}

// Check if user has an active authorized session (15 min window)
$isAuthorized = !empty($_SESSION['manual_inv_authorized']) && (time() - $_SESSION['manual_inv_authorized']) <= 900;

// Fetch items for editing
$items = [];
if ($isAuthorized) {
    try {
        $items = $pdo->query("SELECT i.id, i.sku, i.name, i.qty_on_hand, i.cost_price, i.selling_price, i.expiry_date, i.unit,
                                     c.name as category_name
                              FROM inv_items i
                              LEFT JOIN inv_categories c ON c.id = i.category_id
                              WHERE i.is_active = TRUE
                              ORDER BY c.name, i.name
                              LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $items = []; }
}

// Fetch recent manual update logs for transparency
$auditLogs = [];
try {
    $stmt = $pdo->prepare("SELECT action, details, created_at, user_id FROM audit_logs WHERE action LIKE 'inventory.manual%' ORDER BY created_at DESC LIMIT 20");
    $stmt->execute();
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $auditLogs = []; }

$pageTitle = 'Manual Inventory Update';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold text-slate-900">Manual Inventory Update</h1>
            <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="text-sm text-indigo-600 hover:text-indigo-500">← Back to Inventory</a>
        </div>
    </div>

    <!-- Security Warning Banner -->
    <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-lg">
        <div class="flex items-start gap-3">
            <svg class="w-6 h-6 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-amber-800">Sensitive Operation</h3>
                <p class="text-sm text-amber-700 mt-1">
                    Manual inventory changes are outside the standard system flow. All modifications create an immutable 
                    audit trail visible to owners and admins. This is for transparency, security, and anti-fraud purposes.
                </p>
            </div>
        </div>
    </div>

    <?php if (!$isAuthorized): ?>
    <!-- Password Verification Gate -->
    <div class="max-w-md mx-auto">
        <div class="card">
            <div class="card-header">
                <span class="font-semibold text-slate-800">Verify Your Identity</span>
            </div>
            <div class="card-body">
                <p class="text-sm text-slate-600 mb-4">Re-enter your password to access manual inventory editing. This session will be logged.</p>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="verify_password">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1 required">Password</label>
                        <input type="password" name="password" required autofocus
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                               placeholder="Enter your password...">
                    </div>
                    <button type="submit" class="btn btn-primary w-full">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Verify & Access
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Authorized: Show edit interface -->
    <div class="bg-emerald-50 border-l-4 border-emerald-500 p-3 rounded flex items-center gap-3">
        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        <div>
            <span class="text-sm font-medium text-emerald-800">Access Granted</span>
            <span class="text-xs text-emerald-600 ml-2">(Session expires in <?= max(0, 15 - intdiv(time() - $_SESSION['manual_inv_authorized'], 60)) ?> minutes)</span>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_changes">

        <!-- Date selector -->
        <div class="card card-body mb-4">
            <div class="flex items-center gap-3 flex-wrap">
                <span class="text-sm font-semibold text-slate-700">Editing Sales For:</span>
                <input type="date" name="edit_date" value="<?= date('Y-m-d') ?>" 
                       class="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-sm font-semibold text-indigo-700">
            </div>
        </div>

        <!-- Items Grid -->
        <div class="space-y-3">
            <?php foreach ($items as $item): ?>
            <div class="card card-body">
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($item['name']) ?></h3>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($item['category_name'] ?? $item['sku']) ?></p>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center flex-shrink-0">
                        <div>
                            <p class="text-xs text-slate-400">Expiry Date</p>
                            <p class="text-xs font-medium"><?= $item['expiry_date'] ? date('M d, Y', strtotime($item['expiry_date'])) : 'N/A' ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Current Stock</p>
                            <p class="text-sm font-bold <?= $item['qty_on_hand'] == 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= (int)$item['qty_on_hand'] ?></p>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Sales (Edit)</label>
                            <input type="number" min="0" name="items[<?= $item['id'] ?>][sales]" value="0"
                                   class="w-full rounded-lg border border-slate-300 px-2 py-1 text-sm text-center">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($items)): ?>
        <div class="mt-6 flex justify-center">
            <button type="submit" class="btn btn-primary px-8" data-confirm="Save all manual changes? An audit trail will be created for every modification.">
                Save All Changes
            </button>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- Audit Trail Log (visible to all with manage access) -->
    <?php if (!empty($auditLogs)): ?>
    <div class="card">
        <div class="card-header">
            <span class="font-semibold text-slate-800">Manual Update Audit Trail</span>
        </div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="table-basic w-full">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td class="whitespace-nowrap text-xs"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                            <td class="text-xs font-medium"><?= htmlspecialchars($log['action']) ?></td>
                            <td class="text-xs text-slate-600">
                                <?php
                                $details = json_decode($log['details'] ?? '{}', true);
                                if (is_array($details)) {
                                    $parts = [];
                                    if (!empty($details['item_name'])) $parts[] = '<strong>' . htmlspecialchars($details['item_name']) . '</strong>';
                                    if (!empty($details['changes'])) $parts[] = htmlspecialchars($details['changes']);
                                    if (!empty($details['performed_by'])) $parts[] = 'by ' . htmlspecialchars($details['performed_by']);
                                    if (!empty($details['old_qty']) || !empty($details['new_qty'])) $parts[] = "Qty: {$details['old_qty']} → {$details['new_qty']}";
                                    echo implode(' • ', $parts);
                                } else {
                                    echo htmlspecialchars($log['details'] ?? '');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
