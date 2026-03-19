<?php
/**
 * Test script to verify system logging is working
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

echo "Testing system logging...\n\n";

try {
    // Test 1: Basic log entry
    echo "Test 1: Creating a test log entry...\n";
    sys_log('TEST-LOG-001', 'This is a test log entry', [
        'module' => 'test',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['test_data' => 'Hello World', 'timestamp' => date('Y-m-d H:i:s')],
    ]);
    echo "✓ Log entry created\n\n";
    
    // Test 2: Query the log
    echo "Test 2: Querying the log...\n";
    $pdo = get_db_conn();
    $stmt = $pdo->query("SELECT id, code, message, created_at FROM system_logs WHERE code = 'TEST-LOG-001' ORDER BY id DESC LIMIT 1");
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($log) {
        echo "✓ Log entry found!\n";
        echo "  ID: {$log['id']}\n";
        echo "  Code: {$log['code']}\n";
        echo "  Message: {$log['message']}\n";
        echo "  Created: {$log['created_at']}\n";
    } else {
        echo "✗ Log entry NOT found in database!\n";
    }
    
    echo "\n=== Test Complete ===\n";
    echo "If the log entry was found, logging is working correctly.\n";
    echo "You can view all logs at: /modules/admin/logs\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
