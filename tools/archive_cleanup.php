<?php
/**
 * Archive Cleanup Worker
 * Automatically deletes archived records older than the configured retention period
 * 
 * Usage:
 *   php tools/archive_cleanup.php
 * 
 * Cron example (daily at 2 AM):
 *   0 2 * * * cd /path/to/hrms && php tools/archive_cleanup.php >> logs/archive_cleanup.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// This script can run from CLI or web (with authentication)
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    require_login();
    require_access('user_management', 'system_management', 'write');
    header('Content-Type: application/json');
}

$pdo = get_db_conn();
$startTime = microtime(true);

try {
    // Check if archive system is enabled
    $settingStmt = $pdo->query("
        SELECT setting_value FROM system_settings 
        WHERE setting_key = 'archive_enabled'
    ");
    $enabled = $settingStmt->fetchColumn();
    
    if ($enabled !== '1') {
        $message = 'Archive system is disabled. Cleanup skipped.';
        if ($is_cli) {
            echo "[" . date('Y-m-d H:i:s') . "] $message\n";
        } else {
            echo json_encode(['success' => false, 'message' => $message]);
        }
        exit(0);
    }
    
    // Get auto-delete days setting
    $daysStmt = $pdo->query("
        SELECT setting_value FROM system_settings 
        WHERE setting_key = 'archive_auto_delete_days'
    ");
    $autoDeleteDays = (int)$daysStmt->fetchColumn();
    
    if ($autoDeleteDays <= 0) {
        $message = 'Auto-delete is disabled (set to 0 days). Cleanup skipped.';
        if ($is_cli) {
            echo "[" . date('Y-m-d H:i:s') . "] $message\n";
        } else {
            echo json_encode(['success' => false, 'message' => $message]);
        }
        exit(0);
    }
    
    // Calculate cutoff date
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-$autoDeleteDays days"));
    
    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] Starting archive cleanup...\n";
        echo "[" . date('Y-m-d H:i:s') . "] Retention period: $autoDeleteDays days\n";
        echo "[" . date('Y-m-d H:i:s') . "] Cutoff date: $cutoffDate\n";
        echo str_repeat('-', 80) . "\n";
    }
    
    // Call cleanup function
    $cleanupStmt = $pdo->query("SELECT * FROM cleanup_old_archives()");
    $results = $cleanupStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalDeleted = 0;
    $details = [];
    
    if ($is_cli) {
        echo sprintf("%-30s %15s\n", "Table", "Records Deleted");
        echo str_repeat('-', 80) . "\n";
    }
    
    foreach ($results as $row) {
        $table = $row['table_name'];
        $count = (int)$row['records_deleted'];
        $totalDeleted += $count;
        $details[] = ['table' => $table, 'deleted' => $count];
        
        if ($is_cli) {
            echo sprintf("%-30s %15d\n", $table, $count);
        }
        
        // Log each table cleanup
        action_log('system_management', 'archive_auto_cleanup', 'success', [
            'target_type' => $table,
            'records_deleted' => $count,
            'cutoff_date' => $cutoffDate,
            'retention_days' => $autoDeleteDays,
            'executed_by' => 'system',
            'severity' => 'warning'
        ]);
    }
    
    if (empty($results)) {
        if ($is_cli) {
            echo "No archived records found older than $autoDeleteDays days.\n";
        }
    } else {
        if ($is_cli) {
            echo str_repeat('-', 80) . "\n";
            echo sprintf("%-30s %15d\n", "TOTAL", $totalDeleted);
        }
    }
    
    $executionTime = round(microtime(true) - $startTime, 2);
    
    if ($is_cli) {
        echo str_repeat('-', 80) . "\n";
        echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed in {$executionTime}s\n";
        echo "[" . date('Y-m-d H:i:s') . "] Total records permanently deleted: $totalDeleted\n";
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Cleanup completed successfully",
            'total_deleted' => $totalDeleted,
            'execution_time' => $executionTime,
            'details' => $details
        ]);
    }
    
    // Log overall cleanup
    if ($totalDeleted > 0) {
        action_log('system_management', 'archive_auto_cleanup_summary', 'success', [
            'total_deleted' => $totalDeleted,
            'cutoff_date' => $cutoffDate,
            'retention_days' => $autoDeleteDays,
            'execution_time' => $executionTime,
            'details' => $details,
            'executed_by' => 'system',
            'severity' => 'warning'
        ]);
    }
    
    exit(0);
    
} catch (Throwable $e) {
    $errorMsg = 'Archive cleanup failed: ' . $e->getMessage();
    
    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: $errorMsg\n";
        echo "[" . date('Y-m-d H:i:s') . "] Stack trace:\n";
        echo $e->getTraceAsString() . "\n";
    } else {
        echo json_encode([
            'success' => false,
            'message' => $errorMsg
        ]);
    }
    
    sys_log('ARCHIVE-CLEANUP', $errorMsg, [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    action_log('system_management', 'archive_auto_cleanup', 'error', [
        'error' => $e->getMessage(),
        'severity' => 'critical'
    ]);
    
    exit(1);
}
