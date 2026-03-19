<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'system_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$code = trim($_GET['code'] ?? '');
$module = trim($_GET['module'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = [];$params=[];
if ($q !== '') { $where[]='(message ILIKE :q OR module ILIKE :q OR file ILIKE :q OR func ILIKE :q OR context ILIKE :q)'; $params[':q']="%$q%"; }
if ($code !== '') { $where[]='code = :code'; $params[':code']=$code; }
if ($module !== '') { $where[]='module ILIKE :module'; $params[':module']='%'.$module.'%'; }
if ($from !== '') { $where[]='created_at >= (:from)::timestamp'; $params[':from']=$from.' 00:00:00'; }
if ($to !== '') { $where[]='created_at < ((:to)::date + INTERVAL \'' . '1 day' . '\')'; $params[':to']=$to; }
$whereSql = $where?('WHERE '.implode(' AND ',$where)) : '';

$sql = 'SELECT created_at, code, message, module, file, line, func FROM system_logs ' . $whereSql . ' ORDER BY id DESC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lines = [];
foreach ($rows as $r) {
  $line = ($r['created_at'] ?? '') . ' | ' . ($r['code'] ?? '') . ' | ' . (($r['module'] ?? '') ?: '-') . ' | ' . (($r['file'] ?? '') ?: '-');
  if (isset($r['line']) && $r['line'] !== null && $r['line'] !== '') {
    $line .= ':' . $r['line'];
  }
  $line .= "\n" . ($r['message'] ?? '');
  $lines[] = $line;
}

sys_log('REPORT-GEN', 'Generated system logs PDF', ['module'=>'system_log','file'=>__FILE__,'line'=>__LINE__]);
pdf_output_report('system_logs', 'System Logs', $lines, 'system_logs.pdf');
exit;
