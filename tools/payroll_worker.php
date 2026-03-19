<?php
// Simple payroll compute worker. Run from CLI: php tools/payroll_worker.php [--once]
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payroll.php';

echo "Payroll Worker starting..." . PHP_EOL;
$pdo = get_db_conn();
$once = in_array('--once', $argv, true);
$workerId = gethostname() ?: 'worker';

while (true) {
    try {
        $pdo->beginTransaction();
        // Claim one queued job
        $stmt = $pdo->prepare("SELECT id, payroll_batch_id FROM payroll_compute_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            $pdo->commit();
            if ($once) { echo "No jobs. Exiting." . PHP_EOL; break; }
            sleep(3);
            continue;
        }
        $jobId = $job['id'];
        $batchId = (int)$job['payroll_batch_id'];

        $upd = $pdo->prepare("UPDATE payroll_compute_jobs SET status = 'running', started_at = NOW(), claimed_by = :who, updated_at = NOW() WHERE id = :id");
        $upd->execute([':who' => $workerId, ':id' => $jobId]);
        $pdo->commit();

        echo "Processing job $jobId for batch #$batchId..." . PHP_EOL;
        $res = payroll_generate_payslips_for_batch($pdo, $batchId, null);

        $pdo->beginTransaction();
        if (!empty($res['ok'])) {
            $pdo->prepare("UPDATE payroll_compute_jobs SET status = 'completed', progress = 100, finished_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $jobId]);
        } else {
            $err = substr(implode('; ', $res['errors'] ?? []), 0, 2000);
            $pdo->prepare("UPDATE payroll_compute_jobs SET status = 'failed', error_text = :err, finished_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $jobId, ':err' => $err]);
        }
        $pdo->commit();
        echo "Job $jobId done: " . (!empty($res['ok']) ? 'OK' : 'FAILED') . PHP_EOL;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ie) {} }
        echo "Worker error: " . $e->getMessage() . PHP_EOL;
        sleep(2);
    }

    if ($once) { break; }
}

echo "Worker stopped." . PHP_EOL;
