<?php
/**
 * Inventory - Purchase Orders
 * Full-featured PO management: create, view, edit (draft), receive, track
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'write');

$pdo = get_db_conn();
$pageTitle = 'Purchase Orders';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// --------------- Handle POST actions ---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_POST['csrf'] ?? '';
    if (!csrf_verify($token)) {
        flash_error('Invalid security token. Please try again.');
        header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders');
        exit;
    }
    $postAction = $_POST['action'] ?? '';

    // Create new PO
    if ($postAction === 'create') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $expectedDate = trim($_POST['expected_date'] ?? '');
        $items = $_POST['items'] ?? [];

        if (!$supplierId) { flash_error('Please select a supplier.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=create'); exit; }
        if (empty($items)) { flash_error('Please add at least one item to the order.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=create'); exit; }

        // Validate items
        $validItems = [];
        foreach ($items as $itm) {
            $itemId = (int)($itm['item_id'] ?? 0);
            $qty = max(1, (int)($itm['quantity'] ?? 1));
            $cost = max(0, (float)($itm['unit_cost'] ?? 0));
            if ($itemId <= 0) continue;
            if ($cost <= 0) { flash_error('All items must have a unit cost greater than 0.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=create'); exit; }
            $validItems[] = ['item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => $cost];
        }
        if (empty($validItems)) { flash_error('Please add at least one valid item.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=create'); exit; }

        // Generate PO number
        $poNum = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO inv_purchase_orders (po_number, supplier_id, status, notes, expected_date, created_by) VALUES (:po, :sid, 'draft', :notes, :edate, :uid) RETURNING id");
            $st->execute([
                ':po' => $poNum,
                ':sid' => $supplierId,
                ':notes' => $notes,
                ':edate' => $expectedDate ?: null,
                ':uid' => $_SESSION['user']['id'] ?? null
            ]);
            $poId = (int)$st->fetchColumn();

            $totalAmount = 0;
            $stItem = $pdo->prepare("INSERT INTO inv_purchase_order_items (purchase_order_id, item_id, quantity_ordered, unit_cost) VALUES (:po, :item, :qty, :cost)");
            foreach ($validItems as $itm) {
                $stItem->execute([':po' => $poId, ':item' => $itm['item_id'], ':qty' => $itm['quantity'], ':cost' => $itm['unit_cost']]);
                $totalAmount += $itm['quantity'] * $itm['unit_cost'];
            }

            $pdo->prepare("UPDATE inv_purchase_orders SET total_amount = :t WHERE id = :id")->execute([':t' => $totalAmount, ':id' => $poId]);
            $pdo->commit();

            action_log('inventory', 'create_purchase_order', 'success', ['po_id' => $poId, 'po_number' => $poNum, 'total' => $totalAmount]);
            flash_success('Purchase order ' . $poNum . ' created successfully.');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            sys_log('PO-CREATE', 'Failed to create purchase order: ' . $e->getMessage(), ['module' => 'inventory', 'file' => __FILE__, 'line' => __LINE__]);
            flash_error('Failed to create purchase order. Please try again.');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=create');
            exit;
        }
    }

    // Update draft PO
    if ($postAction === 'update_draft') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $expectedDate = trim($_POST['expected_date'] ?? '');
        $items = $_POST['items'] ?? [];

        $po = $pdo->prepare("SELECT * FROM inv_purchase_orders WHERE id = :id AND status = 'draft'");
        $po->execute([':id' => $poId]);
        $poRow = $po->fetch(PDO::FETCH_ASSOC);
        if (!$poRow) { flash_error('Only draft POs can be edited.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }
        if (!$supplierId) { flash_error('Please select a supplier.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=edit&id=' . $poId); exit; }

        $validItems = [];
        foreach ($items as $itm) {
            $itemId = (int)($itm['item_id'] ?? 0);
            $qty = max(1, (int)($itm['quantity'] ?? 1));
            $cost = max(0, (float)($itm['unit_cost'] ?? 0));
            if ($itemId <= 0 || $cost <= 0) continue;
            $validItems[] = ['item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => $cost];
        }
        if (empty($validItems)) { flash_error('Please add at least one valid item.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=edit&id=' . $poId); exit; }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE inv_purchase_orders SET supplier_id = :sid, notes = :notes, expected_date = :edate, updated_at = NOW() WHERE id = :id")
                ->execute([':sid' => $supplierId, ':notes' => $notes, ':edate' => $expectedDate ?: null, ':id' => $poId]);

            // Clear and re-insert lines
            $pdo->prepare("DELETE FROM inv_purchase_order_items WHERE purchase_order_id = :id")->execute([':id' => $poId]);

            $totalAmount = 0;
            $stItem = $pdo->prepare("INSERT INTO inv_purchase_order_items (purchase_order_id, item_id, quantity_ordered, unit_cost) VALUES (:po, :item, :qty, :cost)");
            foreach ($validItems as $itm) {
                $stItem->execute([':po' => $poId, ':item' => $itm['item_id'], ':qty' => $itm['quantity'], ':cost' => $itm['unit_cost']]);
                $totalAmount += $itm['quantity'] * $itm['unit_cost'];
            }

            $pdo->prepare("UPDATE inv_purchase_orders SET total_amount = :t WHERE id = :id")->execute([':t' => $totalAmount, ':id' => $poId]);
            $pdo->commit();

            action_log('inventory', 'update_purchase_order', 'success', ['po_id' => $poId, 'po_number' => $poRow['po_number']]);
            flash_success('Purchase order updated successfully.');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            sys_log('PO-UPDATE', 'Failed to update PO: ' . $e->getMessage(), ['module' => 'inventory']);
            flash_error('Failed to update purchase order.');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=edit&id=' . $poId);
            exit;
        }
    }

    // Update PO status
    if ($postAction === 'update_status') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $allowed = ['ordered', 'partial', 'received', 'cancelled'];
        if (!in_array($newStatus, $allowed)) { flash_error('Invalid status.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }

        $po = $pdo->prepare("SELECT * FROM inv_purchase_orders WHERE id = :id");
        $po->execute([':id' => $poId]);
        $poRow = $po->fetch(PDO::FETCH_ASSOC);
        if (!$poRow) { flash_error('Purchase order not found.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }

        // Validate state transitions
        $validTransitions = [
            'draft'     => ['ordered', 'cancelled'],
            'ordered'   => ['partial', 'received', 'cancelled'],
            'partial'   => ['received', 'cancelled'],
            'received'  => [],
            'cancelled' => [],
        ];
        $currentStatus = $poRow['status'] ?? 'draft';
        $allowedNext = $validTransitions[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowedNext)) {
            flash_error('Cannot change status from "' . ucfirst($currentStatus) . '" to "' . ucfirst($newStatus) . '".');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
            exit;
        }

        if ($newStatus === 'received') {
            $pdo->prepare("UPDATE inv_purchase_orders SET status = :s, received_date = NOW(), received_by = :rby, updated_at = NOW() WHERE id = :id")
                ->execute([':s' => $newStatus, ':id' => $poId, ':rby' => $_SESSION['user']['id'] ?? null]);
        } else {
            $pdo->prepare("UPDATE inv_purchase_orders SET status = :s, updated_at = NOW() WHERE id = :id")
                ->execute([':s' => $newStatus, ':id' => $poId]);
        }

        action_log('inventory', 'update_po_status', 'success', ['po_id' => $poId, 'po_number' => $poRow['po_number'], 'from' => $currentStatus, 'to' => $newStatus]);
        flash_success('Status updated to "' . ucfirst($newStatus) . '".');
        header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
        exit;
    }

    // Receive items from PO
    if ($postAction === 'receive_items') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $receiveItems = $_POST['receive'] ?? [];

        $po = $pdo->prepare("SELECT * FROM inv_purchase_orders WHERE id = :id");
        $po->execute([':id' => $poId]);
        $poRow = $po->fetch(PDO::FETCH_ASSOC);
        if (!$poRow) { flash_error('Purchase order not found.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }
        if (!in_array($poRow['status'], ['ordered', 'partial'])) {
            flash_error('Items can only be received for orders with status "Ordered" or "Partial".');
            header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stGetLine = $pdo->prepare("SELECT * FROM inv_purchase_order_items WHERE id = :id AND purchase_order_id = :po");
            $stUpdateLine = $pdo->prepare("UPDATE inv_purchase_order_items SET quantity_received = COALESCE(quantity_received, 0) + :qty WHERE id = :id");
            $stUpdateStock = $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand + :qty, updated_at = NOW() WHERE id = :id");
            $stMovement = $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, unit_cost, reference_type, reference_id, notes, created_by) VALUES (:item, 'receipt', :qty, :cost, 'purchase_order', :ref, :notes, :uid)");

            $anyReceived = false;
            $receivedCount = 0;
            foreach ($receiveItems as $lineId => $qty) {
                $qty = max(0, (int)$qty);
                if ($qty <= 0) continue;

                $stGetLine->execute([':id' => (int)$lineId, ':po' => $poId]);
                $line = $stGetLine->fetch(PDO::FETCH_ASSOC);
                if (!$line) continue;

                $remaining = $line['quantity_ordered'] - ($line['quantity_received'] ?? 0);
                $qty = min($qty, $remaining);
                if ($qty <= 0) continue;

                $stUpdateLine->execute([':qty' => $qty, ':id' => $line['id']]);
                $stUpdateStock->execute([':qty' => $qty, ':id' => $line['item_id']]);
                $stMovement->execute([
                    ':item' => $line['item_id'], ':qty' => $qty, ':cost' => $line['unit_cost'],
                    ':ref' => $poId, ':notes' => 'Received from PO: ' . $poRow['po_number'],
                    ':uid' => $_SESSION['user']['id'] ?? null
                ]);
                $anyReceived = true;
                $receivedCount += $qty;
            }

            if ($anyReceived) {
                // Check if fully received
                $stCheck = $pdo->prepare("SELECT COUNT(*) FROM inv_purchase_order_items WHERE purchase_order_id = :po AND COALESCE(quantity_received, 0) < quantity_ordered");
                $stCheck->execute([':po' => $poId]);
                $pending = (int)$stCheck->fetchColumn();
                $newStatus = $pending === 0 ? 'received' : 'partial';

                $updateSql = "UPDATE inv_purchase_orders SET status = :s, updated_at = NOW()";
                $updateParams = [':s' => $newStatus, ':id' => $poId];
                if ($newStatus === 'received') {
                    $updateSql .= ", received_date = NOW(), received_by = :rby";
                    $updateParams[':rby'] = $_SESSION['user']['id'] ?? null;
                }
                $updateSql .= " WHERE id = :id";
                $pdo->prepare($updateSql)->execute($updateParams);
            }

            $pdo->commit();

            if ($anyReceived) {
                action_log('inventory', 'receive_po_items', 'success', ['po_id' => $poId, 'po_number' => $poRow['po_number'], 'qty_received' => $receivedCount]);
                flash_success($receivedCount . ' item(s) received and stock updated successfully.');
            } else {
                flash_error('No items were received. Please enter quantities to receive.');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            sys_log('PO-RECEIVE', 'Failed to receive items: ' . $e->getMessage(), ['module' => 'inventory', 'file' => __FILE__, 'line' => __LINE__]);
            flash_error('Failed to receive items. Please try again.');
        }

        header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders?action=view&id=' . $poId);
        exit;
    }

    // Delete draft PO
    if ($postAction === 'delete_draft') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $po = $pdo->prepare("SELECT * FROM inv_purchase_orders WHERE id = :id AND status = 'draft'");
        $po->execute([':id' => $poId]);
        $poRow = $po->fetch(PDO::FETCH_ASSOC);
        if (!$poRow) { flash_error('Only draft POs can be deleted.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }

        $pdo->prepare("DELETE FROM inv_purchase_orders WHERE id = :id AND status = 'draft'")->execute([':id' => $poId]);
        action_log('inventory', 'delete_purchase_order', 'success', ['po_id' => $poId, 'po_number' => $poRow['po_number']]);
        flash_success('Draft PO ' . $poRow['po_number'] . ' deleted.');
        header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders');
        exit;
    }
}

// --------------- Views ---------------

// Summary stats
$stats = ['total' => 0, 'draft' => 0, 'ordered' => 0, 'partial' => 0, 'received' => 0, 'cancelled' => 0];
try {
    $stStats = $pdo->query("SELECT status, COUNT(*) as cnt FROM inv_purchase_orders GROUP BY status");
    while ($row = $stStats->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['status']] = (int)$row['cnt'];
        $stats['total'] += (int)$row['cnt'];
    }
} catch (Exception $e) {}

// List view data
if ($action === 'list') {
    $statusFilter = $_GET['status'] ?? '';
    $search = trim($_GET['q'] ?? '');
    $where = ["1=1"];
    $params = [];
    if ($statusFilter) { $where[] = "po.status = :status"; $params[':status'] = $statusFilter; }
    if ($search) { $where[] = "(po.po_number ILIKE :q OR s.name ILIKE :q)"; $params[':q'] = '%'.$search.'%'; }
    $whereClause = implode(' AND ', $where);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $stc = $pdo->prepare("SELECT COUNT(*) FROM inv_purchase_orders po LEFT JOIN inv_suppliers s ON s.id = po.supplier_id WHERE $whereClause");
    $stc->execute($params);
    $totalCount = (int)$stc->fetchColumn();
    $totalPages = max(1, ceil($totalCount / $perPage));

    $sql = "SELECT po.*, s.name AS supplier_name, u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM inv_purchase_order_items poi WHERE poi.purchase_order_id = po.id) as item_count
        FROM inv_purchase_orders po
        LEFT JOIN inv_suppliers s ON s.id = po.supplier_id
        LEFT JOIN users u ON u.id = po.created_by
        WHERE $whereClause ORDER BY po.created_at DESC LIMIT :lim OFFSET :off";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Create/Edit form data
if ($action === 'create' || $action === 'edit') {
    $suppliers = $pdo->query("SELECT id, name, contact_person FROM inv_suppliers WHERE is_active ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $allItems = $pdo->query("SELECT id, name, sku, cost_price, unit, qty_on_hand, reorder_level FROM inv_items WHERE is_active ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // For edit, load existing PO
    $editPO = null;
    $editLines = [];
    if ($action === 'edit' && $id) {
        $stPO = $pdo->prepare("SELECT * FROM inv_purchase_orders WHERE id = :id AND status = 'draft'");
        $stPO->execute([':id' => $id]);
        $editPO = $stPO->fetch(PDO::FETCH_ASSOC);
        if (!$editPO) { flash_error('Only draft purchase orders can be edited.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }

        $stLines = $pdo->prepare("SELECT poi.*, i.name AS item_name, i.sku FROM inv_purchase_order_items poi JOIN inv_items i ON i.id = poi.item_id WHERE poi.purchase_order_id = :id ORDER BY poi.id");
        $stLines->execute([':id' => $id]);
        $editLines = $stLines->fetchAll(PDO::FETCH_ASSOC);
    }
}

// View PO detail
if ($action === 'view' && $id) {
    $stPO = $pdo->prepare("SELECT po.*, s.name AS supplier_name, s.contact_person, s.phone, s.email AS supplier_email, s.address AS supplier_address,
        u.full_name AS created_by_name, ru.full_name AS received_by_name
        FROM inv_purchase_orders po
        LEFT JOIN inv_suppliers s ON s.id = po.supplier_id
        LEFT JOIN users u ON u.id = po.created_by
        LEFT JOIN users ru ON ru.id = po.received_by
        WHERE po.id = :id");
    $stPO->execute([':id' => $id]);
    $po = $stPO->fetch(PDO::FETCH_ASSOC);
    if (!$po) { flash_error('Purchase order not found.'); header('Location: ' . BASE_URL . '/modules/inventory/purchase_orders'); exit; }

    $stLines = $pdo->prepare("SELECT poi.*, i.name AS item_name, i.sku, i.unit, i.qty_on_hand FROM inv_purchase_order_items poi JOIN inv_items i ON i.id = poi.item_id WHERE poi.purchase_order_id = :id ORDER BY poi.id");
    $stLines->execute([':id' => $id]);
    $poLines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    // Compute progress
    $totalOrdered = 0;
    $totalReceived = 0;
    foreach ($poLines as $ln) {
        $totalOrdered += (int)$ln['quantity_ordered'];
        $totalReceived += (int)($ln['quantity_received'] ?? 0);
    }
    $progressPct = $totalOrdered > 0 ? round(($totalReceived / $totalOrdered) * 100) : 0;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="max-w-7xl mx-auto space-y-6">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Purchase Orders</h1>
      <p class="text-sm text-slate-500 mt-0.5">Manage supplier orders and track deliveries</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= BASE_URL ?>/modules/inventory/restock" class="btn btn-outline text-sm">
        <svg class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Quick Restock
      </a>
      <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=create" class="btn btn-primary text-sm">
        <svg class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Purchase Order
      </a>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    <?php
    $statCards = [
        ['label' => 'Total', 'value' => $stats['total'], 'color' => 'slate', 'filter' => ''],
        ['label' => 'Draft', 'value' => $stats['draft'], 'color' => 'slate', 'filter' => 'draft'],
        ['label' => 'Ordered', 'value' => $stats['ordered'], 'color' => 'blue', 'filter' => 'ordered'],
        ['label' => 'Partial', 'value' => $stats['partial'], 'color' => 'amber', 'filter' => 'partial'],
        ['label' => 'Received', 'value' => $stats['received'], 'color' => 'emerald', 'filter' => 'received'],
        ['label' => 'Cancelled', 'value' => $stats['cancelled'], 'color' => 'red', 'filter' => 'cancelled'],
    ];
    $statIcons = [
        '' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        'draft' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'ordered' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'partial' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'received' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'cancelled' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    ];
    foreach ($statCards as $sc):
      $activeFilter = ($statusFilter === $sc['filter']) || ($sc['filter'] === '' && $statusFilter === '');
    ?>
    <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders<?= $sc['filter'] ? '?status=' . $sc['filter'] : '' ?>"
       class="card card-body flex items-center gap-3 hover:shadow-md transition-shadow <?= $activeFilter ? 'ring-2 ring-indigo-500 ring-offset-1' : '' ?>">
      <div class="w-9 h-9 rounded-lg bg-<?= $sc['color'] ?>-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4 text-<?= $sc['color'] ?>-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $statIcons[$sc['filter']] ?>"/></svg>
      </div>
      <div class="min-w-0">
        <div class="text-lg font-bold text-slate-900"><?= $sc['value'] ?></div>
        <div class="text-xs text-slate-500"><?= $sc['label'] ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search & Filter -->
  <div class="card card-body">
    <form method="GET" class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by PO number or supplier name..."
               class="w-full rounded-lg border border-slate-300 pl-10 pr-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
      </div>
      <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm min-w-[140px]">
        <option value="">All Statuses</option>
        <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="ordered" <?= $statusFilter === 'ordered' ? 'selected' : '' ?>>Ordered</option>
        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
        <option value="received" <?= $statusFilter === 'received' ? 'selected' : '' ?>>Received</option>
        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>
      <button type="submit" class="btn btn-primary text-sm">
        <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
        Filter
      </button>
      <?php if ($search || $statusFilter): ?>
      <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders" class="btn btn-outline text-sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- PO List -->
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table-basic">
        <thead><tr>
          <th>PO Number</th>
          <th>Supplier</th>
          <th>Items</th>
          <th>Status</th>
          <th class="text-right">Total Amount</th>
          <th>Created By</th>
          <th>Date</th>
          <th class="text-center">Actions</th>
        </tr></thead>
        <tbody>
          <?php if (empty($orders)): ?>
            <tr><td colspan="8" class="text-center py-12">
              <div class="text-slate-400 mb-2">
                <svg class="w-12 h-12 mx-auto text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
              </div>
              <p class="text-sm text-slate-500">No purchase orders found</p>
              <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=create" class="text-sm text-indigo-600 hover:text-indigo-500 mt-1 inline-block">Create your first PO &rarr;</a>
            </td></tr>
          <?php else: foreach ($orders as $o):
            $sc = ['draft'=>'bg-slate-100 text-slate-600','ordered'=>'bg-blue-100 text-blue-700','partial'=>'bg-amber-100 text-amber-700','received'=>'bg-emerald-100 text-emerald-700','cancelled'=>'bg-red-100 text-red-700'];
            $cls = $sc[$o['status']] ?? 'bg-slate-100 text-slate-600';
          ?>
            <tr class="hover:bg-slate-50">
              <td>
                <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=view&id=<?= $o['id'] ?>" class="font-semibold text-indigo-600 hover:text-indigo-500">
                  <?= htmlspecialchars($o['po_number']) ?>
                </a>
              </td>
              <td class="text-slate-700"><?= htmlspecialchars($o['supplier_name'] ?? '—') ?></td>
              <td class="text-slate-500"><?= (int)$o['item_count'] ?> item<?= (int)$o['item_count'] !== 1 ? 's' : '' ?></td>
              <td><span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium <?= $cls ?>"><?= ucfirst($o['status']) ?></span></td>
              <td class="text-right font-semibold text-slate-900">&peso;<?= number_format((float)$o['total_amount'], 2) ?></td>
              <td class="text-slate-500 text-sm"><?= htmlspecialchars($o['created_by_name'] ?? '—') ?></td>
              <td class="text-slate-500 text-xs"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
              <td class="text-center">
                <div class="action-links justify-center">
                  <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=view&id=<?= $o['id'] ?>" class="text-indigo-600 hover:text-indigo-500 text-sm">View</a>
                  <?php if ($o['status'] === 'draft'): ?>
                  <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=edit&id=<?= $o['id'] ?>" class="text-blue-600 hover:text-blue-500 text-sm">Edit</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t bg-slate-50 flex items-center justify-between text-sm">
      <div class="text-slate-500">Showing page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalCount) ?> total)</div>
      <div class="flex gap-1">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-white text-sm">&larr; Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-white text-sm">Next &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>


<?php if ($action === 'create' || $action === 'edit'): ?>
<?php
  $isEdit = ($action === 'edit' && $editPO);
  $formTitle = $isEdit ? 'Edit Purchase Order' : 'New Purchase Order';
  $formSubtitle = $isEdit ? 'Modify draft PO: ' . htmlspecialchars($editPO['po_number']) : 'Create a new purchase order for a supplier';
  $formAction = $isEdit ? 'update_draft' : 'create';
?>
<div class="max-w-5xl mx-auto space-y-6">
  <!-- Header -->
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-slate-900"><?= $formTitle ?></h1>
      <p class="text-sm text-slate-500 mt-0.5"><?= $formSubtitle ?></p>
    </div>
    <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders<?= $isEdit ? '?action=view&id=' . $editPO['id'] : '' ?>" class="text-sm text-slate-500 hover:text-slate-700">
      &larr; Back
    </a>
  </div>

  <form method="POST" class="space-y-6" id="poForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>" />
    <input type="hidden" name="action" value="<?= $formAction ?>" />
    <?php if ($isEdit): ?>
    <input type="hidden" name="po_id" value="<?= $editPO['id'] ?>" />
    <?php endif; ?>

    <!-- Supplier & Details -->
    <div class="card">
      <div class="card-header">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
          Order Details
        </span>
      </div>
      <div class="card-body">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1 required">Supplier</label>
            <select name="supplier_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" id="poSupplier">
              <option value="">Select a supplier...</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($isEdit && $editPO['supplier_id'] == $s['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($s['name']) ?><?= $s['contact_person'] ? ' — ' . htmlspecialchars($s['contact_person']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($suppliers)): ?>
            <p class="text-xs text-amber-600 mt-1">No suppliers found. <a href="<?= BASE_URL ?>/modules/inventory/suppliers" class="underline">Add a supplier first</a>.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Expected Delivery Date</label>
            <input type="date" name="expected_date" value="<?= $isEdit ? htmlspecialchars($editPO['expected_date'] ?? '') : '' ?>"
                   min="<?= date('Y-m-d') ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <input type="text" name="notes" value="<?= $isEdit ? htmlspecialchars($editPO['notes'] ?? '') : '' ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional notes or reference..." />
          </div>
        </div>
      </div>
    </div>

    <!-- Order Items -->
    <div class="card">
      <div class="card-header flex items-center justify-between">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
          Order Items
        </span>
        <button type="button" onclick="addPOLine()" class="btn btn-outline text-xs px-3 py-1.5">
          <svg class="w-3.5 h-3.5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add Item
        </button>
      </div>
      <div class="card-body">
        <!-- Column headers -->
        <div class="hidden md:grid grid-cols-12 gap-3 px-3 pb-2 text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100 mb-3">
          <div class="col-span-5">Product</div>
          <div class="col-span-2 text-right">Quantity</div>
          <div class="col-span-2 text-right">Unit Cost (&peso;)</div>
          <div class="col-span-2 text-right">Line Total</div>
          <div class="col-span-1"></div>
        </div>
        <div id="poLines" class="space-y-2">
          <!-- Lines added by JS -->
        </div>
        <div id="poLinesEmpty" class="text-center py-8 text-sm text-slate-400 hidden">
          <svg class="w-10 h-10 mx-auto text-slate-300 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
          No items added yet. Click "Add Item" to start building your order.
        </div>
      </div>
    </div>

    <!-- Summary & Actions -->
    <div class="card card-body">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-6">
          <div>
            <div class="text-xs text-slate-500 uppercase font-semibold">Items</div>
            <div class="text-lg font-bold text-slate-900" id="poItemCount">0</div>
          </div>
          <div class="h-8 w-px bg-slate-200"></div>
          <div>
            <div class="text-xs text-slate-500 uppercase font-semibold">Total Amount</div>
            <div class="text-2xl font-bold text-indigo-600">&peso;<span id="poTotal">0.00</span></div>
          </div>
        </div>
        <div class="flex gap-3">
          <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders<?= $isEdit ? '?action=view&id=' . $editPO['id'] : '' ?>" class="btn btn-outline">Cancel</a>
          <button type="submit" class="btn btn-primary px-6" id="poSubmitBtn">
            <svg class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <?= $isEdit ? 'Save Changes' : 'Create Purchase Order' ?>
          </button>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
const invItems = <?= json_encode($allItems) ?>;
const existingLines = <?= $isEdit ? json_encode($editLines) : '[]' ?>;
let lineIdx = 0;

function addPOLine(preset) {
  const div = document.createElement('div');
  div.className = 'grid grid-cols-1 md:grid-cols-12 gap-3 items-center p-3 rounded-lg bg-slate-50 border border-slate-100 hover:border-slate-200 transition-colors po-line';
  div.id = 'poLine' + lineIdx;

  const itemId = preset ? preset.item_id : '';
  const qty = preset ? preset.quantity_ordered : 1;
  const cost = preset ? parseFloat(preset.unit_cost).toFixed(2) : '';

  const options = invItems.map(i => {
    const stockBadge = i.qty_on_hand <= 0 ? ' [OUT]' : (i.qty_on_hand <= i.reorder_level ? ' [LOW: ' + i.qty_on_hand + ']' : ' [' + i.qty_on_hand + ' in stock]');
    const selected = (preset && preset.item_id == i.id) ? ' selected' : '';
    return '<option value="' + i.id + '" data-cost="' + i.cost_price + '" data-unit="' + (i.unit || 'pc') + '" data-stock="' + i.qty_on_hand + '"' + selected + '>' + escapeHtml(i.name) + ' (' + escapeHtml(i.sku) + ')' + stockBadge + '</option>';
  }).join('');

  div.innerHTML =
    '<div class="md:col-span-5">' +
      '<label class="text-xs text-slate-500 md:hidden mb-1 block">Product</label>' +
      '<select name="items[' + lineIdx + '][item_id]" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm po-item-select" onchange="updateLineCost(this, ' + lineIdx + ')">' +
        '<option value="">Select product...</option>' + options +
      '</select>' +
    '</div>' +
    '<div class="md:col-span-2">' +
      '<label class="text-xs text-slate-500 md:hidden mb-1 block">Quantity</label>' +
      '<input type="number" name="items[' + lineIdx + '][quantity]" min="1" value="' + qty + '" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-right po-qty" oninput="calcPOTotal()" />' +
    '</div>' +
    '<div class="md:col-span-2">' +
      '<label class="text-xs text-slate-500 md:hidden mb-1 block">Unit Cost (₱)</label>' +
      '<input type="number" name="items[' + lineIdx + '][unit_cost]" step="0.01" min="0.01" value="' + cost + '" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-right po-cost" oninput="calcPOTotal()" placeholder="0.00" />' +
    '</div>' +
    '<div class="md:col-span-2 text-right">' +
      '<label class="text-xs text-slate-500 md:hidden mb-1 block">Line Total</label>' +
      '<span class="text-sm font-semibold text-slate-700 po-line-total">₱0.00</span>' +
    '</div>' +
    '<div class="md:col-span-1 text-right">' +
      '<button type="button" onclick="removePOLine(this)" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-red-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Remove item">' +
        '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>' +
      '</button>' +
    '</div>';
  document.getElementById('poLines').appendChild(div);
  lineIdx++;
  calcPOTotal();
  updateEmptyState();
}

function escapeHtml(str) {
  if (typeof window.escapeHtml === 'function') return window.escapeHtml(str);
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function removePOLine(btn) {
  btn.closest('.po-line').remove();
  calcPOTotal();
  updateEmptyState();
}

function updateLineCost(sel, idx) {
  const opt = sel.options[sel.selectedIndex];
  const cost = parseFloat(opt?.dataset?.cost || 0);
  const parent = sel.closest('.po-line');
  const costInput = parent.querySelector('.po-cost');
  if (costInput && !costInput.value) costInput.value = cost.toFixed(2);
  calcPOTotal();
}

function calcPOTotal() {
  let total = 0;
  let count = 0;
  document.querySelectorAll('#poLines .po-line').forEach(function(line) {
    const qty = parseFloat(line.querySelector('.po-qty')?.value || 0);
    const cost = parseFloat(line.querySelector('.po-cost')?.value || 0);
    const lineTotal = qty * cost;
    total += lineTotal;
    count++;
    const ltEl = line.querySelector('.po-line-total');
    if (ltEl) ltEl.textContent = '₱' + lineTotal.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  });
  document.getElementById('poTotal').textContent = total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  document.getElementById('poItemCount').textContent = count;
}

function updateEmptyState() {
  const lines = document.querySelectorAll('#poLines .po-line');
  const emptyEl = document.getElementById('poLinesEmpty');
  if (lines.length === 0) {
    emptyEl.classList.remove('hidden');
  } else {
    emptyEl.classList.add('hidden');
  }
}

// Initialize
if (existingLines.length > 0) {
  existingLines.forEach(function(line) { addPOLine(line); });
} else {
  addPOLine();
}

// Form validation
document.getElementById('poForm').addEventListener('submit', function(e) {
  var lines = document.querySelectorAll('#poLines .po-line');
  if (lines.length === 0) {
    e.preventDefault();
    alert('Please add at least one item to the order.');
    return;
  }
  // Check for duplicate items
  var itemIds = [];
  var hasDuplicate = false;
  lines.forEach(function(line) {
    var sel = line.querySelector('.po-item-select');
    if (sel && sel.value) {
      if (itemIds.indexOf(sel.value) !== -1) {
        hasDuplicate = true;
        sel.style.borderColor = '#ef4444';
      } else {
        itemIds.push(sel.value);
        sel.style.borderColor = '';
      }
    }
  });
  if (hasDuplicate) {
    e.preventDefault();
    alert('Duplicate items detected. Each product should only appear once. Adjust the quantity instead.');
    return;
  }
});
</script>
<?php endif; ?>


<?php if ($action === 'view' && isset($po)): ?>
<?php
  $sc = ['draft'=>'bg-slate-100 text-slate-600','ordered'=>'bg-blue-100 text-blue-700','partial'=>'bg-amber-100 text-amber-700','received'=>'bg-emerald-100 text-emerald-700','cancelled'=>'bg-red-100 text-red-700'];
  $cls = $sc[$po['status']] ?? 'bg-slate-100 text-slate-600';
  $statusIcons = [
      'draft' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
      'ordered' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
      'partial' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
      'received' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
      'cancelled' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
  ];
?>
<div class="max-w-7xl mx-auto space-y-6">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
    <div>
      <div class="flex items-center gap-3 mb-1">
        <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders" class="text-slate-400 hover:text-slate-600 transition-colors">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-bold text-slate-900"><?= htmlspecialchars($po['po_number']) ?></h1>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?= $cls ?>">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $statusIcons[$po['status']] ?? '' ?>"/></svg>
          <?= ucfirst($po['status']) ?>
        </span>
      </div>
      <p class="text-sm text-slate-500">Created on <?= date('M d, Y h:i A', strtotime($po['created_at'])) ?> by <?= htmlspecialchars($po['created_by_name'] ?? 'System') ?></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      <?php if ($po['status'] === 'draft'): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/purchase_orders?action=edit&id=<?= $po['id'] ?>" class="btn btn-outline text-sm">
          <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          Edit
        </a>
        <form method="POST" class="inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="update_status" />
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>" />
          <input type="hidden" name="new_status" value="ordered" />
          <button class="btn btn-primary text-sm" data-confirm="Mark this PO as ordered? It will no longer be editable.">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Place Order
          </button>
        </form>
        <form method="POST" class="inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="delete_draft" />
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>" />
          <button class="btn btn-danger text-sm" data-confirm="Delete this draft PO? This cannot be undone.">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Delete
          </button>
        </form>
      <?php elseif (in_array($po['status'], ['ordered', 'partial'])): ?>
        <form method="POST" class="inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="update_status" />
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>" />
          <input type="hidden" name="new_status" value="cancelled" />
          <button class="btn btn-danger text-sm" data-confirm="Cancel this purchase order? This action cannot be reversed.">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            Cancel PO
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info Cards -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <!-- Order Summary -->
    <div class="card">
      <div class="card-header text-sm">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          Order Summary
        </span>
      </div>
      <div class="card-body space-y-3">
        <div class="flex justify-between items-center text-sm">
          <span class="text-slate-500">Total Amount</span>
          <span class="text-lg font-bold text-slate-900">&peso;<?= number_format((float)$po['total_amount'], 2) ?></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-slate-500">Items</span>
          <span class="font-medium"><?= count($poLines) ?> product<?= count($poLines) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($po['expected_date']): ?>
        <div class="flex justify-between text-sm">
          <span class="text-slate-500">Expected Delivery</span>
          <span class="font-medium"><?= date('M d, Y', strtotime($po['expected_date'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($po['received_date']): ?>
        <div class="flex justify-between text-sm">
          <span class="text-slate-500">Received On</span>
          <span class="font-medium text-emerald-600"><?= date('M d, Y h:i A', strtotime($po['received_date'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($po['received_by_name'])): ?>
        <div class="flex justify-between text-sm">
          <span class="text-slate-500">Received By</span>
          <span class="font-medium"><?= htmlspecialchars($po['received_by_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($po['notes']): ?>
        <div class="pt-2 border-t border-slate-100">
          <div class="text-xs font-semibold text-slate-500 uppercase mb-1">Notes</div>
          <p class="text-sm text-slate-700"><?= nl2br(htmlspecialchars($po['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Fulfillment Progress -->
        <?php if (in_array($po['status'], ['ordered', 'partial', 'received'])): ?>
        <div class="pt-2 border-t border-slate-100">
          <div class="flex justify-between text-xs mb-1.5">
            <span class="font-semibold text-slate-500">Fulfillment</span>
            <span class="font-bold <?= $progressPct === 100 ? 'text-emerald-600' : 'text-slate-700' ?>"><?= $progressPct ?>%</span>
          </div>
          <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $progressPct === 100 ? 'bg-emerald-500' : ($progressPct > 0 ? 'bg-amber-500' : 'bg-slate-300') ?>"
                 style="width: <?= $progressPct ?>%"></div>
          </div>
          <div class="text-xs text-slate-400 mt-1"><?= $totalReceived ?> of <?= $totalOrdered ?> units received</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Supplier Info -->
    <div class="card">
      <div class="card-header text-sm">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
          Supplier Information
        </span>
      </div>
      <div class="card-body space-y-3">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
            <span class="text-indigo-600 font-bold text-sm"><?= strtoupper(substr($po['supplier_name'] ?? 'S', 0, 2)) ?></span>
          </div>
          <div>
            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($po['supplier_name'] ?? '—') ?></div>
            <?php if ($po['contact_person']): ?>
            <div class="text-xs text-slate-500"><?= htmlspecialchars($po['contact_person']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($po['phone']): ?>
        <div class="flex items-center gap-2 text-sm">
          <svg class="w-4 h-4 text-slate-400 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
          <a href="tel:<?= htmlspecialchars($po['phone']) ?>" class="text-indigo-600 hover:text-indigo-500"><?= htmlspecialchars($po['phone']) ?></a>
        </div>
        <?php endif; ?>
        <?php if ($po['supplier_email']): ?>
        <div class="flex items-center gap-2 text-sm">
          <svg class="w-4 h-4 text-slate-400 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          <a href="mailto:<?= htmlspecialchars($po['supplier_email']) ?>" class="text-indigo-600 hover:text-indigo-500"><?= htmlspecialchars($po['supplier_email']) ?></a>
        </div>
        <?php endif; ?>
        <?php if (!empty($po['supplier_address'])): ?>
        <div class="flex items-start gap-2 text-sm">
          <svg class="w-4 h-4 text-slate-400 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <span class="text-slate-600"><?= htmlspecialchars($po['supplier_address']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Receive Items Panel / Status Panel -->
    <?php if (in_array($po['status'], ['ordered', 'partial'])): ?>
    <div class="card border-indigo-200 bg-indigo-50/30">
      <div class="card-header text-sm bg-indigo-50 border-indigo-100">
        <span class="flex items-center gap-2 text-indigo-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
          Receive Delivery
        </span>
      </div>
      <div class="card-body">
        <form method="POST" class="space-y-3" id="receiveForm">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="receive_items" />
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>" />

          <?php
          $hasPending = false;
          foreach ($poLines as $ln):
            $remaining = $ln['quantity_ordered'] - ($ln['quantity_received'] ?? 0);
            if ($remaining <= 0) continue;
            $hasPending = true;
          ?>
          <div class="bg-white rounded-lg p-3 border border-indigo-100">
            <div class="flex items-center justify-between gap-2 mb-1">
              <div class="text-sm font-medium text-slate-900 truncate"><?= htmlspecialchars($ln['item_name']) ?></div>
              <span class="text-xs text-slate-500 flex-shrink-0"><?= $remaining ?> pending</span>
            </div>
            <div class="flex items-center gap-2">
              <input type="range" min="0" max="<?= $remaining ?>" value="0"
                     class="flex-1 h-2 accent-indigo-600"
                     oninput="this.nextElementSibling.value = this.value; this.nextElementSibling.nextElementSibling.value = this.value"
                     id="slider_<?= $ln['id'] ?>" />
              <output class="text-sm font-bold text-indigo-600 w-8 text-center">0</output>
              <input type="hidden" name="receive[<?= $ln['id'] ?>]" value="0" />
            </div>
            <div class="flex justify-between mt-1">
              <button type="button" onclick="setReceive(<?= $ln['id'] ?>, 0)" class="text-xs text-slate-400 hover:text-slate-600">None</button>
              <button type="button" onclick="setReceive(<?= $ln['id'] ?>, <?= $remaining ?>)" class="text-xs text-indigo-600 hover:text-indigo-500 font-medium">Receive All</button>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (!$hasPending): ?>
          <div class="text-center py-4 text-sm text-emerald-600 font-medium">
            <svg class="w-8 h-8 mx-auto text-emerald-500 mb-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            All items received!
          </div>
          <?php else: ?>
          <div class="flex gap-2 pt-1">
            <button type="button" onclick="receiveAllItems()" class="btn btn-outline text-xs flex-1">Receive All</button>
            <button type="submit" class="btn btn-primary text-xs flex-1" data-confirm="Confirm receiving the selected quantities? Stock levels will be updated.">
              <svg class="w-3.5 h-3.5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              Confirm Receipt
            </button>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php elseif ($po['status'] === 'received'): ?>
    <div class="card border-emerald-200">
      <div class="card-header text-sm bg-emerald-50 border-emerald-100">
        <span class="flex items-center gap-2 text-emerald-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Fully Received
        </span>
      </div>
      <div class="card-body text-center py-6">
        <div class="w-14 h-14 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-3">
          <svg class="w-7 h-7 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <p class="text-sm font-medium text-emerald-700">All items have been received</p>
        <p class="text-xs text-slate-500 mt-1"><?= $totalReceived ?> units across <?= count($poLines) ?> product<?= count($poLines) !== 1 ? 's' : '' ?></p>
      </div>
    </div>
    <?php elseif ($po['status'] === 'cancelled'): ?>
    <div class="card border-red-200">
      <div class="card-header text-sm bg-red-50 border-red-100">
        <span class="flex items-center gap-2 text-red-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Cancelled
        </span>
      </div>
      <div class="card-body text-center py-6">
        <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
          <svg class="w-7 h-7 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
        <p class="text-sm font-medium text-red-700">This order was cancelled</p>
      </div>
    </div>
    <?php else: ?>
    <!-- Draft: Next Steps -->
    <div class="card border-slate-200">
      <div class="card-header text-sm bg-slate-50">
        <span class="flex items-center gap-2 text-slate-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Next Steps
        </span>
      </div>
      <div class="card-body space-y-3">
        <div class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0 mt-0.5">
            <span class="text-xs font-bold text-indigo-600">1</span>
          </div>
          <div>
            <p class="text-sm font-medium text-slate-900">Review the order</p>
            <p class="text-xs text-slate-500">Check items, quantities, and costs</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0 mt-0.5">
            <span class="text-xs font-bold text-indigo-600">2</span>
          </div>
          <div>
            <p class="text-sm font-medium text-slate-900">Place the order</p>
            <p class="text-xs text-slate-500">Click "Place Order" to lock it in</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0 mt-0.5">
            <span class="text-xs font-bold text-slate-400">3</span>
          </div>
          <div>
            <p class="text-sm font-medium text-slate-400">Receive delivery</p>
            <p class="text-xs text-slate-400">Record items when they arrive</p>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Status Timeline -->
  <div class="card card-body">
    <div class="flex items-center justify-between overflow-x-auto pb-1">
      <?php
      $timeline = [
          ['status' => 'draft', 'label' => 'Draft', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
          ['status' => 'ordered', 'label' => 'Ordered', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
          ['status' => 'partial', 'label' => 'Partial', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
          ['status' => 'received', 'label' => 'Received', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
      ];
      $statusOrder = ['draft' => 0, 'ordered' => 1, 'partial' => 2, 'received' => 3, 'cancelled' => -1];
      $currentIdx = $statusOrder[$po['status']] ?? 0;
      $isCancelled = $po['status'] === 'cancelled';

      foreach ($timeline as $i => $step):
        $isCompleted = !$isCancelled && $currentIdx >= $i;
        $isCurrent = !$isCancelled && $currentIdx === $i;
        $circleClass = $isCompleted ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-400';
        if ($isCancelled) $circleClass = 'bg-red-100 text-red-400';
      ?>
      <?php if ($i > 0): ?>
      <div class="flex-1 h-0.5 mx-2 <?= $isCompleted && !$isCancelled ? 'bg-indigo-300' : 'bg-slate-200' ?>"></div>
      <?php endif; ?>
      <div class="flex flex-col items-center gap-1.5 flex-shrink-0">
        <div class="w-8 h-8 rounded-full flex items-center justify-center <?= $circleClass ?> <?= $isCurrent ? 'ring-2 ring-offset-2 ring-indigo-400' : '' ?>">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $step['icon'] ?>"/></svg>
        </div>
        <span class="text-xs font-medium <?= $isCurrent ? 'text-indigo-600' : ($isCompleted ? 'text-slate-700' : 'text-slate-400') ?>"><?= $step['label'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($isCancelled): ?>
    <div class="text-center mt-3 text-xs text-red-500 font-medium">This order was cancelled</div>
    <?php endif; ?>
  </div>

  <!-- Line Items Table -->
  <div class="card overflow-hidden">
    <div class="card-header flex items-center justify-between">
      <span class="flex items-center gap-2 text-sm">
        <svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        Order Items
      </span>
      <span class="text-xs text-slate-500"><?= count($poLines) ?> item<?= count($poLines) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="table-basic">
        <thead><tr>
          <th class="w-8">#</th>
          <th>Product</th>
          <th>Unit</th>
          <th class="text-right">Ordered</th>
          <th class="text-right">Received</th>
          <th class="text-right">Pending</th>
          <th class="text-right">Unit Cost</th>
          <th class="text-right">Line Total</th>
          <th class="text-center">Status</th>
        </tr></thead>
        <tbody>
          <?php foreach ($poLines as $i => $ln):
            $received = (int)($ln['quantity_received'] ?? 0);
            $ordered = (int)$ln['quantity_ordered'];
            $pending = $ordered - $received;
            $lineTotal = $ordered * (float)$ln['unit_cost'];
            $linePct = $ordered > 0 ? round(($received / $ordered) * 100) : 0;
            if ($received >= $ordered) { $lc = 'bg-emerald-100 text-emerald-700'; $lt = 'Complete'; }
            elseif ($received > 0) { $lc = 'bg-amber-100 text-amber-700'; $lt = $linePct . '%'; }
            else { $lc = 'bg-slate-100 text-slate-600'; $lt = 'Pending'; }
          ?>
            <tr class="hover:bg-slate-50">
              <td class="text-slate-400 text-xs"><?= $i + 1 ?></td>
              <td>
                <div class="font-medium text-slate-900"><?= htmlspecialchars($ln['item_name']) ?></div>
                <div class="text-xs text-slate-400"><?= htmlspecialchars($ln['sku']) ?> &middot; <?= (int)$ln['qty_on_hand'] ?> in stock</div>
              </td>
              <td class="text-slate-600 text-sm"><?= htmlspecialchars($ln['unit'] ?? 'pc') ?></td>
              <td class="text-right font-medium"><?= number_format($ordered) ?></td>
              <td class="text-right font-medium <?= $received > 0 ? 'text-emerald-600' : '' ?>"><?= number_format($received) ?></td>
              <td class="text-right <?= $pending > 0 ? 'text-amber-600 font-medium' : 'text-slate-400' ?>"><?= number_format($pending) ?></td>
              <td class="text-right">&peso;<?= number_format((float)$ln['unit_cost'], 2) ?></td>
              <td class="text-right font-semibold">&peso;<?= number_format($lineTotal, 2) ?></td>
              <td class="text-center"><span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium <?= $lc ?>"><?= $lt ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-slate-50">
          <tr class="font-bold">
            <td colspan="7" class="text-right text-sm">Grand Total:</td>
            <td class="text-right text-base">&peso;<?= number_format((float)$po['total_amount'], 2) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<script>
function setReceive(lineId, value) {
  var slider = document.getElementById('slider_' + lineId);
  if (slider) {
    slider.value = value;
    slider.nextElementSibling.textContent = value;
    slider.nextElementSibling.nextElementSibling.value = value;
  }
}
function receiveAllItems() {
  document.querySelectorAll('#receiveForm input[type="range"]').forEach(function(slider) {
    slider.value = slider.max;
    slider.nextElementSibling.textContent = slider.max;
    slider.nextElementSibling.nextElementSibling.value = slider.max;
  });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
