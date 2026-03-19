<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'system_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
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
if ($to !== '') { $where[]="created_at < ((:to)::date + INTERVAL '1 day')"; $params[':to']=$to; }
$whereSql = $where?('WHERE '.implode(' AND ',$where)) : '';

$stmt = $pdo->prepare('SELECT created_at, code, message, module, file, line, func, context FROM system_logs ' . $whereSql . ' ORDER BY id DESC LIMIT 5000');
$stmt->execute($params);
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="system_logs.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['created_at','code','message','module','file','line','func','context']);
foreach ($res as $row) { fputcsv($out, [$row['created_at'],$row['code'],$row['message'],$row['module'],$row['file'],$row['line'],$row['func'],$row['context']]); }
fclose($out);
