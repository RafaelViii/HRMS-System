<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('attendance', 'attendance_records', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$params = [':from'=>$from, ':to'=>$to];
$where = ' WHERE a.date BETWEEN :from AND :to ';
if ($q !== '') { $where .= ' AND (e.employee_code ILIKE :q OR e.first_name ILIKE :q OR e.last_name ILIKE :q) '; $params[':q'] = "%$q%"; }

$sql = 'SELECT a.id, a.date, e.employee_code, e.last_name, e.first_name, a.time_in, a.time_out, a.overtime_minutes, a.status
FROM attendance a JOIN employees e ON e.id=a.employee_id ' . $where . ' ORDER BY a.date DESC, a.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported attendance CSV', ['module'=>'attendance','file'=>__FILE__,'line'=>__LINE__,'context'=>['from'=>$from,'to'=>$to,'query'=>$q,'count'=>count($rows)]]);
audit('export_csv', 'attendance:' . $from . ':' . $to);
output_csv('attendance', ['id','date','employee_code','last_name','first_name','time_in','time_out','overtime_minutes','status'], $rows);
