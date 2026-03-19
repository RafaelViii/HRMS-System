<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'positions', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$where = '';
if ($q !== '') { $where = "WHERE (p.name ILIKE :like1 OR d.name ILIKE :like2) AND p.deleted_at IS NULL"; }
else { $where = "WHERE p.deleted_at IS NULL"; }
$sql = 'SELECT p.id, p.name AS position, d.name AS department, p.base_salary FROM positions p LEFT JOIN departments d ON d.id=p.department_id AND d.deleted_at IS NULL ' . $where . ' ORDER BY p.id DESC';
$stmt = $pdo->prepare($sql);
if ($q !== '') { $like = "%$q%"; $stmt->bindValue(':like1', $like); $stmt->bindValue(':like2', $like); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported positions CSV', ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__,'context'=>['query'=>$q,'count'=>count($rows)]]);
audit('export_csv', 'positions');
output_csv('positions', ['id','position','department','base_salary'], $rows);
