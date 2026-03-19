<?php
/**
 * Approve/Reject Overtime Request
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'attendance', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    flash_error('Overtime request not found');
    header('Location: ' . BASE_URL . '/modules/overtime/index');
    exit;
}

// Fetch OT request
try {
    $sql = "SELECT ot.*, 
                   e.employee_code, e.first_name, e.last_name, e.salary,
                   d.name as department_name,
                   b.name as branch_name
            FROM overtime_requests ot
            JOIN employees e ON e.id = ot.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE ot.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $ot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ot) {
        flash_error('Overtime request not found');
        header('Location: ' . BASE_URL . '/modules/overtime/index');
        exit;
    }
} catch (Throwable $e) {
    flash_error('Failed to load overtime request');
    header('Location: ' . BASE_URL . '/modules/overtime/index');
    exit;
}

// Calculate OT pay
$salary = (float)($ot['salary'] ?? 0);
$hours = (float)($ot['hours_worked'] ?? 0);
$otType = $ot['overtime_type'] ?? 'regular';

// Base rate conversions
$monthlyRate = $salary;
$dailyRate = $monthlyRate / 22;
$hourlyRate = $dailyRate / 8;

// OT multipliers based on type
$multipliers = [
    'regular' => 1.25,  // Regular day OT
    'restday' => 1.30,  // Rest day OT
    'holiday' => 2.60,  // Regular holiday OT
    'special' => 1.69,  // Special non-working holiday OT
];

$multiplier = $multipliers[$otType] ?? 1.25;
$otPay = $hourlyRate * $multiplier * $hours;

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token');
        header('Location: ' . BASE_URL . '/modules/overtime/approve?id=' . $id);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($action === 'approve') {
        try {
            $updateSql = "UPDATE overtime_requests 
                          SET status = 'approved',
                              approved_by = :user_id,
                              approved_at = CURRENT_TIMESTAMP,
                              rejection_reason = NULL
                          WHERE id = :id AND status = 'pending'";
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([':user_id' => $currentUserId, ':id' => $id]);

            if ($stmt->rowCount() > 0) {
                action_log('overtime', 'approve', 'success', [
                    'overtime_id' => $id,
                    'employee_id' => $ot['employee_id'],
                    'hours' => $hours,
                    'amount' => round($otPay, 2)
                ]);

                flash_success('Overtime request approved successfully');
            } else {
                flash_error('Overtime request is no longer pending');
            }
        } catch (Throwable $e) {
            sys_log('OT-APPROVE-001', 'Failed to approve OT: ' . $e->getMessage(), [
                'module' => 'overtime',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['id' => $id]
            ]);
            flash_error('Failed to approve overtime request');
        }
    } elseif ($action === 'reject') {
        if (empty($remarks)) {
            flash_error('Rejection reason is required');
            header('Location: ' . BASE_URL . '/modules/overtime/approve?id=' . $id);
            exit;
        }

        try {
            $updateSql = "UPDATE overtime_requests 
                          SET status = 'rejected',
                              approved_by = :user_id,
                              approved_at = CURRENT_TIMESTAMP,
                              rejection_reason = :reason
                          WHERE id = :id AND status = 'pending'";
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                ':user_id' => $currentUserId,
                ':id' => $id,
                ':reason' => $remarks
            ]);

            if ($stmt->rowCount() > 0) {
                action_log('overtime', 'reject', 'success', [
                    'overtime_id' => $id,
                    'employee_id' => $ot['employee_id'],
                    'reason' => $remarks
                ]);

                flash_success('Overtime request rejected');
            } else {
                flash_error('Overtime request is no longer pending');
            }
        } catch (Throwable $e) {
            sys_log('OT-REJECT-001', 'Failed to reject OT: ' . $e->getMessage(), [
                'module' => 'overtime',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['id' => $id]
            ]);
            flash_error('Failed to reject overtime request');
        }
    }

    header('Location: ' . BASE_URL . '/modules/overtime/admin');
    exit;
}

$pageTitle = 'Review Overtime Request';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/modules/overtime/index" class="btn btn-outline btn-icon flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Review Overtime Request</h1>
                <p class="text-sm text-slate-500 mt-0.5">OT Request #<?= $id ?></p>
            </div>
        </div>
        <?php
            $statusMap = ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-red-100 text-red-800'];
            $statusCls = $statusMap[$ot['status']] ?? 'bg-slate-100 text-slate-800';
        ?>
        <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full <?= $statusCls ?>">
            <?= ucfirst($ot['status']) ?>
        </span>
    </div>

    <!-- Two-Column Layout -->
    <div class="grid gap-6 lg:grid-cols-5">
        <!-- LEFT COLUMN: Request Details -->
        <div class="lg:col-span-3 space-y-6">
            <!-- Employee Info -->
            <div class="card">
                <div class="card-header">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        Employee Information
                    </span>
                </div>
                <div class="card-body">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Employee Name</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                <?= htmlspecialchars($ot['first_name'] . ' ' . $ot['last_name']) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Employee Code</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($ot['employee_code']) ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Department</p>
                            <p class="mt-1 text-sm font-medium text-slate-700"><?= htmlspecialchars($ot['department_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Branch</p>
                            <p class="mt-1 text-sm font-medium text-slate-700"><?= htmlspecialchars($ot['branch_name'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- OT Details -->
            <div class="card">
                <div class="card-header">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Overtime Details
                    </span>
                </div>
                <div class="card-body">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Date</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                <?= date('F d, Y', strtotime($ot['overtime_date'])) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">OT Type</p>
                            <p class="mt-1">
                                <?php
                                    $typeColors = ['regular' => 'bg-blue-100 text-blue-800', 'restday' => 'bg-purple-100 text-purple-800', 'holiday' => 'bg-red-100 text-red-800', 'special' => 'bg-amber-100 text-amber-800'];
                                    $typeCls = $typeColors[$otType] ?? 'bg-slate-100 text-slate-800';
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full <?= $typeCls ?>">
                                    <?= ucfirst($otType) ?> OT
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Time Period</p>
                            <p class="mt-1 text-sm font-medium text-slate-700">
                                <?= date('h:i A', strtotime($ot['start_time'])) ?> – <?= date('h:i A', strtotime($ot['end_time'])) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Hours Worked</p>
                            <p class="mt-1 text-lg font-bold text-indigo-600"><?= number_format($hours, 2) ?> <span class="text-xs font-medium text-indigo-500">hrs</span></p>
                        </div>
                    </div>

                    <?php if (!empty($ot['reason'])): ?>
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Reason</p>
                        <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($ot['reason']) ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($ot['work_description'])): ?>
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Work Description</p>
                        <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($ot['work_description']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Computation + Actions (sticky) -->
        <div class="lg:col-span-2 space-y-6 lg:sticky lg:top-20 lg:self-start">
            <!-- Computation -->
            <div class="card overflow-hidden">
                <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-5 py-4">
                    <h2 class="text-sm font-semibold text-white/90 uppercase tracking-wide">Pay Computation</h2>
                    <p class="mt-2 text-3xl font-bold text-white">₱<?= number_format($otPay, 2) ?></p>
                    <p class="mt-0.5 text-xs text-white/70">
                        ₱<?= number_format($hourlyRate, 2) ?> × <?= number_format($multiplier, 2) ?> × <?= number_format($hours, 2) ?> hrs
                    </p>
                </div>
                <div class="card-body space-y-2.5">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Monthly Salary</span>
                        <span class="font-medium text-slate-900">₱<?= number_format($monthlyRate, 2) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Daily Rate (÷22)</span>
                        <span class="font-medium text-slate-900">₱<?= number_format($dailyRate, 2) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Hourly Rate (÷8)</span>
                        <span class="font-medium text-slate-900">₱<?= number_format($hourlyRate, 2) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">OT Multiplier</span>
                        <span class="font-semibold text-indigo-600">×<?= number_format($multiplier, 2) ?> <span class="text-xs font-normal text-slate-400">(<?= ucfirst($otType) ?>)</span></span>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <?php if ($ot['status'] === 'pending'): ?>
            <div class="card">
                <div class="card-header">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Review Action
                    </span>
                </div>
                <div class="card-body">
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Remarks <span class="font-normal text-slate-400">(required for rejection)</span></label>
                            <textarea name="remarks" rows="3" class="input-text w-full" 
                                      placeholder="Enter your remarks or rejection reason..."></textarea>
                        </div>

                        <div class="flex flex-col gap-2">
                            <button type="submit" name="action" value="approve" 
                                    class="btn w-full justify-center bg-blue-600 hover:bg-blue-700 text-white shadow-sm hover:shadow-md"
                                    data-confirm="Approve this overtime request? The amount will be included in the next payroll cycle.">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Approve OT — ₱<?= number_format($otPay, 2) ?>
                            </button>
                            <button type="submit" name="action" value="reject" 
                                    class="btn btn-danger w-full justify-center"
                                    data-confirm="Reject this overtime request? This action cannot be undone.">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                                Reject OT
                            </button>
                            <a href="<?= BASE_URL ?>/modules/overtime/index" class="btn btn-outline w-full justify-center">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-center">
                        <p class="text-sm text-slate-600">
                            This request has been <strong class="font-semibold"><?= ucfirst($ot['status']) ?></strong>.
                        </p>
                        <?php if ($ot['status'] === 'rejected' && !empty($ot['rejection_reason'])): ?>
                        <p class="text-sm text-slate-500 mt-2">
                            <strong>Reason:</strong> <?= htmlspecialchars($ot['rejection_reason']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
