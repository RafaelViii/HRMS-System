<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/utils.php';
$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
// Flash notifications (used in header toast area)
$__success = $_SESSION['__flash']['success'] ?? null;
$__error = $_SESSION['__flash']['error'] ?? null;
// Notifications quick data for header
$unreadCount = 0; $notifRecent = [];
if ($user) {
  try {
    $pdo = get_db_conn();
    ensure_notification_reads($pdo);
    // Count only user-specific unread notifications (global ones are shared and not per-user readable)
    // Unread = (user-specific unread) + (global not yet marked read in notification_reads)
    $stc = $pdo->prepare(
      "SELECT (
          SELECT COUNT(*) FROM notifications n
          WHERE n.user_id = :uid AND n.is_read = FALSE
        ) + (
          SELECT COUNT(*) FROM notifications g
          LEFT JOIN notification_reads r ON r.notification_id = g.id AND r.user_id = :uid
          WHERE g.user_id IS NULL AND r.notification_id IS NULL
        ) AS cnt"
    );
    $stc->execute([':uid' => (int)$user['id']]);
    $unreadCount = (int)($stc->fetchColumn() ?: 0);
    // Recent notifications (global or for user), latest 5, ignoring ones already read globally by this user
    $str = $pdo->prepare(
      "SELECT n.id, n.message, n.created_at
       FROM notifications n
       LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
       WHERE (n.user_id = :uid AND n.is_read = FALSE)
          OR (n.user_id IS NULL AND r.notification_id IS NULL)
       ORDER BY n.id DESC
       LIMIT 5"
    );
    $str->execute([':uid' => (int)$user['id']]);
    $notifRecent = $str->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $unreadCount = 0; $notifRecent = []; }
}
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
function is_active(string $href, string $current): bool {
  // Consider active if exact match or current starts with href and href isn't root only
  if ($href === $current) return true;
  if ($href !== '/' && str_starts_with($current, $href)) return true;
  return false;
}
$sessionMeta = null;
if (!empty($_SESSION['__meta']) && is_array($_SESSION['__meta'])) {
  $sm = $_SESSION['__meta'];
  $sessionMeta = [
    'serverNow' => (int)($sm['server_now'] ?? time()),
    'idleExpiresAt' => (int)($sm['idle_expires_at'] ?? 0),
    'absoluteExpiresAt' => (int)($sm['absolute_expires_at'] ?? 0),
    'idleTimeout' => (int)($sm['idle_timeout'] ?? HRMS_SESSION_IDLE_TIMEOUT),
    'absoluteTimeout' => (int)($sm['absolute_timeout'] ?? HRMS_SESSION_ABSOLUTE_TIMEOUT),
  ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/resources/logo.jpg" />
  <link rel="shortcut icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/resources/logo.jpg" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&display=swap" rel="stylesheet" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css" />
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css" />
  <!-- Charts library (global) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script>
    window.__baseUrl = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
    // Expose lightweight access levels (at least for common modules) for client UX hints
    window.__userAccess = window.__userAccess || {};
    <?php if ($user): $uid = (int)($user['id'] ?? 0); ?>
      window.__userAccess['employees'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'employees') ?? 'none') ?>';
      window.__userAccess['hr_core.employees'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'employees') ?? 'none') ?>';
      window.__userAccess['departments'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'departments') ?? 'none') ?>';
      window.__userAccess['hr_core.departments'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'departments') ?? 'none') ?>';
      window.__userAccess['positions'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'positions') ?? 'none') ?>';
      window.__userAccess['hr_core.positions'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'positions') ?? 'none') ?>';
      window.__userAccess['attendance'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'attendance', 'attendance_records') ?? 'none') ?>';
      window.__userAccess['leave'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'leave', 'leave_approval') ?? 'none') ?>';
      window.__userAccess['payroll'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'payroll', 'payroll_runs') ?? 'none') ?>';
      window.__userAccess['documents'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'documents', 'memos') ?? 'none') ?>';
      window.__userAccess['recruitment'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'recruitment') ?? 'none') ?>';
      window.__userAccess['hr_core.recruitment'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'hr_core', 'recruitment') ?? 'none') ?>';
      window.__userAccess['audit'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'system', 'audit_logs') ?? 'none') ?>';
      window.__userAccess['settings'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'system', 'system_settings') ?? 'none') ?>';
      window.__userAccess['user_management.user_accounts'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'user_management', 'user_accounts') ?? 'none') ?>';
      window.__userAccess['inventory'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'inventory', 'inventory_items') ?? 'none') ?>';
      window.__userAccess['inventory.inventory_items'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'inventory', 'inventory_items') ?? 'none') ?>';
      window.__userAccess['inventory.pos_transactions'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'inventory', 'pos_transactions') ?? 'none') ?>';
      window.__userAccess['inventory.inventory_reports'] = '<?= htmlspecialchars(get_user_effective_access($uid, 'inventory', 'inventory_reports') ?? 'none') ?>';
    <?php endif; ?>
  </script>
  <?php if ($user): ?>
  <script>
    window.__keepaliveCfg = {
      url: '<?= BASE_URL ?>/modules/auth/keepalive.php',
      token: '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>',
      idleMs: <?= 10800 * 1000 ?>,
      pingMs: 60000
    };
  </script>
  <?php if ($sessionMeta !== null): ?>
  <script>
    window.__sessionMeta = <?= json_encode($sessionMeta, JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <?php endif; ?>
  <?php endif; ?>
</head>
<body class="bg-slate-50 min-h-screen font-sans antialiased">
  <!-- Global Loading Overlay -->
  <div id="appLoader" class="fixed inset-0 bg-white/80 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="loader-spinner" aria-label="Loading"></div>
  </div>
  <div class="flex min-h-screen" id="layoutRoot">
    <div id="notifDetailModal" class="fixed inset-0 z-[120] hidden items-center justify-center px-4">
      <div class="absolute inset-0 bg-slate-900/50" data-detail-dismiss></div>
      <div class="relative w-full max-w-xl overflow-hidden rounded-2xl border bg-white shadow-2xl">
        <div class="flex items-center justify-between px-5 py-4 border-b bg-slate-50">
          <h2 class="text-lg font-semibold text-slate-900">Notification</h2>
          <button class="text-gray-400 hover:text-gray-600" type="button" data-detail-dismiss aria-label="Close notification">&times;</button>
        </div>
        <div class="px-5 py-5 space-y-4">
          <div>
            <h3 class="text-base font-semibold text-slate-900" id="notifDetailTitle">-</h3>
            <div class="mt-1 text-xs uppercase tracking-wide text-slate-400" id="notifDetailTimestamp">-</div>
          </div>
          <div id="notifDetailBodyWrap">
            <div class="text-sm leading-relaxed text-slate-700 whitespace-pre-wrap" id="notifDetailBody">-</div>
          </div>
          <div id="notifMemoPreview" data-notif-memo class="hidden">
            <div class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-emerald-300" data-notif-memo-card role="button" tabindex="0" aria-label="Open memo details">
              <div class="relative">
                <div class="absolute inset-0 hidden items-center justify-center bg-white/80" data-notif-memo-loading>
                  <span class="text-sm font-medium text-slate-500">Loading memo preview…</span>
                </div>
                <div class="space-y-4 px-5 py-5" data-notif-memo-content>
                  <div class="space-y-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.25em] text-emerald-500" data-notif-memo-code></p>
                    <h3 class="text-lg font-semibold text-slate-900" data-notif-memo-title></h3>
                    <p class="text-xs uppercase tracking-wide text-slate-400" data-notif-memo-meta></p>
                  </div>
                  <p class="text-sm leading-relaxed text-slate-700" data-notif-memo-body></p>
                  <div class="grid gap-3" data-notif-memo-attachments></div>
                  <p class="hidden text-xs text-slate-400" data-notif-memo-empty>There are no attachments for this memo.</p>
                </div>
                <div class="hidden px-5 py-4 text-sm font-medium text-red-600" data-notif-memo-error>We couldn't load this memo preview. Click to open the full memo instead.</div>
              </div>
              <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-emerald-600">
                <span>Click to open full memo</span>
                <svg class="h-4 w-4 transition group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 12h14" /></svg>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Sidebar -->
  <aside id="sidebar" class="sidebar w-64 hidden md:flex md:flex-col transition-all duration-300">
      <div class="sidebar-brand">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          <div class="brand-icon">H</div>
          <div class="min-w-0 sidebar-label">
            <div class="brand-text">HydroMed</div>
            <div class="brand-sub"><?= htmlspecialchars(COMPANY_NAME) ?></div>
          </div>
        </div>
      </div>
      <div class="sidebar-collapse-row">
        <button id="btnCollapse" class="sidebar-collapse-btn" title="Collapse sidebar">
          <svg class="collapse-chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
          <svg class="collapse-hamburger w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
          <span class="sidebar-label text-[.65rem] text-slate-400 ml-1">Collapse</span>
        </button>
      </div>
  <nav class="px-2 py-3 space-y-0.5 text-sm sidebar-nav">
        <?php
          $role = strtolower((string)($user['role'] ?? ''));
          $isAdminRole = $role === 'admin';
          $isHrRole = $role === 'hr';
          $isEmployeeRole = $role === 'employee';
          $showEmployeePortal = $user && $isEmployeeRole;
          $uid = (int)($user['id'] ?? 0);
          // Check access using correct domain/resource keys from permissions catalog
          $canAttendanceMgmt = $user && (get_user_effective_access($uid, 'attendance', 'attendance_records') ?? 'none') !== 'none';
          // Check for any leave management permission (approval, balances, or config)
          $leaveApproval = get_user_effective_access($uid, 'leave', 'leave_approval') ?? 'none';
          $leaveBalances = get_user_effective_access($uid, 'leave', 'leave_balances') ?? 'none';
          $leaveConfig = get_user_effective_access($uid, 'leave', 'leave_config') ?? 'none';
          $canLeaveMgmt = $user && ($leaveApproval !== 'none' || $leaveBalances !== 'none' || $leaveConfig !== 'none');
          $canPayrollMgmt = $user && (get_user_effective_access($uid, 'payroll', 'payroll_runs') ?? 'none') !== 'none';
          $canEmployees = $user && (get_user_effective_access($uid, 'hr_core', 'employees') ?? 'none') !== 'none';
          $canDepartments = $user && (get_user_effective_access($uid, 'hr_core', 'departments') ?? 'none') !== 'none';
          $canPositions = $user && (get_user_effective_access($uid, 'hr_core', 'positions') ?? 'none') !== 'none';
          $canDocuments = $user && (get_user_effective_access($uid, 'documents', 'memos') ?? 'none') !== 'none';
          $canRecruitment = $user && (get_user_effective_access($uid, 'hr_core', 'recruitment') ?? 'none') !== 'none';
          $canAudit = $user && (get_user_effective_access($uid, 'system', 'audit_logs') ?? 'none') !== 'none';
          $canAuditTrail = $user && user_has_access($uid, 'system', 'audit_logs', 'read');
          $canInventoryItems = $user && (get_user_effective_access($uid, 'inventory', 'inventory_items') ?? 'none') !== 'none';
          $canPOS = $user && (get_user_effective_access($uid, 'inventory', 'pos_transactions') ?? 'none') !== 'none';
          $canInventoryReports = $user && (get_user_effective_access($uid, 'inventory', 'inventory_reports') ?? 'none') !== 'none';
          $canPOSWrite = $user && user_has_access($uid, 'inventory', 'pos_transactions', 'write');
          $canInventoryManage = $user && user_has_access($uid, 'inventory', 'inventory_items', 'manage');
          $canPrintServer = $user && (get_user_effective_access($uid, 'inventory', 'print_server') ?? 'none') !== 'none';
          $canOvertimeMgmt = $user && (get_user_effective_access($uid, 'payroll', 'overtime') ?? 'none') !== 'none';
          $canBIRReports = $user && (get_user_effective_access($uid, 'reports', 'bir_reports') ?? 'none') !== 'none';
          $canDataCorrections = $user && (get_user_effective_access($uid, 'compliance', 'data_corrections') ?? 'none') !== 'none';
          $canPrivacyConsents = $user && (get_user_effective_access($uid, 'compliance', 'privacy_consents') ?? 'none') !== 'none';
          $canDataErasure = $user && (get_user_effective_access($uid, 'compliance', 'data_erasure') ?? 'none') !== 'none';
          $hasComplianceAdmin = ($canBIRReports || $canDataCorrections || $canPrivacyConsents || $canDataErasure);
          $hasTimeAttendance = ($canAttendanceMgmt || $canOvertimeMgmt || $canLeaveMgmt || $canPayrollMgmt);
          $hasPeopleOrg = ($canEmployees || $canDepartments || $canPositions || $canDocuments || $canRecruitment);
          $hasInventory = ($canInventoryItems || $canInventoryReports);
          $hasSalesPOS = ($canPOS);
          $canClinicRecords = $user && (get_user_effective_access($uid, 'healthcare', 'clinic_records') ?? 'none') !== 'none';
          $canClinicWrite = $user && user_has_access($uid, 'healthcare', 'clinic_records', 'write');
          $canClinicManage = $user && user_has_access($uid, 'healthcare', 'clinic_records', 'manage');
          $hasHealthcare = ($canClinicRecords);
          $hasAdminTools = ($isAdminRole || $isHrRole || $canAudit || $canAuditTrail || $canPrintServer || $hasComplianceAdmin);
        ?>

  <?php if ($showEmployeePortal): ?>
        <div class="nav-group" data-group="my-workspace">
          <button type="button" class="group-label px-3 py-1 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="my-workspace" aria-expanded="true">
            <span>My Workspace</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php $href = BASE_URL . '/index'; $active = is_active($href, $reqPath) || is_active(BASE_URL . '/', $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Home">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7m-9 14V9m0 10h6a2 2 0 002-2v-5m-8 7H7a2 2 0 01-2-2v-5"/></svg>
              </span>
              <span class="sidebar-label">Home</span>
            </a>
            <?php $href = BASE_URL . '/modules/attendance/my'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="My Attendance">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7l2 2 4-4"/></svg>
              </span>
              <span class="sidebar-label">My Attendance</span>
            </a>
            <?php $href = BASE_URL . '/modules/payroll/my_payslips'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="My Payslips">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-3 0-5 1.5-5 4s2 4 5 4 5-1.5 5-4-2-4-5-4zm0-5v5m0 8v5"/></svg>
              </span>
              <span class="sidebar-label">My Payslips</span>
            </a>
            <?php
            $leaveIndexHref = BASE_URL . '/modules/leave/index';
            $active = is_active($leaveIndexHref, $reqPath)
              || is_active(BASE_URL . '/modules/leave/create', $reqPath)
              || is_active(BASE_URL . '/modules/leave/view', $reqPath);
            ?>
            <a href="<?= $leaveIndexHref ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Leaves">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 7.5L3 10l7.5 2.5L21 12l-10.5 4.5V21l3-3m-3-10.5V3"/></svg>
              </span>
              <span class="sidebar-label">Leaves</span>
            </a>
            <?php
            $otIndexHref = BASE_URL . '/modules/overtime/create';
            $active = is_active($otIndexHref, $reqPath)
              || is_active(BASE_URL . '/modules/overtime/index', $reqPath);
            ?>
            <a href="<?= $otIndexHref ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Overtime Request">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </span>
              <span class="sidebar-label">Overtime Request</span>
            </a>
          </div>
        </div>
        <div class="nav-group" data-group="docs-comms">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="docs-comms" aria-expanded="true">
            <span>Documents &amp; Comms</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php $href = BASE_URL . '/modules/documents/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Personal Documents">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h7l4 4v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
              </span>
              <span class="sidebar-label">Personal Documents</span>
            </a>
            <?php $href = BASE_URL . '/modules/memos/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Memos">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 5h10M7 9h10M7 13h6m-7 6h12a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              </span>
              <span class="sidebar-label">Memos</span>
            </a>
            <?php $href = BASE_URL . '/modules/compliance/corrections/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Data Corrections">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </span>
              <span class="sidebar-label">Data Corrections</span>
            </a>
            <?php $href = BASE_URL . '/modules/compliance/privacy/consent'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Privacy Settings">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              </span>
              <span class="sidebar-label">Privacy Settings</span>
            </a>
            <?php $href = BASE_URL . '/modules/compliance/data-export'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Download My Data">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
              </span>
              <span class="sidebar-label">Download My Data</span>
            </a>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($user && !$showEmployeePortal): ?>
          <?php $href = BASE_URL . '/index'; $active = is_active($href, $reqPath) || is_active(BASE_URL . '/', $reqPath); ?>
          <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Home">
            <span class="nav-icon">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7m-9 14V9m0 10h6a2 2 0 002-2v-5m-8 7H7a2 2 0 01-2-2v-5"/></svg>
            </span>
            <span class="sidebar-label">Home</span>
          </a>
        <?php endif; ?>

        <?php if ($hasTimeAttendance): ?>
        <div class="nav-group" data-group="time-attendance">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="time-attendance" aria-expanded="true">
            <span>Time, Attendance &amp; Payroll</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($canAttendanceMgmt): $href = BASE_URL . '/modules/attendance/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Attendance Management" data-module="attendance">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7l2 2 4-4"/></svg>
              </span>
              <span class="sidebar-label">Attendance Management</span>
            </a>
            <?php endif; ?>
            <?php if ($canOvertimeMgmt):
              $href = BASE_URL . '/modules/overtime/admin';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/overtime/index', $reqPath)
                || is_active(BASE_URL . '/modules/overtime/approve', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Overtime Management" data-module="overtime">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </span>
              <span class="sidebar-label">Overtime Management</span>
            </a>
            <?php endif; ?>
            <?php if ($canLeaveMgmt): $href = BASE_URL . '/modules/leave/admin'; $active = is_active($href, $reqPath) || is_active(BASE_URL . '/modules/leave/view', $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Leave Management" data-module="leave">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 7.5L3 10l7.5 2.5L21 12l-10.5 4.5V21l3-3m-3-10.5V3"/></svg>
              </span>
              <span class="sidebar-label">Leave Management</span>
            </a>
            <?php endif; ?>
            <?php if ($canPayrollMgmt): $href = BASE_URL . '/modules/payroll/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Payroll Management" data-module="payroll">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a5 5 0 10-10 0v2M5 9h14l1 10H4L5 9zm5 5h4"/></svg>
              </span>
              <span class="sidebar-label">Payroll Management</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasPeopleOrg): ?>
        <div class="nav-group" data-group="company">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="company" aria-expanded="true">
            <span>People &amp; Organization</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($canEmployees): $href = BASE_URL . '/modules/employees/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Employees" data-module="employees">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M15 11a4 4 0 10-6 0m6 0a4 4 0 11-6 0"/></svg>
              </span>
              <span class="sidebar-label">Employees</span>
            </a>
            <?php endif; ?>
            <?php if ($canRecruitment):
              $href = BASE_URL . '/modules/recruitment/index';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/recruitment/view', $reqPath)
                || is_active(BASE_URL . '/modules/recruitment/create', $reqPath)
                || is_active(BASE_URL . '/modules/recruitment/templates', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Recruitment" data-module="recruitment">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19a7 7 0 0114 0"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 8v6m3-3h-6"/></svg>
              </span>
              <span class="sidebar-label">Recruitment</span>
            </a>
            <?php endif; ?>
            <?php if ($canDepartments): $href = BASE_URL . '/modules/departments/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Departments" data-module="departments">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M9 8h6M8 21V5a2 2 0 012-2h4a2 2 0 012 2v16"/></svg>
              </span>
              <span class="sidebar-label">Departments</span>
            </a>
            <?php endif; ?>
            <?php if ($canPositions): $href = BASE_URL . '/modules/positions/index'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Positions" data-module="positions">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 12h4M4 7a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H6a2 2 0 01-2-2V7zm6-4h4a2 2 0 012 2v1H8V5a2 2 0 012-2z"/></svg>
              </span>
              <span class="sidebar-label">Positions</span>
            </a>
            <?php endif; ?>
            <?php if ($canDocuments): $href = BASE_URL . '/modules/memos/index';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/memos/create', $reqPath)
                || is_active(BASE_URL . '/modules/memos/view', $reqPath)
                || is_active(BASE_URL . '/modules/memos/edit', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Memos" data-module="documents">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h7l4 4v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
              </span>
              <span class="sidebar-label">Memos</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasInventory): ?>
        <div class="nav-group" data-group="inventory">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="inventory" aria-expanded="true">
            <span>Inventory</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($canInventoryItems || $canInventoryReports):
              $href = BASE_URL . '/modules/inventory/index';
              $active = is_active($href, $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Inventory Dashboard" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
              </span>
              <span class="sidebar-label">Dashboard</span>
            </a>
            <?php endif; ?>
            <?php if ($canInventoryItems):
              $href = BASE_URL . '/modules/inventory/inventory';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/inventory/item_form', $reqPath)
                || is_active(BASE_URL . '/modules/inventory/item_view', $reqPath)
                || is_active(BASE_URL . '/modules/inventory/bulk_import', $reqPath)
                || is_active(BASE_URL . '/modules/inventory/manual_update', $reqPath)
                || is_active(BASE_URL . '/modules/inventory/movements', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Inventory" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
              </span>
              <span class="sidebar-label">Items</span>
            </a>
            <?php endif; ?>
            <?php if ($canInventoryReports):
              $href = BASE_URL . '/modules/inventory/reports';
              $active = is_active($href, $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Inventory Reports" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
              </span>
              <span class="sidebar-label">Reports</span>
            </a>
            <?php endif; ?>
            <?php if ($canInventoryItems):
              $href = BASE_URL . '/modules/inventory/restock';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/inventory/purchase_orders', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Order Supplies" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
              </span>
              <span class="sidebar-label">Order Supplies</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasSalesPOS): ?>
        <div class="nav-group" data-group="sales-pos">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="sales-pos" aria-expanded="true">
            <span>Sales &amp; POS</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($canPOS):
              $href = BASE_URL . '/modules/inventory/pos';
              $active = is_active($href, $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="POS Terminal" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
              </span>
              <span class="sidebar-label">POS Terminal</span>
            </a>
            <?php endif; ?>
            <?php if ($canPOS):
              $href = BASE_URL . '/modules/inventory/transactions';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/inventory/transaction_view', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Transactions" data-module="inventory">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
              </span>
              <span class="sidebar-label">Transactions</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasHealthcare): ?>
        <div class="nav-group" data-group="healthcare">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="healthcare" aria-expanded="true">
            <span>Healthcare</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($canClinicRecords):
              $href = BASE_URL . '/modules/clinic_records/index';
              $active = is_active($href, $reqPath)
                || is_active(BASE_URL . '/modules/clinic_records/create', $reqPath)
                || is_active(BASE_URL . '/modules/clinic_records/edit', $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Clinic Records" data-module="healthcare">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
              </span>
              <span class="sidebar-label">Clinic Records</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasAdminTools): ?>
        <div class="nav-group" data-group="administration">
          <button type="button" class="group-label px-3 py-1 mt-2 w-full text-[10px] uppercase tracking-wide text-gray-400 flex items-center justify-between" data-group-toggle="administration" aria-expanded="true">
            <span>Administration</span>
            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
          </button>
          <div class="group-sep"></div>
          <div class="group-content space-y-1">
            <?php if ($isAdminRole || $isHrRole): $href = BASE_URL . '/modules/admin/management'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Management Hub">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M23 21v-2a4 4 0 00-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 3.13a4 4 0 010 7.75"/></svg>
              </span>
              <span class="sidebar-label">Management Hub</span>
            </a>
            <?php endif; ?>
            <?php if ($isAdminRole): $href = BASE_URL . '/modules/admin/access-control/index'; $active = is_active($href, $reqPath) || strpos($reqPath, '/access-control/') !== false; ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Access Control">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              </span>
              <span class="sidebar-label">Access Control</span>
            </a>
            <?php endif; ?>
            <?php if ($isAdminRole || $canAudit): $href = BASE_URL . '/modules/admin/system'; $active = is_active($href, $reqPath); ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="System Management">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              </span>
              <span class="sidebar-label">System Management</span>
            </a>
            <?php endif; ?>
            <?php if ($canPrintServer):
              $href = BASE_URL . '/modules/inventory/print_server';
              $active = is_active($href, $reqPath);
            ?>
            <a href="<?= $href ?>" class="nav-item spa <?= $active ? 'active' : '' ?>" data-tip="Print Server">
              <span class="nav-icon">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
              </span>
              <span class="sidebar-label">Print Server</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </nav>
    </aside>
    <!-- Content -->
    <div class="flex-1 flex flex-col">
      <header class="top-bar">
        <div class="flex items-center gap-3">
          <button class="md:hidden p-1.5 rounded-lg hover:bg-gray-100 text-gray-500" onclick="document.getElementById('mnav').classList.toggle('hidden')" title="Menu">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <div class="page-title"><?= htmlspecialchars($pageTitle) ?></div>
        </div>
        <div class="flex items-center gap-4 relative">
          <div id="headerClock" class="hidden sm:flex items-center text-sm text-gray-600 select-none" title="Current date & time"></div>
          <?php if ($user): ?>
          <!-- Notifications bell -->
          <div class="relative">
            <button
              id="btnNotif"
              class="relative p-2 rounded hover:bg-gray-100"
              title="Notifications"
              data-feed-url="<?= BASE_URL ?>/modules/notifications/feed"
              data-mark-all-url="<?= BASE_URL ?>/modules/notifications/mark_all_read"
              data-mark-url="<?= BASE_URL ?>/modules/notifications/mark_read"
              data-csrf="<?= htmlspecialchars(csrf_token()) ?>"
              data-view-all="<?= BASE_URL ?>/modules/notifications/index"
            >
              <!-- bell icon -->
              <svg class="w-6 h-6 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4a2 2 0 10-4 0v1.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
              <?php if ($unreadCount > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 bg-red-600 text-white text-[10px] leading-4 px-1 rounded-full min-w-[18px] text-center" data-notif-badge>
                  <?= $unreadCount > 99 ? '99+' : (int)$unreadCount ?>
                </span>
              <?php else: ?>
                <span class="absolute -top-0.5 -right-0.5 bg-red-600 text-white text-[10px] leading-4 px-1 rounded-full min-w-[18px] text-center hidden" data-notif-badge></span>
              <?php endif; ?>
            </button>
            <div id="notifDropdown" class="hidden absolute right-0 top-full mt-3 w-[min(360px,calc(100vw-2rem))] sm:w-[420px] z-50">
              <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50">
                  <h2 class="text-base font-semibold text-slate-900">Notifications</h2>
                  <button id="notifMarkAll" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 hover:underline disabled:text-slate-400 disabled:hover:no-underline disabled:cursor-not-allowed" type="button" disabled>Mark all as read</button>
                </div>
                <div id="notifList" class="max-h-96 overflow-y-auto bg-white" data-state="idle">
                  <div id="notifItems" class="divide-y divide-slate-100"></div>
                  <div id="notifEmpty" class="px-6 py-10 text-center text-sm text-slate-500 hidden">You're all caught up. We'll let you know when there's something new.</div>
                </div>
                <div class="px-4 py-3 border-t bg-white text-center">
                  <a id="notifViewAll" class="text-sm font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/notifications/index">View all</a>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($user): ?>
          <div class="relative">
            <button id="btnUser" class="inline-flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
              <span class="user-avatar">
                <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
              </span>
              <span class="hidden sm:flex flex-col items-start">
                <span class="text-sm font-medium text-gray-800 leading-4"><?= htmlspecialchars($user['name']) ?></span>
                <span class="text-[10px] text-gray-400 leading-3 capitalize"><?= htmlspecialchars($user['role']) ?></span>
              </span>
              <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div id="userMenu" class="absolute right-0 mt-2 w-64 bg-white border border-slate-200 rounded-2xl shadow-xl shadow-slate-200/50 hidden overflow-hidden z-50">
              <div class="px-4 py-3 border-b border-slate-100 bg-slate-50/60">
                <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($user['name']) ?></div>
                <div class="text-xs text-slate-400 capitalize"><?= htmlspecialchars($user['role']) ?></div>
              </div>
              <div class="py-1.5">
                <div class="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-400 font-semibold">Self Service</div>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/index">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7m-9 14V9m0 10h6a2 2 0 002-2v-5m-8 7H7a2 2 0 01-2-2v-5"/></svg>
                  Home
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/attendance/my">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7l2 2 4-4"/></svg>
                  My Attendance
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/payroll/my_payslips">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-3 0-5 1.5-5 4s2 4 5 4 5-1.5 5-4-2-4-5-4zm0-5v5m0 8v5"/></svg>
                  My Payslips
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/documents/index">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4h7l4 4v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                  Personal Documents
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/leave/index">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 7.5L3 10l7.5 2.5L21 12l-10.5 4.5V21l3-3m-3-10.5V3"/></svg>
                  Leaves
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/memos/index">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 5h10M7 9h10M7 13h6m-7 6h12a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                  Memo
                </a>
              </div>
              <div class="border-t border-slate-100 py-1.5">
                <div class="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-400 font-semibold">Account</div>
                <a class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors" href="<?= BASE_URL ?>/modules/auth/account">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                  Account Settings
                </a>
                <a class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-medium text-red-500 hover:bg-red-50 hover:text-red-600 transition-colors" href="<?= BASE_URL ?>/logout">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                  Logout
                </a>
              </div>
            </div>
            <!-- Floating overlay notifications anchored below the user profile button -->
            <div id="notifHost" class="absolute right-0 top-full mt-2 z-[70] flex flex-col items-end gap-2 pointer-events-none">
              <?php if ($__success): ?>
                <div class="notif pointer-events-auto shadow-lg rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-3 py-2 text-sm flex items-center justify-between min-w-[280px] max-w-[520px]" role="alert" data-kind="success" data-autoclose="1" data-timeout="5000">
                  <div class="pr-2"><?= htmlspecialchars($__success) ?></div>
                  <button class="ml-4 text-emerald-700/70 hover:text-emerald-900" data-close aria-label="Close notification">&times;</button>
                </div>
              <?php endif; ?>
              <?php if ($__error): ?>
                <div class="notif pointer-events-auto shadow-lg rounded-lg border border-red-200 bg-red-50 text-red-800 px-3 py-2 text-sm flex items-center justify-between min-w-[280px] max-w-[520px]" role="alert" data-kind="error" data-autoclose="1">
                  <div class="pr-2"><?= htmlspecialchars($__error) ?></div>
                  <button class="ml-4 text-red-700/70 hover:text-red-900" data-close aria-label="Close notification">&times;</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </header>
      <!-- Mobile nav -->
      <div id="mnav" class="md:hidden hidden transition-all border-b border-slate-200 bg-white">
        <nav class="px-3 py-3 space-y-0.5 text-sm">
          <?php
            $roleMobile = strtolower((string)($user['role'] ?? ''));
            $isAdminRoleMobile = $roleMobile === 'admin';
            $isHrRoleMobile = $roleMobile === 'hr';
            $isEmployeeRoleMobile = $roleMobile === 'employee';
            $showEmployeePortalMobile = $user && $isEmployeeRoleMobile;
            $uidMobile = (int)($user['id'] ?? 0);
            // Check access using correct domain/resource keys from permissions catalog
            $canAttendanceMobile = $user && (get_user_effective_access($uidMobile, 'attendance', 'attendance_records') ?? 'none') !== 'none';
            // Check for any leave management permission (approval, balances, or config)
            $leaveApprovalMobile = get_user_effective_access($uidMobile, 'leave', 'leave_approval') ?? 'none';
            $leaveBalancesMobile = get_user_effective_access($uidMobile, 'leave', 'leave_balances') ?? 'none';
            $leaveConfigMobile = get_user_effective_access($uidMobile, 'leave', 'leave_config') ?? 'none';
            $canLeaveMobile = $user && ($leaveApprovalMobile !== 'none' || $leaveBalancesMobile !== 'none' || $leaveConfigMobile !== 'none');
            $canPayrollMobile = $user && (get_user_effective_access($uidMobile, 'payroll', 'payroll_runs') ?? 'none') !== 'none';
            $canEmployeesMobile = $user && (get_user_effective_access($uidMobile, 'hr_core', 'employees') ?? 'none') !== 'none';
            $canDepartmentsMobile = $user && (get_user_effective_access($uidMobile, 'hr_core', 'departments') ?? 'none') !== 'none';
            $canPositionsMobile = $user && (get_user_effective_access($uidMobile, 'hr_core', 'positions') ?? 'none') !== 'none';
            $canDocumentsMobile = $user && (get_user_effective_access($uidMobile, 'documents', 'memos') ?? 'none') !== 'none';
            $canRecruitmentMobile = $user && (get_user_effective_access($uidMobile, 'hr_core', 'recruitment') ?? 'none') !== 'none';
            $canAuditMobile = $user && (get_user_effective_access($uidMobile, 'system', 'audit_logs') ?? 'none') !== 'none';
            $canOvertimeMgmtMobile = $user && (get_user_effective_access($uidMobile, 'payroll', 'overtime') ?? 'none') !== 'none';
            $hasHrOpsMobile = ($canAttendanceMobile || $canLeaveMobile || $canOvertimeMgmtMobile);
            $hasTimeAttMobile = ($hasHrOpsMobile || $canPayrollMobile);
            $hasPeopleOrgMobile = ($canEmployeesMobile || $canDepartmentsMobile || $canPositionsMobile || $canDocumentsMobile || $canRecruitmentMobile);
            $canInventoryItemsMobile = $user && (get_user_effective_access($uidMobile, 'inventory', 'inventory_items') ?? 'none') !== 'none';
            $canPOSMobile = $user && (get_user_effective_access($uidMobile, 'inventory', 'pos_transactions') ?? 'none') !== 'none';
            $canInventoryReportsMobile = $user && (get_user_effective_access($uidMobile, 'inventory', 'inventory_reports') ?? 'none') !== 'none';
            $canPrintServerMobile = $user && (get_user_effective_access($uidMobile, 'inventory', 'print_server') ?? 'none') !== 'none';
            $hasInventoryMobile = ($canInventoryItemsMobile || $canInventoryReportsMobile);
            $hasSalesPOSMobile = ($canPOSMobile);
            $canClinicRecordsMobile = $user && (get_user_effective_access($uidMobile, 'healthcare', 'clinic_records') ?? 'none') !== 'none';
            $hasHealthcareMobile = ($canClinicRecordsMobile);
            $hasAdminMobile = ($isAdminRoleMobile || $isHrRoleMobile || $canAuditMobile || $canPrintServerMobile);
          ?>

          <?php if ($showEmployeePortalMobile): ?>
          <div class="px-3 pt-2 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">My Workspace</div>
          <a href="<?= BASE_URL ?>/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Home</a>
          <a href="<?= BASE_URL ?>/modules/attendance/my" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">My Attendance</a>
          <a href="<?= BASE_URL ?>/modules/payroll/my_payslips" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">My Payslips</a>
          <a href="<?= BASE_URL ?>/modules/leave/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Leaves</a>
          <a href="<?= BASE_URL ?>/modules/overtime/create" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Overtime Request</a>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Documents &amp; Comms</div>
          <a href="<?= BASE_URL ?>/modules/documents/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Personal Documents</a>
          <a href="<?= BASE_URL ?>/modules/memos/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Memos</a>
          <?php endif; ?>

          <?php if ($showEmployeePortalMobile && ($hasTimeAttMobile || $hasPeopleOrgMobile || $hasInventoryMobile || $hasSalesPOSMobile || $hasAdminMobile)): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($user && !$showEmployeePortalMobile): ?>
          <a href="<?= BASE_URL ?>/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Dashboard</a>
          <?php endif; ?>

          <?php if ($hasTimeAttMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Time, Attendance &amp; Payroll</div>
          <?php if ($canAttendanceMobile): ?>
          <a href="<?= BASE_URL ?>/modules/attendance/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Attendance Management</a>
          <?php endif; ?>
          <?php $canOvertimeMobile = $user && (get_user_effective_access($uidMobile, 'payroll', 'overtime') ?? 'none') !== 'none'; ?>
          <?php if ($canOvertimeMobile): ?>
          <a href="<?= BASE_URL ?>/modules/overtime/admin" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Overtime Management</a>
          <?php endif; ?>
          <?php if ($canLeaveMobile): ?>
          <a href="<?= BASE_URL ?>/modules/leave/admin" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Leave Management</a>
          <?php endif; ?>
          <?php if ($canPayrollMobile): ?>
          <a href="<?= BASE_URL ?>/modules/payroll/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Payroll Management</a>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($hasTimeAttMobile && $hasPeopleOrgMobile): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($hasPeopleOrgMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">People &amp; Organization</div>
          <?php if ($canEmployeesMobile): ?>
          <a href="<?= BASE_URL ?>/modules/employees/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Employees</a>
          <?php endif; ?>
          <?php if ($canRecruitmentMobile): ?>
          <a href="<?= BASE_URL ?>/modules/recruitment/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Recruitment</a>
          <?php endif; ?>
          <?php if ($canDepartmentsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/departments/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Departments</a>
          <?php endif; ?>
          <?php if ($canPositionsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/positions/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Positions</a>
          <?php endif; ?>
          <?php if ($canDocumentsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/memos/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Memos</a>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($hasPeopleOrgMobile && $hasInventoryMobile): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($hasInventoryMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Inventory</div>
          <?php if ($canInventoryItemsMobile || $canInventoryReportsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Dashboard</a>
          <?php endif; ?>
          <?php if ($canInventoryItemsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Items</a>
          <?php endif; ?>
          <?php if ($canInventoryReportsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/reports" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Reports</a>
          <?php endif; ?>
          <?php if ($canInventoryItemsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/restock" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Order Supplies</a>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($hasInventoryMobile && $hasSalesPOSMobile): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($hasSalesPOSMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Sales &amp; POS</div>
          <?php if ($canPOSMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/pos" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">POS Terminal</a>
          <a href="<?= BASE_URL ?>/modules/inventory/transactions" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Transactions</a>
          <?php endif; ?>
          <?php endif; ?>

          <?php if (($hasSalesPOSMobile || $hasInventoryMobile) && $hasHealthcareMobile): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($hasHealthcareMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Healthcare</div>
          <?php if ($canClinicRecordsMobile): ?>
          <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Clinic Records</a>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($hasSalesPOSMobile && $hasAdminMobile): ?>
          <div class="mobile-nav-divider"></div>
          <?php elseif ($hasAdminMobile && ($hasTimeAttMobile || $hasPeopleOrgMobile || $hasInventoryMobile || $hasHealthcareMobile)): ?>
          <div class="mobile-nav-divider"></div>
          <?php endif; ?>

          <?php if ($hasAdminMobile): ?>
          <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-semibold mobile-group-label">Administration</div>
          <?php if ($isAdminRoleMobile || $isHrRoleMobile): ?>
          <a href="<?= BASE_URL ?>/modules/admin/management" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Management Hub</a>
          <?php endif; ?>
          <?php if ($isAdminRoleMobile): ?>
          <a href="<?= BASE_URL ?>/modules/admin/access-control/index" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Access Control</a>
          <?php endif; ?>
          <?php if ($isAdminRoleMobile || $canAuditMobile): ?>
          <a href="<?= BASE_URL ?>/modules/admin/system" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">System Management</a>
          <?php endif; ?>
          <?php if ($canPrintServerMobile): ?>
          <a href="<?= BASE_URL ?>/modules/inventory/print_server" class="block px-3 py-2 rounded-lg hover:bg-slate-50 spa">Print Server</a>
          <?php endif; ?>
          <?php endif; ?>
        </nav>
      </div>
      <main id="appMain" class="relative p-3 sm:p-5 space-y-4 flex-1">
        <div id="contentLoader" class="hidden absolute inset-0 bg-white/70 backdrop-blur-sm flex items-center justify-center z-10">
          <div class="loader-spinner"></div>
        </div>
        <?php
          if (isset($_SESSION['__flash']['success'])) unset($_SESSION['__flash']['success']);
          if (isset($_SESSION['__flash']['error'])) unset($_SESSION['__flash']['error']);
        ?>

