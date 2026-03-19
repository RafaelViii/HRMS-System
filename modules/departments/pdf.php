<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'departments', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';

$pdo = get_db_conn();
$lines = [];
try {
  $stmt = $pdo->query('SELECT name, description FROM departments WHERE deleted_at IS NULL ORDER BY name');
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    $lines[] = ($row['name'] ?? '') . ' — ' . ((string)($row['description'] ?? ''));
  }
} catch (Throwable $e) {
  sys_log('DB2601', 'Departments PDF query failed - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
}
sys_log('REPORT-GEN', 'Generated departments PDF', ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__,'context'=>['count'=>count($rows ?? [])]]);
audit('export_pdf', 'departments');
pdf_output_report('departments', 'Departments Report', $lines, 'departments.pdf');
