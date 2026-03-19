<?php
/**
 * Diagnostic script to test payroll complaint status updates
 * Usage: php tools/test_complaint_update.php
 * CLI-only — not accessible via web.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payroll.php';
require_once __DIR__ . '/../includes/utils.php';

echo "=== Payroll Complaint Update Test ===\n\n";

try {
    $pdo = get_db_conn();
    
    // Get a pending complaint
    $stmt = $pdo->query("SELECT id, status, employee_id, payroll_run_id FROM payroll_complaints WHERE status = 'pending' ORDER BY id LIMIT 1");
    $complaint = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$complaint) {
        echo "No pending complaints found to test.\n";
        exit(0);
    }
    
    echo "Testing with complaint ID: {$complaint['id']}\n";
    echo "Current status: {$complaint['status']}\n";
    echo "Employee ID: {$complaint['employee_id']}\n";
    echo "Run ID: {$complaint['payroll_run_id']}\n\n";
    
    // Test 1: Update to in_review
    echo "Test 1: Updating to 'in_review'...\n";
    $result1 = payroll_mark_complaint_in_review($pdo, $complaint['id'], 1, 'Test review notes');
    echo "Result: " . ($result1['ok'] ? "SUCCESS" : "FAILED") . "\n";
    if (!$result1['ok']) {
        echo "Error: " . ($result1['error'] ?? 'Unknown') . "\n";
    }
    echo "\n";
    
    // Test 2: Update to resolved (without adjustment)
    echo "Test 2: Updating to 'resolved' (no adjustment)...\n";
    $result2 = payroll_resolve_complaint($pdo, $complaint['id'], [
        'status' => 'resolved',
        'notes' => 'Test resolution notes',
        'adjustment_amount' => 0,
        'adjustment_type' => 'earning',
    ], 1);
    echo "Result: " . ($result2['ok'] ? "SUCCESS" : "FAILED") . "\n";
    if (!$result2['ok']) {
        echo "Error: " . ($result2['error'] ?? 'Unknown') . "\n";
    }
    echo "\n";
    
    // Test 3: Update to confirmed
    echo "Test 3: Updating to 'confirmed'...\n";
    $result3 = payroll_confirm_complaint($pdo, $complaint['id'], 1, 'Test confirmation notes');
    echo "Result: " . ($result3['ok'] ? "SUCCESS" : "FAILED") . "\n";
    if (!$result3['ok']) {
        echo "Error: " . ($result3['error'] ?? 'Unknown') . "\n";
    }
    echo "\n";
    
    // Reset to pending
    echo "Resetting complaint back to pending...\n";
    $pdo->prepare("UPDATE payroll_complaints SET status = 'pending', review_notes = NULL, resolution_notes = NULL, confirmation_notes = NULL, reviewed_at = NULL, resolved_at = NULL, confirmation_at = NULL WHERE id = :id")->execute([':id' => $complaint['id']]);
    echo "Reset complete.\n\n";
    
    echo "=== All tests completed ===\n";
    
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}
