<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'system_settings', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pageTitle = 'HR Admin';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$viewerRole = strtolower((string)($currentUser['role'] ?? ''));
$isAdminUser = $viewerRole === 'admin';
$heroAccessLabel = $isAdminUser ? 'Admins & HR partners' : 'HR partners';
action_log('admin', 'view_hr_admin_dashboard');

$lastLoginDisplay = 'N/A';
if ($currentUserId > 0) {
    try {
        $stmtLastLogin = $pdo->prepare('SELECT last_login FROM users WHERE id = :id');
        $stmtLastLogin->execute([':id' => $currentUserId]);
        $lastLoginRaw = $stmtLastLogin->fetchColumn();
        if ($lastLoginRaw) {
            $lastLoginDisplay = format_datetime_display($lastLoginRaw, true, 'N/A');
        }
    } catch (Throwable $e) {
        sys_log('ADMIN-DASH-LASTLOGIN', 'Failed loading last login: ' . $e->getMessage(), [
            'module' => 'admin',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $currentUserId],
        ]);
    }
}

$approverStats = ['total' => 0, 'active' => 0];
try {
    $stmtApprovers = $pdo->query('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE active) AS active FROM payroll_approvers');
    $rowApprovers = $stmtApprovers ? $stmtApprovers->fetch(PDO::FETCH_ASSOC) : null;
    if ($rowApprovers) {
        $approverStats['total'] = (int)($rowApprovers['total'] ?? 0);
        $approverStats['active'] = (int)($rowApprovers['active'] ?? 0);
    }
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-APPROVER-STATS', 'Failed loading approver stats: ' . $e->getMessage(), [
        'module' => 'admin',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$dashboardMetrics = [
    'openPayrollPeriods' => 0,
    'pendingLeaveRequests' => 0,
    'activeEmployees' => 0,
    'memosPublished30Days' => 0,
    'recentSystemEvents' => 0,
];

$threshold24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
$threshold30d = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

// Optimize dashboard metrics - combine into single query
try {
    $stmtMetrics = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM payroll_periods WHERE status = 'open') AS open_payroll,
            (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') AS pending_leave,
            (SELECT COUNT(*) FROM employees WHERE status = 'active') AS active_employees,
            (SELECT COUNT(*) FROM memos WHERE status = 'published' AND published_at >= :threshold30d) AS memos_30d,
            (SELECT COUNT(*) FROM system_logs WHERE created_at >= :threshold24h) AS logs_24h
    ");
    $stmtMetrics->execute([':threshold30d' => $threshold30d, ':threshold24h' => $threshold24h]);
    $metrics = $stmtMetrics->fetch(PDO::FETCH_ASSOC);
    
    if ($metrics) {
        $dashboardMetrics['openPayrollPeriods'] = (int)($metrics['open_payroll'] ?? 0);
        $dashboardMetrics['pendingLeaveRequests'] = (int)($metrics['pending_leave'] ?? 0);
        $dashboardMetrics['activeEmployees'] = (int)($metrics['active_employees'] ?? 0);
        $dashboardMetrics['memosPublished30Days'] = (int)($metrics['memos_30d'] ?? 0);
        $dashboardMetrics['recentSystemEvents'] = (int)($metrics['logs_24h'] ?? 0);
    }
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRICS', 'Failed loading dashboard metrics: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

try {
    $dashboardMetrics['openPayrollPeriods'] = (int)($pdo->query("SELECT COUNT(*) FROM payroll_periods WHERE status = 'open'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRIC-PAYROLL', 'Failed counting open payroll periods: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

try {
    $dashboardMetrics['pendingLeaveRequests'] = (int)($pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRIC-LEAVE', 'Failed counting pending leave requests: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

try {
    $dashboardMetrics['activeEmployees'] = (int)($pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRIC-EMPLOYEES', 'Failed counting active employees: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

try {
    $stmtMemos = $pdo->prepare("SELECT COUNT(*) FROM memos WHERE status = 'published' AND published_at >= :threshold");
    $stmtMemos->execute([':threshold' => $threshold30d]);
    $dashboardMetrics['memosPublished30Days'] = (int)($stmtMemos->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRIC-MEMOS', 'Failed counting published memos: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

try {
    $stmtPulseCount = $pdo->prepare('SELECT COUNT(*) FROM system_logs WHERE created_at >= :threshold');
    $stmtPulseCount->execute([':threshold' => $threshold24h]);
    $dashboardMetrics['recentSystemEvents'] = (int)($stmtPulseCount->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-METRIC-LOGS', 'Failed counting recent system events: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

$metricAccentStyles = [
    'indigo' => ['border' => 'border-indigo-200', 'label' => 'text-indigo-500', 'cta' => 'text-indigo-600'],
    'emerald' => ['border' => 'border-emerald-200', 'label' => 'text-emerald-500', 'cta' => 'text-emerald-600'],
    'sky' => ['border' => 'border-sky-200', 'label' => 'text-sky-500', 'cta' => 'text-sky-600'],
    'rose' => ['border' => 'border-rose-200', 'label' => 'text-rose-500', 'cta' => 'text-rose-600'],
];

$metricCards = [
    [
        'title' => 'Payroll runs open',
        'value' => number_format($dashboardMetrics['openPayrollPeriods']),
        'description' => 'Payroll periods awaiting finalization.',
        'href' => BASE_URL . '/modules/payroll/index',
        'accent' => 'indigo',
        'cta' => 'Review periods',
    ],
    [
        'title' => 'Pending leave approvals',
        'value' => number_format($dashboardMetrics['pendingLeaveRequests']),
        'description' => 'Leave requests waiting for manager action.',
        'href' => BASE_URL . '/modules/leave/index?filter=pending',
        'accent' => 'emerald',
        'cta' => 'Review leave',
    ],
    [
        'title' => 'Active employees',
        'value' => number_format($dashboardMetrics['activeEmployees']),
        'description' => 'Employees currently marked active in HydroMed HRMS.',
        'href' => BASE_URL . '/modules/employees/index',
        'accent' => 'sky',
        'cta' => 'Manage roster',
    ],
    [
        'title' => 'Broadcasts (30 days)',
        'value' => number_format($dashboardMetrics['memosPublished30Days']),
        'description' => 'Published memos in the last 30 days.',
        'href' => BASE_URL . '/modules/documents/index',
        'accent' => 'rose',
        'cta' => 'View memos',
    ],
];

$dashboardActionItems = [
    [
        'label' => 'Review pending leave requests',
        'count' => $dashboardMetrics['pendingLeaveRequests'],
        'description' => 'Confirm or decline staff leave submissions.',
        'href' => BASE_URL . '/modules/leave/index?filter=pending',
    ],
    [
        'label' => 'Close open payroll runs',
        'count' => $dashboardMetrics['openPayrollPeriods'],
        'description' => 'Prepare release notes and approvals for active runs.',
        'href' => BASE_URL . '/modules/payroll/index',
    ],
    [
        'label' => 'Plan next HR broadcast',
        'count' => $dashboardMetrics['memosPublished30Days'],
        'description' => 'Keep employees aligned with the latest announcements.',
        'href' => BASE_URL . '/modules/admin/notification_create',
    ],
];

$quickActionButtons = [
  ['label' => 'Benefits & Deductions Config', 'href' => BASE_URL . '/modules/admin/compensation'],
  ['label' => 'Approval Workflow', 'href' => BASE_URL . '/modules/admin/approval-workflow'],
  ['label' => 'Leave Entitlements', 'href' => BASE_URL . '/modules/admin/leave-entitlements'],
  ['label' => 'Leave Defaults', 'href' => BASE_URL . '/modules/admin/leave-defaults'],
];

$adminToolCards = [
  [
    'title' => 'Benefits & Deductions Configuration',
    'description' => 'Configure benefit templates, contribution rates, tax rules, and shift-based allowances.',
    'href' => BASE_URL . '/modules/admin/compensation',
    'availability' => 'Admins & HR',
    'icon' => 'coins',
  ],
  [
    'title' => 'Approval Workflow',
    'description' => 'Design payroll approval routing per branch or scope.',
    'href' => BASE_URL . '/modules/admin/approval-workflow',
    'availability' => 'Admins & HR',
    'icon' => 'flow',
  ],
  [
    'title' => 'Leave Entitlements',
    'description' => 'Review employee balances and historical leave usage.',
    'href' => BASE_URL . '/modules/admin/leave-entitlements',
    'availability' => 'Admins & HR',
    'icon' => 'calendar-search',
  ],
  [
    'title' => 'Leave Defaults',
    'description' => 'Maintain global and department-specific leave quotas.',
    'href' => BASE_URL . '/modules/admin/leave-defaults',
    'availability' => 'Admins & HR',
    'icon' => 'sliders',
  ],
  [
    'title' => 'Work Schedules',
    'description' => 'Define work schedule templates and assign them to employees.',
    'href' => BASE_URL . '/modules/admin/work-schedules/index',
    'availability' => 'Admins & HR',
    'icon' => 'clock',
  ],
  [
    'title' => 'Overtime Management',
    'description' => 'Review and approve employee overtime requests before payroll inclusion.',
    'href' => BASE_URL . '/modules/overtime/index',
    'availability' => 'Admins & HR',
    'icon' => 'clock-play',
  ],
  [
    'title' => 'BIR Reports',
    'description' => 'Generate Form 2316, 1604-C Alphalist, and monthly statutory remittance reports.',
    'href' => BASE_URL . '/modules/admin/bir-reports/index',
    'availability' => 'Admins & HR',
    'icon' => 'file-chart',
  ],
  [
    'title' => 'Data Corrections Review',
    'description' => 'Review and approve employee data correction requests (RA 10173 compliance).',
    'href' => BASE_URL . '/modules/admin/corrections/index',
    'availability' => 'Admins & HR',
    'icon' => 'edit-check',
  ],
  [
    'title' => 'Privacy & Compliance',
    'description' => 'Manage consent compliance, data erasure requests, and RA 10173 obligations.',
    'href' => BASE_URL . '/modules/admin/privacy/index',
    'availability' => 'Admins & HR',
    'icon' => 'shield-check',
  ],
];

$quickLinks = [
    [
        'label' => 'Employee Directory',
        'description' => 'Search and manage employee records.',
        'href' => BASE_URL . '/modules/employees/index',
    ],
    [
        'label' => 'Attendance Logs',
        'description' => 'Monitor attendance submissions and corrections.',
        'href' => BASE_URL . '/modules/attendance/index',
    ],
    [
        'label' => 'Leave Calendar',
        'description' => 'See upcoming leaves across the organization.',
        'href' => BASE_URL . '/modules/leave/index',
    ],
    [
        'label' => 'System Management',
        'description' => 'Monitor system health, logs, database, and configuration.',
        'href' => BASE_URL . '/modules/admin/system',
    ],
  [
    'label' => 'Work Schedules',
    'description' => 'Configure schedules that power employee profiles and attendance.',
    'href' => BASE_URL . '/modules/admin/work-schedules/index',
  ],
  [
    'label' => 'BIR Reports',
    'description' => 'Generate tax certificates and statutory remittance reports.',
    'href' => BASE_URL . '/modules/admin/bir-reports/index',
  ],
  [
    'label' => 'Privacy & Compliance',
    'description' => 'RA 10173 compliance dashboard, consent tracking, erasure requests.',
    'href' => BASE_URL . '/modules/admin/privacy/index',
  ],
];

$systemLogPulse = [];
try {
    $stmtPulse = $pdo->query('SELECT code, module, message, created_at FROM system_logs ORDER BY created_at DESC LIMIT 5');
    $systemLogPulse = $stmtPulse ? $stmtPulse->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    sys_log('ADMIN-DASH-SYSTEM-PULSE', 'Failed loading system pulse entries: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Compact Header -->
  <div class="rounded-xl bg-gradient-to-br from-slate-900 via-indigo-900 to-blue-900 p-6 text-white shadow-lg">
    <div class="mb-4 flex items-start justify-between">
      <div>
        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white/75">
          HR Admin
        </div>
        <h1 class="text-2xl font-semibold">Coordinate HR operations from one hub.</h1>
        <p class="mt-1 text-sm text-white/70">Keep payroll, leave programs, and compliance tasks moving with focused tools and dedicated configuration modules.</p>
      </div>
    </div>
    
    <!-- Inline Stats -->
    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Last sign in</div>
        <div class="mt-1 text-sm font-semibold"><?= htmlspecialchars($lastLoginDisplay) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">System events (24h)</div>
        <div class="mt-1 text-sm font-semibold"><?= number_format($dashboardMetrics['recentSystemEvents']) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Active approvers</div>
        <div class="mt-1 text-sm font-semibold"><?= (int)$approverStats['active'] ?> / <?= (int)$approverStats['total'] ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Payroll runs open</div>
        <div class="mt-1 text-sm font-semibold"><?= number_format($dashboardMetrics['openPayrollPeriods']) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Pending leave</div>
        <div class="mt-1 text-sm font-semibold"><?= number_format($dashboardMetrics['pendingLeaveRequests']) ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($adminToolCards)): ?>
  <section class="space-y-3">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Configuration Modules</h2>
      <span class="text-xs text-gray-400"><?= count($adminToolCards) ?> modules</span>
    </div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($adminToolCards as $card): ?>
        <a href="<?= htmlspecialchars($card['href']) ?>" class="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-lg" data-no-loader>
          <div class="flex items-start justify-between gap-3">
            <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700">
              <?php switch ($card['icon']) {
                case 'coins': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="7" rx="6" ry="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 7v6c0 1.66 2.69 3 6 3s6-1.34 6-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 10c0 1.66 2.69 3 6 3s6-1.34 6-3" /></svg>
                  <?php break;
                case 'flow': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h6a3 3 0 010 6H9" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 13l2-2-2-2" /><path stroke-linecap="round" stroke-linejoin="round" d="M19 17h-6a3 3 0 01-3-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11l-2 2 2 2" /></svg>
                  <?php break;
                case 'calendar-search': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4V3m10 1V3" /><rect x="4" y="5" width="16" height="15" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M4 10h16" /><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 17.5L21 21" /><circle cx="14.5" cy="16" r="2.5" /></svg>
                  <?php break;
                case 'sliders': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21v-7" /><path stroke-linecap="round" stroke-linejoin="round" d="M4 10V3" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 8V3" /><path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-5" /><path stroke-linecap="round" stroke-linejoin="round" d="M20 12V3" /><circle cx="4" cy="13" r="2" /><circle cx="12" cy="11" r="2" /><circle cx="20" cy="9" r="2" /></svg>
                  <?php break;
                case 'clock': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                  <?php break;
                case 'clock-play': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /><path stroke-linecap="round" stroke-linejoin="round" d="M10 16l5-3-5-3v6z" /></svg>
                  <?php break;
                case 'file-chart': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 18v-4m4 4v-6m4 6v-2" /></svg>
                  <?php break;
                case 'edit-check': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" /><path stroke-linecap="round" stroke-linejoin="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                  <?php break;
                case 'shield-check': ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                  <?php break;
                default: ?>
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87" /><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75" /></svg>
              <?php } ?>
            </div>
            <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars($card['availability']) ?></span>
          </div>
          <h3 class="mt-4 text-lg font-semibold text-gray-900 transition group-hover:text-indigo-600"><?= htmlspecialchars($card['title']) ?></h3>
          <p class="mt-2 text-sm text-gray-600"><?= htmlspecialchars($card['description']) ?></p>
          <div class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-indigo-600">
            <span>Open module</span>
            <span class="transition group-hover:translate-x-0.5">→</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($metricCards)): ?>
  <section class="space-y-3">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Operational Snapshot</h2>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($metricCards as $metric): ?>
        <?php
          $accentKey = $metric['accent'] ?? 'indigo';
          $accent = $metricAccentStyles[$accentKey] ?? $metricAccentStyles['indigo'];
        ?>
        <a href="<?= htmlspecialchars($metric['href']) ?>" class="group rounded-lg border <?= htmlspecialchars($accent['border']) ?> bg-white p-4 shadow-sm transition hover:shadow-md" data-no-loader>
          <div class="text-[11px] font-semibold uppercase tracking-wider <?= htmlspecialchars($accent['label']) ?>">
            <?= htmlspecialchars($metric['title']) ?>
          </div>
          <p class="mt-2 text-2xl font-semibold text-gray-900"><?= htmlspecialchars($metric['value']) ?></p>
          <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($metric['description']) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="grid gap-4 lg:grid-cols-3">
    <div class="card p-5" id="action-center">
      <div class="mb-1 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">Action Center</h2>
        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">Prioritize today</span>
      </div>
      <p class="text-xs text-gray-500">Top operational items that benefit from an early look.</p>
      <ul class="mt-3 space-y-2">
        <?php foreach ($dashboardActionItems as $item): ?>
          <li class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
            <a class="flex items-start justify-between gap-3" href="<?= htmlspecialchars($item['href']) ?>">
              <div>
                <p class="text-xs font-semibold text-gray-900"><?= htmlspecialchars($item['label']) ?></p>
                <p class="text-[11px] text-gray-500"><?= htmlspecialchars($item['description']) ?></p>
              </div>
              <span class="inline-flex min-w-[2.5rem] justify-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-600"><?= number_format((int)$item['count']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card p-5" id="quick-links">
      <h2 class="mb-1 text-base font-semibold text-gray-900">Quick Links</h2>
      <p class="text-xs text-gray-500">Shortcuts to admin tools you use most.</p>
      <div class="mt-3 grid gap-2 sm:grid-cols-2">
        <?php foreach ($quickLinks as $link): ?>
          <a class="group flex h-full flex-col justify-between rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition hover:border-indigo-300 hover:shadow-md" href="<?= htmlspecialchars($link['href']) ?>" data-no-loader>
            <div>
              <span class="block text-xs font-semibold text-gray-900 transition group-hover:text-indigo-600"><?= htmlspecialchars($link['label']) ?></span>
              <span class="mt-0.5 block text-[11px] text-gray-500"><?= htmlspecialchars($link['description']) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card p-5" id="system-pulse">
      <h2 class="mb-1 text-base font-semibold text-gray-900">System Pulse</h2>
      <p class="text-xs text-gray-500">Latest system events in the audit trail.</p>
      <ul class="mt-3 space-y-2">
        <?php if (!$systemLogPulse): ?>
          <li class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-3 text-xs text-gray-500">No system events logged yet today.</li>
        <?php else: ?>
          <?php foreach ($systemLogPulse as $log): ?>
            <?php
              $logTime = format_datetime_display($log['created_at'] ?? '', true, '');
              $snippet = trim((string)($log['message'] ?? ''));
              if ($snippet !== '') {
                if (function_exists('mb_strlen')) {
                    if (mb_strlen($snippet) > 110) {
                        $snippet = mb_substr($snippet, 0, 107) . '...';
                    }
                } elseif (strlen($snippet) > 110) {
                    $snippet = substr($snippet, 0, 107) . '...';
                }
              }
            ?>
            <li class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
              <div class="flex items-center justify-between text-[11px] text-gray-500">
                <span class="font-mono text-[10px] text-indigo-600"><?= htmlspecialchars($log['code'] ?? 'LOG') ?></span>
                <span><?= htmlspecialchars($logTime) ?></span>
              </div>
              <p class="mt-1.5 text-xs font-medium text-gray-900"><?= htmlspecialchars($log['module'] ?? 'system') ?></p>
              <p class="mt-0.5 text-[11px] text-gray-600" title="<?= htmlspecialchars($log['message'] ?? '') ?>"><?= htmlspecialchars($snippet) ?></p>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
      <div class="mt-3 text-right">
  <a class="inline-flex items-center text-[11px] font-semibold text-indigo-600" href="<?= htmlspecialchars(BASE_URL . '/modules/admin/system/logs') ?>">View system logs<span class="ml-1">&rarr;</span></a>
      </div>
    </div>
  </section>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
