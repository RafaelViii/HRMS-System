<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('payroll', 'payroll_runs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/payroll.php';

header('Content-Type: application/json');

$pdo = get_db_conn();
$jobId = trim($_GET['job_id'] ?? '');
$batchId = (int)($_GET['batch_id'] ?? 0);

try {
    if ($jobId === '' && $batchId > 0) {
        $stmt = $pdo->prepare('SELECT computation_job_id FROM payroll_batches WHERE id = :id');
        $stmt->execute([':id' => $batchId]);
        $jobId = (string)$stmt->fetchColumn();
    }
    if ($jobId === '') {
        echo json_encode(['ok' => false, 'error' => 'job_not_found']);
        exit;
    }
    $job = payroll_get_job($pdo, $jobId);
    if (!$job) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => $job['id'],
            'status' => $job['status'],
            'progress' => (int)($job['progress'] ?? 0),
            'error_text' => $job['error_text'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
