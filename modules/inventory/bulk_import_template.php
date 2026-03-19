<?php
/**
 * Bulk Import CSV Template – serves a sample CSV file for download
 */
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="inventory_import_template.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['name', 'sku', 'barcode', 'generic_name', 'description', 'category', 'supplier', 'location', 'unit', 'cost_price', 'selling_price', 'qty_on_hand', 'reorder_level', 'expiry_date']);
fputcsv($out, ['Paracetamol 500mg', 'MED-0001', '1234567890123', 'Paracetamol', 'Pain reliever tablet', 'Medicine', 'PharmaCorp', 'Main Warehouse', 'pcs', '2.50', '5.00', '100', '20', '2026-12-31']);
fputcsv($out, ['Surgical Gloves M', 'SUP-0001', '', 'Latex Gloves', 'Medium size', 'Supplies', 'MedSupply Inc', 'Storage Room', 'box', '150.00', '250.00', '50', '10', '']);
fclose($out);
exit;
