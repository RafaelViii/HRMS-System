<?php
require_once __DIR__ . '/../../includes/auth.php'; require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$u=current_user(); $uid=(int)$u['id']; $adminView = user_has_access($uid, 'leave', 'leave_approval', 'read');

$status = $_GET['status'] ?? '';
$where = '';
if ($status !== '') { $where = ' WHERE status = :status '; }
$filterMine = '';
if (!$adminView) { $filterMine = ($where ? ' AND ' : ' WHERE ') . ' e.user_id = :uid '; }
$sql = 'SELECT lr.id, e.employee_code, e.last_name, e.first_name, lr.leave_type, lr.start_date, lr.end_date, lr.total_days, lr.status
FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id' . $where . $filterMine . ' ORDER BY lr.id DESC';
$stmt = $pdo->prepare($sql);
if ($where) { $stmt->bindValue(':status', $status); }
if ($filterMine) { $stmt->bindValue(':uid', $uid, PDO::PARAM_INT); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported leave requests CSV', ['module'=>'leave','file'=>__FILE__,'line'=>__LINE__,'context'=>['status'=>$status,'adminView'=>$adminView,'count'=>count($rows)]]);
audit('export_csv', 'leave_requests');
output_csv('leave_requests', ['id','employee_code','last_name','first_name','leave_type','start_date','end_date','total_days','status'], $rows);
