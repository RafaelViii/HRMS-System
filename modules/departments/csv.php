<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'departments', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$where = ($q !== '') ? 'WHERE name ILIKE :q AND deleted_at IS NULL' : 'WHERE deleted_at IS NULL';
$sql = 'SELECT id, name, description FROM departments ' . $where . ' ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
if ($where) { $like = "%$q%"; $stmt->bindValue(':q', $like); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported departments CSV', ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__,'context'=>['query'=>$q,'count'=>count($rows)]]);
audit('export_csv', 'departments');
output_csv('departments', ['id','name','description'], $rows);
