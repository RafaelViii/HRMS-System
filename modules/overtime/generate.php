<?php
/**
 * Generate Overtime from Attendance
 * Auto-calculates OT based on employee schedules and attendance records
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'attendance', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/work_schedules.php';

$pdo = get_db_conn();
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);

// Date range for OT generation
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$preview = isset($_GET['preview']);

$generatedCount = 0;
$skippedCount = 0;
$errors = [];
$previewData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token');
    header('Location: ' . BASE_URL . '/modules/overtime/generate');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from = $_POST['from'] ?? date('Y-m-01');
    $to = $_POST['to'] ?? date('Y-m-t');

    try {
        $pdo->beginTransaction();

        // Fetch all attendance records in the date range with overtime
        $sql = "SELECT a.*, e.id as emp_id, e.employee_code, e.first_name, e.last_name,
                       epp.duty_start, epp.duty_end, epp.allow_overtime
                FROM attendance a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN employee_payroll_profiles epp ON epp.employee_id = e.id
                WHERE a.date BETWEEN :from AND :to
                  AND a.overtime_minutes >= 60
                  AND e.status = 'active'
                ORDER BY a.date, e.id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($attendanceRecords as $record) {
            // Skip if employee doesn't allow overtime
            if (empty($record['allow_overtime'])) {
                $skippedCount++;
                continue;
            }

            // Use overtime_minutes from attendance table (already calculated)
            $otMinutes = (int)($record['overtime_minutes'] ?? 0);
            $otHours = round($otMinutes / 60, 2);

            // Double-check 1-hour threshold (should already be filtered by query)
            if ($otHours < 1.0) {
                $skippedCount++;
                continue;
            }

            // Check if OT request already exists
            $checkSql = "SELECT id FROM overtime_requests 
                         WHERE employee_id = :emp AND overtime_date = :date";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':emp' => $record['employee_id'], ':date' => $record['date']]);

            if ($checkStmt->fetchColumn()) {
                $skippedCount++; // Already exists
                continue;
            }

            // Determine OT type (basic implementation - can be enhanced)
            $otType = 'regular';
            $dayOfWeek = date('N', strtotime($record['date']));
            if ($dayOfWeek >= 6) { // Saturday=6, Sunday=7
                $otType = 'restday';
            }

            // Insert OT request as pending
            $insertSql = "INSERT INTO overtime_requests (
                employee_id, overtime_date, start_time, end_time, hours_worked,
                overtime_type, status, reason, created_by, created_at
            ) VALUES (
                :emp, :date, :time_in, :time_out, :hours,
                :type, 'pending', 'Auto-generated from attendance', :created_by, CURRENT_TIMESTAMP
            )";

            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                ':emp' => $record['employee_id'],
                ':date' => $record['date'],
                ':time_in' => $record['time_in'],
                ':time_out' => $record['time_out'],
                ':hours' => $otHours,
                ':type' => $otType,
                ':created_by' => $currentUserId
            ]);

            $generatedCount++;
        }

        $pdo->commit();

        action_log('overtime', 'generate_from_attendance', 'success', [
            'from' => $from,
            'to' => $to,
            'generated' => $generatedCount,
            'skipped' => $skippedCount
        ]);

        flash_success("Generated {$generatedCount} overtime requests. Skipped {$skippedCount} records.");
        header('Location: ' . BASE_URL . '/modules/overtime/index?status=pending');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sys_log('OT-GEN-001', 'OT generation failed: ' . $e->getMessage(), [
            'module' => 'overtime',
            'file' => __FILE__,
            'line' => __LINE__
        ]);
        flash_error('Failed to generate overtime requests. Please try again.');
    }
}

$pageTitle = 'Generate Overtime from Attendance';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/overtime/index" class="btn btn-outline">← Back</a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Generate Overtime from Attendance</h1>
            <p class="text-sm text-gray-500 mt-1">Automatically create OT requests based on employee schedules</p>
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" 
                               class="input-text" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" 
                               class="input-text" required>
                    </div>
                </div>

                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">Generation Rules</h3>
                            <div class="mt-2 text-sm text-blue-700 space-y-1">
                                <p>• Only processes employees with overtime enabled in their profile</p>
                                <p>• Requires valid duty schedule (duty_end time must be set)</p>
                                <p>• OT is calculated as time worked after duty_end</p>
                                <p>• <strong>1-hour minimum threshold</strong> - OT under 1 hour is skipped</p>
                                <p>• Weekends (Sat/Sun) are marked as "restday" OT</p>
                                <p>• Skips dates where OT request already exists</p>
                                <p>• All generated requests start as "pending" for HR approval</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Generate Overtime Requests
                    </button>
                    <a href="<?= BASE_URL ?>/modules/overtime/index" class="btn btn-outline">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Before You Generate</h3>
                <p class="mt-1 text-sm text-yellow-700">
                    Make sure employee schedules are properly configured in their payroll profiles.
                    Review generated requests in the Overtime Management page before approving.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
