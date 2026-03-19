<?php
/**
 * Sync branch submission status from payroll_batches
 * One-time fix for alignment between batches and submissions table
 * Run from web interface via admin/system tools
 */
// Must be run via web context where session environment is available
if (php_sapi_name() === 'cli') {
    die("This script must be run via the web interface at /tools/sync_branch_submissions.php\n");
}

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_access('system', 'system_management', 'write');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_conn();

try {
    header('Content-Type: text/plain; charset=utf-8');
    
    $stmt = $pdo->prepare("
        UPDATE payroll_branch_submissions pbs
        SET status = 'submitted',
            submitted_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE EXISTS (
            SELECT 1 FROM payroll_batches pb
            WHERE pb.payroll_run_id = pbs.payroll_run_id
              AND pb.branch_id = pbs.branch_id
              AND pb.status = 'submitted'
        )
        AND pbs.status != 'submitted'
    ");
    
    $stmt->execute();
    $updated = $stmt->rowCount();
    
    echo "Updated {$updated} branch submission(s) to 'submitted' status.\n\n";
    
    action_log('system', 'sync_branch_submissions', 'success', [
        'updated_count' => $updated,
        'user_id' => current_user()['id'] ?? null
    ]);
    
    // Show current state
    $check = $pdo->query("
        SELECT pr.id AS run_id,
               b.name AS branch_name,
               pb.status AS batch_status,
               pbs.status AS submission_status
        FROM payroll_runs pr
        JOIN payroll_batches pb ON pb.payroll_run_id = pr.id
        LEFT JOIN payroll_branch_submissions pbs ON pbs.payroll_run_id = pr.id AND pbs.branch_id = pb.branch_id
        LEFT JOIN branches b ON b.id = pb.branch_id
        ORDER BY pr.id DESC, b.name
        LIMIT 10
    ");
    
    echo "Current status:\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-8s | %-20s | %-15s | %s\n", "Run ID", "Branch", "Batch Status", "Submission Status");
    echo str_repeat('-', 80) . "\n";
    while ($row = $check->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "%-8d | %-20s | %-15s | %s\n",
            $row['run_id'],
            substr($row['branch_name'] ?: 'N/A', 0, 20),
            $row['batch_status'],
            $row['submission_status'] ?: 'NULL'
        );
    }
    echo str_repeat('-', 80) . "\n";
    echo "\nSync completed successfully. You can now return to the payroll run page.\n";
    
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
    sys_log('SYNC-BRANCH-SUBMISSIONS', 'Failed: ' . $e->getMessage(), [
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    exit(1);
}
