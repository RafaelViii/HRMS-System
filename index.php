<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/permissions.php';

$user = current_user();
$role = strtolower((string)($user['role'] ?? ''));
$isEmployeeRole = $role === 'employee';
$pageTitle = $isEmployeeRole ? 'Home' : 'Dashboard';

require_once __DIR__ . '/includes/header.php';

$pdo = get_db_conn();

// Get user's position and permissions for customization
$userPositionId = get_user_position_id($user['id']);
$userPosition = null;
if ($userPositionId) {
    try {
        $stmt = $pdo->prepare('SELECT name FROM positions WHERE id = :pid LIMIT 1');
        $stmt->execute([':pid' => $userPositionId]);
        $userPosition = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $userPosition = null;
    }
}

// Check key permissions for dashboard customization
$canManageEmployees = user_has_access($user['id'], 'hr_core', 'employees', 'manage');
$canManagePayroll = user_has_access($user['id'], 'payroll', 'payroll_cycles', 'manage');
$canViewPayroll = user_has_access($user['id'], 'payroll', 'payroll_cycles', 'read');
$canApproveLeaves = user_has_access($user['id'], 'hr_core', 'leave_approval', 'write');
$canViewAttendance = user_has_access($user['id'], 'hr_core', 'attendance', 'read');
$canManageAttendance = user_has_access($user['id'], 'hr_core', 'attendance', 'write');
$canViewReports = user_has_access($user['id'], 'reporting', 'hr_reports', 'read');
$canManageRecruitment = user_has_access($user['id'], 'hr_core', 'recruitment', 'write');
$canManageSystem = user_has_access($user['id'], 'system', 'system_settings', 'manage');

if ($isEmployeeRole) {
  $uid = (int)($user['id'] ?? 0);
  $employee = null;
  $departmentId = null;
  try {
    $stmt = $pdo->prepare('SELECT id, employee_code, first_name, last_name, department_id FROM employees WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $departmentId = $employee ? (int)($employee['department_id'] ?? 0) : null;
  } catch (Throwable $e) {
    $employee = null;
    $departmentId = null;
  }

  if (!$employee) {
    echo '<div class="card p-4 max-w-xl">';
    show_human_error('Your account is not linked to an employee profile.');
    echo '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
  }

  $employeeId = (int)$employee['id'];
  $departmentName = null;
  if ($departmentId) {
    try {
      $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = :dept LIMIT 1');
      $stmt->execute([':dept' => $departmentId]);
      $departmentName = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
      $departmentName = null;
    }
  }

  $entitlementLayers = leave_collect_entitlement_layers($pdo, $employeeId);
  $leaveEntitlements = $entitlementLayers['effective'];
  $knownLeaveTypes = leave_get_known_types($pdo);
  foreach ($knownLeaveTypes as $leaveTypeCode) {
    if (!array_key_exists($leaveTypeCode, $leaveEntitlements)) {
      $leaveEntitlements[$leaveTypeCode] = 0;
    }
  }
  $orderedEntitlements = [];
  foreach ($knownLeaveTypes as $leaveTypeCode) {
    $orderedEntitlements[$leaveTypeCode] = (float)($leaveEntitlements[$leaveTypeCode] ?? 0);
  }
  $leaveEntitlements = $orderedEntitlements;
  $leaveBalances = leave_calculate_balances($pdo, $employeeId, $leaveEntitlements);
  $totalAvailableLeave = 0.0;
  foreach ($leaveBalances as $balance) {
    $totalAvailableLeave += max(0, (float)$balance);
  }
  $pendingLeavesCount = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = :eid AND status = 'pending'");
    $stmt->execute([':eid' => $employeeId]);
    $pendingLeavesCount = (int)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $pendingLeavesCount = 0;
  }
  $pendingLeaves = [];
  try {
    $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid AND status = 'pending' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':eid' => $employeeId]);
    $pendingLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $pendingLeaves = [];
  }
  $recentLeaves = [];
  try {
    $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':eid' => $employeeId]);
    $recentLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $recentLeaves = [];
  }
  $latestPayslip = null;
  try {
    $stmt = $pdo->prepare("SELECT ps.id, ps.net_pay, ps.released_at, ps.period_start, ps.period_end, ps.status
                             FROM payslips ps
                            WHERE ps.employee_id = :eid
                              AND (ps.released_at IS NOT NULL OR ps.status = 'released')
                            ORDER BY COALESCE(ps.released_at, ps.updated_at, ps.created_at) DESC, ps.id DESC
                            LIMIT 1");
    $stmt->execute([':eid' => $employeeId]);
    $latestPayslip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $latestPayslip = null;
  }
  $recentMemos = [];
  try {
    $stmt = $pdo->prepare("SELECT d.id, d.title, d.file_path, d.created_at
                            FROM documents d
                            LEFT JOIN document_assignments da ON da.document_id = d.id
                            LEFT JOIN employees e ON e.id = da.employee_id
                            WHERE d.doc_type = 'memo'
                              AND (
                                da.employee_id = :eid
                                OR da.department_id = :dept
                                OR (da.employee_id IS NULL AND da.department_id IS NULL)
                              )
                            GROUP BY d.id
                            ORDER BY d.id DESC, d.created_at DESC
                            LIMIT 5");
    $stmt->execute([
      ':eid' => $employeeId,
      ':dept' => $departmentId,
    ]);
    $recentMemos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $recentMemos = [];
  }
  $todayLabel = date('l, F j, Y');
  $fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
  ?>
  <div class="space-y-6">
    <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-600 p-6 text-white shadow-lg">
      <div class="absolute inset-y-0 right-0 hidden opacity-20 pointer-events-none md:block">
        <div class="h-full w-64 translate-x-10 rotate-6 rounded-full border border-white/20"></div>
      </div>
      <div class="relative z-10 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-sm uppercase tracking-widest text-white/80">Today • <?= htmlspecialchars($todayLabel) ?></p>
          <h1 class="mt-1 text-2xl font-semibold md:text-3xl">Welcome back, <?= htmlspecialchars($fullName ?: 'there') ?>.</h1>
          <p class="mt-2 max-w-2xl text-sm text-white/75">Here’s your personal HR pulse with time-off, payroll, and company updates all in one place.</p>
          <div class="mt-4 flex flex-wrap gap-2">
            <a class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/25" href="<?= BASE_URL ?>/modules/leave/create">
              <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/20"><span>＋</span></span>
              File Leave
            </a>
            <a class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-medium text-indigo-700 transition hover:bg-indigo-100" href="<?= BASE_URL ?>/modules/payroll/my_payslips">
              <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-indigo-700"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></span>
              View Payslips
            </a>
          </div>
        </div>
        <div class="grid w-full max-w-xs gap-3 rounded-2xl bg-white/10 p-4 text-sm md:text-base">
          <div class="flex items-center justify-between text-white">
            <span class="text-white/80">Employee ID</span>
            <span class="font-semibold">#<?= htmlspecialchars($employee['employee_code'] ?? '—') ?></span>
          </div>
          <div class="flex items-center justify-between text-white">
            <span class="text-white/80">Department</span>
            <span class="font-semibold"><?= htmlspecialchars($departmentName ?? 'Unassigned') ?></span>
          </div>
          <div class="flex items-center justify-between text-white">
            <span class="text-white/80">Available Leave</span>
            <span class="font-semibold"><?= number_format($totalAvailableLeave, 1) ?> days</span>
          </div>
        </div>
      </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-4">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Available Leave Days</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900"><?= number_format($totalAvailableLeave, 1) ?></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-indigo-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></span>
        </div>
        <p class="mt-3 text-xs text-slate-500">Across all leave types.</p>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pending Requests</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900"><?= (int)$pendingLeavesCount ?></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
        </div>
        <a class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create#pending">Review requests →</a>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Latest Payslip</p>
            <?php if ($latestPayslip): ?>
              <p class="mt-3 text-3xl font-semibold text-slate-900"><?= number_format((float)($latestPayslip['net_pay'] ?? 0), 2) ?></p>
            <?php else: ?>
              <p class="mt-3 text-lg font-semibold text-slate-500">Not released</p>
            <?php endif; ?>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></span>
        </div>
        <?php if ($latestPayslip): ?>
          <p class="mt-3 text-xs text-slate-500">
            <?= htmlspecialchars(($latestPayslip['period_start'] ?? '') . ' → ' . ($latestPayslip['period_end'] ?? '')) ?>
            <?php if (!empty($latestPayslip['released_at'])): ?>
              <?php $latestReleaseLabel = format_datetime_display($latestPayslip['released_at'], false, ''); ?>
              <?php if ($latestReleaseLabel !== ''): ?>
                • Released <?= htmlspecialchars($latestReleaseLabel) ?>
              <?php endif; ?>
            <?php endif; ?>
          </p>
          <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <a class="inline-flex items-center justify-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 font-medium text-white hover:bg-indigo-500" href="<?= BASE_URL ?>/modules/payroll/view?id=<?= (int)$latestPayslip['id'] ?>">Open</a>
            <a class="inline-flex items-center justify-center gap-1 rounded-lg border border-indigo-200 px-3 py-2 font-medium text-indigo-600 hover:bg-indigo-50" href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= (int)$latestPayslip['id'] ?>" target="_blank" rel="noopener" data-no-loader>PDF</a>
          </div>
        <?php else: ?>
          <a class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/payroll/my_payslips">Check history →</a>
        <?php endif; ?>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Department</p>
            <p class="mt-3 text-lg font-semibold text-slate-900"><?= htmlspecialchars($departmentName ?? 'Unassigned') ?></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-sky-50 text-sky-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg></span>
        </div>
        <p class="mt-3 text-xs text-slate-500">Need help? Contact your department lead.</p>
      </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-3">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm xl:col-span-2">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <h2 class="text-base font-semibold text-slate-800">Leave Balances</h2>
          <a class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">File new leave →</a>
        </div>
        <div class="mt-4 space-y-3">
          <?php foreach ($leaveEntitlements as $type => $entitled):
            $remaining = (float)($leaveBalances[$type] ?? 0);
            $total = (float)$entitled;
            $used = max(0.0, $total - $remaining);
            $percentUsed = $total > 0 ? min(100, round(($used / $total) * 100)) : 0;
          ?>
            <div class="rounded-lg border border-slate-100 p-3">
              <div class="flex items-start justify-between">
                <div>
                  <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars(leave_label_for_type($type)) ?></p>
                  <p class="text-xs text-slate-500">Entitled <?= number_format($total, 1) ?> day(s) • Remaining <?= number_format($remaining, 1) ?></p>
                </div>
                <span class="text-xs font-semibold text-indigo-600"><?= $percentUsed ?>% used</span>
              </div>
              <div class="mt-3 h-2 rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500" style="width: <?= $total > 0 ? $percentUsed : 0 ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm" id="pending">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Pending Leave Requests</h2>
            <p class="text-xs text-slate-500">Track approvals in progress.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">Manage</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($pendingLeaves): ?>
            <?php foreach ($pendingLeaves as $row): ?>
              <article class="rounded-lg border border-amber-100 bg-amber-50/60 p-3">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-amber-900"><?= htmlspecialchars(leave_label_for_type($row['leave_type'])) ?></span>
                  <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-600">Pending</span>
                </div>
                <p class="mt-1 text-xs text-amber-800"><?= htmlspecialchars($row['start_date']) ?> → <?= htmlspecialchars($row['end_date']) ?> • <?= number_format((float)($row['total_days'] ?? 0), 2) ?> day(s)</p>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$row['id'] ?>">View details →</a>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No pending leave requests right now.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Recent Leave Activity</h2>
            <p class="text-xs text-slate-500">Your last five filings in chronological order.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">Full history</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($recentLeaves): ?>
            <?php foreach ($recentLeaves as $row): ?>
              <article class="rounded-lg border border-slate-100 p-3">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-slate-900"><?= htmlspecialchars(leave_label_for_type($row['leave_type'])) ?></span>
                  <?php
                    $status = strtolower((string)($row['status'] ?? ''));
                    $statusColor = $status === 'approved' ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : ($status === 'rejected' ? 'text-red-600 bg-red-50 border-red-100' : 'text-slate-600 bg-slate-50 border-slate-100');
                  ?>
                  <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide <?= $statusColor ?>"><?= htmlspecialchars(ucfirst($status ?: 'Pending')) ?></span>
                </div>
                <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($row['start_date']) ?> → <?= htmlspecialchars($row['end_date']) ?> • <?= number_format((float)($row['total_days'] ?? 0), 2) ?> day(s)</p>
                <p class="mt-1 text-xs text-slate-400">Filed <?= htmlspecialchars(date('M d, Y', strtotime($row['created_at'] ?? 'now'))) ?></p>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No leave requests on record.</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Latest Memos</h2>
            <p class="text-xs text-slate-500">Stay up-to-date with company communications.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/documents/memo.php">View all</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($recentMemos): ?>
            <?php foreach ($recentMemos as $memo): ?>
              <article class="group rounded-lg border border-slate-100 p-3 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md">
                <h3 class="font-medium text-slate-900 group-hover:text-indigo-600"><?= htmlspecialchars($memo['title']) ?></h3>
                <p class="mt-1 text-xs text-slate-500">Published <?= htmlspecialchars(date('M d, Y', strtotime($memo['created_at'] ?? 'now'))) ?></p>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/<?= ltrim($memo['file_path'], '/') ?>" target="_blank" rel="noopener">Open memo →</a>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No memos available right now.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
  <?php
} else {
  if (!function_exists('scalar')) {
    function scalar(string $sql, array $params = []) {
      $pdo = get_db_conn();
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $val = $st->fetchColumn();
      return (int)($val ?? 0);
    }
  }

  $totalEmployees = $canManageEmployees || $canViewReports ? scalar('SELECT COUNT(*) FROM employees') : 0;
  $activeLeaves = $canApproveLeaves ? scalar("SELECT COUNT(*) FROM leave_requests WHERE status='pending'") : 0;
  $today = date('Y-m-d');
  $presentToday = $canViewAttendance ? scalar("SELECT COUNT(*) FROM attendance WHERE date = :d AND status = 'present'", [':d' => $today]) : 0;
  $payrollReleased = $canViewPayroll ? scalar('SELECT COUNT(*) FROM payroll WHERE released_at::date = CURRENT_DATE') : 0;
  $adminToday = date('l, F j, Y');

  // Pending Requests: aggregate ALL request types (leave, overtime, manual override)
  $pendingOT = 0;
  try { $pendingOT = scalar("SELECT COUNT(*) FROM overtime_requests WHERE status='pending'"); } catch (Throwable $e) { $pendingOT = 0; }
  $totalPendingRequests = $activeLeaves + $pendingOT;

  // Inventory stats
  $canViewInventory = user_has_access($user['id'], 'inventory', 'inventory_items', 'read');
  $invAvailable = 0; $invLowStock = 0; $invOutOfStock = 0; $invExpiringSoon = 0;
  if ($canViewInventory) {
    try {
      $invAvailable = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand > reorder_level");
      $invLowStock = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand > 0 AND qty_on_hand <= reorder_level");
      $invOutOfStock = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand = 0");
      $invExpiringSoon = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND expiry_date IS NOT NULL AND expiry_date <= (CURRENT_DATE + INTERVAL '30 days') AND expiry_date >= CURRENT_DATE");
    } catch (Throwable $e) { /* tables may not exist yet */ }
  }
  
  // Determine dashboard title based on position and role
  $dashboardTitle = 'Dashboard';
  $dashboardSubtitle = 'Your workspace overview';
  if ($userPosition) {
      $dashboardTitle = htmlspecialchars($userPosition) . ' Dashboard';
      $dashboardSubtitle = 'Tools and insights for your role';
  } elseif ($role === 'admin') {
      $dashboardTitle = 'People Operations Pulse';
      $dashboardSubtitle = 'Monitor workforce trends, approvals, and payroll releases at a glance';
  }
  ?>
  <div class="space-y-6">
    <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-800 to-slate-900 p-6 text-white shadow-lg">
      <div class="absolute inset-y-0 right-0 hidden w-64 opacity-30 pointer-events-none lg:block">
        <div class="h-full w-full rotate-3 rounded-full border border-white/20"></div>
      </div>
      <div class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.35em] text-white/60"><?= htmlspecialchars($userPosition ?: 'Admin Overview') ?> • <?= htmlspecialchars($adminToday) ?></p>
          <h1 class="mt-2 text-3xl font-semibold md:text-4xl"><?= $dashboardTitle ?></h1>
          <p class="mt-3 max-w-2xl text-sm text-white/70"><?= $dashboardSubtitle ?></p>
        </div>
        <div class="grid w-full max-w-sm gap-3 rounded-2xl bg-white/10 p-4 text-sm text-white">
          <?php if ($canManageEmployees || $canViewReports): ?>
          <div class="flex items-center justify-between">
            <span class="text-white/70">Employees</span>
            <span class="text-lg font-semibold"><?= $totalEmployees ?></span>
          </div>
          <?php endif; ?>
          <?php if ($canApproveLeaves): ?>
          <div class="flex items-center justify-between">
            <span class="text-white/70">Pending Requests</span>
            <span class="text-lg font-semibold"><?= $totalPendingRequests ?></span>
          </div>
          <?php endif; ?>
          <?php if ($canViewAttendance): ?>
          <div class="flex items-center justify-between">
            <span class="text-white/70">Present Today</span>
            <span class="text-lg font-semibold"><?= $presentToday ?></span>
          </div>
          <?php endif; ?>
          <?php if ($canViewPayroll): ?>
          <div class="flex items-center justify-between">
            <span class="text-white/70">Payroll Releases</span>
            <span class="text-lg font-semibold"><?= $payrollReleased ?></span>
          </div>
          <?php endif; ?>
          <?php if (!$canManageEmployees && !$canApproveLeaves && !$canViewAttendance && !$canViewPayroll): ?>
          <div class="text-center py-2">
            <span class="text-white/70">Welcome to your dashboard</span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php
    // Build dynamic grid of cards based on permissions
    $metricCards = [];
    
    if ($canManageEmployees || $canViewReports) {
        $metricCards[] = [
            'title' => 'Total Employees',
            'value' => $totalEmployees,
            'description' => 'Active headcount across all departments.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
            'color' => 'indigo',
            'href' => BASE_URL . '/modules/employees/index'
        ];
    }
    
    if ($canApproveLeaves) {
        $metricCards[] = [
            'title' => 'Pending Requests',
            'value' => $totalPendingRequests,
            'description' => 'Leave (' . $activeLeaves . '), Overtime (' . $pendingOT . ') awaiting approval.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
            'color' => 'amber',
            'href' => BASE_URL . '/modules/leave/admin?status=pending'
        ];
    }
    
    if ($canViewAttendance) {
        $metricCards[] = [
            'title' => 'Present Today',
            'value' => $presentToday,
            'description' => 'Registered attendance for ' . date('M d, Y') . '.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'color' => 'emerald',
            'href' => BASE_URL . '/modules/attendance/index?from=' . urlencode(date('Y-m-d')) . '&to=' . urlencode(date('Y-m-d'))
        ];
    }
    
    if ($canViewPayroll) {
        $metricCards[] = [
            'title' => 'Payroll Released Today',
            'value' => $payrollReleased,
            'description' => 'Net payslips handed off in the last 24 hours.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
            'color' => 'sky',
            'href' => BASE_URL . '/modules/payroll/index?released=today'
        ];
    }
    
    $gridCols = count($metricCards) === 1 ? 'lg:grid-cols-1 max-w-md' : (count($metricCards) === 2 ? 'lg:grid-cols-2' : (count($metricCards) === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4'));
    ?>
    
    <?php if ($metricCards): ?>
    <section class="grid gap-4 <?= $gridCols ?>">
      <?php foreach ($metricCards as $card): ?>
      <a class="group rounded-xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg" href="<?= htmlspecialchars($card['href']) ?>" title="<?= htmlspecialchars($card['title']) ?>">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= htmlspecialchars($card['title']) ?></span>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-<?= $card['color'] ?>-50 text-<?= $card['color'] ?>-500"><?= $card['icon'] ?></span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900 group-hover:text-indigo-600"><?= $card['value'] ?></p>
        <p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($card['description']) ?></p>
      </a>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($canViewInventory): ?>
    <section>
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold text-slate-800">Inventory Status</h2>
        <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/inventory/inventory">View Inventory</a>
      </div>
      <div class="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-slate-900"><?= $invAvailable ?></p>
              <p class="text-xs text-slate-500">Available Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-amber-600"><?= $invLowStock ?></p>
              <p class="text-xs text-slate-500">Low Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 text-red-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-red-600"><?= $invOutOfStock ?></p>
              <p class="text-xs text-slate-500">Out of Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-purple-100 text-purple-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-purple-600"><?= $invExpiringSoon ?></p>
              <p class="text-xs text-slate-500">Expiring Soon</p>
            </div>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($canViewAttendance || $canManageAttendance): ?>
    <section>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Attendance (Last 7 days)</h2>
            <p class="text-xs text-slate-500">Presence mix across the past week.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/attendance/index">Open attendance hub</a>
        </div>
        <?php
          $rows = [];
          try {
            $rows = $pdo->query("SELECT date::date AS d,
              COUNT(*) FILTER (WHERE status='present') AS present,
              COUNT(*) FILTER (WHERE status='late') AS late,
              COUNT(*) FILTER (WHERE status='absent') AS absent
              FROM attendance WHERE date >= (CURRENT_DATE - INTERVAL '6 days') GROUP BY d ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $labels = [];$present=[];$late=[];$absent=[];
          $period = new DatePeriod(new DateTime('-6 days'), new DateInterval('P1D'), (new DateTime('tomorrow')));
          $byD = [];
          foreach ($rows as $r) { $byD[$r['d']] = $r; }
          foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $labels[] = $dt->format('D, M d');
            $present[] = (int)($byD[$d]['present'] ?? 0);
            $late[] = (int)($byD[$d]['late'] ?? 0);
            $absent[] = (int)($byD[$d]['absent'] ?? 0);
          }
        ?>
        <div class="mt-5" style="position:relative; width:100%; height:250px;">
          <canvas id="chartAttendance"
            data-chart="line"
            data-labels='<?= json_encode($labels) ?>'
            data-datasets='<?= json_encode([
              ["label"=>"Present","data"=>$present,"borderColor"=>"#16a34a","backgroundColor"=>"rgba(22,163,74,0.12)","tension"=>0.3,"fill"=>true],
              ["label"=>"Late","data"=>$late,"borderColor"=>"#f59e0b","backgroundColor"=>"rgba(245,158,11,0.12)","tension"=>0.3,"fill"=>true],
              ["label"=>"Absent","data"=>$absent,"borderColor"=>"#ef4444","backgroundColor"=>"rgba(239,68,68,0.12)","tension"=>0.3,"fill"=>true],
            ]) ?>'
          ></canvas>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($canManageEmployees || $canViewReports): ?>
    <section class="grid gap-4 xl:grid-cols-2">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Headcount Trend (12 months)</h2>
            <p class="text-xs text-slate-500">Active employees snapshot per month.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/employees/index">Manage roster</a>
        </div>
        <?php
          $hcLabels = [];$hcData=[];
          try {
            $stHc = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'active' AND hire_date <= :month_end");
            for ($i=11;$i>=0;$i--) {
              $dt = (new DateTime("first day of -$i month"));
              $monthEnd = (clone $dt)->modify('last day of this month')->format('Y-m-d');
              $hcLabels[] = $dt->format('M Y');
              $stHc->execute([':month_end' => $monthEnd]);
              $hcData[] = (int)($stHc->fetchColumn() ?: 0);
            }
          } catch (Throwable $e) {
            for ($i=11;$i>=0;$i--) {
              $dt = (new DateTime("first day of -$i month"));
              $hcLabels[] = $dt->format('M Y');
              $hcData[] = 0;
            }
          }
        ?>
        <div class="mt-5" style="position:relative; width:100%; height:250px;">
          <canvas id="chartHeadcount"
            data-chart="bar"
            data-labels='<?= json_encode($hcLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Active Employees","data"=>$hcData,"backgroundColor"=>"rgba(59,130,246,0.25)","borderColor"=>"#3b82f6"]]) ?>'
          ></canvas>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Payroll Totals by Month</h2>
            <p class="text-xs text-slate-500">Released net pay (rolling 12 months).</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/payroll/index">Payroll dashboard</a>
        </div>
        <?php
          $plLabels = [];$plData=[];
          for ($i=11;$i>=0;$i--) {
            $dt = (new DateTime("first day of -$i month"));
            $start = $dt->format('Y-m-01');
            $nextMonth = (clone $dt)->modify('first day of next month')->format('Y-m-01');
            $plLabels[] = $dt->format('M Y');
            try {
              $st = $pdo->prepare("SELECT COALESCE(SUM(net_pay),0) FROM payroll WHERE released_at >= :start::date AND released_at < :next::date");
              $st->execute([':start'=>$start, ':next'=>$nextMonth]);
              $plData[] = (float)($st->fetchColumn() ?: 0);
            } catch (Throwable $e) { $plData[] = 0.0; }
          }
        ?>
        <div class="mt-5" style="position:relative; width:100%; height:250px;">
          <canvas id="chartPayrollTotals"
            data-chart="line"
            data-labels='<?= json_encode($plLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Net Pay Released","data"=>$plData,"borderColor"=>"#10b981","backgroundColor"=>"rgba(16,185,129,0.12)","tension"=>0.3]]) ?>'
          ></canvas>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($canApproveLeaves || $canViewReports): ?>
    <section class="grid gap-4 lg:grid-cols-2">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Leave Mix (Last 90 days)</h2>
            <p class="text-xs text-slate-500">Status breakdown to spot bottlenecks.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/admin?status=pending">View queue</a>
        </div>
        <?php
          try {
            $rows = $pdo->query("SELECT status, COUNT(*) c FROM leave_requests WHERE created_at >= (CURRENT_DATE - INTERVAL '90 days') GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $lvLabels = array_column($rows, 'status');
          $lvCounts = array_map('intval', array_column($rows, 'c'));
        ?>
        <div class="mt-5" style="position:relative; width:100%; height:220px;">
          <canvas id="chartLeavesStatus"
            data-chart="doughnut"
            data-labels='<?= json_encode($lvLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Requests","data"=>$lvCounts,"backgroundColor"=>["#60a5fa","#34d399","#f87171","#a3a3a3"]]]) ?>'
          ></canvas>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Leave Types Spotlight</h2>
            <p class="text-xs text-slate-500">Identify high-demand leave categories.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/admin">Leave reports</a>
        </div>
        <?php
          try {
            $rows = $pdo->query("SELECT leave_type, COUNT(*) c FROM leave_requests WHERE created_at >= (CURRENT_DATE - INTERVAL '90 days') GROUP BY leave_type")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $ltLabels = array_column($rows, 'leave_type');
          $ltCounts = array_map('intval', array_column($rows, 'c'));
        ?>
        <div class="mt-5" style="position:relative; width:100%; height:220px;">
          <canvas id="chartLeavesType"
            data-chart="pie"
            data-labels='<?= json_encode($ltLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Requests","data"=>$ltCounts,"backgroundColor"=>["#93c5fd","#86efac","#fca5a5","#fde68a","#c4b5fd"]]]) ?>'
          ></canvas>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <!-- ===================== ACTION CENTER (below all charts) ===================== -->
    <section class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between mb-4">
        <div>
          <h2 class="text-base font-semibold text-slate-800">Action Center</h2>
          <p class="text-xs text-slate-500">Quick access to your most important workflows and pending items.</p>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <?php if ($canManageEmployees): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700" href="<?= BASE_URL ?>/modules/employees/index">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          </span>
          <div>
            <span class="block font-medium">Employees</span>
            <span class="text-xs text-slate-500"><?= $totalEmployees ?> active employees</span>
          </div>
          <span class="ml-auto text-indigo-500">→</span>
        </a>
        <?php endif; ?>

        <?php if ($canApproveLeaves): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-amber-200 hover:bg-amber-50 hover:text-amber-700" href="<?= BASE_URL ?>/modules/leave/admin?status=pending">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          </span>
          <div>
            <span class="block font-medium">Pending Requests</span>
            <span class="text-xs text-slate-500"><?= $activeLeaves ?> leave, <?= $pendingOT ?> overtime</span>
          </div>
          <span class="ml-auto text-amber-500 font-bold"><?= $totalPendingRequests ?></span>
        </a>
        <?php endif; ?>

        <?php if ($canViewAttendance): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700" href="<?= BASE_URL ?>/modules/attendance/index?from=<?= urlencode(date('Y-m-d')) ?>&to=<?= urlencode(date('Y-m-d')) ?>">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </span>
          <div>
            <span class="block font-medium">Present Today</span>
            <span class="text-xs text-slate-500">Live attendance count</span>
          </div>
          <span class="ml-auto text-emerald-600 font-bold"><?= $presentToday ?></span>
        </a>
        <?php endif; ?>

        <?php if ($canViewPayroll): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700" href="<?= BASE_URL ?>/modules/payroll/index">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
          </span>
          <div>
            <span class="block font-medium">Payroll Releases</span>
            <span class="text-xs text-slate-500">Released today</span>
          </div>
          <span class="ml-auto text-sky-600 font-bold"><?= $payrollReleased ?></span>
        </a>
        <?php endif; ?>

        <?php if ($canManagePayroll): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-indigo-200 hover:bg-indigo-50" href="<?= BASE_URL ?>/modules/payroll/index">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </span>
          <div>
            <span class="block font-medium">Run Payroll Cycle</span>
            <span class="text-xs text-slate-500">Start new payroll run</span>
          </div>
          <span class="ml-auto text-indigo-500">→</span>
        </a>
        <?php endif; ?>

        <?php if ($canManageAttendance): ?>
        <a class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition hover:border-indigo-200 hover:bg-indigo-50" href="<?= BASE_URL ?>/modules/attendance/index">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-teal-100 text-teal-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </span>
          <div>
            <span class="block font-medium">Record Attendance</span>
            <span class="text-xs text-slate-500">Import or add entries</span>
          </div>
          <span class="ml-auto text-indigo-500">→</span>
        </a>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===================== RECENT NOTIFICATIONS (bottom) ===================== -->
    <section class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h2 class="text-base font-semibold text-slate-800">Recent Notifications</h2>
          <p class="text-xs text-slate-500">Latest announcements for your team.</p>
        </div>
        <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/notifications/index">Notification center</a>
      </div>
      <?php
        $uid = $_SESSION['user']['id'];
        try {
          $stmt = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id IS NULL OR user_id = :uid ORDER BY id DESC LIMIT 6');
          $stmt->execute([':uid'=>$uid]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
      ?>
      <ol class="mt-4 space-y-3 text-sm text-slate-700">
        <?php foreach ($rows as $n): ?>
          <li class="flex items-start gap-3 rounded-lg border border-slate-100 p-3">
            <span class="mt-0.5 inline-flex h-2.5 w-2.5 flex-shrink-0 rounded-full bg-indigo-500"></span>
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= htmlspecialchars(date('M d, Y • h:i A', strtotime($n['created_at'] ?? 'now'))) ?></p>
              <p class="mt-1 text-sm text-slate-800"><?= htmlspecialchars($n['message']) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <li class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No notifications yet.</li>
        <?php endif; ?>
      </ol>
    </section>
  </div>
  <?php
}

require_once __DIR__ . '/includes/footer.php';
