<?php
/**
 * Archive Recovery Endpoint
 * Restore a soft-deleted record back to active state
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

// Check if table has deleted_at column
try {
    $columnCheck = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = :table AND column_name = 'deleted_at'
        )
    ");
    $columnCheck->execute([':table' => $table]);
    
    if (!$columnCheck->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Table does not support archiving']);
        exit;
    }
} catch (Throwable $e) {
    sys_log('ARCHIVE-RECOVER', "Failed to verify table structure: " . $e->getMessage(), [
        'table' => $table,
        'id' => $id
    ]);
    echo json_encode(['success' => false, 'message' => 'System error']);
    exit;
}

// Get current record data before recovery
try {
    $selectStmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND deleted_at IS NOT NULL");
    $selectStmt->execute([$id]);
    $record = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Record not found or already recovered']);
        exit;
    }
} catch (Throwable $e) {
    sys_log('ARCHIVE-RECOVER', "Failed to fetch record: " . $e->getMessage(), [
        'table' => $table,
        'id' => $id
    ]);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch record']);
    exit;
}

// Recover the record
try {
    $pdo->beginTransaction();
    
    $updateStmt = $pdo->prepare("
        UPDATE $table 
        SET deleted_at = NULL, deleted_by = NULL 
        WHERE id = ? AND deleted_at IS NOT NULL
    ");
    $updateStmt->execute([$id]);
    
    if ($updateStmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to recover record']);
        exit;
    }
    
    // Log the recovery action
    action_log('system_management', 'archive_recover', 'success', [
        'target_type' => $table,
        'target_id' => $id,
        'old_values' => [
            'deleted_at' => $record['deleted_at'],
            'deleted_by' => $record['deleted_by']
        ],
        'new_values' => [
            'deleted_at' => null,
            'deleted_by' => null
        ],
        'message' => "Recovered $table record #$id from archive"
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Record recovered successfully'
    ]);
    
} catch (Throwable $e) {
    $pdo->rollBack();
    sys_log('ARCHIVE-RECOVER', "Failed to recover record: " . $e->getMessage(), [
        'table' => $table,
        'id' => $id,
        'error' => $e->getMessage()
    ]);
    
    action_log('system_management', 'archive_recover', 'error', [
        'target_type' => $table,
        'target_id' => $id,
        'error' => $e->getMessage()
    ]);
    
    echo json_encode([
        'success' => false,
        'message' => 'System error occurred during recovery'
    ]);
}
