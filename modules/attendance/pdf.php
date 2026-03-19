<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('attendance', 'attendance_records', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$q = trim($_GET['q'] ?? '');

$where = ' WHERE a.date BETWEEN :from AND :to ';
$params = [':from'=>$from, ':to'=>$to];
if ($q !== '') { $where .= ' AND (e.employee_code ILIKE :q OR e.first_name ILIKE :q OR e.last_name ILIKE :q) '; $params[':q'] = "%$q%"; }

$sql = 'SELECT a.date, e.employee_code, e.last_name, e.first_name, a.time_in, a.time_out, a.overtime_minutes, a.status FROM attendance a JOIN employees e ON e.id=a.employee_id ' . $where . ' ORDER BY a.date ASC, e.last_name ASC';
try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { sys_log('DB2811', 'Prepare/execute failed: attendance PDF - ' . $e->getMessage(), ['module'=>'attendance','file'=>__FILE__,'line'=>__LINE__]); die('Error generating PDF'); }
$lines = [];
$lines[] = "Attendance Report: $from to $to";
$lines[] = str_repeat('-', 80);
$lines[] = sprintf("%-10s %-12s %-20s %-8s %-8s %-6s %-10s", 'Date','Code','Name','In','Out','OT','Status');
$lines[] = str_repeat('-', 80);
foreach ($rows as $r) {
    $name = $r['last_name'] . ', ' . $r['first_name'];
    $lines[] = sprintf("%-10s %-12s %-20s %-8s %-8s %-6s %-10s", $r['date'], $r['employee_code'], $name, $r['time_in'], $r['time_out'], (int)$r['overtime_minutes'], $r['status']);
}
sys_log('REPORT-GEN', 'Generated attendance PDF', ['module'=>'attendance','file'=>__FILE__,'line'=>__LINE__,'context'=>['from'=>$from,'to'=>$to,'count'=>count($rows)]]);
audit('export_pdf', 'attendance:' . $from . ':' . $to);
pdf_output_report('attendance', 'Attendance Report', $lines, 'attendance.pdf');
