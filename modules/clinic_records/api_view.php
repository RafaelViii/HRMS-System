<?php
/**
 * API: Fetch single clinic record detail for modal view
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'read');

header('Content-Type: application/json');

$pdo = get_db_conn();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing record ID']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT cr.*,
           pe.first_name AS patient_first, pe.last_name AS patient_last, pe.employee_code AS patient_code,
           ne.first_name AS nurse_first, ne.last_name AS nurse_last, ne.employee_code AS nurse_code,
           me.first_name AS medtech_first, me.last_name AS medtech_last, me.employee_code AS medtech_code,
           cu.email AS created_by_email,
           (SELECT CONCAT(e2.first_name, ' ', e2.last_name) FROM users u2 LEFT JOIN employees e2 ON e2.user_id = u2.id WHERE u2.id = cr.created_by) AS created_by_name
    FROM clinic_records cr
    LEFT JOIN employees pe ON pe.id = cr.employee_id
    LEFT JOIN employees ne ON ne.id = cr.nurse_employee_id
    LEFT JOIN employees me ON me.id = cr.medtech_employee_id
    LEFT JOIN users cu ON cu.id = cr.created_by
    WHERE cr.id = :id AND cr.deleted_at IS NULL
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    echo json_encode(['error' => 'Record not found']);
    exit;
}

// Determine current user's employee ID
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$myEmployeeId = null;
$empStmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
$empStmt->execute([':uid' => $uid]);
$empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
if ($empRow) {
    $myEmployeeId = (int)$empRow['id'];
}

$canWrite = user_has_access($uid, 'healthcare', 'clinic_records', 'write');
$canManage = user_has_access($uid, 'healthcare', 'clinic_records', 'manage');

// Build nurse/medtech display names
$nurseName = $record['nurse_first'] ? ($record['nurse_first'] . ' ' . $record['nurse_last']) : null;
$medtechName = $record['medtech_first'] ? ($record['medtech_first'] . ' ' . $record['medtech_last']) : null;

$isNurse = $myEmployeeId && (int)$record['nurse_employee_id'] === $myEmployeeId;
$isMedtech = $myEmployeeId && (int)$record['medtech_employee_id'] === $myEmployeeId;
$hasMedtech = !empty($record['medtech_employee_id']);
$hasNurse = !empty($record['nurse_employee_id']);

echo json_encode([
    'id' => (int)$record['id'],
    'patient_name' => $record['patient_name'],
    'employee_id' => $record['employee_id'] ? (int)$record['employee_id'] : null,
    'patient_code' => $record['patient_code'],
    'record_date' => $record['record_date'],
    'status' => $record['status'],
    'nurse_employee_id' => $record['nurse_employee_id'] ? (int)$record['nurse_employee_id'] : null,
    'nurse_name' => $nurseName,
    'nurse_code' => $record['nurse_code'],
    'nurse_service_datetime' => $record['nurse_service_datetime'],
    'nurse_notes' => $record['nurse_notes'],
    'medtech_employee_id' => $record['medtech_employee_id'] ? (int)$record['medtech_employee_id'] : null,
    'medtech_name' => $medtechName,
    'medtech_code' => $record['medtech_code'],
    'medtech_pickup_datetime' => $record['medtech_pickup_datetime'],
    'medtech_notes' => $record['medtech_notes'],
    'created_by_name' => $record['created_by_name'],
    'created_at' => $record['created_at'],
    'updated_at' => $record['updated_at'],
    // Permissions context for the current viewer
    'permissions' => [
        'can_write' => $canWrite,
        'can_manage' => $canManage,
        'is_nurse' => $isNurse,
        'is_medtech' => $isMedtech,
        'has_medtech' => $hasMedtech,
        'has_nurse' => $hasNurse,
        'my_employee_id' => $myEmployeeId,
        // Nurse can edit/delete only if no medtech assigned yet
        'nurse_can_edit' => $isNurse && !$hasMedtech,
        'nurse_can_delete' => ($isNurse && !$hasMedtech && $canManage),
        // MedTech can assign self if not assigned
        'can_assign_medtech' => $canWrite && !$hasMedtech,
        // MedTech can edit own notes
        'medtech_can_edit_notes' => $isMedtech && $canWrite,
        // Nurse can assign medtech
        'nurse_can_assign_medtech' => $isNurse && !$hasMedtech && $canWrite,
    ],
]);
