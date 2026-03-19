<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'employees', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/encryption.php';
$pdo = get_db_conn();

$deps = [];
$poses = [];
try {
  $deps = $pdo->query('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
  $poses = $pdo->query('SELECT id, name FROM positions WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('DB2100', 'Failed to load deps/positions - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
}
$branches = branches_fetch_all($pdo);
$branchMap = [];
foreach ($branches as $branch) {
  $branchMap[(int)$branch['id']] = $branch;
}
$defaultBranchId = branches_get_default_id($pdo);
$selectedBranchId = $defaultBranchId;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token';
  } else {
    $code = trim($_POST['employee_code'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $pos  = ($_POST['position_id'] ?? '') !== '' ? (int)$_POST['position_id'] : null;
    $branchInput = (int)($_POST['branch_id'] ?? 0);
    $branchId = null;
    $hire = ($_POST['hire_date'] ?? '') ?: null;
    $etype = $_POST['employment_type'] ?? 'regular';
    $status = $_POST['status'] ?? 'active';
    $salary = is_numeric($_POST['salary'] ?? '') ? (float)$_POST['salary'] : 0;
    $sss_number = trim($_POST['sss_number'] ?? '');
    $philhealth_number = trim($_POST['philhealth_number'] ?? '');
    $pagibig_number = trim($_POST['pagibig_number'] ?? '');
    $tin = trim($_POST['tin'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    if (!empty($branchMap)) {
      if ($branchInput > 0 && isset($branchMap[$branchInput])) {
        $branchId = $branchInput;
      } elseif ($defaultBranchId && isset($branchMap[$defaultBranchId])) {
        $branchId = $defaultBranchId;
      }
    }
    if ($branchInput > 0) {
      $selectedBranchId = $branchInput;
    }
    if ($branchId !== null) {
      $selectedBranchId = $branchId;
    }

    if ($code === '' || $first === '' || $last === '' || $email === '') {
      $error = 'Code, First, Last, Email required';
    } else {
      // Duplicate checks
      try {
        $ck = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_code = :code AND deleted_at IS NULL');
        $ck->execute([':code' => $code]);
        if ((int)$ck->fetchColumn() > 0) { $error = 'Employee code already exists.'; }
        if (!$error) {
          $ck2 = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE email = :email AND deleted_at IS NULL');
          $ck2->execute([':email' => $email]);
          if ((int)$ck2->fetchColumn() > 0) { $error = 'Employee email already exists.'; }
        }
      } catch (Throwable $e) { /* non-fatal */ }

      if (!$error) {
        // Prepare employee insert (no account linking here)
        try {
          // Note: column is "salary" in PostgreSQL schema (not base_salary)
          $stmt = $pdo->prepare('INSERT INTO employees (employee_code, first_name, last_name, email, phone, address, department_id, position_id, branch_id, hire_date, employment_type, status, salary, sss_number, philhealth_number, pagibig_number, tin, bank_account_number, bank_name) VALUES (:code, :first, :last, :email, :phone, :address, :dept, :pos, :branch, :hire, :etype, :status, :salary, :sss, :philhealth, :pagibig, :tin, :bank_acct, :bank_name) RETURNING id');
          $stmt->execute([
            ':code' => $code,
            ':first' => $first,
            ':last' => $last,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':dept' => $dept,
            ':pos' => $pos,
            ':branch' => $branchId,
            ':hire' => $hire,
            ':etype' => $etype,
            ':status' => $status,
            ':salary' => $salary,
            ':sss' => encrypt_field($sss_number ?: null),
            ':philhealth' => encrypt_field($philhealth_number ?: null),
            ':pagibig' => encrypt_field($pagibig_number ?: null),
            ':tin' => encrypt_field($tin ?: null),
            ':bank_acct' => encrypt_field($bank_account_number ?: null),
            ':bank_name' => $bank_name ?: null,
          ]);
          $newId = (int)($stmt->fetchColumn() ?: 0);
          audit('create_employee', $code . ' ' . $first . ' ' . $last);
          action_log('employees', 'create_employee', 'success', ['employee_id' => $newId, 'code' => $code]);
          header('Location: ' . BASE_URL . '/modules/employees/view?id=' . $newId);
          exit;
        } catch (Throwable $e) {
          $msg = $e->getMessage();
          if (str_contains(strtolower($msg), 'duplicate') || str_contains(strtolower($msg), 'unique')) {
            $error = 'Employee code or email already exists.';
          } else {
            sys_log('DB2102', 'Execute failed: employees insert - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
            $error = 'Could not save employee at this time.';
          }
        }
      }
    }
  }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="max-w-3xl">
  <div class="flex items-center gap-3 mb-4">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/employees/index">Back</a>
    <h1 class="text-xl font-semibold">Add Employee</h1>
  </div>
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="bg-white p-4 rounded shadow grid md:grid-cols-2 gap-4" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <?php $selectedBranchId = (int)($selectedBranchId ?? 0); ?>
    <div>
      <label class="form-label">Employee Code</label>
      <input name="employee_code" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">Email</label>
      <input name="email" type="email" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">First Name</label>
      <input name="first_name" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">Last Name</label>
      <input name="last_name" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">Phone</label>
      <input name="phone" class="w-full border rounded px-3 py-2">
    </div>
    <div class="md:col-span-2">
      <label class="form-label">Address</label>
      <input name="address" class="w-full border rounded px-3 py-2">
    </div>
    <div>
      <label class="form-label">Department</label>
      <select name="department_id" class="w-full border rounded px-3 py-2">
        <option value="">— None —</option>
        <?php foreach ($deps as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Position</label>
      <select name="position_id" class="w-full border rounded px-3 py-2">
        <option value="">— None —</option>
        <?php foreach ($poses as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Branch</label>
      <?php if ($branches): ?>
      <select name="branch_id" class="w-full border rounded px-3 py-2">
        <?php foreach ($branches as $branch): $bid = (int)$branch['id']; ?>
          <option value="<?= $bid ?>" <?= $selectedBranchId === $bid ? 'selected' : '' ?>><?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <div class="text-xs text-gray-500">No branches configured. <a class="underline" href="<?= BASE_URL ?>/modules/admin/branches">Create a branch</a>.</div>
      <?php endif; ?>
    </div>
    <div>
      <label class="form-label">Hire Date</label>
      <input type="date" name="hire_date" class="w-full border rounded px-3 py-2">
    </div>
    <div>
      <label class="form-label">Employment Type</label>
      <select name="employment_type" class="w-full border rounded px-3 py-2">
        <option>regular</option><option>probationary</option><option>contract</option><option>part-time</option>
      </select>
    </div>
    <div>
      <label class="form-label">Status</label>
      <select name="status" class="w-full border rounded px-3 py-2">
        <option>active</option><option>terminated</option><option>resigned</option><option>on-leave</option>
      </select>
    </div>
    <div>
      <label class="form-label">Salary</label>
      <input type="number" step="0.01" name="salary" class="w-full border rounded px-3 py-2" value="0">
    </div>

    <!-- Government IDs & Banking -->
    <div class="md:col-span-2 pt-4 border-t">
      <h3 class="text-sm font-semibold text-slate-700 mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        Government IDs & Banking
      </h3>
    </div>
    <div>
      <label class="form-label">SSS Number</label>
      <input name="sss_number" class="w-full border rounded px-3 py-2" placeholder="00-0000000-0">
    </div>
    <div>
      <label class="form-label">PhilHealth Number</label>
      <input name="philhealth_number" class="w-full border rounded px-3 py-2" placeholder="00-000000000-0">
    </div>
    <div>
      <label class="form-label">Pag-IBIG Number</label>
      <input name="pagibig_number" class="w-full border rounded px-3 py-2" placeholder="0000-0000-0000">
    </div>
    <div>
      <label class="form-label">TIN</label>
      <input name="tin" class="w-full border rounded px-3 py-2" placeholder="000-000-000-000">
    </div>
    <div>
      <label class="form-label">Bank Account Number</label>
      <input name="bank_account_number" class="w-full border rounded px-3 py-2">
    </div>
    <div>
      <label class="form-label">Bank Name</label>
      <input name="bank_name" class="w-full border rounded px-3 py-2">
    </div>

    <div class="md:col-span-2 flex gap-2">
      <button class="px-3 py-2 bg-blue-600 text-white rounded">Save</button>
  <a class="px-3 py-2 border rounded" href="<?= BASE_URL ?>/modules/employees/index">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
