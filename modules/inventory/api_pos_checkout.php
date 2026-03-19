<?php
/**
 * API: POS Checkout - Process a sale transaction
 * Accepts JSON payload, creates transaction + line items, adjusts stock
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'write');

header('Content-Type: application/json');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$userName = $_SESSION['user']['name'] ?? 'Cashier';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Validate CSRF
if (!csrf_verify($input['csrf'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh.']);
    exit;
}

$cartItems = $input['items'] ?? [];
$discount = max(0, (float)($input['discount'] ?? 0));
$discountType = trim($input['discount_type'] ?? 'none');
$paymentMethod = $input['payment_method'] ?? 'cash';
$amountTendered = (float)($input['amount_tendered'] ?? 0);
$customerName = trim($input['customer_name'] ?? '');
$notes = trim($input['notes'] ?? '');
$referenceNumber = trim($input['reference_number'] ?? '');

if (empty($cartItems)) {
    echo json_encode(['success' => false, 'error' => 'Cart is empty']);
    exit;
}

// Validate payment method against DB (fallback to hardcoded if table missing)
$validMethods = ['cash', 'e-wallet', 'card'];
$requiresRef = false;
try {
    $ptRows = $pdo->query("SELECT code, requires_reference FROM inv_payment_types WHERE is_active = TRUE")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($ptRows)) {
        $validMethods = array_column($ptRows, 'code');
        foreach ($ptRows as $pt) {
            if ($pt['code'] === $paymentMethod && ($pt['requires_reference'] === true || $pt['requires_reference'] === 't' || $pt['requires_reference'] === 1 || $pt['requires_reference'] === '1')) {
                $requiresRef = true;
                break;
            }
        }
    }
} catch (Throwable $e) { /* table may not exist — use fallback */ }

if (!in_array($paymentMethod, $validMethods)) $paymentMethod = 'cash';

// Validate reference # if required
if ($requiresRef && $referenceNumber === '') {
    echo json_encode(['success' => false, 'error' => 'Reference number is required for this payment method']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Generate transaction number
    $today = date('Ymd');
    $st = $pdo->prepare("SELECT COUNT(*) + 1 FROM inv_transactions WHERE txn_number LIKE :prefix");
    $st->execute([':prefix' => "TXN-$today-%"]);
    $seq = (int)$st->fetchColumn();
    $txnNumber = sprintf("TXN-%s-%04d", $today, $seq);

    // Validate stock and calculate totals
    $subtotal = 0;
    $lineItems = [];
    foreach ($cartItems as $ci) {
        $itemId = (int)($ci['id'] ?? 0);
        $qty = max(1, (int)($ci['qty'] ?? 1));

        $stItem = $pdo->prepare("SELECT id, name, sku, qty_on_hand, selling_price, cost_price FROM inv_items WHERE id = :id AND is_active = TRUE FOR UPDATE");
        $stItem->execute([':id' => $itemId]);
        $dbItem = $stItem->fetch(PDO::FETCH_ASSOC);

        if (!$dbItem) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "Item #$itemId not found"]);
            exit;
        }
        if ($dbItem['qty_on_hand'] < $qty) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "Not enough stock for " . $dbItem['name'] . " (available: " . $dbItem['qty_on_hand'] . ")"]);
            exit;
        }

        // Always use server-side price — never trust client-submitted price
        $price = round((float)$dbItem['selling_price'], 2);
        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;

        $lineItems[] = [
            'item_id' => $itemId,
            'item_name' => $dbItem['name'],
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $lineTotal,
            'cost_price' => (float)$dbItem['cost_price'],
        ];

        // Deduct stock
        $pdo->prepare("UPDATE inv_items SET qty_on_hand = qty_on_hand - :qty, updated_at = NOW() WHERE id = :id")
            ->execute([':qty' => $qty, ':id' => $itemId]);

        // Record stock movement
        $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, reference_type, unit_cost, notes, created_by)
            VALUES (:iid, 'sale', :qty, 'transaction', :cost, :notes, :uid)")
            ->execute([
                ':iid' => $itemId,
                ':qty' => -$qty,
                ':cost' => (float)$dbItem['cost_price'],
                ':notes' => "POS Sale: $txnNumber",
                ':uid' => $uid,
            ]);
    }

    // Server-side discount re-computation from discount type
    if ($discountType && $discountType !== 'none') {
        try {
            $dtStmt = $pdo->prepare("SELECT discount_mode, value, min_amount, max_discount FROM inv_discount_types WHERE code = :code AND is_active = TRUE");
            $dtStmt->execute([':code' => $discountType]);
            $dtData = $dtStmt->fetch(PDO::FETCH_ASSOC);
            if ($dtData) {
                $minAmt = (float)($dtData['min_amount'] ?? 0);
                if ($subtotal >= $minAmt) {
                    if ($dtData['discount_mode'] === 'percentage') {
                        $discount = round($subtotal * ((float)$dtData['value'] / 100), 2);
                    } else {
                        $discount = round((float)$dtData['value'], 2);
                    }
                    if ($dtData['max_discount'] !== null) {
                        $discount = min($discount, (float)$dtData['max_discount']);
                    }
                    $discount = min($discount, $subtotal);
                } else {
                    $discount = 0; // doesn't meet minimum
                }
            }
        } catch (Throwable $e) { /* fallback to client-sent discount */ }
    }

    $totalAmount = round(max(0, $subtotal - $discount), 2);
    $changeAmount = $paymentMethod === 'cash' ? round(max(0, $amountTendered - $totalAmount), 2) : 0;
    if ($paymentMethod !== 'cash') $amountTendered = $totalAmount;

    // Create transaction
    $stTxn = $pdo->prepare("INSERT INTO inv_transactions (txn_number, txn_type, customer_name, subtotal, discount_amount, discount_type, total_amount, amount_tendered, change_amount, payment_method, reference_number, status, notes, created_by)
        VALUES (:num, 'sale', :cust, :sub, :disc, :dtype, :total, :tend, :change, :method, :ref, 'completed', :notes, :uid)");
    $stTxn->execute([
        ':num' => $txnNumber,
        ':cust' => $customerName ?: null,
        ':sub' => $subtotal,
        ':disc' => $discount,
        ':dtype' => ($discountType && $discountType !== 'none') ? $discountType : null,
        ':total' => $totalAmount,
        ':tend' => $amountTendered,
        ':change' => $changeAmount,
        ':method' => $paymentMethod,
        ':ref' => $referenceNumber ?: null,
        ':notes' => $notes ?: null,
        ':uid' => $uid,
    ]);
    $txnId = (int)$pdo->lastInsertId();

    // Update stock movement references
    // Insert line items
    foreach ($lineItems as $li) {
        $pdo->prepare("INSERT INTO inv_transaction_items (txn_id, item_id, item_name, quantity, unit_price, line_total)
            VALUES (:txn, :iid, :name, :qty, :price, :total)")
            ->execute([
                ':txn' => $txnId,
                ':iid' => $li['item_id'],
                ':name' => $li['item_name'],
                ':qty' => $li['quantity'],
                ':price' => $li['unit_price'],
                ':total' => $li['line_total'],
            ]);
    }

    $pdo->commit();

    // Fetch receipt settings
    $receiptSettings = $pdo->query("SELECT * FROM inv_receipt_settings ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    action_log('inventory', 'pos_sale', 'success', ['txn_id' => $txnId, 'txn_number' => $txnNumber, 'total' => $totalAmount, 'discount_type' => $discountType, 'payment_method' => $paymentMethod]);

    echo json_encode([
        'success' => true,
        'transaction' => [
            'id' => $txnId,
            'txn_number' => $txnNumber,
            'txn_type' => 'sale',
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'discount_type' => ($discountType && $discountType !== 'none') ? $discountType : null,
            'total_amount' => $totalAmount,
            'amount_tendered' => $amountTendered,
            'change_amount' => $changeAmount,
            'payment_method' => $paymentMethod,
            'reference_number' => $referenceNumber ?: null,
            'customer_name' => $customerName,
            'cashier' => $userName,
            'date' => date('M d, Y h:i A'),
            'items' => $lineItems,
            'receipt_header' => $receiptSettings['company_name'] ?? COMPANY_NAME,
            'receipt_subheader' => $receiptSettings['header_text'] ?? '',
            'receipt_address' => $receiptSettings['address'] ?? '',
            'receipt_footer' => $receiptSettings['footer_text'] ?? 'Thank you!',
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sys_log('INV-POS-ERROR', 'Checkout failed: ' . $e->getMessage(), ['module' => 'inventory', 'file' => __FILE__, 'line' => __LINE__]);
    echo json_encode(['success' => false, 'error' => 'System error. Please try again.']);
}
