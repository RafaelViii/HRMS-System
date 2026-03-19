<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'employees', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();

$id = (int)($_GET['id'] ?? 0);
$e = null;
try {
  $stmt = $pdo->prepare('SELECT e.*, COALESCE(d.name, '-') AS dept, COALESCE(p.name, '-') AS pos FROM employees e LEFT JOIN departments d ON d.id=e.department_id AND d.deleted_at IS NULL LEFT JOIN positions p ON p.id=e.position_id AND p.deleted_at IS NULL WHERE e.id = :id AND e.deleted_at IS NULL');
  $stmt->execute([':id' => $id]);
  $e = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $eex) { sys_log('DB2801', 'Employee profile PDF query failed - ' . $eex->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]); }
if (!$e) { die('Not found'); }

$lines = [
  'Code: ' . $e['employee_code'],
  'Name: ' . $e['first_name'] . ' ' . $e['last_name'],
  'Email: ' . $e['email'],
  'Phone: ' . $e['phone'],
  'Department: ' . $e['dept'],
  'Position: ' . $e['pos'],
  'Hire Date: ' . $e['hire_date'],
  'Employment Type: ' . $e['employment_type'],
  'Status: ' . $e['status'],
  'Salary: ' . number_format((float)$e['salary'],2),
  'Address: ' . $e['address'],
];
sys_log('REPORT-GEN', 'Generated employee profile PDF', ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['employee_id'=>$e['id'],'employee_code'=>$e['employee_code']]]);
audit('export_pdf', 'employee_profile:' . $e['employee_code']);
pdf_output_report('employee_profile', 'Employee Profile', $lines, 'employee_'.$e['employee_code'].'.pdf');
