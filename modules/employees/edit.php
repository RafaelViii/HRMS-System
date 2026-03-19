 <?php
/**
 * Employee Edit Module - Modernized with Tabbed Interface
 * Features: Personal Info, Compensation, Leave, Overtime Management
 */

require_once __DIR__ . '/../../includes/auth.php';
require_login();
// Allow single-use override token for read-only users
$tok = $_GET['authz'] ?? '';
$idTok = $_GET['id'] ?? null;
if ($tok && consume_override_token($tok, 'employees', 'edit_employee', $idTok)) {
  // Override token consumed - allow access
} else {
  require_access('hr_core', 'employees', 'write');
}
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';
require_once __DIR__ . '/../../includes/encryption.php';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);

// ================================================================
// UNBIND USER ACCOUNT
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unbind_user'])) {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); exit;
  }
  $eid = (int)($_POST['employee_id'] ?? 0);
  if ($eid !== $id || $id <= 0) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); exit;
  }
  try {
    $est = $pdo->prepare('SELECT user_id, employee_code FROM employees WHERE id = :id AND deleted_at IS NULL');
    $est->execute([':id' => $id]);
    $empRow = $est->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $empRow = [];
    sys_log('DB3200', 'Prepare/execute failed: employee fetch for unbind - ' . $e->getMessage(), ['module' => 'employees', 'file' => __FILE__, 'line' => __LINE__]);
  }
  $uid = (int)($empRow['user_id'] ?? 0);
  if ($uid <= 0) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); exit;
  }
  $ok = false;
  try {
    $pdo->beginTransaction();
    try { $pdo->exec('CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL)'); } catch (Throwable $e) {}
    try {
      $bk = $pdo->prepare('INSERT INTO users_backup OVERRIDING SYSTEM VALUE SELECT * FROM users WHERE id = :uid');
      $bk->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
    try {
      $pdo->prepare('UPDATE action_reversals SET reversed_by = NULL WHERE reversed_by = :uid')->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
    try {
      $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :uid')->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
    $dst = $pdo->prepare('DELETE FROM users WHERE id = :uid');
    $dst->execute([':uid' => $uid]);
    $pdo->commit();
    remember_clear_tokens($uid);
    $ok = true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      try { $pdo->rollBack(); } catch (Throwable $inner) {}
    }
    sys_log('DB3201', 'Unbind account failed - ' . $e->getMessage(), ['module' => 'employees', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['id' => $id, 'user' => $uid]]);
  }
  if ($ok) {
    audit('unbind_account', 'emp=' . ($empRow['employee_code'] ?? '') . ', user_id=' . $uid);
    flash_success('Changes have been saved');
  } else {
    flash_error('Changes could not be saved');
  }
  header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); exit;
}

// ================================================================
// FETCH EMPLOYEE DATA
// ================================================================
try { 
  $stmt = $pdo->prepare('SELECT e.*, p.base_salary AS position_base_salary, u.status AS account_status FROM employees e LEFT JOIN positions p ON p.id = e.position_id AND p.deleted_at IS NULL LEFT JOIN users u ON u.id = e.user_id WHERE e.id = :id AND e.deleted_at IS NULL'); 
  $stmt->execute([':id' => $id]); 
  $emp = $stmt->fetch(PDO::FETCH_ASSOC); 
} catch (Throwable $e) { 
  sys_log('DB2100', 'Fetch employee failed - '.$e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]); 
  $emp = null; 
}

$branches = branches_fetch_all($pdo);
$branchIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $branches);
try { $deps = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $deps = []; }
try { $poses = $pdo->query('SELECT id, name FROM positions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $poses = []; }

$error = '';
$leaveTypes = leave_get_known_types($pdo);
$compDefaults = payroll_get_compensation_defaults($pdo);
$compOverride = $id > 0 ? payroll_get_employee_compensation($pdo, $id) : null;
$compAllowances = array_values($compOverride['allowances'] ?? []);
$compDeductions = array_values($compOverride['deductions'] ?? []);
$compNotes = $compOverride['notes'] ?? '';
$compDefaultTax = isset($compDefaults['tax_percentage']) && $compDefaults['tax_percentage'] !== null ? (float)$compDefaults['tax_percentage'] : null;
$compOverrideTax = (is_array($compOverride) && isset($compOverride['tax_percentage']) && $compOverride['tax_percentage'] !== null) ? (float)$compOverride['tax_percentage'] : null;
$compEffectiveTax = $compOverrideTax !== null ? $compOverrideTax : $compDefaultTax;
$compTaxFieldValue = $compOverrideTax !== null ? rtrim(rtrim(number_format($compOverrideTax, 2, '.', ''), '0'), '.') : '';
$compDefaultTaxLabel = $compDefaultTax !== null ? rtrim(rtrim(number_format($compDefaultTax, 2, '.', ''), '0'), '.') . '%' : 'Not configured';
$compEffectiveTaxLabel = $compEffectiveTax !== null ? rtrim(rtrim(number_format($compEffectiveTax, 2, '.', ''), '0'), '.') . '%' : 'Not configured';
$compOverrideTaxLabel = $compOverrideTax !== null ? rtrim(rtrim(number_format($compOverrideTax, 2, '.', ''), '0'), '.') . '%' : null;
$compTaxPlaceholderHint = $compDefaultTax !== null ? ('the company default of ' . $compDefaultTaxLabel) : 'the company default rate (currently not set)';
$compAllowanceRows = $compAllowances ?: [['code' => '', 'label' => '', 'amount' => '']];
$compDeductionRows = $compDeductions ?: [['code' => '', 'label' => '', 'amount' => '']];
$hasCompOverride = $compOverride !== null;

// Fetch overtime requests for this employee
try {
  $otStmt = $pdo->prepare('
    SELECT 
      ot.*,
      u.full_name AS approver_name,
      pr.id AS payroll_run_id,
      pr.status AS payroll_run_status
    FROM overtime_requests ot
    LEFT JOIN users u ON ot.approved_by = u.id
    LEFT JOIN payroll_runs pr ON ot.included_in_payroll_run_id = pr.id
    WHERE ot.employee_id = :emp_id
    ORDER BY ot.overtime_date DESC, ot.created_at DESC
    LIMIT 100
  ');
  $otStmt->execute([':emp_id' => $id]);
  $overtimeRequests = $otStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  sys_log('OT-FETCH', 'Failed fetching overtime requests - ' . $e->getMessage(), ['module' => 'employees', 'file' => __FILE__, 'line' => __LINE__]);
  $overtimeRequests = [];
}

if (!function_exists('leave_format_days_compact')) {
  function leave_format_days_compact($value): string {
    if ($value === null || $value === '') { return ''; }
    $formatted = number_format((float)$value, 2, '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');
    return $trimmed === '' ? '0' : $trimmed;
  }
}

// ================================================================
// POST HANDLERS
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { 
    $error = 'Invalid CSRF token'; 
  } else {
    // DELETE EMPLOYEE
    if (isset($_POST['delete_employee'])) {
      // Log for debugging
      error_log('DELETE EMPLOYEE - User: ' . $currentUserId . ', Override Email: ' . ($_POST['override_email'] ?? 'NONE') . ', Override Force: ' . ($_POST['override_force'] ?? 'NONE'));
      
      // Validate authorization - use domain.resource format
      $authz = ensure_action_authorized('hr_core.employees', 'delete_employee', 'write');
      
      // Log auth result
      error_log('AUTH RESULT: ' . json_encode($authz));
      
      if (!$authz['ok']) {
        $errorMsg = 'Authorization failed';
        if (isset($authz['error'])) {
          if ($authz['error'] === 'no_access') {
            $errorMsg = 'You do not have access to delete employees';
          } elseif ($authz['error'] === 'override_failed') {
            $errorMsg = 'Invalid authorization credentials - please verify your email and password';
          }
        }
        error_log('DELETE FAILED: ' . $errorMsg . ' - ' . json_encode($authz));
        flash_error($errorMsg);
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); 
        exit;
      }
      
      // Authorization successful - proceed with deletion
      try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT user_id, employee_code FROM employees WHERE id = :id AND deleted_at IS NULL');
        $st->execute([':id'=>$id]);
        $empData = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$empData) {
          throw new Exception('Employee not found');
        }
        
        $uid = (int)($empData['user_id'] ?? 0) ?: null;
        $empCode = $empData['employee_code'] ?? '';
        
        // Delete associated user account if exists
        if ($uid) {
          // Delete user access permissions
          $pdo->prepare('DELETE FROM user_access_permissions WHERE user_id = :uid')->execute([':uid'=>$uid]);
          
          // Backup user record
          $pdo->exec('CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL)');
          try { 
            $bk=$pdo->prepare('INSERT INTO users_backup OVERRIDING SYSTEM VALUE SELECT * FROM users WHERE id=:id'); 
            $bk->execute([':id'=>$uid]); 
          } catch (Throwable $e) {
            // Ignore duplicate key errors on backup
            if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'unique') === false) {
              throw $e;
            }
          }
          
          // Nullify foreign key references that would block deletion
          $pdo->prepare('UPDATE action_reversals SET reversed_by = NULL WHERE reversed_by = :uid')->execute([':uid'=>$uid]);
          
          // Delete remember tokens
          $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :uid')->execute([':uid'=>$uid]);
          
          // Delete the user account
          $pdo->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$uid]);
          
          audit('delete_account_cascade', 'user_id=' . $uid . ' via employee_id=' . $id);
        }
        
        // Delete employee record
        if (!backup_then_delete($pdo, 'employees', 'id', $id)) { 
          throw new Exception('backup_then_delete failed'); 
        }
        
        $pdo->commit();
        audit('delete_employee', 'ID=' . $id . ' Code=' . $empCode);
        action_log('employees', 'delete_employee', 'success', ['id' => $id, 'code' => $empCode]);
        flash_success('Employee deleted successfully');
        header('Location: ' . BASE_URL . '/modules/employees/index'); 
        exit;
      } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
        sys_log('DB2105', 'Delete employee failed - '.$e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]);
        flash_error('Unable to delete employee. Please try again.');
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id); 
        exit;
      }
    }
    
    // OVERTIME APPROVAL/REJECTION
    elseif (isset($_POST['overtime_action'])) {
      $otId = (int)($_POST['overtime_id'] ?? 0);
      $action = $_POST['overtime_action']; // approve or reject
      $rejectionReason = trim($_POST['rejection_reason'] ?? '');
      
      if ($otId <= 0) {
        flash_error('Invalid overtime request');
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#overtime'); exit;
      }
      
      if (!in_array($action, ['approve', 'reject'], true)) {
        flash_error('Invalid action');
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#overtime'); exit;
      }
      
      if ($action === 'reject' && $rejectionReason === '') {
        flash_error('Rejection reason is required');
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#overtime'); exit;
      }
      
      try {
        $pdo->beginTransaction();
        
        // Verify overtime belongs to this employee
        $verifyStmt = $pdo->prepare('SELECT employee_id, status FROM overtime_requests WHERE id = :id');
        $verifyStmt->execute([':id' => $otId]);
        $otRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otRow || (int)$otRow['employee_id'] !== $id) {
          throw new Exception('Overtime request not found or does not belong to this employee');
        }
        
        if ($otRow['status'] !== 'pending') {
          throw new Exception('Overtime request has already been processed');
        }
        
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $updateStmt = $pdo->prepare('
          UPDATE overtime_requests 
          SET status = :status,
              approved_by = :approver,
              approved_at = CURRENT_TIMESTAMP,
              rejection_reason = :reason
          WHERE id = :id
        ');
        $updateStmt->execute([
          ':status' => $newStatus,
          ':approver' => $currentUserId,
          ':reason' => $action === 'reject' ? $rejectionReason : null,
          ':id' => $otId
        ]);
        
        $pdo->commit();
        
        action_log('overtime', 'overtime_' . $action . 'd', 'success', ['overtime_id' => $otId, 'employee_id' => $id]);
        audit('overtime_' . $action . 'd', json_encode(['id' => $otId, 'employee_id' => $id], JSON_UNESCAPED_SLASHES));
        flash_success('Overtime request ' . $action . 'd successfully');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('OT-ACTION', 'Failed to ' . $action . ' overtime - ' . $e->getMessage(), ['module' => 'employees', 'file' => __FILE__, 'line' => __LINE__]);
        flash_error('Failed to process overtime request');
      }
      
      header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#overtime'); exit;
    }
    
    // LEAVE ENTITLEMENTS
    elseif (isset($_POST['form']) && $_POST['form'] === 'employee_leave') {
      $payload = $_POST['leave_days'] ?? [];
      if (!is_array($payload)) {
        flash_error('Invalid leave payload.');
        header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#leave');
        exit;
      }
      $entries = [];
      foreach ($leaveTypes as $type) {
        $raw = trim((string)($payload[$type] ?? ''));
        if ($raw === '') {
          $entries[$type] = null;
          continue;
        }
        if (!is_numeric($raw)) {
          flash_error('Leave overrides must be numeric.');
          header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#leave');
          exit;
        }
        $entries[$type] = max(0, (float)$raw);
      }
      try {
        $pdo->beginTransaction();
        $deleteStmt = $pdo->prepare('DELETE FROM leave_entitlements WHERE scope_type = :scope AND scope_id = :scope_id AND leave_type = :type');
        $upsertStmt = $pdo->prepare('INSERT INTO leave_entitlements (scope_type, scope_id, leave_type, days) VALUES (:scope, :scope_id, :type, :days) ON CONFLICT (scope_type, scope_id, leave_type) DO UPDATE SET days = EXCLUDED.days, updated_at = CURRENT_TIMESTAMP');
        $updatedCount = 0;
        foreach ($entries as $type => $value) {
          $base = [':scope' => 'employee', ':scope_id' => $id, ':type' => $type];
          if ($value === null) {
            $deleteStmt->execute($base);
            continue;
          }
          $upsertStmt->execute($base + [':days' => $value]);
          $updatedCount++;
        }
        $pdo->commit();
        $payloadLog = ['employee_id' => $id, 'overrides' => array_filter($entries, static fn($v) => $v !== null)];
        action_log('leave', 'employee_entitlements_saved', 'success', ['employee_id' => $id, 'count' => $updatedCount]);
        audit('employee_leave_override', json_encode($payloadLog, JSON_UNESCAPED_SLASHES));
        flash_success($updatedCount > 0 ? 'Leave overrides saved.' : 'Leave overrides cleared.');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('LEAVE-EMPLOYEE-SAVE', 'Failed saving employee leave overrides: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $id]]);
        flash_error('Unable to save leave overrides.');
      }
      header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#leave');
      exit;
    }

    // SALARY UPDATE (from Salary Rate tab)
    elseif (isset($_POST['form']) && $_POST['form'] === 'employee_salary') {
      $salaryRaw = trim((string)($_POST['salary'] ?? ''));
      if ($salaryRaw === '' || !is_numeric($salaryRaw)) {
        flash_error('Salary must be a valid number.');
      } else {
        $salaryVal = max(0, (float)$salaryRaw);
        try {
          $stmt = $pdo->prepare('UPDATE employees SET salary = :salary WHERE id = :id');
          $stmt->execute([':salary' => $salaryVal, ':id' => $id]);
          action_log('employees', 'update_salary', 'success', ['employee_id' => $id, 'salary' => $salaryVal]);
          audit('update_employee_salary', json_encode(['employee_id' => $id, 'salary' => $salaryVal], JSON_UNESCAPED_SLASHES));
          flash_success('Salary updated successfully.');
        } catch (Throwable $e) {
          sys_log('DB2103', 'Failed updating employee salary: ' . $e->getMessage(), ['module' => 'employees', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $id]]);
          flash_error('Unable to update salary.');
        }
      }
      header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '#compensation');
      exit;
    }
    
    // COMPENSATION OVERRIDES
    elseif (isset($_POST['form']) && $_POST['form'] === 'employee_compensation') {
      $redirectUrl = BASE_URL . '/modules/employees/edit?id=' . $id . '#compensation';
      $action = $_POST['comp_action'] ?? 'save';
      if ($action === 'clear') {
        if (payroll_delete_employee_compensation_override($pdo, $id)) {
          action_log('payroll', 'employee_comp_override_cleared', 'success', ['employee_id' => $id]);
          audit('employee_comp_override_cleared', json_encode(['employee_id' => $id], JSON_UNESCAPED_SLASHES));
          flash_success('Compensation overrides cleared.');
        } else {
          flash_error('Unable to clear overrides.');
        }
        header('Location: ' . $redirectUrl);
        exit;
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
      $notes = trim((string)($_POST['comp_notes'] ?? ''));
      $taxRaw = trim((string)($_POST['tax_percentage'] ?? ''));
      $taxOverride = null;
      if ($taxRaw !== '') {
        if (!is_numeric($taxRaw)) {
          $collector[] = 'Tax percentage override must be numeric.';
        } else {
          $taxOverride = max(0.0, min(100.0, (float)$taxRaw));
        }
      }

      if ($collector) {
        flash_error(implode(' ', array_unique($collector)));
        header('Location: ' . $redirectUrl);
        exit;
      }

      $saved = payroll_save_employee_compensation($pdo, $id, $allowancesItems, $deductionsItems, $notes, $currentUserId ?: null, $taxOverride);
      if ($saved) {
        action_log('payroll', 'employee_comp_override_saved', 'success', ['employee_id' => $id, 'allowances' => count($allowancesItems), 'deductions' => count($deductionsItems), 'tax_override' => $taxOverride]);
        audit('employee_comp_override_saved', json_encode(['employee_id' => $id, 'allowances' => $allowancesItems, 'deductions' => $deductionsItems, 'tax_percentage' => $taxOverride], JSON_UNESCAPED_SLASHES));
        flash_success('Compensation overrides saved.');
      } else {
        flash_error('Unable to save overrides.');
      }
      header('Location: ' . $redirectUrl);
      exit;
    }
    
    // UPDATE EMPLOYEE DETAILS
    else {
      $code = trim($_POST['employee_code'] ?? '');
      $first = trim($_POST['first_name'] ?? '');
      $last = trim($_POST['last_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $phone = trim($_POST['phone'] ?? '');
      $address = trim($_POST['address'] ?? '');
      $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
      $pos  = ($_POST['position_id'] ?? '') !== '' ? (int)$_POST['position_id'] : null;
      $branch = ($_POST['branch_id'] ?? '') !== '' ? (int)$_POST['branch_id'] : null;
      if ($branch !== null && !in_array($branch, $branchIds, true)) {
        $branch = null;
      }
      $hire = ($_POST['hire_date'] ?? '') ?: null;
      $etype = $_POST['employment_type'] ?? 'regular';
      $status = $_POST['status'] ?? 'active';
      $sss_number = trim($_POST['sss_number'] ?? '');
      $philhealth_number = trim($_POST['philhealth_number'] ?? '');
      $pagibig_number = trim($_POST['pagibig_number'] ?? '');
      $tin = trim($_POST['tin'] ?? '');
      $bank_account_number = trim($_POST['bank_account_number'] ?? '');
      $bank_name = trim($_POST['bank_name'] ?? '');
      if ($code===''||$first===''||$last===''||$email==='') { 
        $error='Required fields missing'; 
      } else {
        try {
          $stmt = $pdo->prepare('UPDATE employees SET employee_code = :code, first_name = :first, last_name = :last, email = :email, phone = :phone, address = :address, department_id = :dept, position_id = :pos, branch_id = :branch, hire_date = :hire, employment_type = :etype, status = :status, sss_number = :sss, philhealth_number = :philhealth, pagibig_number = :pagibig, tin = :tin, bank_account_number = :bank_acct, bank_name = :bank_name WHERE id = :id');
          $stmt->execute([
            ':code' => $code,
            ':first' => $first,
            ':last' => $last,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':dept' => $dept,
            ':pos' => $pos,
            ':branch' => $branch,
            ':hire' => $hire,
            ':etype' => $etype,
            ':status' => $status,
            ':sss' => encrypt_field($sss_number ?: null),
            ':philhealth' => encrypt_field($philhealth_number ?: null),
            ':pagibig' => encrypt_field($pagibig_number ?: null),
            ':tin' => encrypt_field($tin ?: null),
            ':bank_acct' => encrypt_field($bank_account_number ?: null),
            ':bank_name' => $bank_name ?: null,
            ':id' => $id,
          ]);
          audit('update_employee', $code);
          flash_success('Changes have been saved');
          header('Location: ' . BASE_URL . '/modules/employees/view?id='.$id);
          exit;
        } catch (Throwable $e) {
          $msg = $e->getMessage();
          if (str_contains($msg, 'duplicate') || str_contains($msg, 'unique')) { 
            $error = 'Employee code or email already exists.'; 
          } else { 
            sys_log('DB2102', 'Execute failed: employees update - ' . $e->getMessage(), ['module'=>'employees', 'file'=>__FILE__, 'line'=>__LINE__]); 
            show_system_error('Could not update employee at this time.'); 
          }
        }
      }
    }
  }
}

$hasAccount = !empty($emp['user_id']);
$defaultAllowances = $compDefaults['allowances'] ?? [];
$defaultDeductions = $compDefaults['deductions'] ?? [];
$leaveLayers = $id > 0 ? leave_collect_entitlement_layers($pdo, $id) : ['defaults' => [], 'global' => [], 'department' => [], 'employee' => [], 'effective' => [], 'sources' => []];
$effectiveLeaveEntitlements = $leaveLayers['effective'] ?? [];
$employeeLeaveOverrides = $leaveLayers['employee'] ?? [];
$leaveSourceMap = $leaveLayers['sources'] ?? [];
$leaveSourceLabels = [
  'defaults' => 'System Default',
  'global' => 'Global Override',
  'department' => 'Department Override',
  'employee' => 'Employee Override',
];

// Get active tab from URL hash or default to 'personal'
$activeTab = $_GET['tab'] ?? 'personal';
if (!in_array($activeTab, ['personal', 'compensation', 'leave', 'overtime'], true)) {
  $activeTab = 'personal';
}

require_once __DIR__ . '/../../includes/header.php';

if (!$emp) { 
  echo '<div class="p-3">Employee not found</div>'; 
  require_once __DIR__ . '/../../includes/footer.php'; 
  exit; 
}
?>

<style>
/* Modern Tab Styling */
.tabs-container {
  display: flex;
  border-bottom: 2px solid #e5e7eb;
  gap: 0.5rem;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.tab-button {
  padding: 0.75rem 1.5rem;
  font-weight: 500;
  color: #6b7280;
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
  position: relative;
}

.tab-button:hover {
  color: #3b82f6;
  background: #eff6ff;
}

.tab-button.active {
  color: #2563eb;
  border-bottom-color: #2563eb;
  background: #eff6ff;
}

.tab-button .badge {
  display: inline-block;
  margin-left: 0.5rem;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  border-radius: 9999px;
}

.tab-content {
  display: none;
}

.tab-content.active {
  display: block;
  animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Status Badge Colors */
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.status-paid { background: #dbeafe; color: #1e40af; }

/* Overtime Table Styling */
.overtime-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}

.overtime-table th {
  background: #f9fafb;
  padding: 0.75rem 1rem;
  text-align: left;
  font-weight: 600;
  font-size: 0.875rem;
  color: #374151;
  border-bottom: 2px solid #e5e7eb;
}

.overtime-table td {
  padding: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.overtime-table tbody tr:hover {
  background: #f9fafb;
}

.overtime-table tbody tr:last-child td {
  border-bottom: none;
}

/* Card Styling */
.info-card {
  background: white;
  border-radius: 0.75rem;
  padding: 1.5rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
  border: 1px solid #e5e7eb;
}

.info-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.info-card-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: #111827;
}

.info-card-subtitle {
  font-size: 0.875rem;
  color: #6b7280;
  margin-top: 0.25rem;
}

/* Responsive */
@media (max-width: 768px) {
  .tab-button {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
  }
  
  .info-card {
    padding: 1rem;
  }
}
</style>

<div class="max-w-7xl mx-auto">
  <!-- Header -->
  <div class="flex flex-col gap-3 mb-6 md:flex-row md:items-center md:justify-between">
    <div class="flex items-center gap-3">
      <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/employees/index">← Back</a>
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Edit Employee</h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')) ?> • <?= htmlspecialchars($emp['employee_code'] ?? '') ?></p>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?php if (!$hasAccount): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/account/create?employee_id=<?= (int)$emp['id'] ?>">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
          Create Account
        </a>
      <?php else: ?>
        <span class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide rounded-full bg-green-100 text-green-800">
          Account: <?= htmlspecialchars($emp['account_status'] ?: 'active') ?>
        </span>
        <a class="btn btn-accent" href="<?= BASE_URL ?>/modules/account/edit?id=<?= (int)$emp['user_id'] ?>">
          Manage Account
        </a>
        <form method="post" class="inline" data-confirm="Are you sure you want to unbind this account? This will permanently delete the user account and remove portal access.">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
          <button name="unbind_user" value="1" class="btn btn-danger">Remove Account</button>
        </form>
      <?php endif; ?>
      <form method="post" class="inline" data-confirm="Delete this employee? This cannot be undone." data-authz-module="hr_core.employees" data-authz-required="write" data-authz-force data-authz-action="Delete employee profile">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="delete_employee" value="1">
        <button type="submit" class="btn btn-danger">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          Delete
        </button>
      </form>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
        </div>
        <div class="ml-3">
          <p class="text-sm text-red-700"><?= htmlspecialchars($error) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['__authz_form'])): $af = $_SESSION['__authz_form']; unset($_SESSION['__authz_form']); ?>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      const modal = document.getElementById('authzModal');
      const notice = document.getElementById('authzNotice');
      const form = document.getElementById('authzForm');
      const email = document.getElementById('authzEmail');
      const emailError = document.getElementById('authzEmailError');
      if (!modal || !notice || !form || !email) return;
      notice.classList.add('hidden');
      form.classList.remove('hidden');
      modal.classList.remove('hidden');
      email.value = <?= json_encode($af['email'] ?? '') ?>;
      const type = <?= json_encode($af['error'] ?? 'invalid') ?>;
      if (emailError) {
        emailError.classList.remove('hidden');
        emailError.textContent = (type === 'insufficient') ? "The account doesn't have enough access" : "Account invalid";
      }
    });
  </script>
  <?php endif; ?>

  <!-- Tabs Navigation -->
  <div class="bg-white rounded-t-xl shadow-sm border border-gray-200 border-b-0">
    <div class="tabs-container px-4">
      <button class="tab-button <?= $activeTab === 'personal' ? 'active' : '' ?>" data-tab="personal">
        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        Personal Info
      </button>
      <button class="tab-button <?= $activeTab === 'compensation' ? 'active' : '' ?>" data-tab="compensation">
        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Salary Rate
        <?php if ($hasCompOverride): ?>
          <span class="badge bg-blue-100 text-blue-800">Custom</span>
        <?php endif; ?>
      </button>
      <button class="tab-button <?= $activeTab === 'leave' ? 'active' : '' ?>" data-tab="leave">
        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Leave Entitlements
        <?php if ($employeeLeaveOverrides): ?>
          <span class="badge bg-blue-100 text-blue-800"><?= count($employeeLeaveOverrides) ?></span>
        <?php endif; ?>
      </button>
      <button class="tab-button <?= $activeTab === 'overtime' ? 'active' : '' ?>" data-tab="overtime">
        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Overtime
        <?php 
          $pendingOTCount = count(array_filter($overtimeRequests, fn($ot) => $ot['status'] === 'pending'));
          if ($pendingOTCount > 0): 
        ?>
          <span class="badge bg-yellow-100 text-yellow-800"><?= $pendingOTCount ?> pending</span>
        <?php endif; ?>
      </button>
    </div>
  </div>

  <!-- Tab Content Container -->
  <div class="bg-white rounded-b-xl shadow-sm border border-gray-200 p-6">
    <?php
    // Include tab content sections
    include __DIR__ . '/edit_tabs/personal.php';
    include __DIR__ . '/edit_tabs/compensation.php';
    include __DIR__ . '/edit_tabs/leave.php';
    include __DIR__ . '/edit_tabs/overtime.php';
    ?>
  </div>
</div>

<script>
// Tab switching logic
document.addEventListener('DOMContentLoaded', function() {
  const tabButtons = document.querySelectorAll('.tab-button');
  const tabContents = document.querySelectorAll('.tab-content');
  
  // Handle hash-based tab switching
  function switchTab(tabName) {
    tabButtons.forEach(btn => {
      if (btn.dataset.tab === tabName) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });
    
    tabContents.forEach(content => {
      if (content.id === 'tab-' + tabName) {
        content.classList.add('active');
      } else {
        content.classList.remove('active');
      }
    });
  }
  
  tabButtons.forEach(button => {
    button.addEventListener('click', function() {
      const tabName = this.dataset.tab;
      switchTab(tabName);
      window.location.hash = tabName;
    });
  });
  
  // Handle initial load and browser back/forward
  function handleHashChange() {
    const hash = window.location.hash.replace('#', '');
    if (hash && ['personal', 'compensation', 'leave', 'overtime'].includes(hash)) {
      switchTab(hash);
    }
  }
  
  window.addEventListener('hashchange', handleHashChange);
  handleHashChange(); // Call on load
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
