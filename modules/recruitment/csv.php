<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'recruitment', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$rows = [];
try {
  $rows = $pdo->query('SELECT id, full_name, email, phone, position_applied, status, created_at FROM recruitment ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
  sys_log('EXPORT-CSV', 'Exported recruitment CSV', ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__,'context'=>['count'=>count($rows)]]);
  audit('export_csv', 'recruitment');
} catch (Throwable $e) { $rows = []; }
output_csv('recruitment', ['id','full_name','email','phone','position_applied','status','created_at'], $rows);
