<?php
/**
 * Check payroll complaint status enum values in database
 * This diagnostic script helps verify the database enum configuration.
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Payroll Complaint Status Enum Diagnostic ===\n\n";

try {
    $pdo = get_db_conn();
    
    echo "1. Checking payroll_complaint_status enum values...\n";
    
    $stmt = $pdo->query("
        SELECT enumlabel 
        FROM pg_enum 
        WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'payroll_complaint_status') 
        ORDER BY enumsortorder
    ");
    
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($values)) {
        echo "   ERROR: No enum values found for payroll_complaint_status!\n";
        echo "   The enum type might not exist in the database.\n\n";
    } else {
        echo "   Found " . count($values) . " enum values:\n";
        foreach ($values as $value) {
            echo "      - '$value'\n";
        }
        echo "\n";
        
        // Check which values are expected
        $expected = ['pending', 'in_review', 'resolved', 'rejected', 'confirmed'];
        $missing = array_diff($expected, $values);
        $extra = array_diff($values, $expected);
        
        if (!empty($missing)) {
            echo "   WARNING: Missing expected values: " . implode(', ', $missing) . "\n";
        }
        if (!empty($extra)) {
            echo "   INFO: Additional values found: " . implode(', ', $extra) . "\n";
        }
        if (empty($missing) && empty($extra)) {
            echo "   ✓ All expected enum values are present!\n";
        }
    }
    
    echo "\n2. Checking sample complaints...\n";
    
    $stmt = $pdo->query("
        SELECT id, status, employee_id, payroll_run_id, submitted_at
        FROM payroll_complaints 
        ORDER BY submitted_at DESC 
        LIMIT 5
    ");
    
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($complaints)) {
        echo "   No complaints found in database.\n";
    } else {
        echo "   Found " . count($complaints) . " recent complaint(s):\n";
        foreach ($complaints as $c) {
            echo "      ID: {$c['id']} | Status: '{$c['status']}' | Employee: {$c['employee_id']} | Run: {$c['payroll_run_id']}\n";
        }
    }
    
    echo "\n3. Checking complaint status distribution...\n";
    
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM payroll_complaints 
        GROUP BY status 
        ORDER BY count DESC
    ");
    
    $distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($distribution)) {
        echo "   No complaints in database.\n";
    } else {
        foreach ($distribution as $row) {
            echo "      {$row['status']}: {$row['count']} complaint(s)\n";
        }
    }
    
    echo "\n=== Diagnostic Complete ===\n";
    
} catch (PDOException $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
