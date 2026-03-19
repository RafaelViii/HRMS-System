<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// Scope to current user's assignments (employee, department, or global)
$emp = null; $deptId = null;
try {
  $st = $pdo->prepare('SELECT id, department_id FROM employees WHERE user_id = :uid LIMIT 1');
  $st->execute([':uid' => $uid]);
  $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  $deptId = $emp ? (int)($emp['department_id'] ?? 0) : null;
} catch (Throwable $e) { $emp = null; $deptId = null; }

$rows = [];
try {
  $sql = "SELECT d.id, d.title, d.doc_type, d.file_path, d.created_at
          FROM documents d
          LEFT JOIN document_assignments da ON da.document_id = d.id
          WHERE (da.employee_id = :eid OR da.department_id = :dept OR (da.employee_id IS NULL AND da.department_id IS NULL))
          GROUP BY d.id
          ORDER BY d.id DESC";
  $q = $pdo->prepare($sql);
  $q->execute([':eid' => (int)($emp['id'] ?? 0), ':dept' => $deptId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

$headers = ['ID','Title','Type','File','Created At'];
$data = [];
foreach ($rows as $r) {
  $data[] = [
    (string)$r['id'],
    (string)$r['title'],
    (string)$r['doc_type'],
    (string)$r['file_path'],
    (string)$r['created_at'],
  ];
}

sys_log('EXPORT-CSV', 'Exported documents CSV', ['module'=>'documents','file'=>__FILE__,'line'=>__LINE__,'context'=>['count'=>count($data)]]);
audit('export_csv', 'documents');
output_csv('documents.csv', $headers, $data);
