<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$where = 'WHERE e.deleted_at IS NULL';
if ($q !== '') { $where .= ' AND (e.first_name ILIKE :q OR e.last_name ILIKE :q OR e.email ILIKE :q)'; }

$sql = 'SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email, d.name AS department, p.name AS position, e.status
FROM employees e LEFT JOIN departments d ON d.id=e.department_id AND d.deleted_at IS NULL LEFT JOIN positions p ON p.id=e.position_id AND p.deleted_at IS NULL ' . $where . ' ORDER BY e.id DESC';
$stmt = $pdo->prepare($sql);
if ($where) { $like = "%$q%"; $stmt->bindValue(':q', $like); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported employees CSV', ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['query'=>$q,'count'=>count($rows)]]);
audit('export_csv', 'employees');
output_csv('employees', ['id','employee_code','first_name','last_name','email','department','position','status'], $rows);
