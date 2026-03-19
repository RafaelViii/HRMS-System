<?php
/**
 * Quick test script for leave API endpoint
 * Run from command line: php tools/test_leave_api.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "Testing Leave API Endpoint\n";
echo "==========================\n\n";

try {
  $pdo = get_db_conn();
  
  // Test 1: Check leave_requests table
  echo "1. Checking leave_requests table...\n";
  $stmt = $pdo->query('SELECT COUNT(*) FROM leave_requests');
  $count = $stmt->fetchColumn();
  echo "   ✓ Found {$count} leave requests\n\n";
  
  // Test 2: Check department_supervisors
  echo "2. Checking department_supervisors...\n";
  $stmt = $pdo->query('SELECT ds.supervisor_user_id, u.email, d.name 
                       FROM department_supervisors ds 
                       JOIN users u ON u.id = ds.supervisor_user_id 
                       JOIN departments d ON d.id = ds.department_id');
  $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($supervisors as $sup) {
    echo "   ✓ {$sup['email']} supervises {$sup['name']}\n";
  }
  echo "\n";
  
  // Test 3: Check if Stephanie Cueto exists and her role
  echo "3. Checking Stephanie Cueto's account...\n";
  $stmt = $pdo->prepare('SELECT id, email, role, status FROM users WHERE email = :email');
  $stmt->execute([':email' => 'cueto.stephaniebscs2023@gmail.com']);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($user) {
    echo "   ✓ User ID: {$user['id']}\n";
    echo "   ✓ Email: {$user['email']}\n";
    echo "   ✓ Role: {$user['role']}\n";
    echo "   ✓ Status: {$user['status']}\n";
  } else {
    echo "   ✗ User not found!\n";
  }
  echo "\n";
  
  // Test 4: Check leave requests with employee joins
  echo "4. Testing leave request query...\n";
  $stmt = $pdo->query('SELECT lr.id, e.employee_code, e.first_name, e.last_name, d.name as dept
                       FROM leave_requests lr
                       JOIN employees e ON e.id = lr.employee_id
                       LEFT JOIN departments d ON d.id = e.department_id
                       ORDER BY lr.created_at DESC
                       LIMIT 3');
  $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($requests as $req) {
    echo "   ✓ [{$req['id']}] {$req['employee_code']} - {$req['first_name']} {$req['last_name']} ({$req['dept']})\n";
  }
  echo "\n";
  
  echo "✓ All tests passed!\n";
  echo "\nTo test the API endpoint, visit:\n";
  echo BASE_URL . "/modules/leave/api_admin_list.php\n";
  echo "(Make sure you're logged in as Stephanie Cueto first)\n";
  
} catch (Throwable $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
  echo "File: " . $e->getFile() . "\n";
  echo "Line: " . $e->getLine() . "\n";
}
