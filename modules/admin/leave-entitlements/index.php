<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('leave', 'leave_entitlements', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pageTitle = 'Leave Entitlements';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// Determine active tab
$activeTab = trim($_GET['tab'] ?? 'balances');
if (!in_array($activeTab, ['balances', 'policies'], true)) {
    $activeTab = 'balances';
}

action_log('leave', 'view_leave_entitlements_admin', 'success', ['tab' => $activeTab]);

if (!function_exists('format_leave_days_input')) {
    function format_leave_days_input($value): string {
        if ($value === null || $value === '') {
            return '';
        }
        $formatted = number_format((float)$value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

$leaveTypes = leave_get_known_types($pdo);
$errors = [];
$success = null;

// Handle leave type management (POST from policies tab)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action'] ?? '');
    
    if ($action === 'create_leave_type' && $activeTab === 'policies') {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $newTypeCode = strtolower(trim($_POST['leave_type_code'] ?? ''));
            $newTypeLabel = trim($_POST['leave_type_label'] ?? '');
            $requireNotice = isset($_POST['require_advance_notice']) && $_POST['require_advance_notice'] === '1';
            $noticeDays = (int)($_POST['advance_notice_days'] ?? 5);
            $notes = trim($_POST['notes'] ?? '');

            // Validation
            if ($newTypeCode === '') {
                $errors[] = 'Leave type code is required.';
            } elseif (!preg_match('/^[a-z0-9_]+$/', $newTypeCode)) {
                $errors[] = 'Leave type code must contain only lowercase letters, numbers, and underscores.';
            } elseif (in_array($newTypeCode, $leaveTypes, true)) {
                $errors[] = 'Leave type code already exists.';
            }
            if ($newTypeLabel === '') {
                $errors[] = 'Leave type label is required.';
            }
            if ($noticeDays < 0 || $noticeDays > 365) {
                $errors[] = 'Advance notice days must be between 0 and 365.';
            }

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    
                    // Add to enum - use prepared statement parameter for safety
                    $safeTypeCode = preg_replace('/[^a-z0-9_]/', '', $newTypeCode);
                    $pdo->exec("ALTER TYPE leave_type ADD VALUE IF NOT EXISTS '{$safeTypeCode}'");
                    
                    // Add to custom types table (create if not exists)
                    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_type_labels (
                        leave_type VARCHAR(50) PRIMARY KEY,
                        label VARCHAR(150) NOT NULL,
                        is_custom BOOLEAN DEFAULT TRUE NOT NULL,
                        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
                    )");
                    
                    $stmt = $pdo->prepare("INSERT INTO leave_type_labels (leave_type, label, is_custom, created_by) VALUES (:code, :label, TRUE, :uid)");
                    $stmt->execute([':code' => $newTypeCode, ':label' => $newTypeLabel, ':uid' => $currentUserId]);
                    
                    // Create default policy
                    $stmt = $pdo->prepare("
                        INSERT INTO leave_filing_policies (leave_type, require_advance_notice, advance_notice_days, notes, updated_by)
                        VALUES (:lt, :req, :days, :notes, :uid)
                    ");
                    $stmt->execute([
                        ':lt' => $newTypeCode,
                        ':req' => $requireNotice ? 't' : 'f',
                        ':days' => $noticeDays,
                        ':notes' => $notes,
                        ':uid' => $currentUserId
                    ]);
                    
                    $pdo->commit();
                    action_log('admin', 'create_leave_type', 'success', ['leave_type' => $newTypeCode, 'label' => $newTypeLabel]);
                    audit('leave_type_created', json_encode(['leave_type' => $newTypeCode, 'label' => $newTypeLabel]));
                    
                    flash_success('New leave type created successfully.');
                    header('Location: ' . BASE_URL . '/modules/admin/leave-entitlements?tab=policies');
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    sys_log('LEAVE_TYPE_CREATE_FAILED', 'Failed to create leave type: ' . $e->getMessage(), [
                        'module' => 'admin',
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]);
                    $errors[] = 'Failed to create leave type: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_leave_type' && $activeTab === 'policies') {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $typeToDelete = strtolower(trim($_POST['leave_type'] ?? ''));
            
            // Check if it's a system type
            $systemTypes = ['sick', 'vacation', 'emergency', 'maternity', 'paternity'];
            if (in_array($typeToDelete, $systemTypes, true)) {
                $errors[] = 'Cannot delete system leave types.';
            } elseif ($typeToDelete === '') {
                $errors[] = 'Leave type is required.';
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Check if any leave requests exist
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM leave_requests WHERE leave_type = :lt");
                    $stmt->execute([':lt' => $typeToDelete]);
                    $requestCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
                    
                    // Check if any leave entitlements exist
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM leave_entitlements WHERE leave_type = :lt");
                    $stmt->execute([':lt' => $typeToDelete]);
                    $entitlementCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
                    
                    if ($requestCount > 0) {
                        $errors[] = "Cannot delete leave type '{$typeToDelete}' because {$requestCount} leave request(s) exist with this type.";
                        $pdo->rollBack();
                    } elseif ($entitlementCount > 0) {
                        $errors[] = "Cannot delete leave type '{$typeToDelete}' because {$entitlementCount} entitlement(s) are configured with this type. Remove them first.";
                        $pdo->rollBack();
                    } else {
                        // Delete from policy table
                        $stmt = $pdo->prepare("DELETE FROM leave_filing_policies WHERE leave_type = :lt");
                        $stmt->execute([':lt' => $typeToDelete]);
                        
                        // Delete from labels table
                        $stmt = $pdo->prepare("DELETE FROM leave_type_labels WHERE leave_type = :lt");
                        $stmt->execute([':lt' => $typeToDelete]);
                        
                        // Note: Cannot remove from enum type in PostgreSQL, but we can mark as unused
                        
                        $pdo->commit();
                        action_log('admin', 'delete_leave_type', 'success', ['leave_type' => $typeToDelete]);
                        audit('leave_type_deleted', json_encode(['leave_type' => $typeToDelete]));
                        
                        flash_success('Leave type deleted successfully.');
                        header('Location: ' . BASE_URL . '/modules/admin/leave-entitlements?tab=policies');
                        exit;
                    }
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    sys_log('LEAVE_TYPE_DELETE_FAILED', 'Failed to delete leave type: ' . $e->getMessage());
                    $errors[] = 'Failed to delete leave type: ' . $e->getMessage();
                }
            }
        }
    }
}

// Reload leave types after any modifications
$leaveTypes = leave_get_known_types($pdo);

// Handle policy updates (POST from policies tab)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeTab === 'policies' && !isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $leaveType = trim($_POST['leave_type'] ?? '');
        $requireNotice = isset($_POST['require_advance_notice']) && $_POST['require_advance_notice'] === '1';
        $noticeDays = (int)($_POST['advance_notice_days'] ?? 5);
        $notes = trim($_POST['notes'] ?? '');

        if ($leaveType === '') {
            $errors[] = 'Leave type is required.';
        } elseif (!in_array($leaveType, $leaveTypes, true)) {
            $errors[] = 'Invalid leave type specified.';
        }
        if ($noticeDays < 0 || $noticeDays > 365) {
            $errors[] = 'Advance notice days must be between 0 and 365.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO leave_filing_policies (leave_type, require_advance_notice, advance_notice_days, notes, updated_by)
                    VALUES (:lt, :req, :days, :notes, :uid)
                    ON CONFLICT (leave_type) 
                    DO UPDATE SET 
                        require_advance_notice = EXCLUDED.require_advance_notice,
                        advance_notice_days = EXCLUDED.advance_notice_days,
                        notes = EXCLUDED.notes,
                        updated_by = EXCLUDED.updated_by,
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    ':lt' => $leaveType,
                    ':req' => $requireNotice ? 't' : 'f',
                    ':days' => $noticeDays,
                    ':notes' => $notes,
                    ':uid' => $currentUserId
                ]);
                
                $pdo->commit();
                action_log('admin', 'update_leave_filing_policy', 'success', ['leave_type' => $leaveType, 'require_notice' => $requireNotice]);
                audit('leave_policy_updated', json_encode(['leave_type' => $leaveType, 'require_advance_notice' => $requireNotice, 'advance_notice_days' => $noticeDays]));
                
                flash_success('Leave filing policy updated successfully.');
                header('Location: ' . BASE_URL . '/modules/admin/leave-entitlements?tab=policies');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                sys_log('LEAVE_POLICY_UPDATE_FAILED', 'Failed to update leave filing policy: ' . $e->getMessage(), [
                    'module' => 'admin',
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]);
                $errors[] = 'Failed to update policy: ' . $e->getMessage();
            }
        }
    }
}

// Fetch policies for policies tab
$policies = [];
$policyMap = [];
$customLeaveTypes = [];
if ($activeTab === 'policies') {
    try {
        $stmt = $pdo->query("
            SELECT 
                id,
                leave_type,
                require_advance_notice,
                advance_notice_days,
                is_active,
                notes,
                updated_at
            FROM leave_filing_policies
            ORDER BY 
                CASE leave_type
                    WHEN 'vacation' THEN 1
                    WHEN 'sick' THEN 2
                    WHEN 'emergency' THEN 3
                    WHEN 'maternity' THEN 4
                    WHEN 'paternity' THEN 5
                    ELSE 99
                END,
                leave_type
        ");
        $policies = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($policies as $p) {
            $policyMap[$p['leave_type']] = $p;
        }
        
        // Fetch custom leave type labels if table exists
        try {
            $stmt = $pdo->query("SELECT leave_type, label, is_custom FROM leave_type_labels");
            if ($stmt) {
                $customLeaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            // Table doesn't exist yet, will be created on first custom type
        }
    } catch (Throwable $e) {
        sys_log('LEAVE_POLICIES_FETCH_FAILED', 'Failed to fetch leave policies: ' . $e->getMessage());
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
$perPage = 15;

// Only fetch employee data for balances tab
$employeeRows = [];
$employeeSummaries = [];
$employeeDetails = [];
$total = 0;
$offset = 0;
$limit = $perPage;
$pages = 0;

if ($activeTab === 'balances') {
    $where = "WHERE e.status != 'terminated'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (e.first_name ILIKE :q OR e.last_name ILIKE :q OR e.email ILIKE :q OR e.employee_code ILIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $countSql = 'SELECT COUNT(*) FROM employees e ' . $where;
    try {
        $stmtCount = $pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtCount->execute();
        $total = (int)$stmtCount->fetchColumn();
    } catch (Throwable $e) {
        sys_log('LEAVE-ENTITLEMENTS-COUNT', 'Failed counting employees: ' . $e->getMessage(), [
            'module' => 'leave',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
        $total = 0;
    }

    [$offset, $limit, $page, $pages] = paginate($total, $page, $perPage);

    $sql = 'SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email, e.status, e.department_id, e.branch_id,
                   d.name AS department_name, b.name AS branch_name
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN branches b ON b.id = e.branch_id
            ' . $where . '
            ORDER BY e.last_name ASC, e.first_name ASC
            LIMIT :limit OFFSET :offset';

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $employeeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('LEAVE-ENTITLEMENTS-LIST', 'Failed loading employee entitlements list: ' . $e->getMessage(), [
            'module' => 'leave',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
        $employeeRows = [];
    }

    foreach ($employeeRows as $row) {
        $employeeId = (int)($row['id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }
        $entitlements = [];
        $remaining = [];
        try {
            $entitlements = leave_get_effective_entitlements($pdo, $employeeId);
            $remaining = leave_calculate_balances($pdo, $employeeId, $entitlements);
        } catch (Throwable $e) {
            sys_log('LEAVE-ENTITLEMENTS-CALC', 'Failed computing leave balances: ' . $e->getMessage(), [
                'module' => 'leave',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['employee_id' => $employeeId],
            ]);
            $entitlements = [];
            $remaining = [];
        }
        $summaryParts = [];
        $detail = [];
        foreach ($leaveTypes as $type) {
            $total_ent = isset($entitlements[$type]) ? (float)$entitlements[$type] : (float)(LEAVE_DEFAULT_ENTITLEMENTS[$type] ?? 0);
            $rem = isset($remaining[$type]) ? (float)$remaining[$type] : $total_ent;
            if ($rem < 0) {
                $rem = round($rem, 2);
            }
            $used = max(0.0, $total_ent - $rem);
            $detail[$type] = [
                'label' => leave_label_for_type($type),
                'entitled' => $total_ent,
                'used' => $used,
                'remaining' => $rem,
            ];
            $summaryParts[] = leave_label_for_type($type) . ' ' . format_leave_days_input($rem) . 'd';
        }
        $employeeSummaries[$employeeId] = implode(', ', $summaryParts);
        $employeeDetails[$employeeId] = $detail;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="container mx-auto px-4 py-6">
<div class="space-y-8">
  <section class="card p-6 md:p-8">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="space-y-2">
        <div class="flex items-center gap-3 text-sm">
          <a href="<?= BASE_URL ?>/modules/admin/index" class="inline-flex items-center gap-2 font-semibold text-indigo-600 transition hover:text-indigo-700" data-no-loader>
            <span class="text-base">←</span>
            <span>HR Admin</span>
          </a>
          <span class="text-slate-400">/</span>
          <span class="uppercase tracking-[0.2em] text-slate-500">Leave</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Leave Entitlements</h1>
        <p class="text-sm text-slate-600">Manage employee balances and filing policies.</p>
      </div>
      <?php if ($activeTab === 'balances'): ?>
      <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-indigo-500">Employees listed</p>
          <p class="mt-1 text-2xl font-semibold text-indigo-900"><?= number_format($total) ?></p>
          <p class="text-xs text-indigo-600">Matches current filters.</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">Leave types</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= count($leaveTypes) ?></p>
          <p class="text-xs text-emerald-600">Tracked per employee.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-slate-500">Page</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900"><?= $pages ? $page : 0 ?> <span class="text-sm font-medium text-slate-600">/ <?= $pages ?></span></p>
          <p class="text-xs text-slate-500">Navigate for more records.</p>
        </div>
      </div>
      <?php else: ?>
      <div class="grid gap-3 text-sm sm:grid-cols-2">
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">Leave types</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= count($leaveTypes) ?></p>
          <p class="text-xs text-emerald-600">Configurable policies.</p>
        </div>
        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-amber-500">Active policies</p>
          <p class="mt-1 text-2xl font-semibold text-amber-900"><?= count(array_filter($policyMap, fn($p) => $p['require_advance_notice'] === 't')) ?></p>
          <p class="text-xs text-amber-600">Enforcing advance notice.</p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Tab Navigation -->
  <div class="border-b border-slate-200">
    <nav class="flex gap-8 px-6" role="tablist">
      <a href="<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=balances" 
         class="py-3 px-1 border-b-2 font-medium text-sm transition <?= $activeTab === 'balances' ? 'border-green-600 text-green-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
        Employee Balances
      </a>
      <a href="<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=policies" 
         class="py-3 px-1 border-b-2 font-medium text-sm transition <?= $activeTab === 'policies' ? 'border-green-600 text-green-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
        Filing Policies
      </a>
    </nav>
  </div>

  <?php if ($activeTab === 'balances'): ?>
  <!-- Employee Balances Tab -->
  <section class="card p-6 space-y-6">
    <form method="get" class="flex flex-col gap-2 sm:flex-row sm:items-center">
      <input type="hidden" name="tab" value="balances">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="input-text w-full sm:w-72" placeholder="Search name, email, or code">
      <div class="flex gap-2">
        <button type="submit" class="btn btn-outline">Search</button>
        <?php if ($q !== ''): ?>
          <a href="<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=balances" class="btn btn-ghost" data-no-loader>Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="overflow-x-auto border border-gray-200 rounded-xl">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-3 py-2 text-left">Employee</th>
            <th class="px-3 py-2 text-left">Branch</th>
            <th class="px-3 py-2 text-left">Department</th>
            <th class="px-3 py-2 text-left">Status</th>
            <th class="px-3 py-2 text-left">Remaining Balances</th>
            <th class="px-3 py-2 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$employeeRows): ?>
            <tr>
              <td colspan="6" class="px-3 py-6 text-center text-sm text-gray-500">No employees found. Adjust your search or check back later.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($employeeRows as $row): ?>
              <?php
                $empId = (int)$row['id'];
                $summary = $employeeSummaries[$empId] ?? '';
                $status = (string)($row['status'] ?? 'unknown');
              ?>
              <tr class="bg-white hover:bg-gray-50">
                <td class="px-3 py-3">
                  <div class="font-medium text-gray-900"><?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?></div>
                  <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span class="font-mono text-[11px] uppercase"><?= htmlspecialchars($row['employee_code'] ?? '') ?></span>
                    <span><?= htmlspecialchars(strtolower((string)($row['email'] ?? ''))) ?></span>
                  </div>
                </td>
                <td class="px-3 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['branch_name'] ?? '—') ?></td>
                <td class="px-3 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['department_name'] ?? '—') ?></td>
                <td class="px-3 py-3 text-sm">
                  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide <?= $status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                    <?= htmlspecialchars($status) ?>
                  </span>
                </td>
                <td class="px-3 py-3 text-sm text-gray-700">
                  <div class="line-clamp-2" title="<?= htmlspecialchars($summary) ?>"><?= htmlspecialchars($summary) ?></div>
                </td>
                <td class="px-3 py-3 text-sm">
                  <button type="button" class="btn btn-outline btn-sm" data-employee-modal="employee-modal-<?= $empId ?>">View breakdown</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="flex flex-wrap items-center gap-2">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a class="btn btn-outline <?= $i === $page ? 'bg-gray-100' : '' ?>" href="?tab=balances&q=<?= urlencode($q) ?>&page=<?= $i ?>" data-no-loader><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php foreach ($employeeRows as $row): ?>
    <?php $empId = (int)$row['id']; $detail = $employeeDetails[$empId] ?? []; ?>
    <div id="employee-modal-<?= $empId ?>" class="employee-modal fixed inset-0 z-50 hidden" data-employee-id="<?= $empId ?>">
    <div class="absolute inset-0 bg-black/40" data-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
        <div class="flex items-start justify-between border-b border-gray-100 px-5 py-4">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">
              <?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?> — Leave Breakdown
            </h3>
            <p class="text-xs text-gray-500">Employee code <?= htmlspecialchars($row['employee_code'] ?? '') ?> · <?= htmlspecialchars(strtolower((string)($row['email'] ?? ''))) ?></p>
          </div>
          <button type="button" class="text-gray-400 hover:text-gray-600" data-close aria-label="Close">✕</button>
        </div>
        <div class="px-5 py-5 space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
              <div class="text-xs uppercase tracking-wide text-gray-500">Branch</div>
              <div class="mt-1 font-medium text-gray-900"><?= htmlspecialchars($row['branch_name'] ?? '—') ?></div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
              <div class="text-xs uppercase tracking-wide text-gray-500">Department</div>
              <div class="mt-1 font-medium text-gray-900"><?= htmlspecialchars($row['department_name'] ?? '—') ?></div>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
              <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left">Leave Type</th>
                  <th class="px-3 py-2 text-left">Entitled</th>
                  <th class="px-3 py-2 text-left">Used</th>
                  <th class="px-3 py-2 text-left">Remaining</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php foreach ($leaveTypes as $type): ?>
                  <?php $info = $detail[$type] ?? ['label' => leave_label_for_type($type), 'entitled' => 0, 'used' => 0, 'remaining' => 0]; ?>
                  <tr>
                    <td class="px-3 py-2 text-sm font-medium text-gray-900"><?= htmlspecialchars($info['label']) ?></td>
                    <td class="px-3 py-2 text-sm text-gray-700"><?= htmlspecialchars(format_leave_days_input($info['entitled'])) ?>d</td>
                    <td class="px-3 py-2 text-sm text-gray-700"><?= htmlspecialchars(format_leave_days_input($info['used'])) ?>d</td>
                    <td class="px-3 py-2 text-sm <?= $info['remaining'] < 0 ? 'text-red-600 font-semibold' : 'text-gray-700' ?>"><?= htmlspecialchars(format_leave_days_input($info['remaining'])) ?>d</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-5 py-4">
          <button type="button" class="btn btn-outline" data-close>Close</button>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script>
(function(){
  function bindEmployeeModals(scope = document) {
    scope.querySelectorAll('[data-employee-modal]').forEach((trigger) => {
      if (trigger.dataset.empModalBound) return;
      trigger.dataset.empModalBound = '1';
      const target = trigger.getAttribute('data-employee-modal');
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        if (target) {
          openModal(target);
        }
      });
    });
    scope.querySelectorAll('.employee-modal').forEach((modal) => {
      if (modal.dataset.modalBound) return;
      modal.dataset.modalBound = '1';
      modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
          closeModal(modal.id);
        }
      });
    });
  }
  bindEmployeeModals(document);
  document.addEventListener('spa:loaded', () => bindEmployeeModals(document));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.employee-modal').forEach((modal) => {
        if (!modal.classList.contains('hidden')) {
          closeModal(modal.id);
        }
      });
    }
  });
})();
</script>
  <?php endif; ?>

  <?php if ($activeTab === 'policies'): ?>
  <!-- Filing Policies Tab -->
  <section class="space-y-6">
    <!-- Alerts -->
    <?php if ($errors): ?>
      <?php foreach ($errors as $e): ?>
        <div class="border border-red-300 bg-red-50 rounded-lg p-4 text-sm text-red-800">
          <?= htmlspecialchars($e) ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Info Card -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
      <div class="flex gap-3">
        <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div class="text-sm text-blue-800">
          <p class="font-medium mb-1">How it works:</p>
          <ul class="list-disc list-inside space-y-1">
            <li><strong>OFF (No Requirement):</strong> Employees can file leave anytime, even for same-day or next-day leave.</li>
            <li><strong>ON (Required):</strong> Employees must file leave at least <strong>X days</strong> before the leave start date.</li>
            <li>Configure each leave type separately based on your company policy.</li>
            <li>Emergency/sick leaves typically have no advance notice requirement (0 days).</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Create New Leave Type Section -->
    <div class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-lg p-6">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Create Custom Leave Type</h2>
          <p class="text-sm text-slate-600 mt-1">Add a new leave type with custom policies</p>
        </div>
        <button type="button" id="toggle-create-form" class="btn btn-sm btn-outline">
          <span id="toggle-icon">+</span> <span id="toggle-text">Show Form</span>
        </button>
      </div>
      
      <form method="post" action="<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=policies" id="create-leave-type-form" class="hidden space-y-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_leave_type">
        
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">
              Leave Type Code <span class="text-red-500">*</span>
              <span class="text-xs font-normal text-slate-500">(lowercase, no spaces)</span>
            </label>
            <input 
              type="text" 
              name="leave_type_code" 
              required
              pattern="[a-z0-9_]+"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
              placeholder="e.g., study_leave"
            >
            <p class="text-xs text-slate-500 mt-1">Used internally (e.g., study_leave, bereavement)</p>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">
              Display Label <span class="text-red-500">*</span>
            </label>
            <input 
              type="text" 
              name="leave_type_label" 
              required
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
              placeholder="e.g., Study Leave"
            >
            <p class="text-xs text-slate-500 mt-1">Shown to employees</p>
          </div>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-white rounded-lg border border-slate-200">
          <div>
            <label class="text-sm font-medium text-slate-900">Require Advance Notice</label>
            <p class="text-xs text-slate-500 mt-0.5">When enabled, employees must file leave in advance</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" name="require_advance_notice" value="1" class="sr-only peer">
            <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
          </label>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">
            Advance Notice Days
          </label>
          <input 
            type="number" 
            name="advance_notice_days" 
            min="0" 
            max="365" 
            value="5"
            class="w-full max-w-xs px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
          >
        </div>
        
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">Notes (Optional)</label>
          <textarea 
            name="notes" 
            rows="2" 
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            placeholder="Policy guidelines or additional notes..."
          ></textarea>
        </div>
        
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="document.getElementById('toggle-create-form').click()" class="btn btn-ghost">
            Cancel
          </button>
          <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition">
            Create Leave Type
          </button>
        </div>
      </form>
    </div>

    <!-- Policy Cards -->
    <div class="space-y-4">
      <?php foreach ($leaveTypes as $type): ?>
        <?php
          $policy = $policyMap[$type] ?? null;
          $requireNotice = $policy && $policy['require_advance_notice'] === 't';
          $noticeDays = $policy ? (int)$policy['advance_notice_days'] : 5;
          $notes = $policy ? $policy['notes'] : '';
          
          // Get custom label if exists
          $typeLabel = leave_label_for_type($type);
          foreach ($customLeaveTypes as $custom) {
            if ($custom['leave_type'] === $type) {
              $typeLabel = $custom['label'];
              break;
            }
          }
          
          // Determine if this is a system type
          $systemTypes = ['sick', 'vacation', 'emergency', 'maternity', 'paternity'];
          $isSystemType = in_array($type, $systemTypes, true);
        ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
          <div class="border-b border-slate-200 bg-slate-50 px-6 py-3">
            <div class="flex items-start justify-between">
              <div>
                <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($typeLabel) ?></h2>
                <p class="text-xs text-slate-500 mt-0.5">
                  Leave type: <code class="bg-slate-100 px-1 py-0.5 rounded text-xs"><?= htmlspecialchars($type) ?></code>
                  <?php if (!$isSystemType): ?>
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Custom</span>
                  <?php endif; ?>
                </p>
              </div>
              <?php if (!$isSystemType): ?>
                <button type="button" onclick="confirmDeleteLeaveType('<?= htmlspecialchars($type) ?>', '<?= htmlspecialchars($typeLabel) ?>')" class="text-red-600 hover:text-red-800 text-sm font-medium">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
          <form method="post" action="<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=policies" class="p-6">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="leave_type" value="<?= htmlspecialchars($type) ?>">
            
            <div class="space-y-4">
              <!-- Toggle -->
              <div class="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                <div>
                  <label class="text-sm font-medium text-slate-900">Require Advance Notice</label>
                  <p class="text-xs text-slate-500 mt-0.5">When enabled, employees must file leave in advance</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" name="require_advance_notice" value="1" class="sr-only peer" <?= $requireNotice ? 'checked' : '' ?> onchange="toggleNoticeDays(this)">
                  <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                </label>
              </div>

              <!-- Notice Days Input -->
              <div class="notice-days-section" <?= $requireNotice ? '' : 'style="display:none;"' ?>>
                <label class="block text-sm font-medium text-slate-900 mb-2">
                  Advance Notice Days
                  <span class="text-xs font-normal text-slate-500">(Number of days before leave start date)</span>
                </label>
                <input 
                  type="number" 
                  name="advance_notice_days" 
                  min="0" 
                  max="365" 
                  value="<?= htmlspecialchars($noticeDays) ?>" 
                  class="w-full max-w-xs px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  placeholder="5"
                >
                <p class="text-xs text-slate-500 mt-1">Example: 5 days means employee must file at least 5 days before leave starts</p>
              </div>

              <!-- Notes -->
              <div>
                <label class="block text-sm font-medium text-slate-900 mb-2">
                  Notes <span class="text-xs font-normal text-slate-500">(Optional)</span>
                </label>
                <textarea 
                  name="notes" 
                  rows="2" 
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  placeholder="Additional policy notes or guidelines..."
                ><?= htmlspecialchars($notes) ?></textarea>
              </div>

              <!-- Last Updated -->
              <?php if ($policy && !empty($policy['updated_at'])): ?>
                <div class="text-xs text-slate-500">
                  Last updated: <?= htmlspecialchars(format_datetime_display($policy['updated_at'], true, 'Never')) ?>
                </div>
              <?php endif; ?>

              <!-- Submit Button -->
              <div class="flex justify-end pt-2">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition">
                  Save Policy
                </button>
              </div>
            </div>
          </form>
        </div>
      <?php endforeach; ?>

      <?php if (!$leaveTypes): ?>
        <div class="text-center py-12 text-slate-500">
          <p>No leave types found in the system.</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <script>
  function toggleNoticeDays(checkbox) {
    const form = checkbox.closest('form');
    const noticeDaysSection = form.querySelector('.notice-days-section');
    if (noticeDaysSection) {
      noticeDaysSection.style.display = checkbox.checked ? 'block' : 'none';
    }
  }
  
  // Toggle create form visibility
  document.getElementById('toggle-create-form')?.addEventListener('click', function() {
    const form = document.getElementById('create-leave-type-form');
    const icon = document.getElementById('toggle-icon');
    const text = document.getElementById('toggle-text');
    
    if (form.classList.contains('hidden')) {
      form.classList.remove('hidden');
      icon.textContent = '−';
      text.textContent = 'Hide Form';
    } else {
      form.classList.add('hidden');
      icon.textContent = '+';
      text.textContent = 'Show Form';
    }
  });
  
  // Confirm delete leave type
  function confirmDeleteLeaveType(leaveType, leaveLabel) {
    // Escape for safe display in confirm dialog
    const safeLabel = String(leaveLabel).replace(/[<>&"'`]/g, function(c) {
      return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;','`':'&#96;'}[c];
    });
    const safeType = String(leaveType).replace(/[<>&"'`]/g, function(c) {
      return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;','`':'&#96;'}[c];
    });
    
    if (!confirm('Are you sure you want to delete \"' + safeLabel + '\" (' + safeType + ')?\\n\\nThis will remove the leave type and its policy. This action cannot be undone.')) {
      return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= BASE_URL ?>/modules/admin/leave-entitlements?tab=policies';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf';
    csrfInput.value = '<?= htmlspecialchars(csrf_token()) ?>';
    form.appendChild(csrfInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete_leave_type';
    form.appendChild(actionInput);
    
    const typeInput = document.createElement('input');
    typeInput.type = 'hidden';
    typeInput.name = 'leave_type';
    typeInput.value = leaveType;
    form.appendChild(typeInput);
    
    document.body.appendChild(form);
    form.submit();
  }
  </script>
  <?php endif; ?>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
