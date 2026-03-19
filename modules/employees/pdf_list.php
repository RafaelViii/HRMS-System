<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'employees', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();

$lines = [];
try {
  $stmt = $pdo->query("SELECT e.employee_code, e.first_name, e.last_name, COALESCE(d.name, '-') AS dept, COALESCE(p.name, '-') AS pos, e.status FROM employees e LEFT JOIN departments d ON d.id=e.department_id AND d.deleted_at IS NULL LEFT JOIN positions p ON p.id=e.position_id AND p.deleted_at IS NULL WHERE e.deleted_at IS NULL ORDER BY e.last_name, e.first_name");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    $lines[] = $row['employee_code'] . ' — ' . $row['last_name'] . ', ' . $row['first_name'] . ' — ' . $row['dept'] . ' — ' . $row['pos'] . ' — ' . $row['status'];
  }
} catch (Throwable $e) { sys_log('DB2802', 'Query failed: employees list PDF - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]); die('Error generating PDF'); }
sys_log('REPORT-GEN', 'Generated employees list PDF', ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
audit('export_pdf', 'employees_list');
pdf_output_report('employees_list', 'Employees List', $lines, 'employees.pdf');
