<?php
/**
 * Action Log System
 * Displays detailed user actions with advanced filtering capabilities
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Require audit trail access via permissions
require_login();
require_access('user_management', 'audit_logs', 'read');

$pdo = get_db_conn();

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filter parameters
$filterEmployee = $_GET['employee_id'] ?? null;
$filterPosition = $_GET['position_id'] ?? null;
$filterDepartment = $_GET['department_id'] ?? null;
$filterModule = $_GET['module'] ?? null;
$filterActionType = $_GET['action_type'] ?? null;
$filterStatus = $_GET['status'] ?? null;
$filterSeverity = $_GET['severity'] ?? null;
$filterDateFrom = $_GET['date_from'] ?? null;
$filterDateTo = $_GET['date_to'] ?? null;
$filterSearch = $_GET['search'] ?? null;

// Build WHERE clause
$where = [];
$params = [];

if ($filterEmployee) {
    $where[] = 'employee_id = :employee_id';
    $params[':employee_id'] = $filterEmployee;
}

if ($filterPosition) {
    $where[] = 'position_id = :position_id';
    $params[':position_id'] = $filterPosition;
}

if ($filterDepartment) {
    $where[] = 'department_id = :department_id';
    $params[':department_id'] = $filterDepartment;
}

if ($filterModule) {
    $where[] = 'module = :module';
    $params[':module'] = $filterModule;
}

if ($filterActionType) {
    $where[] = 'action_type = :action_type';
    $params[':action_type'] = $filterActionType;
}

if ($filterStatus) {
    $where[] = 'status = :status';
    $params[':status'] = $filterStatus;
}

if ($filterSeverity) {
    $where[] = 'severity = :severity';
    $params[':severity'] = $filterSeverity;
}

if ($filterDateFrom) {
    $where[] = 'DATE(created_at) >= :date_from';
    $params[':date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
    $where[] = 'DATE(created_at) <= :date_to';
    $params[':date_to'] = $filterDateTo;
}

if ($filterSearch) {
    $where[] = '(action ILIKE :search OR details ILIKE :search OR user_email ILIKE :search OR user_full_name ILIKE :search)';
    $params[':search'] = '%' . $filterSearch . '%';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total records
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_trail_view $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Fetch audit logs
$stmt = $pdo->prepare("
    SELECT *
    FROM audit_trail_view
    $whereClause
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch filter options
$employees = $pdo->query("
    SELECT id, first_name, last_name, employee_code 
    FROM employees 
    WHERE status = 'active' 
    ORDER BY last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("
    SELECT id, name 
    FROM departments 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$positions = $pdo->query("
    SELECT id, name 
    FROM positions 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$modules = $pdo->query("
    SELECT DISTINCT module 
    FROM audit_logs 
    WHERE module IS NOT NULL 
    ORDER BY module
")->fetchAll(PDO::FETCH_COLUMN);

$actionTypes = $pdo->query("
    SELECT DISTINCT action_type 
    FROM audit_logs 
    WHERE action_type IS NOT NULL 
    ORDER BY action_type
")->fetchAll(PDO::FETCH_COLUMN);

// Page title
$pageTitle = 'Action Log';

require_once __DIR__ . '/../../includes/header.php';

// Count active filters
$activeFilterCount = 0;
if ($filterEmployee) $activeFilterCount++;
if ($filterPosition) $activeFilterCount++;
if ($filterDepartment) $activeFilterCount++;
if ($filterModule) $activeFilterCount++;
if ($filterActionType) $activeFilterCount++;
if ($filterStatus) $activeFilterCount++;
if ($filterSeverity) $activeFilterCount++;
if ($filterDateFrom) $activeFilterCount++;
if ($filterDateTo) $activeFilterCount++;
if ($filterSearch) $activeFilterCount++;
?>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Action Log</h1>
            <p class="text-gray-600 mt-2">Comprehensive system activity tracking and monitoring</p>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-sm text-gray-500">
                <span class="font-semibold"><?= number_format($totalRecords) ?></span> total records
            </div>
            <button onclick="openFilterModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                </svg>
                Filters
                <?php if ($activeFilterCount > 0): ?>
                    <span class="bg-white text-blue-600 rounded-full px-2 py-0.5 text-xs font-semibold">
                        <?= $activeFilterCount ?>
                    </span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- Active Filters Summary -->
    <?php if ($activeFilterCount > 0): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-blue-800">Active Filters:</span>
                    <?php if ($filterSearch): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Search: "<?= htmlspecialchars($filterSearch) ?>"
                        </span>
                    <?php endif; ?>
                    <?php if ($filterEmployee): 
                        $empName = '';
                        foreach ($employees as $emp) {
                            if ($emp['id'] == $filterEmployee) {
                                $empName = $emp['employee_code'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name'];
                                break;
                            }
                        }
                    ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Employee: <?= htmlspecialchars($empName) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterDepartment): 
                        $deptName = '';
                        foreach ($departments as $dept) {
                            if ($dept['id'] == $filterDepartment) {
                                $deptName = $dept['name'];
                                break;
                            }
                        }
                    ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Department: <?= htmlspecialchars($deptName) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterPosition): 
                        $posName = '';
                        foreach ($positions as $pos) {
                            if ($pos['id'] == $filterPosition) {
                                $posName = $pos['name'];
                                break;
                            }
                        }
                    ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Position: <?= htmlspecialchars($posName) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterModule): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Module: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $filterModule))) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterActionType): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Type: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $filterActionType))) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterStatus): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Status: <?= htmlspecialchars(ucfirst($filterStatus)) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterSeverity): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            Severity: <?= htmlspecialchars(ucfirst($filterSeverity)) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterDateFrom): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            From: <?= htmlspecialchars($filterDateFrom) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($filterDateTo): ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-200 text-blue-800">
                            To: <?= htmlspecialchars($filterDateTo) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <a href="?" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Clear All</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Modal -->
    <div id="filterModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-6 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Filter Action Logs</h3>
                <button onclick="closeFilterModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <form method="GET" action="" id="filterForm" class="space-y-4">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <!-- Search -->
                    <div class="lg:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($filterSearch ?? '') ?>" 
                               placeholder="Search action, details, user email, or name..." 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <!-- Employee Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Employee</label>
                        <select name="employee_id" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= $filterEmployee == $emp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['employee_code'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Department Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                        <select name="department_id" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= $filterDepartment == $dept['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Position Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Position</label>
                        <select name="position_id" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Positions</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?= $pos['id'] ?>" <?= $filterPosition == $pos['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pos['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Module Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Module</label>
                        <select name="module" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Modules</option>
                            <?php foreach ($modules as $mod): ?>
                                <option value="<?= htmlspecialchars($mod) ?>" <?= $filterModule == $mod ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $mod))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Action Type Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Action Type</label>
                        <select name="action_type" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Types</option>
                            <?php foreach ($actionTypes as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= $filterActionType == $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Statuses</option>
                            <option value="success" <?= $filterStatus == 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="failed" <?= $filterStatus == 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="partial" <?= $filterStatus == 'partial' ? 'selected' : '' ?>>Partial</option>
                        </select>
                    </div>

                    <!-- Severity Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Severity</label>
                        <select name="severity" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Severities</option>
                            <option value="low" <?= $filterSeverity == 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="normal" <?= $filterSeverity == 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= $filterSeverity == 'high' ? 'selected' : '' ?>>High</option>
                            <option value="critical" <?= $filterSeverity == 'critical' ? 'selected' : '' ?>>Critical</option>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom ?? '') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <!-- Date To -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo ?? '') ?>" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6 pt-6 border-t border-gray-200">
                    <button type="button" onclick="discardFilters()" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
                        Discard
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                        Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Audit Log Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                No audit logs found matching your filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="showAuditDetails(<?= $log['id'] ?>)">
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($log['user_full_name'] ?? 'System') ?></div>
                                    <div class="text-gray-500 text-xs"><?= htmlspecialchars($log['user_email'] ?? 'N/A') ?></div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?php if ($log['employee_code']): ?>
                                        <div><?= htmlspecialchars($log['employee_code']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></div>
                                    <?php else: ?>
                                        <span class="text-gray-400">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($log['module']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['module']))) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 font-medium">
                                    <?= htmlspecialchars($log['action']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($log['action_type']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action_type']))) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php
                                    $statusColors = [
                                        'success' => 'bg-green-100 text-green-800',
                                        'failed' => 'bg-red-100 text-red-800',
                                        'partial' => 'bg-yellow-100 text-yellow-800'
                                    ];
                                    $statusClass = $statusColors[$log['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                        <?= htmlspecialchars(ucfirst($log['status'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php
                                    $severityColors = [
                                        'low' => 'bg-gray-100 text-gray-600',
                                        'normal' => 'bg-blue-100 text-blue-800',
                                        'high' => 'bg-orange-100 text-orange-800',
                                        'critical' => 'bg-red-100 text-red-800'
                                    ];
                                    $severityClass = $severityColors[$log['severity']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $severityClass ?>">
                                        <?= htmlspecialchars(ucfirst($log['severity'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <div class="max-w-xs">
                                        <?php 
                                        $details = $log['details'] ?? '-';
                                        // Try to parse JSON details for better readability
                                        if ($details !== '-' && (strpos($details, '{') === 0 || strpos($details, '[') === 0)) {
                                            $parsed = json_decode($details, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                                                // Format as human-readable key-value pairs
                                                $readable = [];
                                                foreach ($parsed as $key => $value) {
                                                    if (is_array($value) || is_object($value)) {
                                                        $value = json_encode($value);
                                                    }
                                                    $readable[] = ucwords(str_replace('_', ' ', $key)) . ': ' . $value;
                                                }
                                                echo '<div class="text-xs space-y-1">';
                                                foreach (array_slice($readable, 0, 2) as $item) {
                                                    echo '<div class="truncate" title="' . htmlspecialchars($item) . '">' . htmlspecialchars($item) . '</div>';
                                                }
                                                if (count($readable) > 2) {
                                                    echo '<div class="text-blue-600 text-xs">+' . (count($readable) - 2) . ' more...</div>';
                                                }
                                                echo '</div>';
                                            } else {
                                                echo '<div class="truncate" title="' . htmlspecialchars($details) . '">' . htmlspecialchars($details) . '</div>';
                                            }
                                        } else {
                                            echo '<div class="truncate" title="' . htmlspecialchars($details) . '">' . htmlspecialchars($details) . '</div>';
                                        }
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="bg-gray-50 px-4 py-3 flex items-center justify-between border-t border-gray-200">
                <div class="text-sm text-gray-700">
                    Showing <span class="font-medium"><?= $offset + 1 ?></span> to 
                    <span class="font-medium"><?= min($offset + $perPage, $totalRecords) ?></span> of 
                    <span class="font-medium"><?= number_format($totalRecords) ?></span> results
                </div>
                <div class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                           class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                           class="px-3 py-1 <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> border border-gray-300 rounded-md text-sm font-medium">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                           class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Next
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Audit Details Modal -->
<div id="auditDetailsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Audit Log Details</h3>
            <button onclick="closeAuditDetails()" class="text-gray-400 hover:text-gray-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="auditDetailsContent" class="space-y-4">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>
</div>

<script>
function openFilterModal() {
    document.getElementById('filterModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeFilterModal() {
    document.getElementById('filterModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function discardFilters() {
    window.location.href = '?';
}

function showAuditDetails(logId) {
    fetch(`audit_trail_details.php?id=${logId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('auditDetailsContent').innerHTML = data.html;
                document.getElementById('auditDetailsModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        });
}

function closeAuditDetails() {
    document.getElementById('auditDetailsModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close modals on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeFilterModal();
        closeAuditDetails();
    }
});

// Close modals when clicking outside
document.getElementById('filterModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeFilterModal();
    }
});

document.getElementById('auditDetailsModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeAuditDetails();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
