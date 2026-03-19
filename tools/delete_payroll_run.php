<?php
// Temporary helper to delete a payroll run with a point-in-time backup.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/payroll.php';

$token = $_SERVER['HTTP_X_TOOL_TOKEN'] ?? $_POST['token'] ?? '';
$expectedToken = getenv('HRMS_TOOL_SECRET') ?: '';
if ($expectedToken === '' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    echo 'Forbidden - local access or HRMS_TOOL_SECRET required';
    exit;
}
if ($expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo 'Forbidden - invalid token';
    exit;
}

$runId = (int)($_GET['run'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo 'Missing run id. Use ?run=123&confirm=1&token=...';
    exit;
}

$pdo = get_db_conn();
$run = payroll_get_run($pdo, $runId);
if (!$run) {
    http_response_code(404);
    echo 'Payroll run not found.';
    exit;
}

$summary = [
    'payslips' => 0,
    'payslip_items' => 0,
    'branch_submissions' => 0,
    'complaints' => 0,
];

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM payslips WHERE payroll_run_id = :run');
$countStmt->execute([':run' => $runId]);
$summary['payslips'] = (int)$countStmt->fetchColumn();

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM payslip_items WHERE payslip_id IN (SELECT id FROM payslips WHERE payroll_run_id = :run)');
$countStmt->execute([':run' => $runId]);
$summary['payslip_items'] = (int)$countStmt->fetchColumn();

if (payroll_table_exists($pdo, 'payroll_branch_submissions')) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_branch_submissions WHERE payroll_run_id = :run');
    $countStmt->execute([':run' => $runId]);
    $summary['branch_submissions'] = (int)$countStmt->fetchColumn();
}

if (payroll_table_exists($pdo, 'payroll_complaints')) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_complaints WHERE payroll_run_id = :run');
    $countStmt->execute([':run' => $runId]);
    $summary['complaints'] = (int)$countStmt->fetchColumn();
}

if (!(bool)($_GET['confirm'] ?? 0)) {
    header('Content-Type: text/plain');
    echo "About to delete payroll run #{$runId}\n";
    echo 'Period: ' . ($run['period_start'] ?? '') . ' to ' . ($run['period_end'] ?? '') . "\n";
    echo 'Status: ' . ($run['status'] ?? '') . "\n\n";
    foreach ($summary as $key => $value) {
        echo ucfirst(str_replace('_', ' ', $key)) . ': ' . $value . "\n";
    }
    echo "\nRe-run with confirm=1 to proceed. Example:\n";
    echo "  /tools/delete_payroll_run.php?run={$runId}&confirm=1&token=YOURTOKEN\n";
    exit;
}

// Prepare backup payload before destructive actions.
$backupDir = __DIR__ . '/tmp';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$payslipStmt = $pdo->prepare('SELECT * FROM payslips WHERE payroll_run_id = :run ORDER BY id');
$payslipStmt->execute([':run' => $runId]);
$payslips = $payslipStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$itemPayload = [];
if ($payslips) {
    $itemStmt = $pdo->prepare('SELECT * FROM payslip_items WHERE payslip_id = ANY (SELECT id FROM payslips WHERE payroll_run_id = :run) ORDER BY id');
    $itemStmt->execute([':run' => $runId]);
    $itemPayload = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$backup = [
    'run' => $run,
    'summary' => $summary,
    'payslips' => $payslips,
    'payslip_items' => $itemPayload,
    'exported_at' => date('c'),
];

$backupPath = $backupDir . '/payroll_run_' . $runId . '_backup.json';
if (file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    http_response_code(500);
    echo 'Failed writing backup file. Aborting.';
    exit;
}

try {
    $pdo->beginTransaction();

    $deleteItems = $pdo->prepare('DELETE FROM payslip_items WHERE payslip_id = ANY (SELECT id FROM payslips WHERE payroll_run_id = :run)');
    $deleteItems->execute([':run' => $runId]);

    $deletePayslips = $pdo->prepare('DELETE FROM payslips WHERE payroll_run_id = :run');
    $deletePayslips->execute([':run' => $runId]);

    $deleteRun = $pdo->prepare('DELETE FROM payroll_runs WHERE id = :run');
    $deleteRun->execute([':run' => $runId]);

    $pdo->commit();

    audit('delete_payroll_run', json_encode(['run_id' => $runId, 'summary' => $summary], JSON_UNESCAPED_SLASHES));
    action_log('payroll', 'delete_run', 'success', ['run_id' => $runId, 'summary' => $summary, 'backup' => $backupPath]);

    header('Content-Type: text/plain');
    echo 'Payroll run deleted. Backup stored at ' . $backupPath;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sys_log('PAYROLL-RUN-DELETE', 'Failed deleting payroll run: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
    http_response_code(500);
    echo 'Deletion failed. See system logs.';
}
