<?php
/**
 * AJAX Attendance Preview for Submit DTR Modal
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');

$batchId = (int)($_GET['batch_id'] ?? 0);

if ($batchId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid batch ID']);
    exit;
}

$pdo = get_db_conn();

try {
    // Get batch details
    $batchSql = "SELECT pb.*, pr.period_start, pr.period_end 
                 FROM payroll_batches pb
                 JOIN payroll_runs pr ON pr.id = pb.payroll_run_id
                 WHERE pb.id = :batch_id";
    $batchStmt = $pdo->prepare($batchSql);
    $batchStmt->execute([':batch_id' => $batchId]);
    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        echo json_encode(['success' => false, 'error' => 'Batch not found']);
        exit;
    }

    // Fetch attendance records for employees in this branch during the payroll period
    $attendanceSql = "SELECT 
                        a.id,
                        a.date,
                        a.time_in::text AS time_in,
                        a.time_out::text AS time_out,
                        e.employee_code,
                        e.first_name,
                        e.last_name
                      FROM attendance a
                      JOIN employees e ON e.id = a.employee_id
                      WHERE e.branch_id = :branch_id
                        AND a.date BETWEEN :period_start AND :period_end
                        AND a.time_in IS NOT NULL
                      ORDER BY a.date DESC, e.employee_code";
    
    $attStmt = $pdo->prepare($attendanceSql);
    $attStmt->execute([
        ':branch_id' => $batch['branch_id'],
        ':period_start' => $batch['period_start'],
        ':period_end' => $batch['period_end']
    ]);
    
    $records = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format time display
    $formattedRecords = array_map(function($record) {
        // Format time - strip microseconds from PostgreSQL TIME type
        $timeIn = '—';
        $timeOut = '—';
        
        if (!empty($record['time_in'])) {
            // Strip microseconds: "08:30:15.123456" -> "08:30:15"
            $cleanTime = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $record['time_in']);
            try {
                $dt = DateTime::createFromFormat('H:i:s', $cleanTime);
                if ($dt) {
                    $timeIn = $dt->format('h:i A');
                } else {
                    $timeIn = $cleanTime;
                }
            } catch (Exception $e) {
                $timeIn = $cleanTime;
            }
        }
        
        if (!empty($record['time_out'])) {
            // Strip microseconds: "17:30:45.654321" -> "17:30:45"
            $cleanTime = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $record['time_out']);
            try {
                $dt = DateTime::createFromFormat('H:i:s', $cleanTime);
                if ($dt) {
                    $timeOut = $dt->format('h:i A');
                } else {
                    $timeOut = $cleanTime;
                }
            } catch (Exception $e) {
                $timeOut = $cleanTime;
            }
        }
        
        return [
            'id' => $record['id'],
            'employee_name' => $record['employee_code'] . ' - ' . $record['first_name'] . ' ' . $record['last_name'],
            'date' => date('M d, Y', strtotime($record['date'])),
            'time_in' => $timeIn,
            'time_out' => $timeOut,
        ];
    }, $records);

    echo json_encode([
        'success' => true,
        'records' => $formattedRecords
    ]);

} catch (Throwable $e) {
    sys_log('AJAX-ATT-PREVIEW', 'Failed to load attendance preview: ' . $e->getMessage(), [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['batch_id' => $batchId]
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load attendance records'
    ]);
}
