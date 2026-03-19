<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'audit_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE (a.action ILIKE :q OR COALESCE(a.details, \'\') ILIKE :q OR COALESCE(u.full_name, \'\') ILIKE :q)';
  $params[':q'] = "%$q%";
}

$sql = 'SELECT a.id, a.created_at, COALESCE(u.full_name, \'-\') AS user_full_name, a.action, COALESCE(a.details, \'\') AS details,
  (SELECT COUNT(*) FROM action_reversals ar WHERE ar.audit_log_id=a.id) AS is_reversed
  FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ' . $where . ' ORDER BY a.id DESC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sys_log('EXPORT-CSV', 'Exported audit logs CSV', ['module'=>'audit','file'=>__FILE__,'line'=>__LINE__,'context'=>['query'=>$q,'count'=>count($rows)]]);
audit('export_csv', 'audit_logs');
output_csv('audit_logs', ['id','created_at','user_full_name','action','details','is_reversed'], $rows);
