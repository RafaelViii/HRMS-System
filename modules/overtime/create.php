<?php
/**
 * Overtime Request - Employee Filing Form
 * Employees can file overtime requests with date, actual time out, and reason.
 * System calculates OT hours based on (Actual Time Out - Scheduled Time Out).
 * OT is only credited when approved by admin — NOT auto from biometrics.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)$user['id'];

// Find employee record for this user
try {
    $stmt = $pdo->prepare('SELECT e.id, e.employee_code, e.first_name, e.last_name, e.department_id, e.salary, e.branch_id, d.name as department_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $emp = null;
}

$pageTitle = 'File Overtime Request';

if (!$emp) {
    require_once __DIR__ . '/../../includes/header.php';
    show_human_error('Your account is not linked to an employee profile.');
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$employeeId = (int)$emp['id'];

// Get employee's scheduled time-out from work schedules
$scheduledTimeOut = '17:00'; // Default 5 PM
try {
    $today = date('Y-m-d');
    // Try employee_work_schedules (custom or template-based)
    $schedStmt = $pdo->prepare("SELECT COALESCE(ews.custom_end_time, wst.end_time) AS time_out
        FROM employee_work_schedules ews
        LEFT JOIN work_schedule_templates wst ON wst.id = ews.schedule_template_id
        WHERE ews.employee_id = :eid
        AND ews.effective_from <= :today
        AND (ews.effective_to IS NULL OR ews.effective_to >= :today2)
        ORDER BY ews.priority DESC, ews.effective_from DESC LIMIT 1");
    $schedStmt->execute([':eid' => $employeeId, ':today' => $today, ':today2' => $today]);
    $sched = $schedStmt->fetch(PDO::FETCH_ASSOC);
    if ($sched && !empty($sched['time_out'])) {
        $scheduledTimeOut = $sched['time_out'];
    } else {
        // Fallback: check employee_payroll_profiles for duty_end
        $ppStmt = $pdo->prepare("SELECT duty_end FROM employee_payroll_profiles WHERE employee_id = :eid");
        $ppStmt->execute([':eid' => $employeeId]);
        $pp = $ppStmt->fetch(PDO::FETCH_ASSOC);
        if ($pp && !empty($pp['duty_end'])) {
            $scheduledTimeOut = $pp['duty_end'];
        }
    }
} catch (Throwable $e) {
    // Tables might not exist yet, use default
}

// Handle POST - File overtime request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }

    $overtimeDate = trim($_POST['overtime_date'] ?? '');
    $actualTimeOut = trim($_POST['actual_time_out'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $overtimeType = trim($_POST['overtime_type'] ?? 'regular');

    $errors = [];

    if (empty($overtimeDate)) $errors[] = 'Overtime date is required.';
    if (empty($actualTimeOut)) $errors[] = 'Actual time out is required.';
    if (empty($reason)) $errors[] = 'Reason for overtime is required.';

    // Determine the scheduled end time for the OT date
    $schedEnd = $scheduledTimeOut;

    // Calculate hours: Actual Time Out - Scheduled Time Out
    if (empty($errors)) {
        $schedDT = new DateTime($overtimeDate . ' ' . $schedEnd);
        $actualDT = new DateTime($overtimeDate . ' ' . $actualTimeOut);
        
        // Handle overnight (e.g., clocked out after midnight)
        if ($actualDT < $schedDT) {
            $actualDT->modify('+1 day');
        }
        
        $diff = $schedDT->diff($actualDT);
        $hoursWorked = $diff->h + ($diff->i / 60);
        $hoursWorked = round($hoursWorked, 2);
        
        if ($hoursWorked <= 0) {
            $errors[] = 'Actual time out must be after your scheduled time out (' . date('h:i A', strtotime($schedEnd)) . ').';
        }

        // Determine correct payroll cutoff period
        $otDay = (int)date('d', strtotime($overtimeDate));
        if ($otDay <= 15) {
            $cutoffLabel = date('M 1', strtotime($overtimeDate)) . ' - ' . date('M 15', strtotime($overtimeDate));
        } else {
            $cutoffLabel = date('M 16', strtotime($overtimeDate)) . ' - ' . date('M t', strtotime($overtimeDate));
        }
    }

    // Check for duplicate request on same date
    if (empty($errors)) {
        try {
            $dupStmt = $pdo->prepare("SELECT id FROM overtime_requests WHERE employee_id = :eid AND overtime_date = :d AND status != 'rejected' LIMIT 1");
            $dupStmt->execute([':eid' => $employeeId, ':d' => $overtimeDate]);
            if ($dupStmt->fetch()) {
                $errors[] = 'You already have an overtime request for this date.';
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $err) flash_error($err);
        header('Location: ' . BASE_URL . '/modules/overtime/create');
        exit;
    }

    // Insert the OT request
    try {
        $startTime = $schedEnd;
        $endTime = $actualTimeOut;

        $sql = "INSERT INTO overtime_requests (employee_id, overtime_date, start_time, end_time, hours_worked, overtime_type, reason, status, created_by, created_at)
                VALUES (:eid, :odate, :start, :end, :hours, :otype, :reason, 'pending', :uid, CURRENT_TIMESTAMP)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':eid' => $employeeId,
            ':odate' => $overtimeDate,
            ':start' => $startTime,
            ':end' => $endTime,
            ':hours' => $hoursWorked,
            ':otype' => $overtimeType,
            ':reason' => $reason,
            ':uid' => $uid,
        ]);

        action_log('overtime', 'file_request', 'success', [
            'employee_id' => $employeeId,
            'date' => $overtimeDate,
            'hours' => $hoursWorked,
            'cutoff' => $cutoffLabel ?? '',
        ]);

        flash_success('Overtime request filed successfully (' . number_format($hoursWorked, 2) . ' hours). Awaiting admin approval.');
    } catch (Throwable $e) {
        sys_log('OT-CREATE-001', 'Failed to create OT request: ' . $e->getMessage(), ['file' => __FILE__]);
        flash_error('Failed to file overtime request. Please try again.');
    }

    header('Location: ' . BASE_URL . '/modules/overtime/create');
    exit;
}

// Fetch recent OT requests for this employee
$recentOT = [];
try {
    $stmt = $pdo->prepare("SELECT id, overtime_date, start_time, end_time, hours_worked, overtime_type, status, reason, created_at
        FROM overtime_requests WHERE employee_id = :eid ORDER BY overtime_date DESC, created_at DESC LIMIT 10");
    $stmt->execute([':eid' => $employeeId]);
    $recentOT = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentOT = [];
}

$pendingCount = 0;
$approvedCount = 0;
foreach ($recentOT as $ot) {
    if ($ot['status'] === 'pending') $pendingCount++;
    if ($ot['status'] === 'approved') $approvedCount++;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-slate-900">File Overtime Request</h1>
            <p class="text-sm text-slate-500 mt-0.5">Submit your overtime hours for approval. Only approved OT is credited to payroll.</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid gap-4 grid-cols-2 lg:grid-cols-3">
        <div class="card card-body flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-2xl font-bold text-slate-900"><?= $pendingCount ?></div>
                <div class="text-xs text-slate-500">Pending</div>
            </div>
        </div>
        <div class="card card-body flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-2xl font-bold text-emerald-600"><?= $approvedCount ?></div>
                <div class="text-xs text-slate-500">Approved</div>
            </div>
        </div>
        <div class="card card-body flex items-center gap-3 col-span-2 sm:col-span-1">
            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <div class="text-sm font-medium text-slate-900">Sched. Time Out</div>
                <div class="text-xs text-slate-500"><?= date('h:i A', strtotime($scheduledTimeOut)) ?></div>
            </div>
        </div>
    </div>

    <!-- OT Request Form -->
    <div class="card">
        <div class="card-header">
            <span class="font-semibold text-slate-800">New Overtime Request</span>
        </div>
        <div class="card-body">
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1 required">Overtime Date</label>
                        <input type="date" name="overtime_date" value="<?= date('Y-m-d') ?>" 
                               max="<?= date('Y-m-d') ?>"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
                        <p class="text-xs text-slate-400 mt-1">Default: today. You can backdate for late filing.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1 required">Actual Time Out</label>
                        <input type="time" name="actual_time_out" 
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" required>
                        <p class="text-xs text-slate-400 mt-1">When you actually left. Your scheduled out is <?= date('h:i A', strtotime($scheduledTimeOut)) ?>.</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">OT Type</label>
                    <select name="overtime_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        <option value="regular">Regular Day OT</option>
                        <option value="restday">Rest Day OT</option>
                        <option value="holiday">Holiday OT</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1 required">Reason for Overtime</label>
                    <textarea name="reason" rows="3" 
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                              placeholder="Briefly describe the work done and why overtime was needed..." required></textarea>
                </div>

                <div class="bg-blue-50 border-l-4 border-blue-400 p-3 rounded text-sm text-blue-800">
                    <strong>Note:</strong> OT is computed as <code>Actual Time Out - Scheduled Time Out</code>. 
                    Hours will only be credited to payroll after admin approval.
                    Make sure the OT date falls within the correct payroll cutoff (1st-15th or 16th-30th).
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary" data-confirm="Submit this overtime request?">
                        Submit OT Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recent OT Requests -->
    <div class="card">
        <div class="card-header flex items-center justify-between">
            <span class="font-semibold text-slate-800">Your Recent OT Requests</span>
        </div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="table-basic w-full">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Hours</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentOT)): ?>
                            <tr><td colspan="6" class="text-center py-6 text-sm text-slate-500">No overtime requests filed yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentOT as $ot): ?>
                            <tr>
                                <td class="whitespace-nowrap"><?= date('M d, Y', strtotime($ot['overtime_date'])) ?></td>
                                <td class="whitespace-nowrap text-xs">
                                    <?= date('h:i A', strtotime($ot['start_time'])) ?> - <?= date('h:i A', strtotime($ot['end_time'])) ?>
                                </td>
                                <td class="font-semibold"><?= number_format((float)$ot['hours_worked'], 2) ?> hrs</td>
                                <td>
                                    <?php
                                    $typeColors = ['regular' => 'bg-blue-100 text-blue-800', 'holiday' => 'bg-purple-100 text-purple-800', 'restday' => 'bg-orange-100 text-orange-800'];
                                    $tc = $typeColors[$ot['overtime_type']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $tc ?>"><?= ucfirst($ot['overtime_type']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $sc = ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-red-100 text-red-800'];
                                    $sColor = $sc[$ot['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $sColor ?>"><?= ucfirst($ot['status']) ?></span>
                                </td>
                                <td class="text-xs text-slate-600 max-w-[200px] truncate"><?= htmlspecialchars($ot['reason'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
