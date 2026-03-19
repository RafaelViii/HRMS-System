<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'admin_workbench', 'manage');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pageTitle = 'HR Admin Workbench';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$viewerRole = strtolower((string)($currentUser['role'] ?? ''));
$isAdminUser = $viewerRole === 'admin';
$heroAccessLabel = $isAdminUser ? 'Admins & HR partners' : 'HR partners';
action_log('admin', 'view_hr_admin_workbench');

$hubCardGroups = [
  'Payroll & Compensation' => [
    [
      'title' => 'Compensation Defaults',
      'description' => 'Adjust allowances, deductions, and the baseline tax used in new payroll runs.',
      'href' => '#payroll-compensation',
      'availability' => 'Admins & HR',
      'icon' => 'coins',
      'scroll' => true,
    ],
    [
      'title' => 'Approver Routing',
      'description' => 'Update sequential payroll approvers and scope-specific routing rules.',
      'href' => '#payroll-approvers',
      'availability' => 'Admins & HR',
      'icon' => 'flow',
      'scroll' => true,
    ],
    [
      'title' => 'Run Payroll',
      'description' => 'Launch or review payroll cycles that are currently in-flight.',
      'href' => BASE_URL . '/modules/payroll/index',
      'availability' => 'Admins & HR',
      'icon' => 'rocket',
    ],
  ],
  'Leave Programs' => [
    [
      'title' => 'Leave Defaults',
      'description' => 'Tune the company-wide leave quota per type before the next cycle.',
      'href' => '#leave-defaults',
      'availability' => 'Admins & HR',
      'icon' => 'calendar',
      'scroll' => true,
    ],
    [
      'title' => 'Department Overrides',
      'description' => 'Apply department-specific allowances with override authorization.',
      'href' => '#department-overrides',
      'availability' => 'Admins & HR',
      'icon' => 'building',
      'scroll' => true,
    ],
  ],
  'Monitoring & Broadcasts' => [
    [
      'title' => 'Notification Broadcasts',
      'description' => 'Send company-wide announcements with acknowledgement tracking.',
      'href' => BASE_URL . '/modules/admin/notification_create',
      'availability' => 'Admins',
      'icon' => 'megaphone',
      'requireAdmin' => true,
    ],
    [
      'title' => 'System Logs',
      'description' => 'Open the detailed log explorer and export structured records.',
      'href' => BASE_URL . '/modules/admin/system_log',
      'availability' => 'Admins',
      'icon' => 'terminal',
      'requireAdmin' => true,
    ],
    [
      'title' => 'Database Backup',
      'description' => 'Trigger a safeguard snapshot before you roll out major changes.',
      'href' => BASE_URL . '/modules/admin/backup',
      'availability' => 'Admins',
      'icon' => 'shield',
      'requireAdmin' => true,
    ],
  ],
];

foreach ($hubCardGroups as $groupName => $cards) {
  $filtered = [];
  foreach ($cards as $card) {
    if (!empty($card['requireAdmin']) && !$isAdminUser) {
      continue;
    }
    $filtered[] = $card;
  }
  if (!$filtered) {
    unset($hubCardGroups[$groupName]);
    continue;
  }
  $hubCardGroups[$groupName] = $filtered;
}

$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/admin/workbench#payroll-approvers');
    exit;
  }

  $action = $_POST['action'] ?? '';
  $redirectAnchor = '#payroll-approvers';

  try {
    switch ($action) {
      case 'save_payroll_compensation_defaults':
        $redirectAnchor = '#payroll-compensation';
        $authEmail = trim((string)($_POST['override_email'] ?? ''));
        $authPassword = (string)($_POST['override_password'] ?? '');
        if ($authEmail === '' || $authPassword === '') {
          flash_error('Authorization failed. Provide a valid admin credential.');
          break;
        }
        $authz = validate_override_credentials('payroll', 'admin', $authEmail, $authPassword, 'Update payroll compensation defaults');
        if (!$authz['ok']) {
          flash_error('Authorization failed. Provide a valid admin credential.');
          break;
        }

        $parseItems = static function (array $rawBucket, string $labelPrefix, array &$collector): array {
          $labels = isset($rawBucket['label']) && is_array($rawBucket['label']) ? $rawBucket['label'] : [];
          $codes = isset($rawBucket['code']) && is_array($rawBucket['code']) ? $rawBucket['code'] : [];
          $amounts = isset($rawBucket['amount']) && is_array($rawBucket['amount']) ? $rawBucket['amount'] : [];
          $max = max(count($labels), count($codes), count($amounts));
          $items = [];
          for ($i = 0; $i < $max; $i++) {
            $label = trim((string)($labels[$i] ?? ''));
            $code = trim((string)($codes[$i] ?? ''));
            $amountRaw = trim((string)($amounts[$i] ?? ''));
            if ($label === '' && $code === '' && $amountRaw === '') {
              continue;
            }
            if ($label === '') {
              $collector[] = $labelPrefix . ' row ' . ($i + 1) . ' needs a label.';
              continue;
            }
            if ($amountRaw === '' || !is_numeric($amountRaw)) {
              $collector[] = $labelPrefix . ' row ' . ($i + 1) . ' amount must be numeric.';
              continue;
            }
            $items[] = [
              'label' => $label,
              'code' => $code,
              'amount' => (float)$amountRaw,
            ];
          }
          return $items;
        };

        $collector = [];
        $allowancesItems = $parseItems(is_array($_POST['allowances'] ?? null) ? $_POST['allowances'] : [], 'Allowance', $collector);
        $deductionsItems = $parseItems(is_array($_POST['deductions'] ?? null) ? $_POST['deductions'] : [], 'Contribution', $collector);
        $notesRaw = trim((string)($_POST['comp_notes'] ?? ''));
        $notes = $notesRaw !== '' ? $notesRaw : null;
        $taxRaw = trim((string)($_POST['tax_percentage'] ?? ''));
        $taxPercentage = null;
        if ($taxRaw !== '') {
          if (!is_numeric($taxRaw)) {
            $collector[] = 'Tax percentage must be numeric.';
          } else {
            $taxPercentage = max(0.0, min(100.0, (float)$taxRaw));
          }
        }

        if ($collector) {
          flash_error(implode(' ', array_unique($collector)));
          break;
        }

        $saved = payroll_save_compensation_defaults($pdo, $allowancesItems, $deductionsItems, $notes, $currentUserId ?: null, $taxPercentage);
        if ($saved) {
          $context = [
            'allowances' => count($allowancesItems),
            'deductions' => count($deductionsItems),
            'tax_percentage' => $taxPercentage,
          ];
          if (!empty($authz['user']['id'])) {
            $context['authorized_by'] = (int)$authz['user']['id'];
          }
          action_log('payroll', 'compensation_defaults_saved', 'success', $context);
          audit('payroll_comp_defaults_saved', json_encode($context, JSON_UNESCAPED_SLASHES));
          flash_success('Payroll compensation defaults updated.');
        } else {
          flash_error('Unable to save compensation defaults.');
        }
        break;

      case 'save_leave_defaults':
        $redirectAnchor = '#leave-defaults';
        $payload = $_POST['leave_days'] ?? [];
        if (!is_array($payload)) {
          flash_error('Invalid payload for leave defaults.');
          break;
        }
        $knownTypes = leave_get_known_types($pdo);
        $entries = [];
        foreach ($knownTypes as $type) {
          $raw = trim((string)($payload[$type] ?? ''));
          if ($raw === '') {
            $entries[$type] = null;
            continue;
          }
          if (!is_numeric($raw)) {
            flash_error('Leave allowance values must be numeric.');
            $entries = null;
            break;
          }
          $entries[$type] = max(0, (float)$raw);
        }
        if ($entries === null) {
          break;
        }
        try {
          $pdo->beginTransaction();
          $deleteStmt = $pdo->prepare('DELETE FROM leave_entitlements WHERE scope_type = :scope AND scope_id IS NULL AND leave_type = :type');
          $upsertStmt = $pdo->prepare(
            'INSERT INTO leave_entitlements (scope_type, scope_id, leave_type, days)
             VALUES (:scope, NULL, :type, :days)
             ON CONFLICT (scope_type, scope_id, leave_type)
             DO UPDATE SET days = EXCLUDED.days, updated_at = CURRENT_TIMESTAMP'
          );
          $updatedCount = 0;
          foreach ($entries as $type => $value) {
            if ($value === null) {
              $deleteStmt->execute([':scope' => 'global', ':type' => $type]);
              continue;
            }
            $upsertStmt->execute([':scope' => 'global', ':type' => $type, ':days' => $value]);
            $updatedCount++;
          }
          $pdo->commit();
          action_log('leave', 'global_entitlements_saved', 'success', ['count' => $updatedCount]);
          flash_success('Default leave allowances updated.');
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
          }
          sys_log('LEAVE-GLOBAL-SAVE', 'Failed saving global leave allowances: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__]);
          flash_error('Unable to save default leave allowances.');
        }
        break;

      case 'save_department_entitlements':
        $redirectAnchor = '#department-overrides';
        $deptId = (int)($_POST['department_id'] ?? 0);
        if ($deptId <= 0) {
          flash_error('Select a department to update.');
          break;
        }
        $deptStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute([':id' => $deptId]);
        $deptName = $deptStmt->fetchColumn();
        if (!$deptName) {
          flash_error('Department not found.');
          break;
        }
        $authorizedBy = null;
        $authEmail = trim((string)($_POST['override_email'] ?? ''));
        $authPassword = (string)($_POST['override_password'] ?? '');
        if ($authEmail === '' || $authPassword === '') {
          flash_error('Department overrides require a confirmed admin credential.');
          break;
        }
        $authz = validate_override_credentials('leave', 'admin', $authEmail, $authPassword, 'Update department leave overrides for ' . $deptName);
        if (!$authz['ok']) {
          flash_error('Department overrides require a confirmed admin credential.');
          break;
        }
        $authorizedBy = (int)($authz['user']['id'] ?? 0);
        if ($authorizedBy > 0) {
          $GLOBALS['__override_as_user_id'] = $authorizedBy;
        }
        $payload = $_POST['leave_days'] ?? [];
        if (!is_array($payload)) {
          flash_error('Invalid payload for department overrides.');
          break;
        }
        $knownTypes = leave_get_known_types($pdo);
        $entries = [];
        foreach ($knownTypes as $type) {
          $raw = trim((string)($payload[$type] ?? ''));
          if ($raw === '') {
            $entries[$type] = null;
            continue;
          }
          if (!is_numeric($raw)) {
            flash_error('Leave allowance values must be numeric.');
            $entries = null;
            break;
          }
          $entries[$type] = max(0, (float)$raw);
        }
        if ($entries === null) {
          break;
        }
        try {
          $pdo->beginTransaction();
          $deleteStmt = $pdo->prepare('DELETE FROM leave_entitlements WHERE scope_type = :scope AND scope_id = :scope_id AND leave_type = :type');
          $upsertStmt = $pdo->prepare(
            'INSERT INTO leave_entitlements (scope_type, scope_id, leave_type, days)
             VALUES (:scope, :scope_id, :type, :days)
             ON CONFLICT (scope_type, scope_id, leave_type)
             DO UPDATE SET days = EXCLUDED.days, updated_at = CURRENT_TIMESTAMP'
          );
          $updatedCount = 0;
          foreach ($entries as $type => $value) {
            $baseParams = [':scope' => 'department', ':scope_id' => $deptId, ':type' => $type];
            if ($value === null) {
              $deleteStmt->execute($baseParams);
              continue;
            }
            $upsertStmt->execute($baseParams + [':days' => $value]);
            $updatedCount++;
          }
          $pdo->commit();
          $meta = ['department_id' => $deptId, 'count' => $updatedCount];
          if (!empty($authorizedBy)) {
            $meta['authorized_by'] = $authorizedBy;
          }
          action_log('leave', 'department_entitlements_saved', 'success', $meta);
          $message = $updatedCount > 0 ? 'Department leave overrides saved.' : 'Department leave overrides cleared.';
          flash_success($message);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
          }
          sys_log('LEAVE-DEPARTMENT-SAVE', 'Failed saving department leave overrides: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['department_id' => $deptId]]);
          flash_error('Unable to save department leave overrides.');
        }
        break;

      case 'update_order_bulk':
        $payload = trim((string)($_POST['order_payload'] ?? ''));
        $rows = json_decode($payload, true);
        if (!is_array($rows)) { flash_error('Invalid order payload.'); break; }
        $seen = [];
        foreach ($rows as $r) {
          $id = (int)($r['id'] ?? 0); $step = (int)($r['step_order'] ?? 0);
          if ($id <= 0 || $step <= 0) { flash_error('Step orders must be positive.'); break 2; }
          if (isset($seen[$step])) { flash_error('Duplicate step order detected.'); break 2; }
          $seen[$step] = true;
        }
        $pdo->beginTransaction();
        try {
          $upd = $pdo->prepare('UPDATE payroll_approvers SET step_order = :step, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
          foreach ($rows as $r) {
            $upd->execute([':step' => (int)$r['step_order'], ':id' => (int)$r['id']]);
          }
          $pdo->commit();
          flash_success('Approval steps reordered.');
          action_log('payroll', 'approver_order_updated', 'success', ['count' => count($rows)]);
        } catch (Throwable $ie) {
          try { $pdo->rollBack(); } catch (Throwable $ie2) {}
          throw $ie;
        }
        break;

      case 'add_approver':
        $userId = (int)($_POST['user_id'] ?? 0);
        $stepOrder = (int)($_POST['step_order'] ?? 0);
        $appliesToRaw = trim((string)($_POST['applies_to'] ?? ''));
        $scope = $appliesToRaw !== '' ? $appliesToRaw : null;
        $activeFlag = isset($_POST['active']);

        if ($userId <= 0 || $stepOrder <= 0) {
          flash_error('Select a user and provide a step order greater than zero.');
          break;
        }

        $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
          flash_error('The selected user no longer exists.');
          break;
        }

        $lookup = $pdo->prepare(
          "SELECT id FROM payroll_approvers WHERE user_id = :user_id AND COALESCE(applies_to, 'global') = COALESCE(:scope, 'global') LIMIT 1"
        );
        $lookup->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $lookup->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $lookup->execute();
        $existingId = $lookup->fetchColumn();

        if ($existingId) {
          $update = $pdo->prepare(
            'UPDATE payroll_approvers
             SET step_order = :step_order,
               applies_to = :scope,
               active = :active,
               updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
          );
          $update->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
          $update->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
          $update->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
          $update->bindValue(':id', (int)$existingId, PDO::PARAM_INT);
          $update->execute();
          flash_success('Approver updated.');
          action_log('payroll', 'approver_updated', 'success', [
            'approver_id' => (int)$existingId,
            'user_id' => $userId,
            'scope' => $scope,
            'step_order' => $stepOrder,
          ]);
        } else {
          $insert = $pdo->prepare(
            'INSERT INTO payroll_approvers (user_id, step_order, applies_to, active)
             VALUES (:user_id, :step_order, :scope, :active)
             RETURNING id'
          );
          $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
          $insert->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
          $insert->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
          $insert->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
          $insert->execute();
          $approverId = (int)$insert->fetchColumn();
          flash_success('Approver added.');
          action_log('payroll', 'approver_added', 'success', [
            'approver_id' => $approverId,
            'user_id' => $userId,
            'scope' => $scope,
            'step_order' => $stepOrder,
          ]);
        }
        break;

      case 'update_approver':
        $approverId = (int)($_POST['approver_id'] ?? 0);
        $stepOrder = (int)($_POST['step_order'] ?? 0);
        $appliesToRaw = trim((string)($_POST['applies_to'] ?? ''));
        $scope = $appliesToRaw !== '' ? $appliesToRaw : null;
        $activeFlag = isset($_POST['active']);

        if ($approverId <= 0 || $stepOrder <= 0) {
          flash_error('Provide a valid step order for the selected approver.');
          break;
        }

        $current = $pdo->prepare('SELECT user_id FROM payroll_approvers WHERE id = :id LIMIT 1');
        $current->execute([':id' => $approverId]);
        $row = $current->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
          flash_error('Approver record not found.');
          break;
        }

        $update = $pdo->prepare(
          'UPDATE payroll_approvers
           SET step_order = :step_order,
             applies_to = :scope,
             active = :active,
             updated_at = CURRENT_TIMESTAMP
           WHERE id = :id'
        );
        $update->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
        $update->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $update->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
        $update->bindValue(':id', $approverId, PDO::PARAM_INT);
        $update->execute();

        flash_success('Approver saved.');
        action_log('payroll', 'approver_updated', 'success', [
          'approver_id' => $approverId,
          'user_id' => (int)$row['user_id'],
          'scope' => $scope,
          'step_order' => $stepOrder,
          'active' => $activeFlag,
        ]);
        break;

      case 'delete_approver':
        $approverId = (int)($_POST['approver_id'] ?? 0);
        if ($approverId <= 0) {
          flash_error('Approver record not found.');
          break;
        }
        $fetch = $pdo->prepare('SELECT user_id FROM payroll_approvers WHERE id = :id LIMIT 1');
        $fetch->execute([':id' => $approverId]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
          flash_error('Approver record not found.');
          break;
        }
        $delete = $pdo->prepare('DELETE FROM payroll_approvers WHERE id = :id');
        $delete->execute([':id' => $approverId]);
        flash_success('Approver removed.');
        action_log('payroll', 'approver_removed', 'success', [
          'approver_id' => $approverId,
          'user_id' => (int)$row['user_id'],
        ]);
        break;

      default:
        flash_error('Unsupported action.');
        break;
    }
  } catch (Throwable $e) {
    sys_log('PAYROLL-APPROVER-MGMT', 'Failed managing admin settings: ' . $e->getMessage(), [
      'module' => 'payroll',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => ['action' => $action],
    ]);
    flash_error('We could not process the requested change. Please try again.');
  }

  header('Location: ' . BASE_URL . '/modules/admin/workbench' . $redirectAnchor);
  exit;
}

$approvers = [];
try {
    $stmt = $pdo->query(
        'SELECT pa.*, u.full_name, u.email
         FROM payroll_approvers pa
         LEFT JOIN users u ON u.id = pa.user_id
         ORDER BY pa.step_order, pa.id'
    );
    $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('PAYROLL-APPROVER-LIST', 'Failed loading payroll approvers: ' . $e->getMessage(), [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$activeUsers = [];
try {
    $userStmt = $pdo->query("SELECT id, full_name, email, role FROM users WHERE status = 'active' ORDER BY full_name");
    $activeUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('PAYROLL-APPROVER-USERS', 'Failed loading user list for approvers: ' . $e->getMessage(), [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$assignedGlobal = [];
foreach ($approvers as $row) {
    if (($row['applies_to'] ?? null) === null) {
        $assignedGlobal[(int)$row['user_id']] = true;
    }
}

$approverStats = [
  'total' => count($approvers),
  'active' => 0,
  'scoped' => 0,
];
foreach ($approvers as $row) {
  if (!empty($row['active'])) {
    $approverStats['active']++;
  }
  if (($row['applies_to'] ?? '') !== null && trim((string)$row['applies_to']) !== '') {
    $approverStats['scoped']++;
  }
}

$leaveTypes = leave_get_known_types($pdo);
$defaultEntitlements = leave_get_default_entitlements();
$globalEntitlements = leave_fetch_entitlements($pdo, 'global', null);
$departments = [];
try {
  $departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  sys_log('DEPARTMENT-LIST', 'Unable to load departments for leave overrides: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__]);
}

$departmentOverrides = [];
try {
  $deptOverridesStmt = $pdo->query("SELECT scope_id, LOWER(leave_type) AS leave_type, days FROM leave_entitlements WHERE scope_type = 'department'");
  foreach ($deptOverridesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $deptId = (int)($row['scope_id'] ?? 0);
    if ($deptId <= 0) {
      continue;
    }
    $type = strtolower((string)($row['leave_type'] ?? ''));
    if ($type === '') {
      continue;
    }
    $departmentOverrides[$deptId][$type] = max(0, (float)($row['days'] ?? 0));
  }
} catch (Throwable $e) {
  sys_log('LEAVE-DEPARTMENT-READ', 'Unable to load department leave overrides: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__]);
}

$departmentSummaries = [];
$departmentOverrideCounts = [];
$departmentEffective = [];
foreach ($departments as $deptRow) {
  $deptId = (int)$deptRow['id'];
  $overrides = $departmentOverrides[$deptId] ?? [];
  $overrideCount = 0;
  foreach ($leaveTypes as $type) {
    $overrideValue = array_key_exists($type, $overrides) ? $overrides[$type] : null;
    if ($overrideValue !== null) {
      $overrideCount++;
    }
    $globalValue = $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0);
    $effectiveValue = $overrideValue ?? $globalValue;
    $departmentEffective[$deptId][$type] = [
      'override' => $overrideValue,
      'global' => $globalValue,
      'effective' => $effectiveValue,
    ];
  }
  $departmentOverrideCounts[$deptId] = $overrideCount;
  if ($overrideCount === 0) {
    $departmentSummaries[$deptId] = 'Using global defaults';
    continue;
  }
  $parts = [];
  foreach ($overrides as $type => $value) {
    if ($value === null) {
      continue;
    }
    $parts[] = leave_label_for_type($type) . ': ' . format_leave_days_input($value) . 'd';
  }
  $departmentSummaries[$deptId] = $parts ? implode(', ', $parts) : 'Using global defaults';
}

$leaveStats = [
  'global' => count($globalEntitlements),
  'departments' => 0,
];
foreach ($departmentOverrides as $overrides) {
  if (!empty($overrides)) {
    $leaveStats['departments']++;
  }
}

$payrollDefaults = payroll_get_compensation_defaults($pdo);
$payrollDefaultAllowances = $payrollDefaults['allowances'] ?? [];
$payrollDefaultDeductions = $payrollDefaults['deductions'] ?? [];
$payrollDefaultNotes = (string)($payrollDefaults['notes'] ?? '');
$payrollDefaultTax = isset($payrollDefaults['tax_percentage']) && $payrollDefaults['tax_percentage'] !== null ? (float)$payrollDefaults['tax_percentage'] : null;
$payrollDefaultTaxLabel = $payrollDefaultTax !== null ? rtrim(rtrim(number_format($payrollDefaultTax, 2, '.', ''), '0'), '.') . '%' : 'Not configured';
$payrollDefaultsUpdatedAt = $payrollDefaults['updated_at'] ?? null;
$payrollDefaultsUpdatedBy = $payrollDefaults['updated_by'] ?? null;
$payrollDefaultsUpdatedLabel = '';
if ($payrollDefaultsUpdatedAt) {
  try {
    $dt = new DateTime((string)$payrollDefaultsUpdatedAt);
    $payrollDefaultsUpdatedLabel = $dt->format('M d, Y g:i A');
  } catch (Throwable $e) {
    $payrollDefaultsUpdatedLabel = (string)$payrollDefaultsUpdatedAt;
  }
}
$payrollDefaultsUpdatedByLabel = null;
if ($payrollDefaultsUpdatedBy) {
  try {
    $userStmt = $pdo->prepare('SELECT full_name, email FROM users WHERE id = :id');
    $userStmt->execute([':id' => $payrollDefaultsUpdatedBy]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($userRow) {
      $name = trim((string)($userRow['full_name'] ?? ''));
      $email = strtolower((string)($userRow['email'] ?? ''));
      $payrollDefaultsUpdatedByLabel = $name !== '' ? $name : $email;
    }
  } catch (Throwable $e) {
    sys_log('PAYROLL-COMP-DEFAULTS-UPDATER', 'Failed resolving payroll defaults updater: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
  }
}
$payrollDefaultAllowancesRows = $payrollDefaultAllowances ?: [['code' => '', 'label' => '', 'amount' => '']];
$payrollDefaultDeductionsRows = $payrollDefaultDeductions ?: [['code' => '', 'label' => '', 'amount' => '']];

if (!function_exists('format_leave_days_input')) {
  function format_leave_days_input($value): string {
    if ($value === null || $value === '') {
      return '';
    }
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
  }
}

$lastLoginDisplay = '—';
if ($currentUserId > 0) {
  try {
    $stmtLastLogin = $pdo->prepare('SELECT last_login FROM users WHERE id = :id');
    $stmtLastLogin->execute([':id' => $currentUserId]);
    $lastLoginRaw = $stmtLastLogin->fetchColumn();
    if ($lastLoginRaw) {
      $lastLoginDisplay = format_datetime_display($lastLoginRaw, true, '—');
    }
  } catch (Throwable $e) {
    sys_log('ADMIN-WORKBENCH-LASTLOGIN', 'Failed loading last login: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['user_id' => $currentUserId]]);
  }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-8">
  <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-10">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
      <div class="space-y-4 max-w-3xl">
        <a href="<?= BASE_URL ?>/modules/admin/index" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 transition hover:text-indigo-700" data-no-loader>
          <span class="text-lg">←</span>
          <span>Back to HR Admin</span>
        </a>
        <div class="space-y-3">
          <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em] text-slate-600">Workbench</span>
          <h1 class="text-3xl font-semibold text-slate-900 md:text-4xl">Configure payroll flows and leave programs in one place.</h1>
          <p class="text-base text-slate-600">Use this workbench to update approvers, adjust compensation defaults, and fine-tune department-level leave entitlements. All actions require proper authorization and are logged automatically.</p>
        </div>
        <div class="flex flex-wrap gap-3 text-xs text-slate-500">
          <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1">Access: <?= htmlspecialchars($heroAccessLabel) ?></span>
          <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1">Last sign in: <?= htmlspecialchars($lastLoginDisplay) ?></span>
        </div>
      </div>
      <div class="grid w-full max-w-sm gap-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
          <p class="text-xs uppercase tracking-wide text-indigo-500">Active approvers</p>
          <p class="mt-2 text-3xl font-semibold text-indigo-900"><?= (int)$approverStats['active'] ?> <span class="text-base font-medium text-indigo-600">/ <?= (int)$approverStats['total'] ?></span></p>
          <p class="mt-1 text-xs text-indigo-600">Ensure sequential routing is fully staffed.</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
          <p class="text-xs uppercase tracking-wide text-emerald-500">Dept overrides</p>
          <p class="mt-2 text-3xl font-semibold text-emerald-900"><?= (int)$leaveStats['departments'] ?></p>
          <p class="mt-1 text-xs text-emerald-600">Departments with custom leave allowances.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Global leave types</p>
          <p class="mt-2 text-3xl font-semibold text-slate-900"><?= count($leaveTypes) ?></p>
          <p class="mt-1 text-xs text-slate-500">Included in company-wide defaults.</p>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($hubCardGroups)): ?>
    <?php foreach ($hubCardGroups as $groupLabel => $cards): ?>
      <section class="space-y-3">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars($groupLabel) ?></h2>
          <span class="text-xs text-gray-400"><?= count($cards) ?> <?= count($cards) === 1 ? 'tool' : 'tools' ?></span>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <?php foreach ($cards as $card): ?>
            <?php
              $cardHref = $card['href'];
              $isScrollCard = !empty($card['scroll']) && isset($cardHref[0]) && $cardHref[0] === '#';
              $scrollAttr = $isScrollCard ? ' data-scroll-target="' . htmlspecialchars($cardHref) . '"' : '';
              $launchLabel = $isScrollCard ? 'Jump to section' : 'Launch tool';
              $externalAttr = !empty($card['requireAdmin']) && !$isAdminUser ? ' aria-disabled="true"' : '';
              $cardAttrs = $externalAttr;
              if (!$externalAttr && empty($card['scroll'])) {
                $cardAttrs .= ' data-no-loader';
              }
            ?>
            <a href="<?= htmlspecialchars($cardHref) ?>" class="group card flex flex-col gap-4 border border-gray-200 bg-white p-6 shadow-sm transition hover:border-indigo-300 hover:shadow-lg"<?= $scrollAttr ?><?= $cardAttrs ?>>
              <div class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                <?php switch ($card['icon']) {
                  case 'coins': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="7" rx="6" ry="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 7v6c0 1.66 2.69 3 6 3s6-1.34 6-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 10c0 1.66 2.69 3 6 3s6-1.34 6-3" /></svg>
                <?php break;
                  case 'flow': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h6a3 3 0 010 6H9" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 13l2-2-2-2" /><path stroke-linecap="round" stroke-linejoin="round" d="M19 17h-6a3 3 0 01-3-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11l-2 2 2 2" /></svg>
                <?php break;
                  case 'rocket': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l4 4m6-4l4 4" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c4 1 5 4.5 5 7 0 2.5-1 4-2.5 5.5L12 18l-2.5-2.5C8 14 7 12.5 7 10c0-2.5 1-6 5-7z" /><circle cx="12" cy="9" r="1.5" /></svg>
                <?php break;
                  case 'calendar': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4V3m10 1V3" /><rect x="4" y="5" width="16" height="15" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M4 10h16" /></svg>
                <?php break;
                  case 'building': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21V7a2 2 0 012-2h4V3a1 1 0 011-1h6a1 1 0 011 1v2h4a2 2 0 012 2v14" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 21V9h6v12" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18" /></svg>
                <?php break;
                  case 'megaphone': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10v4" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 9l10-5v16l-10-5" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 9v6" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 006 0" /></svg>
                <?php break;
                  case 'terminal': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 9l3 3-3 3" /><path stroke-linecap="round" stroke-linejoin="round" d="M13 15h4" /></svg>
                <?php break;
                  case 'shield': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V6l-8-3-8 3v6c0 6 8 10 8 10z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12.5l1.5 1.5 3.5-3.5" /></svg>
                <?php break;
                  default: ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87" /><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75" /></svg>
                <?php } ?>
              </div>
              <div class="flex-1 space-y-2">
                <div class="flex items-center justify-between gap-2">
                  <h3 class="text-lg font-semibold text-gray-900 transition group-hover:text-indigo-600"><?= htmlspecialchars($card['title']) ?></h3>
                  <span class="text-xs font-medium uppercase tracking-wide text-gray-500"><?= htmlspecialchars($card['availability']) ?></span>
                </div>
                <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars($card['description']) ?></p>
              </div>
              <div class="flex items-center gap-1 text-sm font-semibold text-indigo-600">
                <span><?= htmlspecialchars($launchLabel) ?></span>
                <span class="transition group-hover:translate-x-0.5">→</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <section id="payroll-compensation" class="card p-6 space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
      <div>
        <h2 class="text-xl font-semibold text-gray-900">Payroll Compensation Defaults</h2>
        <p class="text-sm text-gray-600">Configure organization-wide allowances, contributions, and the baseline tax percentage.</p>
      </div>
      <div class="text-xs text-gray-500">
        <?php if ($payrollDefaultsUpdatedLabel !== ''): ?>
          Last updated <?= htmlspecialchars($payrollDefaultsUpdatedLabel) ?><?php if ($payrollDefaultsUpdatedByLabel): ?> by <?= htmlspecialchars($payrollDefaultsUpdatedByLabel) ?><?php endif; ?>
        <?php else: ?>
          Never updated
        <?php endif; ?>
      </div>
    </div>
    <div class="grid gap-6 lg:grid-cols-2">
      <div class="space-y-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h3 class="text-sm font-semibold text-gray-800">Current Defaults</h3>
          <div class="mt-3 space-y-4 text-sm text-gray-700">
            <div>
              <div class="text-xs uppercase tracking-wide text-gray-500">Allowances</div>
              <?php if ($payrollDefaultAllowances): ?>
                <ul class="mt-2 space-y-1">
                  <?php foreach ($payrollDefaultAllowances as $row): ?>
                    <li class="flex items-center justify-between">
                      <span><?= htmlspecialchars($row['label']) ?></span>
                      <span class="font-medium">₱<?= number_format((float)$row['amount'], 2) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="mt-2 text-gray-500">No default allowances configured.</p>
              <?php endif; ?>
            </div>
            <div>
              <div class="text-xs uppercase tracking-wide text-gray-500">Contributions</div>
              <?php if ($payrollDefaultDeductions): ?>
                <ul class="mt-2 space-y-1">
                  <?php foreach ($payrollDefaultDeductions as $row): ?>
                    <li class="flex items-center justify-between">
                      <span><?= htmlspecialchars($row['label']) ?></span>
                      <span class="font-medium">₱<?= number_format((float)$row['amount'], 2) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="mt-2 text-gray-500">No default contributions configured.</p>
              <?php endif; ?>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">Default Tax</div>
                <p class="mt-1 font-semibold text-gray-900"><?= htmlspecialchars($payrollDefaultTaxLabel) ?></p>
              </div>
              <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">Notes</div>
                <p class="mt-1 text-gray-700"><?= $payrollDefaultNotes !== '' ? nl2br(htmlspecialchars($payrollDefaultNotes)) : '—' ?></p>
              </div>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-500">Defaults cascade to all employees unless a profile override is set.</p>
      </div>
      <form method="post" class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-4" id="payroll-compensation-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" />
        <input type="hidden" name="action" value="save_payroll_compensation_defaults" />
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700">Allowance Defaults</h3>
            <button type="button" class="btn btn-outline text-xs" data-comp-add="allowances">Add Allowance</button>
          </div>
          <div class="space-y-3" data-comp-list="allowances">
            <?php foreach ($payrollDefaultAllowancesRows as $row): ?>
              <?php $amountValue = isset($row['amount']) ? rtrim(rtrim(number_format((float)$row['amount'], 2, '.', ''), '0'), '.') : ''; ?>
              <div class="grid gap-2 md:grid-cols-5" data-comp-row>
                <div class="md:col-span-2">
                  <label class="text-xs uppercase tracking-wide text-gray-500">Label</label>
                  <input type="text" class="input-text w-full" name="allowances[label][]" value="<?= htmlspecialchars($row['label'] ?? '') ?>" placeholder="e.g., Transportation" />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-wide text-gray-500">Code</label>
                  <input type="text" class="input-text w-full" name="allowances[code][]" value="<?= htmlspecialchars($row['code'] ?? '') ?>" placeholder="Optional" />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-wide text-gray-500">Amount</label>
                  <input type="number" step="0.01" min="0" class="input-text w-full" name="allowances[amount][]" value="<?= htmlspecialchars($amountValue) ?>" placeholder="0.00" />
                </div>
                <div class="flex items-end justify-end">
                  <button type="button" class="btn btn-outline text-xs" data-comp-remove>Remove</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700">Contribution Defaults</h3>
            <button type="button" class="btn btn-outline text-xs" data-comp-add="deductions">Add Contribution</button>
          </div>
          <div class="space-y-3" data-comp-list="deductions">
            <?php foreach ($payrollDefaultDeductionsRows as $row): ?>
              <?php $amountValue = isset($row['amount']) ? rtrim(rtrim(number_format((float)$row['amount'], 2, '.', ''), '0'), '.') : ''; ?>
              <div class="grid gap-2 md:grid-cols-5" data-comp-row>
                <div class="md:col-span-2">
                  <label class="text-xs uppercase tracking-wide text-gray-500">Label</label>
                  <input type="text" class="input-text w-full" name="deductions[label][]" value="<?= htmlspecialchars($row['label'] ?? '') ?>" placeholder="e.g., SSS" />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-wide text-gray-500">Code</label>
                  <input type="text" class="input-text w-full" name="deductions[code][]" value="<?= htmlspecialchars($row['code'] ?? '') ?>" placeholder="Optional" />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-wide text-gray-500">Amount</label>
                  <input type="number" step="0.01" min="0" class="input-text w-full" name="deductions[amount][]" value="<?= htmlspecialchars($amountValue) ?>" placeholder="0.00" />
                </div>
                <div class="flex items-end justify-end">
                  <button type="button" class="btn btn-outline text-xs" data-comp-remove>Remove</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="text-xs uppercase tracking-wide text-gray-500">Default Tax Percentage</label>
            <div class="mt-1 flex items-center gap-2">
              <input type="number" step="0.01" min="0" max="100" class="input-text w-full md:w-32" name="tax_percentage" value="<?= $payrollDefaultTax !== null ? htmlspecialchars(rtrim(rtrim(number_format($payrollDefaultTax, 2, '.', ''), '0'), '.')) : '' ?>" placeholder="e.g., 8" />
              <span class="text-sm text-gray-600">%</span>
            </div>
            <p class="mt-1 text-xs text-gray-500">Applied before statutory computations; override per employee if needed.</p>
          </div>
          <div>
            <label class="text-xs uppercase tracking-wide text-gray-500">Notes</label>
            <textarea class="input-text w-full" name="comp_notes" rows="3" placeholder="Context for future changes."><?= htmlspecialchars($payrollDefaultNotes) ?></textarea>
          </div>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
          <label class="block text-sm">
            <span class="text-xs uppercase tracking-wide text-gray-500">Override Email</span>
            <input type="email" name="override_email" class="input-text w-full" placeholder="admin@company.com" required />
          </label>
          <label class="block text-sm">
            <span class="text-xs uppercase tracking-wide text-gray-500">Override Password</span>
            <input type="password" name="override_password" class="input-text w-full" required />
          </label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-xs text-gray-500">Authorization required. All updates are tracked in the audit trail.</p>
          <button type="submit" class="btn btn-primary">Save Defaults</button>
        </div>
        <template data-comp-template="allowances">
          <div class="grid gap-2 md:grid-cols-5" data-comp-row>
            <div class="md:col-span-2">
              <label class="text-xs uppercase tracking-wide text-gray-500">Label</label>
              <input type="text" class="input-text w-full" name="allowances[label][]" placeholder="e.g., Transportation" />
            </div>
            <div>
              <label class="text-xs uppercase tracking-wide text-gray-500">Code</label>
              <input type="text" class="input-text w-full" name="allowances[code][]" placeholder="Optional" />
            </div>
            <div>
              <label class="text-xs uppercase tracking-wide text-gray-500">Amount</label>
              <input type="number" step="0.01" min="0" class="input-text w-full" name="allowances[amount][]" placeholder="0.00" />
            </div>
            <div class="flex items-end justify-end">
              <button type="button" class="btn btn-outline text-xs" data-comp-remove>Remove</button>
            </div>
          </div>
        </template>
        <template data-comp-template="deductions">
          <div class="grid gap-2 md:grid-cols-5" data-comp-row>
            <div class="md:col-span-2">
              <label class="text-xs uppercase tracking-wide text-gray-500">Label</label>
              <input type="text" class="input-text w-full" name="deductions[label][]" placeholder="e.g., SSS" />
            </div>
            <div>
              <label class="text-xs uppercase tracking-wide text-gray-500">Code</label>
              <input type="text" class="input-text w-full" name="deductions[code][]" placeholder="Optional" />
            </div>
            <div>
              <label class="text-xs uppercase tracking-wide text-gray-500">Amount</label>
              <input type="number" step="0.01" min="0" class="input-text w-full" name="deductions[amount][]" placeholder="0.00" />
            </div>
            <div class="flex items-end justify-end">
              <button type="button" class="btn btn-outline text-xs" data-comp-remove>Remove</button>
            </div>
          </div>
        </template>
      </form>
    </div>
  </section>

  <section id="payroll-approvers" class="card p-6 space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-xl font-semibold text-gray-900">Payroll Approvers</h2>
        <p class="text-sm text-gray-600">Define sequential approvals per scope. Drag cards to adjust ordering.</p>
      </div>
      <div class="text-sm text-gray-500">Active: <span class="font-medium text-gray-900"><?= (int)$approverStats['active'] ?></span> · Total steps: <span class="font-medium text-gray-900"><?= (int)$approverStats['total'] ?></span></div>
    </div>

    <form method="post" class="grid gap-3 md:grid-cols-5 text-sm bg-gray-50 border border-gray-200 rounded-lg p-4">
      <input type="hidden" name="csrf" value="<?= $csrf ?>" />
      <input type="hidden" name="action" value="add_approver" />
      <label class="md:col-span-2 block">
        <span class="text-xs uppercase tracking-wide text-gray-500">User</span>
        <select name="user_id" class="input-text w-full" required>
          <option value="">Select a user</option>
          <?php foreach ($activeUsers as $user): ?>
            <?php $label = trim(($user['full_name'] ?? '') . ' (' . strtolower($user['email'] ?? '') . ')'); ?>
            <option value="<?= (int)$user['id'] ?>">
              <?= htmlspecialchars($label) ?><?= isset($assignedGlobal[(int)$user['id']]) ? ' (already in global flow)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Step Order</span>
        <input type="number" name="step_order" min="1" class="input-text w-full" placeholder="1" required />
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Scope (optional)</span>
        <input type="text" name="applies_to" class="input-text w-full" placeholder="global" />
      </label>
      <div class="flex items-end justify-between md:justify-start gap-2">
        <label class="flex items-center gap-2 text-gray-700">
          <input type="checkbox" name="active" value="1" checked />
          <span>Active</span>
        </label>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>

    <?php if (!$approvers): ?>
      <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
        No approvers configured yet. Add at least one approver to enforce sequential approvals.
      </div>
    <?php else: ?>
      <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <p class="text-xs text-gray-500">Tip: Drag cards to reorder. Click <strong>Save Order</strong> to persist sequence numbers.</strong></p>
        <form method="post" id="approver-order-form" class="flex items-center gap-2">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="update_order_bulk" />
          <input type="hidden" name="order_payload" id="order_payload" value="" />
          <button type="submit" class="btn btn-outline">Save Order</button>
        </form>
      </div>
      <div class="space-y-3" id="approver-list">
        <?php foreach ($approvers as $approver): ?>
          <div class="border border-gray-200 rounded-xl p-4 bg-white shadow-sm approver-item" data-approver-id="<?= (int)$approver['id'] ?>">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-gray-900">
                  Step <?= (int)$approver['step_order'] ?> · <?= htmlspecialchars($approver['full_name'] ?? 'Unassigned User') ?>
                </p>
                <p class="text-xs text-gray-500 flex flex-wrap gap-3">
                  <span><?= htmlspecialchars(strtolower($approver['email'] ?? 'unknown email')) ?></span>
                  <span>Scope: <?= htmlspecialchars(($approver['applies_to'] ?? '') !== '' ? $approver['applies_to'] : 'Global') ?></span>
                </p>
              </div>
              <button type="button" class="drag-handle inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-600" title="Drag to reorder">⇅<span class="sr-only">Drag</span></button>
              <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold <?= !empty($approver['active']) ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' ?>">
                <?= !empty($approver['active']) ? 'Active' : 'Inactive' ?>
              </span>
            </div>

            <form method="post" class="mt-4 grid gap-3 md:grid-cols-4 text-sm">
              <input type="hidden" name="csrf" value="<?= $csrf ?>" />
              <input type="hidden" name="action" value="update_approver" />
              <input type="hidden" name="approver_id" value="<?= (int)$approver['id'] ?>" />
              <label class="block">
                <span class="text-xs uppercase tracking-wide text-gray-500">Step Order</span>
                <input type="number" name="step_order" min="1" class="input-text w-full" value="<?= (int)$approver['step_order'] ?>" required />
              </label>
              <label class="block">
                <span class="text-xs uppercase tracking-wide text-gray-500">Scope (optional)</span>
                <input type="text" name="applies_to" class="input-text w-full" value="<?= htmlspecialchars($approver['applies_to'] ?? '') ?>" placeholder="global" />
              </label>
              <div class="flex items-end">
                <label class="flex items-center gap-2 text-gray-700">
                  <input type="checkbox" name="active" value="1" <?= !empty($approver['active']) ? 'checked' : '' ?> />
                  <span>Active</span>
                </label>
              </div>
              <div class="flex items-end justify-end">
                <button type="submit" class="btn btn-primary w-full">Save</button>
              </div>
            </form>

            <form method="post" class="mt-3 flex items-center justify-between text-xs text-gray-500">
              <input type="hidden" name="csrf" value="<?= $csrf ?>" />
              <input type="hidden" name="action" value="delete_approver" />
              <input type="hidden" name="approver_id" value="<?= (int)$approver['id'] ?>" />
              <button type="submit" class="text-red-600 hover:underline" onclick="return confirm('Remove this approver? Existing runs tied to this approver will lose the step.');">Remove approver</button>
              <span>Updated <?= htmlspecialchars($approver['updated_at'] ?? $approver['created_at']) ?></span>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section id="leave-settings" class="space-y-4">
    <div class="card p-6 space-y-6" id="leave-defaults">
      <div>
        <h2 class="text-xl font-semibold text-gray-900">Default Leave Allowances</h2>
        <p class="text-sm text-gray-600">Set the organization-wide quota for each leave type. Leave a field blank to fall back to the system default.</p>
      </div>
      <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" />
        <input type="hidden" name="action" value="save_leave_defaults" />
        <?php foreach ($leaveTypes as $type): ?>
          <?php $value = $globalEntitlements[$type] ?? null; ?>
          <label class="block text-sm">
            <span class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(leave_label_for_type($type)) ?></span>
            <input type="number" step="0.5" min="0" class="input-text w-full mt-1" name="leave_days[<?= htmlspecialchars($type) ?>]" value="<?= htmlspecialchars(format_leave_days_input($value)) ?>" placeholder="Inherit" />
          </label>
        <?php endforeach; ?>
        <div class="sm:col-span-2 lg:col-span-3 flex items-center justify-between text-xs text-gray-500">
          <span>Blank values inherit defaults from <code>LEAVE_DEFAULT_ENTITLEMENTS</code>.</span>
          <button type="submit" class="btn btn-primary">Save Defaults</button>
        </div>
      </form>
    </div>

    <div class="card p-6 space-y-6" id="department-overrides">
      <div>
        <h2 class="text-xl font-semibold text-gray-900">Department Overrides</h2>
        <p class="text-sm text-gray-600">Fine-tune allowances per department. Clear values to restore the organization defaults.</p>
      </div>
      <?php if (!$departments): ?>
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">No departments found. Add departments first to configure overrides.</div>
      <?php else: ?>
        <div class="space-y-4">
          <?php foreach ($departments as $deptRow): ?>
            <?php
              $deptId = (int)$deptRow['id'];
              $summaryText = $departmentSummaries[$deptId] ?? 'Using global defaults';
              $overrideCount = $departmentOverrideCounts[$deptId] ?? 0;
              $effectiveSet = $departmentEffective[$deptId] ?? [];
            ?>
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
              <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($deptRow['name']) ?></p>
                  <p class="text-xs text-gray-500"><?= htmlspecialchars($summaryText) ?></p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded-full border <?= $overrideCount > 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-50 text-gray-500' ?> px-3 py-1 text-[11px] font-semibold uppercase tracking-wide">
                    <?= $overrideCount > 0 ? ($overrideCount === 1 ? '1 override active' : $overrideCount . ' overrides active') : 'No overrides' ?>
                  </span>
                  <button type="button" class="btn btn-outline px-3 py-2 text-sm" data-dept-modal="dept-modal-<?= $deptId ?>" data-dept-id="<?= $deptId ?>">Edit</button>
                </div>
              </div>
              <dl class="grid gap-3 px-4 py-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($leaveTypes as $type): ?>
                  <?php
                    $metrics = $effectiveSet[$type] ?? [
                      'override' => null,
                      'global' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                      'effective' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                    ];
                    $overrideValue = $metrics['override'];
                    $globalValue = $metrics['global'];
                    $effectiveValue = $metrics['effective'];
                  ?>
                  <div class="rounded-lg bg-gray-50 p-3">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(leave_label_for_type($type)) ?></dt>
                    <dd class="mt-1 flex items-center justify-between font-semibold text-gray-900">
                      <span><?= htmlspecialchars(format_leave_days_input($effectiveValue)) ?>d</span>
                      <?php if ($overrideValue !== null): ?>
                        <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Override</span>
                      <?php else: ?>
                        <span class="ml-2 text-[11px] uppercase tracking-wide text-gray-400">Default</span>
                      <?php endif; ?>
                    </dd>
                    <?php if ($overrideValue !== null): ?>
                      <p class="mt-1 text-xs text-gray-500">Global/default: <?= htmlspecialchars(format_leave_days_input($globalValue)) ?>d</p>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          <?php endforeach; ?>
        </div>

        <?php foreach ($departments as $deptRow): ?>
          <?php
            $deptId = (int)$deptRow['id'];
            $effectiveSet = $departmentEffective[$deptId] ?? [];
          ?>
          <div id="dept-modal-<?= $deptId ?>" class="dept-modal fixed inset-0 z-50 hidden" data-dept-id="<?= $deptId ?>" data-dept-name="<?= htmlspecialchars($deptRow['name'], ENT_QUOTES) ?>">
            <div class="absolute inset-0 bg-black/40" data-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
              <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-gray-100 px-5 py-4">
                  <div>
                    <h3 class="text-lg font-semibold text-gray-900" data-dept-modal-title>Adjust <?= htmlspecialchars($deptRow['name']) ?> overrides</h3>
                    <p class="text-xs text-gray-500">Leave a field blank to inherit the global allowance.</p>
                  </div>
                  <button type="button" class="text-gray-400 hover:text-gray-600" data-close aria-label="Close">✕</button>
                </div>
                <form method="post" id="department-form-<?= $deptId ?>" class="space-y-5 px-5 py-5" data-dept-form>
                  <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                  <input type="hidden" name="action" value="save_department_entitlements" />
                  <input type="hidden" name="department_id" value="<?= $deptId ?>" data-dept-input="department_id" />
                  <div class="grid gap-4 sm:grid-cols-2">
                    <?php foreach ($leaveTypes as $type): ?>
                      <?php
                        $metrics = $effectiveSet[$type] ?? [
                          'override' => null,
                          'global' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                          'effective' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                        ];
                        $currentOverride = $metrics['override'];
                        $currentDefault = $metrics['effective'];
                        $inheritValue = $metrics['global'];
                      ?>
                      <label class="block text-sm" data-leave-row data-leave-type="<?= htmlspecialchars($type) ?>">
                        <span class="text-xs uppercase tracking-wide text-gray-500" data-leave-label><?= htmlspecialchars(leave_label_for_type($type)) ?></span>
                        <input type="number" step="0.5" min="0" class="input-text mt-1 w-full" name="leave_days[<?= htmlspecialchars($type) ?>]" value="<?= htmlspecialchars(format_leave_days_input($currentOverride)) ?>" placeholder="Inherit" data-leave-input />
                        <p class="mt-1 text-xs text-gray-500" data-leave-meta>
                          Current: <?= htmlspecialchars(format_leave_days_input($currentDefault)) ?>d <?= $currentOverride !== null ? '(override)' : '(default)' ?>
                          <?php if ($currentOverride !== null): ?> · Global <?= htmlspecialchars(format_leave_days_input($inheritValue)) ?>d<?php endif; ?>
                        </p>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
                    Sensitive change. Provide an admin credential to confirm this override update.
                  </div>
                  <div class="grid gap-4 md:grid-cols-2">
                    <label class="block text-sm">
                      <span class="text-xs uppercase tracking-wide text-gray-500">Admin Email</span>
                      <input type="email" name="override_email" class="input-text mt-1 w-full" placeholder="admin@example.com" autocomplete="off" required />
                    </label>
                    <label class="block text-sm">
                      <span class="text-xs uppercase tracking-wide text-gray-500">Admin Password</span>
                      <input type="password" name="override_password" class="input-text mt-1 w-full" placeholder="Re-enter password" autocomplete="new-password" required />
                    </label>
                  </div>
                  <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    <button type="button" class="btn btn-outline text-sm" data-clear-target="#department-form-<?= $deptId ?>">Clear Overrides</button>
                    <div class="flex items-center gap-2">
                      <button type="button" class="btn btn-outline text-sm" data-close>Cancel</button>
                      <button type="submit" class="btn btn-primary text-sm">Save Overrides</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

  <script>
    (function(){
      const headerOffset = 88;
      document.querySelectorAll('[data-scroll-target]').forEach(function(link){
        link.addEventListener('click', function(event){
          const selector = link.getAttribute('data-scroll-target');
          if (!selector) { return; }
          const target = document.querySelector(selector);
          if (!target) { return; }
          event.preventDefault();
          const top = window.scrollY + target.getBoundingClientRect().top - headerOffset;
          window.scrollTo({ top: top < 0 ? 0 : top, behavior: 'smooth' });
          target.classList.add('ring-2', 'ring-indigo-300', 'ring-offset-2');
          setTimeout(function(){
            target.classList.remove('ring-2', 'ring-indigo-300', 'ring-offset-2');
          }, 1200);
        });
      });
    })();
  </script>
<script>
  (function(){
    const form = document.getElementById('payroll-compensation-form');
    if (!form) { return; }

    function addRow(type) {
      if (!type) { return; }
      const template = form.querySelector(`template[data-comp-template="${type}"]`);
      const list = form.querySelector(`[data-comp-list="${type}"]`);
      if (!template || !list) { return; }
      const clone = template.content.firstElementChild.cloneNode(true);
      list.appendChild(clone);
      const focusInput = clone.querySelector('input');
      if (focusInput) { focusInput.focus(); }
    }

    form.querySelectorAll('[data-comp-add]').forEach(function(btn){
      btn.addEventListener('click', function(){
        addRow(this.getAttribute('data-comp-add'));
      });
    });

    form.addEventListener('click', function(event){
      const removeBtn = event.target.closest('[data-comp-remove]');
      if (!removeBtn) { return; }
      const row = removeBtn.closest('[data-comp-row]');
      if (!row) { return; }
      const list = row.parentElement;
      row.remove();
      const type = list ? list.getAttribute('data-comp-list') : null;
      if (type && !list.querySelector('[data-comp-row]')) {
        addRow(type);
      }
    });
  })();
</script>
<script>
(function(){
  const list = document.getElementById('approver-list');
  if (!list) return;
  let dragEl = null; let placeholder = document.createElement('div');
  placeholder.className = 'border border-dashed border-gray-300 rounded p-3 my-1 bg-gray-50';
  list.addEventListener('dragstart', (e) => {
    const item = e.target.closest('.approver-item');
    if (!item) return; dragEl = item; item.classList.add('opacity-60');
    e.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragend', () => { if (dragEl) dragEl.classList.remove('opacity-60'); dragEl = null; placeholder.remove(); });
  list.addEventListener('dragover', (e) => {
    e.preventDefault(); const over = e.target.closest('.approver-item'); if (!over || over===dragEl) return;
    const rect = over.getBoundingClientRect(); const before = (e.clientY - rect.top) < rect.height/2;
    list.insertBefore(placeholder, before ? over : over.nextSibling);
  });
  list.querySelectorAll('.approver-item').forEach(it => { it.draggable = true; });
  const btns = list.querySelectorAll('.drag-handle'); btns.forEach(b => { b.addEventListener('mousedown', (e)=>{ const it=e.target.closest('.approver-item'); if(it){ it.draggable=true; } }); });
  const form = document.getElementById('approver-order-form');
  form?.addEventListener('submit', ()=>{
    const ids = Array.from(list.querySelectorAll('.approver-item')).map((el, idx)=>({id: parseInt(el.dataset.approverId||'0'), step_order: idx+1}));
    document.getElementById('order_payload').value = JSON.stringify(ids);
  });
})();

document.querySelectorAll('[data-clear-target]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const selector = btn.getAttribute('data-clear-target');
    if (!selector) return;
    const form = document.querySelector(selector);
    if (!form) return;
    const inputs = form.querySelectorAll('input[type="number"]');
    inputs.forEach((input) => { input.value = ''; });
  });
});

(function(){
  function bindDepartmentModals(scope = document) {
    scope.querySelectorAll('[data-dept-modal]').forEach((btn) => {
      if (btn.dataset.deptModalBound) return;
      btn.dataset.deptModalBound = '1';
      const target = btn.getAttribute('data-dept-modal');
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        if (target) {
          openModal(target);
        }
      });
    });
    scope.querySelectorAll('.dept-modal').forEach((modal) => {
      if (modal.dataset.modalBound) return;
      modal.dataset.modalBound = '1';
      modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
          closeModal(modal.id);
        }
      });
    });
  }
  bindDepartmentModals(document);
  document.addEventListener('spa:loaded', () => bindDepartmentModals(document));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.dept-modal').forEach((modal) => {
        if (!modal.classList.contains('hidden')) {
          closeModal(modal.id);
        }
      });
    }
  });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
