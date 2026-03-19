<?php
/**
 * Archive Permanent Delete Endpoint
 * Permanently delete a record from the database (irreversible)
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

header('Content-Type: application/json');

require_login();
require_access('user_management', 'system_management', 'write');

$pdo = get_db_conn();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Verify CSRF
$token = $_POST['csrf_token'] ?? '';
if (!csrf_verify($token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$table = $_POST['table'] ?? '';
$id = (int)($_POST['id'] ?? 0);

$allowedTables = ['employees', 'departments', 'positions', 'memos', 'documents'];

if (!in_array($table, $allowedTables) || $id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Get record data before deletion for audit
try {
    $selectStmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND deleted_at IS NOT NULL");
    $selectStmt->execute([$id]);
    $record = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Record not found or not in archive']);
        exit;
    }
} catch (Throwable $e) {
    sys_log('ARCHIVE-DELETE', "Failed to fetch record: " . $e->getMessage(), [
        'table' => $table,
        'id' => $id
    ]);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch record']);
    exit;
}

// Permanently delete the record
try {
    $pdo->beginTransaction();
    
    // Handle foreign key constraints - cascade delete or set null
    // This is a simplified approach; in production, you'd need table-specific logic
    $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE id = ? AND deleted_at IS NOT NULL");
    $deleteStmt->execute([$id]);
    
    if ($deleteStmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete record']);
        exit;
    }
    
    // Log the permanent deletion with critical severity
    action_log('system_management', 'archive_delete_permanent', 'success', [
        'target_type' => $table,
        'target_id' => $id,
        'old_values' => $record,
        'new_values' => null,
        'message' => "PERMANENTLY DELETED $table record #$id - IRREVERSIBLE",
        'severity' => 'critical'
    ]);
    
    // Also create a detailed audit entry
    audit($user['id'], 'DELETE_PERMANENT', $table, $id, $record, null, [
        'warning' => 'PERMANENT DELETION - DATA CANNOT BE RECOVERED',
        'deleted_by' => $user['id'],
        'deleted_at' => date('Y-m-d H:i:s')
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Record permanently deleted'
    ]);
    
} catch (Throwable $e) {
    $pdo->rollBack();
    sys_log('ARCHIVE-DELETE', "Failed to permanently delete record: " . $e->getMessage(), [
        'table' => $table,
        'id' => $id,
        'error' => $e->getMessage()
    ]);
    
    action_log('system_management', 'archive_delete_permanent', 'error', [
        'target_type' => $table,
        'target_id' => $id,
        'error' => $e->getMessage(),
        'severity' => 'critical'
    ]);
    
    echo json_encode([
        'success' => false,
        'message' => 'A system error occurred during deletion. Please contact your administrator.'
    ]);
}
