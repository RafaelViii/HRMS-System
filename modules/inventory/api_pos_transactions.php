<?php
/**
 * API: POS Transactions - Returns JSON list of recent transactions + detail view
 * GET  ?action=list          → recent transactions (last 50)
 * GET  ?action=detail&id=X   → single transaction with line items
 * POST ?action=refund&id=X   → process a refund/return for a transaction
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'read');

header('Content-Type: application/json');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $search = trim($_GET['q'] ?? '');
    $where = "1=1";
    $params = [];
    if ($search) {
        $where .= " AND (t.txn_number ILIKE :q OR t.customer_name ILIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }
    try {
        $st = $pdo->prepare("SELECT t.id, t.txn_number, t.txn_type, t.customer_name, t.total_amount, t.payment_method, t.status, t.created_at,
            u.full_name AS cashier_name
            FROM inv_transactions t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE $where
            ORDER BY t.created_at DESC
            LIMIT 50");
        $st->execute($params);
        echo json_encode(['success' => true, 'transactions' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        sys_log('INV-TXN-LIST', 'Failed listing transactions: ' . $e->getMessage(), ['module' => 'inventory']);
        echo json_encode(['success' => false, 'error' => 'Failed to load transactions']);
    }
    exit;
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    try {
        $st = $pdo->prepare("SELECT t.*, u.full_name AS cashier_name, v.full_name AS voider_name
            FROM inv_transactions t
            LEFT JOIN users u ON u.id = t.created_by
            LEFT JOIN users v ON v.id = t.voided_by
            WHERE t.id = :id");
        $st->execute([':id' => $id]);
        $txn = $st->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            exit;
        }
        $items = $pdo->prepare("SELECT * FROM inv_transaction_items WHERE txn_id = :id ORDER BY id");
        $items->execute([':id' => $id]);
        $txn['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'transaction' => $txn]);
    } catch (Throwable $e) {
        sys_log('INV-TXN-DETAIL', 'Failed loading transaction detail: ' . $e->getMessage(), ['module' => 'inventory']);
        echo json_encode(['success' => false, 'error' => 'Failed to load transaction details']);
    }
    exit;
}

if ($action === 'refund' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_access('inventory', 'pos_transactions', 'manage');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !csrf_verify($input['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid request or session expired']);
        exit;
    }

    $txnId = (int)($input['id'] ?? 0);
    $reason = trim($input['reason'] ?? 'Customer return/refund');

    $st = $pdo->prepare("SELECT * FROM inv_transactions WHERE id = :id");
    $st->execute([':id' => $txnId]);
    $txn = $st->fetch(PDO::FETCH_ASSOC);

    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }
    if ($txn['status'] !== 'completed') {
        echo json_encode(['success' => false, 'error' => 'Only completed transactions can be refunded']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Restore stock for all items
        $txnItems = $pdo->prepare("SELECT item_id, quantity FROM inv_transaction_items WHERE txn_id = :id");
        $txnItems->execute([':id' => $txnId]);
        foreach ($txnItems->fetchAll(PDO::FETCH_ASSOC) as $ti) {
            $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand + :qty, updated_at = NOW() WHERE id = :id")
                ->execute([':qty' => $ti['quantity'], ':id' => $ti['item_id']]);
            $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (:iid, 'return', :qty, 'refund', :txn, :notes, :uid)")
                ->execute([':iid' => $ti['item_id'], ':qty' => $ti['quantity'], ':txn' => $txnId, ':notes' => "Refund: $reason", ':uid' => $uid]);
        }

        $pdo->prepare("UPDATE inv_transactions SET status = 'refunded', voided_by = :uid, voided_at = NOW(), void_reason = :reason WHERE id = :id")
            ->execute([':uid' => $uid, ':reason' => $reason, ':id' => $txnId]);

        $pdo->commit();
        action_log('inventory', 'refund_transaction', 'success', ['txn_id' => $txnId, 'reason' => $reason]);
        echo json_encode(['success' => true, 'message' => 'Transaction refunded and stock restored']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sys_log('INV-REFUND', 'Refund failed: ' . $e->getMessage(), ['module' => 'inventory']);
        echo json_encode(['success' => false, 'error' => 'System error. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
