<?php
/**
 * Overtime Management - HR Module
 * Shows all employee overtime requests with approval workflow
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'attendance', 'write'); // HR can manage overtime
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// Filters
$status = $_GET['status'] ?? 'all';
$employeeSearch = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-t');

// Build query
$where = [];
$params = [];

if ($status !== 'all') {
    $where[] = 'ot.status = :status';
    $params[':status'] = $status;
}

if ($employeeSearch !== '') {
    $where[] = '(e.employee_code ILIKE :q OR e.first_name ILIKE :q OR e.last_name ILIKE :q)';
    $params[':q'] = "%{$employeeSearch}%";
}

if ($dateFrom) {
    $where[] = 'ot.overtime_date >= :from';
    $params[':from'] = $dateFrom;
}

if ($dateTo) {
    $where[] = 'ot.overtime_date <= :to';
    $params[':to'] = $dateTo;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT 
    ot.*,
    e.employee_code,
    e.first_name,
    e.last_name,
    e.branch_id,
    b.name as branch_name,
    d.name as department_name,
    u.full_name as approver_name
FROM overtime_requests ot
JOIN employees e ON e.id = ot.employee_id
LEFT JOIN branches b ON b.id = e.branch_id
LEFT JOIN departments d ON d.id = e.department_id
LEFT JOIN users u ON u.id = ot.approved_by
{$whereClause}
ORDER BY ot.overtime_date DESC, ot.created_at DESC
LIMIT 500";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $overtimeRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('OT-001', 'Failed to fetch overtime requests: ' . $e->getMessage(), [
        'module' => 'overtime',
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    $overtimeRequests = [];
}

// Global stats (unfiltered — always shows full picture)
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total_hours' => 0,
    'total_amount' => 0,
];

try {
    $statsRows = $pdo->query("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(hours_worked), 0) AS hrs FROM overtime_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statsRows as $r) {
        $stats[$r['status']] = (int)$r['cnt'];
        $stats['total_hours'] += (float)$r['hrs'];
    }
} catch (Throwable $e) { /* ignore */ }

$pageTitle = 'Overtime Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Overtime Management</h1>
            <p class="text-sm text-gray-500 mt-1">Approve employee overtime hours for payroll processing</p>
        </div>
        <div class="flex gap-2">
            <a href="<?= BASE_URL ?>/modules/overtime/generate" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Generate OT from Attendance
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid gap-4 md:grid-cols-4">
        <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Pending Approval</p>
                    <p class="mt-2 text-3xl font-semibold text-amber-600"><?= $stats['pending'] ?></p>
                </div>
                <div class="h-12 w-12 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Approved</p>
                    <p class="mt-2 text-3xl font-semibold text-green-600"><?= $stats['approved'] ?></p>
                </div>
                <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Rejected</p>
                    <p class="mt-2 text-3xl font-semibold text-red-600"><?= $stats['rejected'] ?></p>
                </div>
                <div class="h-12 w-12 rounded-full bg-red-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Hours</p>
                    <p class="mt-2 text-3xl font-semibold text-indigo-600"><?= number_format($stats['total_hours'], 2) ?></p>
                </div>
                <div class="h-12 w-12 rounded-full bg-indigo-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <form method="get" id="otFilterForm" class="flex flex-wrap gap-3 items-center">
            <input type="text" name="q" id="otSearchInput" value="<?= htmlspecialchars($employeeSearch) ?>" 
                   placeholder="Search employee..." class="input-text flex-1 min-w-[200px]">
            
            <select name="status" class="input-text" onchange="this.form.submit()">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>

            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="input-text" onchange="this.form.submit()">
            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="input-text" onchange="this.form.submit()">

            <a href="<?= BASE_URL ?>/modules/overtime/index" class="btn btn-outline">Clear</a>
        </form>
    </div>
    <script>
    (function(){
        var timer = null;
        var input = document.getElementById('otSearchInput');
        if(input) {
            input.addEventListener('input', function(){
                clearTimeout(timer);
                timer = setTimeout(function(){ document.getElementById('otFilterForm').submit(); }, 400);
            });
        }
    })();
    </script>

    <!-- Overtime List -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($overtimeRequests)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No overtime requests found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overtimeRequests as $ot): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= date('M d, Y', strtotime($ot['overtime_date'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($ot['first_name'] . ' ' . $ot['last_name']) ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($ot['employee_code']) ?> • <?= htmlspecialchars($ot['department_name'] ?? 'No Dept') ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= date('h:i A', strtotime($ot['start_time'])) ?> - <?= date('h:i A', strtotime($ot['end_time'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    <?= number_format((float)$ot['hours_worked'], 2) ?> hrs
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $typeColors = [
                                        'regular' => 'bg-blue-100 text-blue-800',
                                        'holiday' => 'bg-purple-100 text-purple-800',
                                        'restday' => 'bg-orange-100 text-orange-800',
                                    ];
                                    $typeColor = $typeColors[$ot['overtime_type']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $typeColor ?>">
                                        <?= ucfirst($ot['overtime_type']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusColors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'rejected' => 'bg-red-100 text-red-800',
                                    ];
                                    $statusColor = $statusColors[$ot['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusColor ?>">
                                        <?= ucfirst($ot['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php if ($ot['status'] === 'pending'): ?>
                                        <a href="<?= BASE_URL ?>/modules/overtime/approve?id=<?= $ot['id'] ?>" 
                                           class="text-indigo-600 hover:text-indigo-900">Review</a>
                                    <?php else: ?>
                                        <a href="<?= BASE_URL ?>/modules/overtime/view?id=<?= $ot['id'] ?>" 
                                           class="text-gray-600 hover:text-gray-900">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">Overtime Threshold</h3>
                <p class="mt-1 text-sm text-blue-700">
                    Only overtime hours beyond 1 hour are eligible for approval and payroll computation. 
                    Overtime under 1 hour will not be included in pay calculations.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
