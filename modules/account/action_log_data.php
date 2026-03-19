<?php
/**
 * Action Log Data Endpoint
 * Returns action log entries for a specific user with optional date filtering
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

try {
    require_login();
    
    // Check if user has permission to view audit logs
    $currentUser = $_SESSION['user'] ?? null;
    $currentUserId = (int)($currentUser['id'] ?? 0);
    
    // Check access permission
    if (!user_has_access($currentUserId, 'user_management', 'user_accounts', 'read')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to view action logs']);
        exit;
    }
    
    $pdo = get_db_conn();
    $userId = (int)($_GET['user_id'] ?? 0);
    
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
        exit;
    }
    
    // Get date filters
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    
    // Build WHERE clause
    $where = ['al.user_id = :user_id'];
    $params = [':user_id' => $userId];
    
    if ($dateFrom !== '') {
        $where[] = 'DATE(al.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo !== '') {
        $where[] = 'DATE(al.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $where);
    
    // Count total records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Fetch action logs (limit to 100 most recent)
    $sql = "
        SELECT 
            al.id,
            al.created_at,
            al.action,
            al.action_type,
            al.module,
            al.details,
            al.status,
            al.severity,
            al.target_type,
            al.target_id
        FROM audit_logs al
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format actions for display
    $actions = [];
    foreach ($logs as $log) {
        $timestamp = strtotime($log['created_at']);
        $actions[] = [
            'id' => (int)$log['id'],
            'date' => date('M d, Y', $timestamp),
            'time' => date('H:i:s', $timestamp),
            'timestamp' => $log['created_at'],
            'action' => $log['action'] ?? '',
            'action_type' => $log['action_type'] ? ucwords(str_replace('_', ' ', $log['action_type'])) : '',
            'module' => $log['module'] ? ucwords(str_replace('_', ' ', $log['module'])) : '',
            'details' => $log['details'] ?? '',
            'status' => $log['status'] ?? 'success',
            'severity' => $log['severity'] ?? 'normal',
            'target_type' => $log['target_type'] ?? '',
            'target_id' => $log['target_id'] ?? null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'actions' => $actions,
        'total' => $total,
        'showing' => count($actions),
    ]);
    
} catch (Throwable $e) {
    sys_log('ACTION-LOG-DATA', 'Failed to fetch action log data: ' . $e->getMessage(), [
        'module' => 'account',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['user_id' => $userId ?? null]
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load action log data'
    ]);
}
