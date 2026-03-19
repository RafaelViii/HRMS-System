<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

$canManageLeaves = user_has_access($uid, 'leave', 'leave_approval', 'write');
$requestedMode = strtolower((string)($_GET['mode'] ?? ''));
if ($requestedMode === 'admin') {
  if ($canManageLeaves) {
    $redirectParams = $_GET;
    unset($redirectParams['mode']);
    $target = BASE_URL . '/modules/leave/admin';
    if (!empty($redirectParams)) {
      $target .= '?' . http_build_query($redirectParams);
    }
    header('Location: ' . $target);
    exit;
  }
  flash_error('You do not have permission to access leave management.');
  header('Location: ' . BASE_URL . '/modules/leave/index');
  exit;
}

$pageTitle = 'Leaves';
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];

$employeeProfile = null;
$employeeStatusFilter = '';
$employeeStatusNav = [];
$leaveEntitlements = [];
$leaveBalances = [];
$totalAvailableLeave = 0.0;
$statusMetrics = [
  'pending' => ['count' => 0, 'days' => 0.0],
  'approved' => ['count' => 0, 'days' => 0.0],
  'rejected' => ['count' => 0, 'days' => 0.0],
  'cancelled' => ['count' => 0, 'days' => 0.0],
];
$approvedDaysThisYear = 0.0;
$upcomingRequests = [];
$historyRows = [];
$employeeError = null;

try {
  $stmt = $pdo->prepare('SELECT id, employee_code, first_name, last_name, department_id FROM employees WHERE user_id = :uid LIMIT 1');
  $stmt->execute([':uid' => $uid]);
  $employeeProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  sys_log('LEAVE-PROFILE', 'Unable to load employee profile: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'user_id' => $uid]);
  $employeeProfile = null;
}

if (!$employeeProfile) {
  $employeeError = 'Your account is not linked to an employee profile yet.';
} else {
  $entitlementLayers = leave_collect_entitlement_layers($pdo, (int)$employeeProfile['id']);
  $knownLeaveTypes = leave_get_known_types($pdo);
  foreach ($knownLeaveTypes as $typeCode) {
    $leaveEntitlements[$typeCode] = (float)($entitlementLayers['effective'][$typeCode] ?? 0.0);
  }
  $leaveBalances = leave_calculate_balances($pdo, (int)$employeeProfile['id'], $leaveEntitlements);
  foreach ($leaveBalances as $balance) {
    $totalAvailableLeave += max(0, (float)$balance);
  }

  try {
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total, COALESCE(SUM(total_days), 0) AS days FROM leave_requests WHERE employee_id = :eid GROUP BY status');
    $stmt->execute([':eid' => (int)$employeeProfile['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $code = strtolower((string)($row['status'] ?? ''));
      if ($code === '') {
        continue;
      }
      if (!isset($statusMetrics[$code])) {
        $statusMetrics[$code] = ['count' => 0, 'days' => 0.0];
      }
      $statusMetrics[$code]['count'] = (int)($row['total'] ?? 0);
      $statusMetrics[$code]['days'] = (float)($row['days'] ?? 0.0);
    }
  } catch (Throwable $e) {
    sys_log('LEAVE-STATUS-STATS', 'Unable to load leave status metrics: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'employee_id' => $employeeProfile['id']]);
  }

  try {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_days), 0) FROM leave_requests WHERE employee_id = :eid AND status = :status AND start_date BETWEEN :start AND :end');
    $stmt->execute([
      ':eid' => (int)$employeeProfile['id'],
      ':status' => 'approved',
      ':start' => date('Y-01-01'),
      ':end' => date('Y-12-31'),
    ]);
    $approvedDaysThisYear = (float)($stmt->fetchColumn() ?: 0.0);
  } catch (Throwable $e) {
    sys_log('LEAVE-YEAR-STATS', 'Unable to load leave totals for current year: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'employee_id' => $employeeProfile['id']]);
    $approvedDaysThisYear = 0.0;
  }

  try {
    $stmt = $pdo->prepare('SELECT id, leave_type, start_date, end_date, total_days, status FROM leave_requests WHERE employee_id = :eid AND status = :status AND start_date >= :start ORDER BY start_date ASC, id ASC LIMIT 5');
    $stmt->execute([
      ':eid' => (int)$employeeProfile['id'],
      ':status' => 'approved',
      ':start' => date('Y-m-d'),
    ]);
    $upcomingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    sys_log('LEAVE-UPCOMING', 'Unable to load upcoming leaves: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'employee_id' => $employeeProfile['id']]);
    $upcomingRequests = [];
  }

  $employeeStatusFilter = strtolower((string)($_GET['status'] ?? ''));
  if ($employeeStatusFilter !== '' && !in_array($employeeStatusFilter, $allowedStatuses, true)) {
    $employeeStatusFilter = '';
  }

  $historySql = 'SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid';
  $historyParams = [':eid' => (int)$employeeProfile['id']];
  if ($employeeStatusFilter !== '') {
    $historySql .= ' AND status = :status';
    $historyParams[':status'] = $employeeStatusFilter;
  }
  $historySql .= ' ORDER BY created_at DESC, id DESC LIMIT 50';
  try {
    $stmt = $pdo->prepare($historySql);
    $stmt->execute($historyParams);
    $historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    sys_log('LEAVE-HISTORY', 'Unable to load leave history: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'employee_id' => $employeeProfile['id']]);
    $historyRows = [];
  }

  $statusFilterLabels = [
    '' => 'All',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Declined',
    'cancelled' => 'Cancelled',
  ];
  foreach ($statusFilterLabels as $code => $label) {
    $params = [];
    if ($code !== '') {
      $params['status'] = $code;
    }
    $url = BASE_URL . '/modules/leave/index';
    if ($params) {
      $url .= '?' . http_build_query($params);
    }
    $employeeStatusNav[] = [
      'code' => $code,
      'label' => $label,
      'url' => $url,
      'active' => $employeeStatusFilter === $code,
    ];
  }
}

require_once __DIR__ . '/../../includes/header.php';

$statusBadgeClasses = [
  'pending' => 'bg-yellow-100 text-yellow-800',
  'approved' => 'bg-green-100 text-green-800',
  'rejected' => 'bg-red-100 text-red-700',
  'cancelled' => 'bg-gray-100 text-gray-700',
];
?>
  <div class="space-y-4">
    <div class="bg-white p-4 md:p-6 rounded shadow">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">Leaves</h1>
          <p class="text-sm text-gray-600">Review your leave history and plan upcoming time off. Use the button to file a new request.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/leave/create">File Leave</a>
        </div>
      </div>
      <?php if ($employeeError): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 p-3 rounded text-sm"><?= htmlspecialchars($employeeError) ?></div>
      <?php else: ?>
        <div class="grid gap-3 md:grid-cols-3">
          <div class="border border-gray-200 rounded p-3 bg-gray-50">
            <p class="text-xs uppercase tracking-wide text-gray-500">Total Remaining</p>
            <p class="text-lg font-semibold text-gray-800 mt-1"><?= number_format($totalAvailableLeave, 2) ?> day(s)</p>
          </div>
          <div class="border border-gray-200 rounded p-3 bg-gray-50">
            <p class="text-xs uppercase tracking-wide text-gray-500">Pending Requests</p>
            <p class="text-lg font-semibold text-gray-800 mt-1"><?= (int)($statusMetrics['pending']['count'] ?? 0) ?></p>
          </div>
          <div class="border border-gray-200 rounded p-3 bg-gray-50">
            <p class="text-xs uppercase tracking-wide text-gray-500">Approved Days (<?= date('Y') ?>)</p>
            <p class="text-lg font-semibold text-gray-800 mt-1"><?= number_format($approvedDaysThisYear, 2) ?></p>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$employeeError): ?>
      <div class="bg-white p-4 md:p-5 rounded shadow">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Upcoming Approved Leaves</h2>
        <?php if ($upcomingRequests): ?>
          <div class="space-y-2">
            <?php foreach ($upcomingRequests as $req): ?>
              <?php
                $startLabel = !empty($req['start_date']) ? date('M d, Y', strtotime($req['start_date'])) : '—';
                $endLabel = !empty($req['end_date']) ? date('M d, Y', strtotime($req['end_date'])) : '—';
              ?>
              <div class="border border-green-100 bg-green-50 rounded p-3 flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                  <p class="text-sm font-medium text-green-900"><?= htmlspecialchars(leave_label_for_type((string)$req['leave_type'])) ?></p>
                  <p class="text-xs text-green-800"><?= htmlspecialchars($startLabel) ?> to <?= htmlspecialchars($endLabel) ?> • <?= number_format((float)$req['total_days'], 2) ?> day(s)</p>
                </div>
                <div class="mt-2 md:mt-0">
                  <a class="text-sm text-green-800 hover:underline" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$req['id'] ?>">View details</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-sm text-gray-600">You have no upcoming approved leaves.</p>
        <?php endif; ?>
      </div>

      <div class="bg-white p-4 md:p-5 rounded shadow">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
          <h2 class="text-base font-semibold text-gray-800">Leave History</h2>
          <div class="flex flex-wrap items-center gap-2">
            <?php foreach ($employeeStatusNav as $item): ?>
              <a class="px-3 py-1 rounded text-sm <?= $item['active'] ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>" href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="table-basic min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="p-2 text-left">Type</th>
                <th class="p-2 text-left">Period</th>
                <th class="p-2 text-left">Days</th>
                <th class="p-2 text-left">Status</th>
                <th class="p-2 text-left">Filed</th>
                <th class="p-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historyRows as $row): ?>
                <?php
                  $startLabel = !empty($row['start_date']) ? date('M d, Y', strtotime($row['start_date'])) : '—';
                  $endLabel = !empty($row['end_date']) ? date('M d, Y', strtotime($row['end_date'])) : '—';
                  $createdLabel = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—';
                  $statusCode = strtolower((string)$row['status']);
                  $badgeClass = $statusBadgeClasses[$statusCode] ?? 'bg-gray-100 text-gray-700';
                ?>
                <tr class="border-t">
                  <td class="p-2 text-gray-800 font-medium"><?= htmlspecialchars(leave_label_for_type((string)$row['leave_type'])) ?></td>
                  <td class="p-2 text-gray-700"><?= htmlspecialchars($startLabel) ?> to <?= htmlspecialchars($endLabel) ?></td>
                  <td class="p-2 text-gray-700"><?= number_format((float)$row['total_days'], 2) ?> day(s)</td>
                  <td class="p-2">
                    <span class="px-2 py-1 rounded text-xs font-semibold <?= $badgeClass ?> capitalize"><?= htmlspecialchars($row['status']) ?></span>
                  </td>
                  <td class="p-2 text-gray-600"><?= htmlspecialchars($createdLabel) ?></td>
                  <td class="p-2"><a class="text-blue-700 hover:underline" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$row['id'] ?>">View</a></td>
                </tr>
              <?php endforeach; if (!$historyRows): ?>
                <tr><td class="p-3 text-gray-500" colspan="6">No leave requests found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-white p-4 md:p-5 rounded shadow">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Leave Balances (<?= date('Y') ?>)</h2>
        <div class="grid gap-2 md:grid-cols-2 text-sm text-gray-700">
          <?php foreach ($leaveEntitlements as $type => $total): ?>
            <div class="flex items-center justify-between border border-gray-200 rounded p-3">
              <span class="font-medium text-gray-800"><?= htmlspecialchars(leave_label_for_type($type)) ?></span>
              <span><?= number_format((float)($leaveBalances[$type] ?? 0), 2) ?> / <?= number_format((float)$total, 2) ?> day(s)</span>
            </div>
          <?php endforeach; if (!$leaveEntitlements): ?>
            <p class="text-gray-600">No entitlement data available.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

<?php

require_once __DIR__ . '/../../includes/footer.php';
?>
