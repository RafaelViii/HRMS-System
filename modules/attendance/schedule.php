<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// Get current user
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

// Get filters from query params
$view = $_GET['view'] ?? 'branch'; // branch, department, position, individual
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : null;
$searchQuery = trim($_GET['q'] ?? '');
$currentMonth = $_GET['month'] ?? date('Y-m');

// Parse month
$dateObj = DateTime::createFromFormat('Y-m', $currentMonth);
if (!$dateObj) {
    $dateObj = new DateTime();
    $currentMonth = $dateObj->format('Y-m');
}

$year = (int)$dateObj->format('Y');
$month = (int)$dateObj->format('m');
$firstDay = new DateTime("$year-$month-01");
$lastDay = (clone $firstDay)->modify('last day of this month');

// Navigation dates
$prevMonth = (clone $firstDay)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $firstDay)->modify('+1 month')->format('Y-m');

// Get branches, departments, positions for filters
$branches = [];
$departments = [];
$positions = [];

try {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $positions = $pdo->query("SELECT id, name FROM positions WHERE deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('SCHEDULE-FILTERS', 'Failed loading filter options: ' . $e->getMessage(), ['module' => 'attendance']);
}

// Build employee query based on view
$employeeSql = "SELECT e.id, e.employee_code, e.first_name, e.last_name, 
                       e.branch_id, b.name as branch_name,
                       e.department_id, d.name as department_name,
                       e.position_id, p.name as position_title,
                       COALESCE(ews.custom_start_time, wst.start_time, epp.duty_start) as work_start,
                       COALESCE(ews.custom_end_time, wst.end_time, epp.duty_end) as work_end,
                       wst.name as schedule_name
                FROM employees e
                LEFT JOIN branches b ON b.id = e.branch_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN positions p ON p.id = e.position_id
                LEFT JOIN employee_work_schedules ews ON ews.employee_id = e.id 
                    AND CURRENT_DATE BETWEEN ews.effective_from AND COALESCE(ews.effective_to, '9999-12-31')
                LEFT JOIN work_schedule_templates wst ON wst.id = ews.schedule_template_id AND wst.is_active = TRUE
                LEFT JOIN employee_payroll_profiles epp ON epp.employee_id = e.id
                WHERE e.status = 'active' AND e.deleted_at IS NULL";

$params = [];

if ($view === 'branch' && $viewId) {
    $employeeSql .= " AND e.branch_id = :view_id";
    $params[':view_id'] = $viewId;
} elseif ($view === 'department' && $viewId) {
    $employeeSql .= " AND e.department_id = :view_id";
    $params[':view_id'] = $viewId;
} elseif ($view === 'position' && $viewId) {
    $employeeSql .= " AND e.position_id = :view_id";
    $params[':view_id'] = $viewId;
} elseif ($view === 'individual' && $viewId) {
    $employeeSql .= " AND e.id = :view_id";
    $params[':view_id'] = $viewId;
}

if ($searchQuery !== '') {
    $employeeSql .= " AND (e.employee_code ILIKE :search OR e.first_name ILIKE :search OR e.last_name ILIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$employeeSql .= " ORDER BY e.last_name, e.first_name LIMIT 100";

$employees = [];
try {
    $stmt = $pdo->prepare($employeeSql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('SCHEDULE-EMP', 'Failed loading employees: ' . $e->getMessage(), ['module' => 'attendance']);
    flash_error('Error loading employee data');
}

// Get leave requests for the month
$leaveData = [];
if (!empty($employees)) {
    $employeeIds = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    
    try {
        $leaveSql = "SELECT lr.id, lr.employee_id, 
                            lr.leave_type::text as leave_type, 
                            lr.start_date, lr.end_date, 
                            lr.status::text as status, 
                            lr.remarks as reason,
                            e.employee_code, e.first_name, e.last_name
                     FROM leave_requests lr
                     JOIN employees e ON e.id = lr.employee_id
                     WHERE lr.employee_id IN ($placeholders)
                       AND lr.status IN ('approved', 'pending')
                       AND lr.start_date <= :end_date
                       AND lr.end_date >= :start_date
                     ORDER BY lr.start_date";
        
        $leaveStmt = $pdo->prepare($leaveSql);
        $leaveParams = array_merge($employeeIds, [
            ':start_date' => $firstDay->format('Y-m-d'),
            ':end_date' => $lastDay->format('Y-m-d')
        ]);
        $leaveStmt->execute($leaveParams);
        $leaveData = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sys_log('SCHEDULE-LEAVE', 'Failed loading leave data: ' . $e->getMessage(), ['module' => 'attendance']);
    }
}

// Organize leave by employee and date
$leaveByEmployee = [];
foreach ($leaveData as $leave) {
    $empId = (int)$leave['employee_id'];
    if (!isset($leaveByEmployee[$empId])) {
        $leaveByEmployee[$empId] = [];
    }
    
    $start = new DateTime($leave['start_date']);
    $end = new DateTime($leave['end_date']);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    foreach ($period as $date) {
        if ($date >= $firstDay && $date <= $lastDay) {
            $dateKey = $date->format('Y-m-d');
            if (!isset($leaveByEmployee[$empId][$dateKey])) {
                $leaveByEmployee[$empId][$dateKey] = [];
            }
            $leaveByEmployee[$empId][$dateKey][] = $leave;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.schedule-calendar {
    display: grid;
    gap: 1px;
    background: #e5e7eb;
    border: 1px solid #e5e7eb;
}
.schedule-row {
    display: grid;
    grid-template-columns: 200px repeat(31, minmax(40px, 1fr));
    gap: 1px;
    background: #e5e7eb;
}
.schedule-cell {
    background: white;
    padding: 0.5rem;
    min-height: 3rem;
    position: relative;
}
.schedule-header {
    background: #f9fafb;
    font-weight: 600;
    font-size: 0.75rem;
    text-align: center;
    padding: 0.5rem 0.25rem;
    position: sticky;
    top: 0;
    z-index: 10;
}
.schedule-employee {
    background: #f9fafb;
    font-weight: 500;
    font-size: 0.875rem;
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
    position: sticky;
    left: 0;
    z-index: 5;
}
.leave-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.leave-approved {
    background: #dcfce7;
    color: #166534;
}
.leave-pending {
    background: #fef3c7;
    color: #92400e;
}
.leave-spanning {
    position: absolute;
    top: 0.5rem;
    height: 1.5rem;
    z-index: 2;
    display: flex;
    align-items: center;
    padding: 0 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.625rem;
    font-weight: 600;
    cursor: pointer;
}
.weekend-cell {
    background: #f9fafb;
}
</style>

<div class="space-y-4">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
      <div class="flex items-center gap-3">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/attendance/index">Back</a>
        <div>
          <h1 class="text-xl font-semibold text-slate-900">Employee Schedule & Leave Calendar</h1>
          <p class="text-sm text-slate-500 mt-0.5">View employee schedules and leave requests</p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card p-6">

        <form method="get" class="space-y-4">
            <input type="hidden" name="month" value="<?= htmlspecialchars($currentMonth) ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <!-- View Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">View Type</label>
                    <select name="view" class="input-text" onchange="this.form.submit()">
                        <option value="branch" <?= $view === 'branch' ? 'selected' : '' ?>>Branch</option>
                        <option value="department" <?= $view === 'department' ? 'selected' : '' ?>>Department</option>
                        <option value="position" <?= $view === 'position' ? 'selected' : '' ?>>Position</option>
                        <option value="individual" <?= $view === 'individual' ? 'selected' : '' ?>>Individual</option>
                    </select>
                </div>

                <!-- View ID Selection -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        <?php
                        if ($view === 'branch') echo 'Branch';
                        elseif ($view === 'department') echo 'Department';
                        elseif ($view === 'position') echo 'Position';
                        elseif ($view === 'individual') echo 'Employee';
                        ?>
                    </label>
                    <select name="view_id" class="input-text" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php
                        $options = [];
                        if ($view === 'branch') $options = $branches;
                        elseif ($view === 'department') $options = $departments;
                        elseif ($view === 'position') $options = $positions;
                        elseif ($view === 'individual') $options = $employees;
                        
                        foreach ($options as $opt):
                            $optId = (int)$opt['id'];
                            $optName = '';
                            if ($view === 'branch') $optName = $opt['name'];
                            elseif ($view === 'department') $optName = $opt['name'];
                            elseif ($view === 'position') $optName = $opt['name'];
                            elseif ($view === 'individual') $optName = $opt['employee_code'] . ' - ' . $opt['first_name'] . ' ' . $opt['last_name'];
                        ?>
                            <option value="<?= $optId ?>" <?= $viewId === $optId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($optName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Search Employee</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" 
                           class="input-text" placeholder="Code or name...">
                </div>

                <!-- Search Button -->
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="18" height="18" class="inline-block mr-1">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                        </svg>
                        Filter
                    </button>
                </div>
            </div>
        </form>

        <!-- Month Navigation -->
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-200">
            <a href="?month=<?= urlencode($prevMonth) ?>&view=<?= urlencode($view) ?>&view_id=<?= urlencode((string)$viewId) ?>&q=<?= urlencode($searchQuery) ?>" 
               class="btn btn-outline btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" class="inline-block mr-1">
                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
                Previous
            </a>
            <h2 class="text-lg font-semibold text-slate-900">
                <?= $dateObj->format('F Y') ?>
            </h2>
            <a href="?month=<?= urlencode($nextMonth) ?>&view=<?= urlencode($view) ?>&view_id=<?= urlencode((string)$viewId) ?>&q=<?= urlencode($searchQuery) ?>" 
               class="btn btn-outline btn-sm">
                Next
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" class="inline-block ml-1">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                </svg>
            </a>
        </div>
    </div>

    <!-- Legend -->
    <div class="card p-4">
        <div class="flex items-center gap-6 text-sm">
            <span class="font-medium text-slate-700">Legend:</span>
            <div class="flex items-center gap-2">
                <span class="leave-badge leave-approved">Approved Leave</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="leave-badge leave-pending">Pending Leave</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-slate-50 border border-slate-200"></div>
                <span class="text-slate-600">Weekend</span>
            </div>
        </div>
    </div>

    <!-- Calendar -->
    <div class="card p-6">
        <?php if (empty($employees)): ?>
            <div class="text-center py-12 text-slate-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <p class="text-base font-medium">No employees found</p>
                <p class="text-sm mt-1">Try adjusting your filters or search query</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <div class="schedule-calendar" style="min-width: 1400px;">
                    <!-- Header Row -->
                    <div class="schedule-row">
                        <div class="schedule-header" style="text-align: left; padding-left: 0.75rem;">Employee</div>
                        <?php
                        $daysInMonth = (int)$lastDay->format('d');
                        for ($day = 1; $day <= $daysInMonth; $day++):
                            $currentDate = new DateTime("$year-$month-$day");
                            $dayOfWeek = $currentDate->format('D');
                            $isWeekend = in_array($dayOfWeek, ['Sat', 'Sun']);
                        ?>
                            <div class="schedule-header <?= $isWeekend ? 'text-slate-400' : '' ?>">
                                <div><?= $day ?></div>
                                <div class="text-[0.625rem] font-normal"><?= $dayOfWeek ?></div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Employee Rows -->
                    <?php foreach ($employees as $emp):
                        $empId = (int)$emp['id'];
                        $empLeaves = $leaveByEmployee[$empId] ?? [];
                    ?>
                        <div class="schedule-row">
                            <div class="schedule-employee">
                                <span class="font-semibold text-slate-900">
                                    <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                </span>
                                <span class="text-xs text-slate-500 mt-0.5">
                                    <?= htmlspecialchars($emp['employee_code']) ?>
                                </span>
                                <?php if ($emp['position_title']): ?>
                                    <span class="text-xs text-slate-400 mt-0.5">
                                        <?= htmlspecialchars($emp['position_title']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($emp['work_start'] && $emp['work_end']): 
                                    $startTime = date('g:i A', strtotime($emp['work_start']));
                                    $endTime = date('g:i A', strtotime($emp['work_end']));
                                ?>
                                    <span class="text-xs text-indigo-600 mt-1 font-medium">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="12" height="12" class="inline-block mr-0.5">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                        </svg>
                                        <?= htmlspecialchars($startTime . ' - ' . $endTime) ?>
                                    </span>
                                <?php elseif ($emp['schedule_name']): ?>
                                    <span class="text-xs text-slate-400 mt-1">
                                        <?= htmlspecialchars($emp['schedule_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php
                            for ($day = 1; $day <= $daysInMonth; $day++):
                                $currentDate = new DateTime("$year-$month-$day");
                                $dateKey = $currentDate->format('Y-m-d');
                                $dayOfWeek = $currentDate->format('w');
                                $isWeekend = in_array($dayOfWeek, ['0', '6']); // Sunday = 0, Saturday = 6
                                $leaves = $empLeaves[$dateKey] ?? [];
                            ?>
                                <div class="schedule-cell <?= $isWeekend ? 'weekend-cell' : '' ?>">
                                    <?php if (!empty($leaves)):
                                        $leave = $leaves[0]; // Take first leave if multiple
                                        $statusClass = $leave['status'] === 'approved' ? 'leave-approved' : 'leave-pending';
                                        $leaveType = ucfirst(str_replace('_', ' ', $leave['leave_type']));
                                    ?>
                                        <div class="leave-badge <?= $statusClass ?>" 
                                             title="<?= htmlspecialchars($leaveType . ' - ' . $leave['status']) ?>">
                                            <?= htmlspecialchars(substr($leaveType, 0, 3)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-4 text-sm text-slate-600">
                Showing <?= count($employees) ?> employee<?= count($employees) !== 1 ? 's' : '' ?>
                <?php if (count($employees) >= 100): ?>
                    (limited to first 100 results)
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
