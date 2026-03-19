<?php
/**
 * Clinic Records — CSV Export
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();

// Same filters as index page
$q = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';

$where = ['cr.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = "(cr.patient_name ILIKE :q OR CONCAT(ne.first_name, ' ', ne.last_name) ILIKE :q OR CONCAT(me.first_name, ' ', me.last_name) ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($dateFrom) { $where[] = 'cr.record_date >= :date_from'; $params[':date_from'] = $dateFrom; }
if ($dateTo) { $where[] = 'cr.record_date <= :date_to'; $params[':date_to'] = $dateTo; }
if ($status && in_array($status, ['open', 'completed', 'cancelled'])) {
    $where[] = 'cr.status = :status'; $params[':status'] = $status;
}
if ($type === 'nurse') { $where[] = 'cr.nurse_employee_id IS NOT NULL AND cr.medtech_employee_id IS NULL'; }
elseif ($type === 'medtech') { $where[] = 'cr.medtech_employee_id IS NOT NULL AND cr.nurse_employee_id IS NULL'; }
elseif ($type === 'both') { $where[] = 'cr.nurse_employee_id IS NOT NULL AND cr.medtech_employee_id IS NOT NULL'; }

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cr.id, cr.record_date, cr.patient_name, cr.status,
           CONCAT(ne.first_name, ' ', ne.last_name) AS nurse_name,
           cr.nurse_service_datetime, cr.nurse_notes,
           CONCAT(me.first_name, ' ', me.last_name) AS medtech_name,
           cr.medtech_pickup_datetime, cr.medtech_notes
    FROM clinic_records cr
    LEFT JOIN employees ne ON ne.id = cr.nurse_employee_id
    LEFT JOIN employees me ON me.id = cr.medtech_employee_id
    WHERE {$whereSQL}
    ORDER BY cr.record_date DESC, cr.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

audit('export_csv', 'clinic_records');
sys_log('EXPORT-CSV', 'Exported clinic records CSV', [
    'module' => 'clinic_records', 'file' => __FILE__, 'line' => __LINE__,
    'context' => ['count' => count($rows)],
]);

output_csv('clinic_records', [
    'ID', 'Record Date', 'Patient Name', 'Status',
    'Nurse', 'Nurse Service Date/Time', 'Nurse Notes',
    'MedTech', 'MedTech Pickup Date/Time', 'MedTech Notes',
], $rows);
