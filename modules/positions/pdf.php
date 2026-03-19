<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'positions', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();

$rows = [];
try { $rows = $pdo->query('SELECT p.name, COALESCE(d.name, "-") as dept, p.base_salary FROM positions p LEFT JOIN departments d ON d.id=p.department_id AND d.deleted_at IS NULL WHERE p.deleted_at IS NULL ORDER BY d.name NULLS FIRST, p.name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }
$lines = array_map(function($row){ return $row['dept'] . ' — ' . $row['name'] . ' — ' . number_format((float)$row['base_salary'],2); }, $rows);
sys_log('REPORT-GEN', 'Generated positions PDF', ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__,'context'=>['count'=>count($rows)]]);
audit('export_pdf', 'positions');
pdf_output_report('positions', 'Positions Report', $lines, 'positions.pdf');
