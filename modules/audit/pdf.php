<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'audit_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pdf.php';
$pdo = get_db_conn();
try { $rows = $pdo->query('SELECT a.created_at, COALESCE(u.full_name, "-") name, a.action, COALESCE(a.details, "") details FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }
$lines = [];
foreach ($rows as $r) { $lines[] = $r['created_at'] . ' — ' . $r['name'] . ' — ' . $r['action'] . ' — ' . $r['details']; }
sys_log('REPORT-GEN', 'Generated action log PDF', ['module'=>'audit','file'=>__FILE__,'line'=>__LINE__,'context'=>['count'=>count($rows)]]);
audit('export_pdf', 'action_log');
pdf_output_simple('Action Log', $lines, 'action_log.pdf');
