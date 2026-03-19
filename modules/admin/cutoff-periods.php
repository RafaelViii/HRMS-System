<?php
/**
 * Cutoff Period Management
 * 
 * Allows HR/Admin to create and manage payroll cutoff periods.
 * These periods define the attendance calculation windows for payroll.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/permissions.php';

// Require payroll management access
require_access('payroll', 'payroll_cycles', 'write');

$pdo = get_db_conn();
$userId = $_SESSION['user']['id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_period') {
        $periodName = trim($_POST['period_name'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $cutoffDate = trim($_POST['cutoff_date'] ?? '');
        $payDate = trim($_POST['pay_date'] ?? '') ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;
        
        if (!$periodName || !$startDate || !$endDate || !$cutoffDate) {
            flash_error('Period name, start date, end date, and cutoff date are required');
        } elseif (strtotime($startDate) > strtotime($endDate)) {
            flash_error('Start date must be before or equal to end date');
        } elseif (strtotime($cutoffDate) < strtotime($endDate)) {
            flash_error('Cutoff date must be on or after the end date');
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO cutoff_periods 
                    (period_name, start_date, end_date, cutoff_date, pay_date, notes, created_by, created_at, updated_at)
                    VALUES (:name, :start, :end, :cutoff, :pay, :notes, :user, NOW(), NOW())
                ');
                $stmt->execute([
                    ':name' => $periodName,
                    ':start' => $startDate,
                    ':end' => $endDate,
                    ':cutoff' => $cutoffDate,
                    ':pay' => $payDate,
                    ':notes' => $notes,
                    ':user' => $userId
                ]);
                
                audit('create_cutoff_period', json_encode([
                    'period_name' => $periodName,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'cutoff_date' => $cutoffDate
                ]));
                
                action_log('cutoff', 'create_period', 'success', [
                    'period_name' => $periodName,
                    'date_range' => "$startDate to $endDate"
                ]);
                
                flash_success('Cutoff period created successfully');
                header('Location: ' . BASE_URL . '/modules/admin/cutoff-periods');
                exit;
                
            } catch (Throwable $e) {
                sys_log('DB-CUTOFF-001', 'Failed to create cutoff period: ' . $e->getMessage(), [
                    'module' => 'cutoff',
                    'file' => __FILE__,
                    'line' => __LINE__
                ]);
                flash_error('Failed to create cutoff period');
            }
        }
    }
    
    if ($action === 'update_status') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        
        if ($periodId && in_array($newStatus, ['active', 'closed', 'cancelled'])) {
            try {
                $stmt = $pdo->prepare('
                    UPDATE cutoff_periods 
                    SET status = :status, 
                        is_locked = CASE WHEN :status = \'closed\' THEN TRUE ELSE is_locked END,
                        updated_at = NOW()
                    WHERE id = :id
                ');
                $stmt->execute([':status' => $newStatus, ':id' => $periodId]);
                
                audit('update_cutoff_status', json_encode([
                    'period_id' => $periodId,
                    'new_status' => $newStatus
                ]));
                
                flash_success("Cutoff period status updated to $newStatus");
                header('Location: ' . BASE_URL . '/modules/admin/cutoff-periods');
                exit;
                
            } catch (Throwable $e) {
                sys_log('DB-CUTOFF-002', 'Failed to update cutoff status: ' . $e->getMessage());
                flash_error('Failed to update cutoff period status');
            }
        }
    }
    
    if ($action === 'toggle_lock') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        
        if ($periodId) {
            try {
                $stmt = $pdo->prepare('
                    UPDATE cutoff_periods 
                    SET is_locked = NOT is_locked,
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING is_locked
                ');
                $stmt->execute([':id' => $periodId]);
                $isLocked = $stmt->fetchColumn();
                
                audit('toggle_cutoff_lock', json_encode([
                    'period_id' => $periodId,
                    'is_locked' => $isLocked
                ]));
                
                flash_success('Cutoff period lock status updated');
                header('Location: ' . BASE_URL . '/modules/admin/cutoff-periods');
                exit;
                
            } catch (Throwable $e) {
                sys_log('DB-CUTOFF-003', 'Failed to toggle cutoff lock: ' . $e->getMessage());
                flash_error('Failed to update lock status');
            }
        }
    }
    
    if ($action === 'delete_period') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        
        if ($periodId) {
            try {
                // Check if period is used in payroll
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) FROM payroll 
                    WHERE period_start >= (SELECT start_date FROM cutoff_periods WHERE id = :id)
                      AND period_end <= (SELECT end_date FROM cutoff_periods WHERE id = :id)
                ');
                $stmt->execute([':id' => $periodId]);
                $payrollCount = (int)$stmt->fetchColumn();
                
                if ($payrollCount > 0) {
                    flash_error('Cannot delete cutoff period with associated payroll records');
                } else {
                    $stmt = $pdo->prepare('DELETE FROM cutoff_periods WHERE id = :id');
                    $stmt->execute([':id' => $periodId]);
                    
                    audit('delete_cutoff_period', json_encode(['period_id' => $periodId]));
                    flash_success('Cutoff period deleted successfully');
                }
                
                header('Location: ' . BASE_URL . '/modules/admin/cutoff-periods');
                exit;
                
            } catch (Throwable $e) {
                sys_log('DB-CUTOFF-004', 'Failed to delete cutoff period: ' . $e->getMessage());
                flash_error('Failed to delete cutoff period');
            }
        }
    }
    
    if ($action === 'populate_defaults') {
        $startYear = (int)($_POST['start_year'] ?? date('Y'));
        $startMonth = (int)($_POST['start_month'] ?? date('n'));
        $monthsToGenerate = (int)($_POST['months_count'] ?? 6);
        
        $createdCount = 0;
        $skippedCount = 0;
        
        try {
            for ($i = 0; $i < $monthsToGenerate * 2; $i++) {
                $currentMonth = $startMonth + floor($i / 2);
                $currentYear = $startYear + floor(($currentMonth - 1) / 12);
                $currentMonth = (($currentMonth - 1) % 12) + 1;
                
                $isFirstCutoff = ($i % 2) === 0;
                
                if ($isFirstCutoff) {
                    // First cutoff: 6th to 20th
                    $periodStart = sprintf('%04d-%02d-06', $currentYear, $currentMonth);
                    $periodEnd = sprintf('%04d-%02d-20', $currentYear, $currentMonth);
                    $lastDay = date('t', strtotime($periodStart));
                    $payDate = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, min(30, $lastDay));
                    $cutoffDate = sprintf('%04d-%02d-22', $currentYear, $currentMonth);
                    $periodName = date('F', strtotime($periodStart)) . ' 6-20, ' . $currentYear;
                } else {
                    // Second cutoff: 21st to 5th of next month
                    $nextMonth = $currentMonth % 12 + 1;
                    $nextYear = $currentMonth === 12 ? $currentYear + 1 : $currentYear;
                    
                    $periodStart = sprintf('%04d-%02d-21', $currentYear, $currentMonth);
                    $periodEnd = sprintf('%04d-%02d-05', $nextYear, $nextMonth);
                    $payDate = sprintf('%04d-%02d-15', $nextYear, $nextMonth);
                    $cutoffDate = sprintf('%04d-%02d-07', $nextYear, $nextMonth);
                    $periodName = date('F', strtotime($periodStart)) . ' 21 - ' . 
                                  date('F', strtotime($periodEnd)) . ' 5, ' . $currentYear;
                }
                
                // Check if exists
                $checkStmt = $pdo->prepare('SELECT id FROM cutoff_periods WHERE start_date = :start AND end_date = :end');
                $checkStmt->execute([':start' => $periodStart, ':end' => $periodEnd]);
                
                if ($checkStmt->fetch()) {
                    $skippedCount++;
                    continue;
                }
                
                // Insert
                $insertStmt = $pdo->prepare('
                    INSERT INTO cutoff_periods 
                    (period_name, start_date, end_date, cutoff_date, pay_date, status, is_locked, notes, created_by, created_at, updated_at)
                    VALUES (:name, :start, :end, :cutoff, :pay, :status, :locked, :notes, :created_by, NOW(), NOW())
                ');
                
                $insertStmt->execute([
                    ':name' => $periodName,
                    ':start' => $periodStart,
                    ':end' => $periodEnd,
                    ':cutoff' => $cutoffDate,
                    ':pay' => $payDate,
                    ':status' => 'active',
                    ':locked' => false,
                    ':notes' => 'Auto-generated Philippine payroll cutoff',
                    ':created_by' => $userId
                ]);
                
                $createdCount++;
            }
            
            if ($createdCount > 0) {
                flash_success("Created $createdCount cutoff periods" . ($skippedCount > 0 ? " (skipped $skippedCount duplicates)" : ''));
            } else {
                flash_error("No new periods created" . ($skippedCount > 0 ? " - all $skippedCount already exist" : ''));
            }
            
            audit('populate_cutoff_periods', json_encode([
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'start' => "$startYear-$startMonth",
                'months' => $monthsToGenerate
            ]));
            
        } catch (Throwable $e) {
            sys_log('DB-CUTOFF-005', 'Failed to populate cutoff periods: ' . $e->getMessage());
            flash_error('Failed to populate cutoff periods');
        }
        
        header('Location: ' . BASE_URL . '/modules/admin/cutoff-periods');
        exit;
    }
}

// Fetch all cutoff periods
$periods = [];
try {
    $stmt = $pdo->query('
        SELECT 
            cp.*,
            u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM attendance a 
             WHERE a.date >= cp.start_date AND a.date <= cp.end_date) AS attendance_count
        FROM cutoff_periods cp
        LEFT JOIN users u ON u.id = cp.created_by
        ORDER BY cp.start_date DESC
    ');
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('DB-CUTOFF-005', 'Failed to fetch cutoff periods: ' . $e->getMessage());
    $periods = [];
}

// Get statistics
$stats = [
    'active_periods' => 0,
    'total_periods' => count($periods),
    'upcoming_cutoff' => null
];

foreach ($periods as $period) {
    if ($period['status'] === 'active') {
        $stats['active_periods']++;
        if (!$stats['upcoming_cutoff'] || $period['cutoff_date'] < $stats['upcoming_cutoff']['cutoff_date']) {
            $stats['upcoming_cutoff'] = $period;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Cutoff Period Management</h1>
            <p class="mt-1 text-sm text-gray-600">Define payroll cutoff periods for attendance calculation</p>
        </div>
        <div class="flex gap-2">
            <button onclick="document.getElementById('populate-modal').classList.remove('hidden')" 
                    class="btn btn-outline">
                <span class="text-lg"><svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg></span>
                <span>Auto-Populate</span>
            </button>
            <button onclick="document.getElementById('create-modal').classList.remove('hidden')" 
                    class="btn btn-primary">
                <span class="text-lg">+</span>
                <span>Create Period</span>
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid gap-4 md:grid-cols-3">
        <div class="card p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total Periods</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-900"><?= $stats['total_periods'] ?></p>
                </div>
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg></span>
            </div>
        </div>
        
        <div class="card p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Active Periods</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-900"><?= $stats['active_periods'] ?></p>
                </div>
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            </div>
        </div>
        
        <div class="card p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Next Cutoff</p>
                    <p class="mt-2 text-lg font-semibold text-gray-900">
                        <?php if ($stats['upcoming_cutoff']): ?>
                            <?= htmlspecialchars(date('M d, Y', strtotime($stats['upcoming_cutoff']['cutoff_date']))) ?>
                        <?php else: ?>
                            <span class="text-gray-400 text-base">None</span>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            </div>
        </div>
    </div>

    <!-- Periods Table -->
    <div class="card">
        <div class="p-5 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Cutoff Periods</h2>
            <p class="mt-1 text-sm text-gray-600">Manage payroll cutoff windows and attendance calculation periods</p>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Period Name</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Date Range</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Cutoff Date</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Pay Date</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Records</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if ($periods): ?>
                        <?php foreach ($periods as $period): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-4">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($period['period_name']) ?></div>
                                    <?php if ($period['is_locked']): ?>
                                        <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 rounded">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg> Locked
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars(date('M d', strtotime($period['start_date']))) ?> - 
                                    <?= htmlspecialchars(date('M d, Y', strtotime($period['end_date']))) ?>
                                    <span class="text-xs text-gray-400">
                                        (<?= (strtotime($period['end_date']) - strtotime($period['start_date'])) / 86400 + 1 ?> days)
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars(date('M d, Y', strtotime($period['cutoff_date']))) ?>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    <?= $period['pay_date'] ? htmlspecialchars(date('M d, Y', strtotime($period['pay_date']))) : '—' ?>
                                </td>
                                <td class="px-5 py-4">
                                    <?php
                                        $statusColors = [
                                            'active' => 'bg-emerald-100 text-emerald-800',
                                            'closed' => 'bg-gray-100 text-gray-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusColor = $statusColors[$period['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full <?= $statusColor ?>">
                                        <?= htmlspecialchars(ucfirst($period['status'])) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    <?= number_format((int)$period['attendance_count']) ?> attendance
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <?php if ($period['status'] === 'active'): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                <input type="hidden" name="status" value="closed">
                                                <button type="submit" class="text-xs font-medium text-blue-600 hover:text-blue-500"
                                                        data-confirm="Close this cutoff period? This will lock attendance for this period.">
                                                    Close
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="toggle_lock">
                                            <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                            <button type="submit" class="text-xs font-medium text-amber-600 hover:text-amber-500">
                                                <?= $period['is_locked'] ? 'Unlock' : 'Lock' ?>
                                            </button>
                                        </form>
                                        
                                        <?php if ($period['status'] !== 'closed' && (int)$period['attendance_count'] === 0): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete_period">
                                                <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-500"
                                                        data-confirm="Permanently delete this cutoff period?">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <div class="text-gray-400 text-5xl mb-3"><svg class="w-12 h-12 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg></div>
                                <p class="text-sm font-medium text-gray-900">No cutoff periods yet</p>
                                <p class="text-sm text-gray-600 mt-1">Create your first payroll cutoff period to get started</p>
                                <button onclick="document.getElementById('create-modal').classList.remove('hidden')" 
                                        class="mt-4 btn btn-primary btn-sm">
                                    Create First Period
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Auto-Populate Modal -->
<div id="populate-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-900">Auto-Populate Cutoff Periods</h2>
                <button onclick="document.getElementById('populate-modal').classList.add('hidden')" 
                        class="text-gray-400 hover:text-gray-600">
                    <span class="text-2xl">×</span>
                </button>
            </div>
            <p class="mt-1 text-sm text-gray-600">Generate standard Philippine payroll cutoffs automatically</p>
        </div>
        
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="populate_defaults">
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-900">
                <p class="font-medium mb-2 flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg> Standard Philippine Cutoff Schedule:</p>
                <ul class="list-disc pl-5 space-y-1 text-xs">
                    <li><strong>Cutoff 1:</strong> 6th to 20th → Pay on 30th of same month</li>
                    <li><strong>Cutoff 2:</strong> 21st to 5th of next month → Pay on 15th of next month</li>
                </ul>
            </div>
            
            <div class="grid gap-4 grid-cols-3">
                <div>
                    <label class="form-label">Start Year</label>
                    <input type="number" name="start_year" class="input-text w-full" style="min-width:0"
                           value="<?= date('Y') ?>" min="2020" max="2030" required>
                </div>
                <div>
                    <label class="form-label">Start Month</label>
                    <select name="start_month" class="input-text w-full" style="min-width:0" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Months</label>
                    <input type="number" name="months_count" class="input-text w-full" style="min-width:0"
                           value="6" min="1" max="24" required>
                    <p class="mt-1 text-xs text-gray-500">2 cutoffs per month</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 pt-4 border-t border-gray-200">
                <button type="submit" class="btn btn-primary">Generate Periods</button>
                <button type="button" 
                        onclick="document.getElementById('populate-modal').classList.add('hidden')"
                        class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Period Modal -->
<div id="create-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-900">Create Cutoff Period</h2>
                <button onclick="document.getElementById('create-modal').classList.add('hidden')" 
                        class="text-gray-400 hover:text-gray-600">
                    <span class="text-2xl">×</span>
                </button>
            </div>
            <p class="mt-1 text-sm text-gray-600">Define the work period, cutoff deadline, and pay date</p>
        </div>
        
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create_period">
            
            <div>
                <label class="form-label">Period Name *</label>
                <input type="text" name="period_name" class="input-text" 
                       placeholder="e.g., October 16-31, 2025" required>
                <p class="mt-1 text-xs text-gray-500">A descriptive name for this cutoff period</p>
            </div>
            
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="form-label">Start Date *</label>
                    <input type="date" name="start_date" class="input-text" required>
                </div>
                <div>
                    <label class="form-label">End Date *</label>
                    <input type="date" name="end_date" class="input-text" required>
                </div>
            </div>
            
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="form-label">Cutoff Date *</label>
                    <input type="date" name="cutoff_date" class="input-text" required>
                    <p class="mt-1 text-xs text-gray-500">Deadline for attendance corrections</p>
                </div>
                <div>
                    <label class="form-label">Pay Date (Optional)</label>
                    <input type="date" name="pay_date" class="input-text">
                    <p class="mt-1 text-xs text-gray-500">Expected payroll release date</p>
                </div>
            </div>
            
            <div>
                <label class="form-label">Notes (Optional)</label>
                <textarea name="notes" class="input-text" rows="3" 
                          placeholder="Additional notes or special instructions..."></textarea>
            </div>
            
            <div class="flex items-center gap-3 pt-4 border-t border-gray-200">
                <button type="submit" class="btn btn-primary">Create Period</button>
                <button type="button" 
                        onclick="document.getElementById('create-modal').classList.add('hidden')"
                        class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Add confirmation dialogs
document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm(this.dataset.confirm)) {
            e.preventDefault();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
