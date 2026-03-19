<?php
/**
 * API: Clinic Record Actions — create, assign_medtech, assign_medtech_by_nurse,
 *      update_medtech_notes, update_nurse, delete
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$userName = $user['name'] ?? 'Unknown';

// Get current user's employee ID
$myEmployeeId = null;
$myEmpName = '';
$empStmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
$empStmt->execute([':uid' => $uid]);
$myEmp = $empStmt->fetch(PDO::FETCH_ASSOC);
if ($myEmp) {
    $myEmployeeId = (int)$myEmp['id'];
    $myEmpName = $myEmp['first_name'] . ' ' . $myEmp['last_name'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$csrfToken = $input['csrf_token'] ?? '';

if (!csrf_verify($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$canWrite = user_has_access($uid, 'healthcare', 'clinic_records', 'write');
$canManage = user_has_access($uid, 'healthcare', 'clinic_records', 'manage');

/**
 * Insert audit history entry for a clinic record
 */
function clinic_history($pdo, $recordId, $action, $userId, $userName, $oldValues = null, $newValues = null, $notes = null) {
    $stmt = $pdo->prepare("
        INSERT INTO clinic_record_history (clinic_record_id, action, changed_by, changed_by_name, old_values, new_values, notes, created_at)
        VALUES (:rid, :action, :uid, :uname, :old, :new, :notes, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        ':rid' => $recordId,
        ':action' => $action,
        ':uid' => $userId,
        ':uname' => $userName,
        ':old' => $oldValues ? json_encode($oldValues) : null,
        ':new' => $newValues ? json_encode($newValues) : null,
        ':notes' => $notes,
    ]);
}

// ─── CREATE ACTION (from modal) ───
if ($action === 'create') {
    if (!$canWrite) {
        http_response_code(403);
        echo json_encode(['error' => 'Write access required']);
        exit;
    }

    $patientType = $input['patient_type'] ?? 'employee';
    $employeeId = ($patientType === 'employee') ? ((int)($input['employee_id'] ?? 0) ?: null) : null;
    $patientName = trim($input['patient_name'] ?? '');
    $recordDate = $input['record_date'] ?? date('Y-m-d');

    // If employee, fetch name
    if ($employeeId) {
        $empSt = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = :id AND deleted_at IS NULL');
        $empSt->execute([':id' => $employeeId]);
        $empRow = $empSt->fetch(PDO::FETCH_ASSOC);
        if ($empRow) $patientName = $empRow['first_name'] . ' ' . $empRow['last_name'];
    }

    if (empty($patientName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Patient name is required']);
        exit;
    }

    // Nurse fields
    $hasNurse = !empty($input['has_nurse']);
    $nurseEmployeeId = $hasNurse ? ((int)($input['nurse_employee_id'] ?? 0) ?: null) : null;
    $nurseDatetime = $hasNurse ? ($input['nurse_service_datetime'] ?? null) : null;
    $nurseNotes = $hasNurse ? trim($input['nurse_notes'] ?? '') : null;

    // MedTech fields
    $hasMedtech = !empty($input['has_medtech']);
    $medtechEmployeeId = $hasMedtech ? ((int)($input['medtech_employee_id'] ?? 0) ?: null) : null;
    $medtechDatetime = $hasMedtech ? ($input['medtech_pickup_datetime'] ?? null) : null;
    $medtechNotes = $hasMedtech ? trim($input['medtech_notes'] ?? '') : null;

    if (!$hasNurse && !$hasMedtech) {
        http_response_code(400);
        echo json_encode(['error' => 'At least one service entry (Nurse or MedTech) is required']);
        exit;
    }

    // Permission validation: validate nurse/medtech employee IDs exist
    if ($nurseEmployeeId) {
        $chk = $pdo->prepare('SELECT id FROM employees WHERE id = :id AND deleted_at IS NULL');
        $chk->execute([':id' => $nurseEmployeeId]);
        if (!$chk->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Selected nurse employee not found']);
            exit;
        }
    }
    if ($medtechEmployeeId) {
        $chk = $pdo->prepare('SELECT id FROM employees WHERE id = :id AND deleted_at IS NULL');
        $chk->execute([':id' => $medtechEmployeeId]);
        if (!$chk->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Selected medtech employee not found']);
            exit;
        }
    }

    $status = ($hasNurse && $hasMedtech) ? 'completed' : 'open';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clinic_records
            (employee_id, patient_name, record_date, status,
             nurse_employee_id, nurse_service_datetime, nurse_notes,
             medtech_employee_id, medtech_pickup_datetime, medtech_notes,
             created_by, created_at, updated_at)
            VALUES (:emp_id, :name, :rdate, :status,
             :nurse_id, :nurse_dt, :nurse_notes,
             :mt_id, :mt_dt, :mt_notes,
             :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':name' => $patientName,
            ':rdate' => $recordDate,
            ':status' => $status,
            ':nurse_id' => $nurseEmployeeId,
            ':nurse_dt' => $nurseDatetime ?: null,
            ':nurse_notes' => $nurseNotes ?: null,
            ':mt_id' => $medtechEmployeeId,
            ':mt_dt' => $medtechDatetime ?: null,
            ':mt_notes' => $medtechNotes ?: null,
            ':created_by' => $uid,
        ]);
        $newId = $stmt->fetchColumn();

        clinic_history($pdo, $newId, 'created', $uid, $userName, null, [
            'patient_name' => $patientName,
            'nurse_employee_id' => $nurseEmployeeId,
            'medtech_employee_id' => $medtechEmployeeId,
            'status' => $status,
        ]);

        action_log('clinic_records', 'create_record', 'success', [
            'record_id' => $newId,
            'patient_name' => $patientName,
        ]);

        echo json_encode(['ok' => true, 'message' => 'Record created successfully', 'id' => $newId]);
    } catch (Throwable $e) {
        sys_log('MED1002', 'Failed to create clinic record: ' . $e->getMessage(), [
            'module' => 'clinic_records', 'file' => __FILE__, 'line' => __LINE__,
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create record']);
    }
    exit;
}

// ─── RECORD-SPECIFIC ACTIONS ───
$recordId = (int)($input['record_id'] ?? 0);
if (!$recordId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing record ID']);
    exit;
}

// Fetch current record
$stmt = $pdo->prepare('SELECT * FROM clinic_records WHERE id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $recordId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    echo json_encode(['error' => 'Record not found']);
    exit;
}

try {
    switch ($action) {
        case 'assign_medtech_self':
            if (!$canWrite) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to perform this action']);
                exit;
            }
            if (!empty($record['medtech_employee_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'MedTech already assigned to this record']);
                exit;
            }
            if (!$myEmployeeId) {
                http_response_code(400);
                echo json_encode(['error' => 'No employee profile linked to your account']);
                exit;
            }

            $notes = trim($input['medtech_notes'] ?? '');
            $now = date('Y-m-d H:i:s');

            $upd = $pdo->prepare("
                UPDATE clinic_records
                SET medtech_employee_id = :mid, medtech_pickup_datetime = :dt, medtech_notes = :notes,
                    status = 'completed', updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([
                ':mid' => $myEmployeeId,
                ':dt' => $now,
                ':notes' => $notes ?: null,
                ':uid' => $uid,
                ':id' => $recordId,
            ]);

            clinic_history($pdo, $recordId, 'medtech_assigned', $uid, $userName, null, [
                'medtech_employee_id' => $myEmployeeId,
                'medtech_name' => $myEmpName,
                'medtech_pickup_datetime' => $now,
                'medtech_notes' => $notes,
            ], 'MedTech self-assigned');

            action_log('clinic_records', 'assign_medtech_self', 'success', [
                'record_id' => $recordId,
                'medtech_employee_id' => $myEmployeeId,
            ]);

            echo json_encode(['ok' => true, 'message' => 'You have been assigned as MedTech']);
            break;

        case 'assign_medtech_by_nurse':
            if (!$canWrite) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to assign MedTech']);
                exit;
            }
            // Only the nurse on the record or a manager can assign medtech
            $isNurse = $myEmployeeId && (int)$record['nurse_employee_id'] === $myEmployeeId;
            if (!$isNurse && !$canManage) {
                http_response_code(403);
                echo json_encode(['error' => 'Only the assigned nurse or a manager can assign MedTech']);
                exit;
            }
            if (!empty($record['medtech_employee_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'MedTech already assigned to this record']);
                exit;
            }

            $medtechId = (int)($input['medtech_employee_id'] ?? 0);
            if (!$medtechId) {
                http_response_code(400);
                echo json_encode(['error' => 'MedTech employee is required']);
                exit;
            }

            $chk = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE id = :id AND deleted_at IS NULL");
            $chk->execute([':id' => $medtechId]);
            $mtEmp = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$mtEmp) {
                http_response_code(400);
                echo json_encode(['error' => 'MedTech employee not found']);
                exit;
            }

            $now = date('Y-m-d H:i:s');
            $mtName = $mtEmp['first_name'] . ' ' . $mtEmp['last_name'];

            $upd = $pdo->prepare("
                UPDATE clinic_records
                SET medtech_employee_id = :mid, medtech_pickup_datetime = :dt,
                    status = 'completed', updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([
                ':mid' => $medtechId,
                ':dt' => $now,
                ':uid' => $uid,
                ':id' => $recordId,
            ]);

            clinic_history($pdo, $recordId, 'medtech_assigned', $uid, $userName, null, [
                'medtech_employee_id' => $medtechId,
                'medtech_name' => $mtName,
                'medtech_pickup_datetime' => $now,
            ], 'Assigned by nurse/manager');

            action_log('clinic_records', 'assign_medtech_by_nurse', 'success', [
                'record_id' => $recordId,
                'medtech_employee_id' => $medtechId,
                'assigned_by' => $uid,
            ]);

            echo json_encode(['ok' => true, 'message' => 'MedTech assigned: ' . htmlspecialchars($mtName)]);
            break;

        case 'update_medtech_notes':
            if (!$canWrite) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to update notes']);
                exit;
            }
            $isMedtech = $myEmployeeId && (int)$record['medtech_employee_id'] === $myEmployeeId;
            if (!$isMedtech && !$canManage) {
                http_response_code(403);
                echo json_encode(['error' => 'Only the assigned MedTech or a manager can edit these notes']);
                exit;
            }

            $newNotes = trim($input['medtech_notes'] ?? '');
            $oldNotes = $record['medtech_notes'];

            $upd = $pdo->prepare("
                UPDATE clinic_records
                SET medtech_notes = :notes, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([':notes' => $newNotes ?: null, ':uid' => $uid, ':id' => $recordId]);

            clinic_history($pdo, $recordId, 'medtech_updated', $uid, $userName,
                ['medtech_notes' => $oldNotes],
                ['medtech_notes' => $newNotes],
                'MedTech notes updated'
            );

            action_log('clinic_records', 'update_medtech_notes', 'success', ['record_id' => $recordId]);
            echo json_encode(['ok' => true, 'message' => 'MedTech notes updated']);
            break;

        case 'update_nurse':
            if (!$canWrite) {
                http_response_code(403);
                echo json_encode(['error' => 'You do not have permission to update notes']);
                exit;
            }
            $isNurse = $myEmployeeId && (int)$record['nurse_employee_id'] === $myEmployeeId;
            $hasMedtech = !empty($record['medtech_employee_id']);

            if (!$canManage && ($hasMedtech || !$isNurse)) {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot edit: MedTech already assigned or you are not the assigned nurse']);
                exit;
            }

            $newNotes = trim($input['nurse_notes'] ?? '');
            $oldNotes = $record['nurse_notes'];

            $upd = $pdo->prepare("
                UPDATE clinic_records
                SET nurse_notes = :notes, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([':notes' => $newNotes ?: null, ':uid' => $uid, ':id' => $recordId]);

            clinic_history($pdo, $recordId, 'nurse_updated', $uid, $userName,
                ['nurse_notes' => $oldNotes],
                ['nurse_notes' => $newNotes],
                'Nurse notes updated'
            );

            action_log('clinic_records', 'update_nurse_notes', 'success', ['record_id' => $recordId]);
            echo json_encode(['ok' => true, 'message' => 'Nurse notes updated']);
            break;

        case 'delete':
            if (!$canManage) {
                http_response_code(403);
                echo json_encode(['error' => 'Manage access required to delete records']);
                exit;
            }

            $isNurse = $myEmployeeId && (int)$record['nurse_employee_id'] === $myEmployeeId;
            $hasMedtech = !empty($record['medtech_employee_id']);
            $isAdmin = $user && !empty($user['is_system_admin']);

            if ($isNurse && $hasMedtech && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot delete: MedTech is already assigned']);
                exit;
            }

            $upd = $pdo->prepare("
                UPDATE clinic_records
                SET deleted_at = CURRENT_TIMESTAMP, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upd->execute([':uid' => $uid, ':id' => $recordId]);

            clinic_history($pdo, $recordId, 'deleted', $uid, $userName, [
                'patient_name' => $record['patient_name'],
                'status' => $record['status'],
                'nurse_employee_id' => $record['nurse_employee_id'],
                'medtech_employee_id' => $record['medtech_employee_id'],
            ], null, 'Record deleted');

            action_log('clinic_records', 'delete_record', 'success', [
                'record_id' => $recordId,
                'patient_name' => $record['patient_name'],
            ]);

            echo json_encode(['ok' => true, 'message' => 'Record deleted']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    sys_log('MED1001', 'Clinic record action failed: ' . $e->getMessage(), [
        'module' => 'clinic_records',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['action' => $action, 'record_id' => $recordId ?? 0],
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred']);
}
