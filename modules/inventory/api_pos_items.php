<?php
/**
 * API: POS Items - Returns JSON list of active items for POS grid
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'write');

header('Content-Type: application/json');

$pdo = get_db_conn();

$items = $pdo->query("SELECT i.id, i.sku, i.barcode, i.name, i.generic_name, i.unit, i.selling_price, i.qty_on_hand, i.reorder_level, i.category_id, i.expiry_date
    FROM inv_items i
    WHERE i.is_active = TRUE
    ORDER BY i.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['items' => $items, 'categories' => $categories]);
