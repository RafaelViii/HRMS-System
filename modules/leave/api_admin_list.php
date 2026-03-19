<?php
// API endpoint for async leave request filtering
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display to avoid breaking JSON
ini_set('log_errors', '1');

// Capture any unexpected output and convert to JSON error
ob_start();
register_shutdown_function(function() {
  $error = error_get_last();
  if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
      'success' => false,
      'error' => 'Fatal error occurred'
    ]);
  } else {
    ob_end_flush();
  }
});

try {
  require_once __DIR__ . '/../../includes/config.php';
  require_once __DIR__ . '/../../includes/auth.php';
  require_once __DIR__ . '/../../includes/db.php';
  require_once __DIR__ . '/../../includes/utils.php';
} catch (Throwable $e) {
  ob_clean();
  header('Content-Type: application/json');
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Failed to load dependencies'
  ]);
  exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check authentication
if (empty($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));

// Check access (department supervisors can access even if not admin/hr)
$pdo = get_db_conn();

// Check if user is a department supervisor first
$isDeptSupervisor = false;
try {
  $checkSupStmt = $pdo->prepare('SELECT COUNT(*) FROM department_supervisors WHERE supervisor_user_id = :uid');
  $checkSupStmt->execute([':uid' => $uid]);
  $isDeptSupervisor = ((int)$checkSupStmt->fetchColumn() > 0);
} catch (Throwable $e) {
  $isDeptSupervisor = false;
}

$isAdminOrHR = in_array($role, ['admin', 'hr'], true);

// Allow access if user is admin, hr, or is a department supervisor
// Department supervisors bypass the permission check
if (!$isAdminOrHR && !$isDeptSupervisor) {
  // Check permissions only if not admin/hr and not a department supervisor
  try {
    $hasAccess = user_has_access($uid, 'leave', 'leave_requests', 'write');
    if (!$hasAccess) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Forbidden - You do not have permission to manage leave requests']);
      exit;
    }
  } catch (Throwable $e) {
    // If permission check fails, log and deny access
    error_log('Leave API permission check failed: ' . $e->getMessage());
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission check failed']);
    exit;
  }
}

// Check if user is a department supervisor (restricts view to their department(s))
$supervisedDepartments = [];
try {
  $deptStmt = $pdo->prepare('SELECT DISTINCT department_id FROM department_supervisors WHERE supervisor_user_id = :uid');
  $deptStmt->execute([':uid' => $uid]);
  $supervisedDepartments = $deptStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
  // If query fails, leave empty (no restriction for admins/hr)
}

$isDepartmentSupervisor = !empty($supervisedDepartments);

// Parse filters
$status = strtolower((string)($_GET['status'] ?? ''));
$leaveType = strtolower(trim((string)($_GET['type'] ?? '')));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
  $status = '';
}

try {
  // Build query
  $sql = 'SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date, lr.total_days, lr.status, lr.created_at, lr.remarks,
    e.employee_code, e.first_name, e.last_name, e.id as employee_id, e.department_id,
    d.name as department_name
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE 1=1';
  
  $countSql = 'SELECT COUNT(*) FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE 1=1';
  
  $params = [];
  
  // Department supervisor restriction: only show requests from their supervised departments
  if ($isDepartmentSupervisor && !$isAdminOrHR) {
    $deptPlaceholders = [];
    foreach ($supervisedDepartments as $idx => $deptId) {
      $key = ':dept_' . $idx;
      $deptPlaceholders[] = $key;
      $params[$key] = (int)$deptId;
    }
    $placeholders = implode(',', $deptPlaceholders);
    $sql .= " AND e.department_id IN ($placeholders)";
    $countSql .= " AND e.department_id IN ($placeholders)";
  }
  
  // Status filter
  if ($status !== '') {
    $sql .= ' AND lr.status = :status';
    $countSql .= ' AND lr.status = :status';
    $params[':status'] = $status;
  }
  
  // Leave type filter
  if ($leaveType !== '') {
    $sql .= ' AND LOWER(lr.leave_type) = :type';
    $countSql .= ' AND LOWER(lr.leave_type) = :type';
    $params[':type'] = $leaveType;
  }
  
  // Search filter
  if ($search !== '') {
    $searchPattern = '%' . $search . '%';
    $sql .= ' AND (e.employee_code ILIKE :search OR e.first_name ILIKE :search OR e.last_name ILIKE :search)';
    $countSql .= ' AND (e.employee_code ILIKE :search OR e.first_name ILIKE :search OR e.last_name ILIKE :search)';
    $params[':search'] = $searchPattern;
  }
  
  // Get total count
  $stmtCount = $pdo->prepare($countSql);
  foreach ($params as $key => $value) {
    $stmtCount->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmtCount->execute();
  $total = (int)$stmtCount->fetchColumn();
  
  // Get status breakdown for stats
  $statsSql = 'SELECT lr.status, COUNT(*) as count FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE 1=1';
  $statsParams = [];
  
  // Department restriction for stats (use named params same as main query)
  if ($isDepartmentSupervisor && !$isAdminOrHR) {
    $deptPlaceholders = [];
    foreach ($supervisedDepartments as $idx => $deptId) {
      $key = ':sdept_' . $idx;
      $deptPlaceholders[] = $key;
      $statsParams[$key] = (int)$deptId;
    }
    $placeholders = implode(',', $deptPlaceholders);
    $statsSql .= " AND e.department_id IN ($placeholders)";
  }
  if ($leaveType !== '') {
    $statsSql .= ' AND LOWER(lr.leave_type) = :type';
    $statsParams[':type'] = $leaveType;
  }
  if ($search !== '') {
    $searchPattern = '%' . $search . '%';
    $statsSql .= ' AND (e.employee_code ILIKE :search OR e.first_name ILIKE :search OR e.last_name ILIKE :search)';
    $statsParams[':search'] = $searchPattern;
  }
  $statsSql .= ' GROUP BY lr.status';
  
  $statsStmt = $pdo->prepare($statsSql);
  foreach ($statsParams as $key => $value) {
    $statsStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $statsStmt->execute();
  $statsRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  $stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
  ];
  foreach ($statsRows as $row) {
    $status = strtolower($row['status']);
    if (isset($stats[$status])) {
      $stats[$status] = (int)$row['count'];
    }
  }
  
  // Add sorting and pagination
  $sql .= ' ORDER BY lr.created_at DESC, lr.id DESC LIMIT :limit OFFSET :offset';
  
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  // Format data
  $results = [];
  foreach ($rows as $row) {
    $results[] = [
      'id' => (int)$row['id'],
      'employee_code' => $row['employee_code'],
      'employee_name' => $row['last_name'] . ', ' . $row['first_name'],
      'employee_id' => (int)$row['employee_id'],
      'department' => $row['department_name'] ?? 'Unassigned',
      'leave_type' => $row['leave_type'],
      'leave_type_label' => leave_label_for_type($row['leave_type']),
      'start_date' => $row['start_date'],
      'end_date' => $row['end_date'],
      'total_days' => (float)$row['total_days'],
      'status' => $row['status'],
      'created_at' => $row['created_at'],
      'remarks' => $row['remarks'] ?? '',
      'formatted' => [
        'start_date' => date('M d, Y', strtotime($row['start_date'])),
        'end_date' => date('M d, Y', strtotime($row['end_date'])),
        'created_at' => format_datetime_display($row['created_at'], false, ''),
      ]
    ];
  }
  
  // Return response
  echo json_encode([
    'success' => true,
    'data' => $results,
    'pagination' => [
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => (int)ceil($total / $limit),
    ],
    'stats' => $stats,
    'filters' => [
      'status' => $status,
      'type' => $leaveType,
      'search' => $search,
    ],
  ], JSON_UNESCAPED_SLASHES);
  
} catch (Throwable $e) {
  sys_log('LEAVE-API-LIST', 'API list error: ' . $e->getMessage(), [
    'module' => 'leave',
    'file' => __FILE__,
    'line' => __LINE__,
    'user_id' => $uid
  ]);
  
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Internal server error'
  ]);
}
