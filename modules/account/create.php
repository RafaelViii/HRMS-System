<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('user_management', 'user_accounts', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$branches = branches_fetch_all($pdo);
$branchMap = [];
foreach ($branches as $branch) {
  $branchMap[(int)$branch['id']] = $branch;
}
$defaultBranchId = branches_get_default_id($pdo);
$selectedBranchId = $defaultBranchId;

// Position-based permissions: role is now just admin/employee for system access level
// Actual permissions are inherited from the employee's assigned position
$roleOptions = ['employee', 'admin'];

$error = '';
$employeeId = (int)($_GET['employee_id'] ?? 0);
$emp = null;
if ($employeeId) {
  $st = $pdo->prepare('SELECT first_name,last_name,email,user_id,branch_id FROM employees WHERE id=:id');
  $st->execute([':id'=>$employeeId]);
  $emp = $st->fetch(PDO::FETCH_ASSOC);
  // Enforce: Each employee can only have one bound account
  if ($emp && !empty($emp['user_id'])) {
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="bg-yellow-50 text-yellow-700 p-2 rounded mb-3 text-sm">This employee already has an account bound.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
  }
  if ($emp && !empty($emp['branch_id']) && isset($branchMap[(int)$emp['branch_id']])) {
    $selectedBranchId = (int)$emp['branch_id'];
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token';
  } else {

    $email = trim($_POST['email'] ?? '');
    $name  = trim($_POST['full_name'] ?? '');
    if ($employeeId && $emp) {
      $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    }
  $role  = $_POST['role'] ?? 'employee';
    $status= $_POST['status'] ?? 'active';
    $pwd   = trim($_POST['password'] ?? '');
    $pwd2  = trim($_POST['password_confirm'] ?? '');
  $templateId = null; // deprecated templates

    $branchId = null;
    $branchInput = (int)($_POST['branch_id'] ?? 0);
    if (!empty($branchMap)) {
      if ($branchInput > 0 && isset($branchMap[$branchInput])) {
        $branchId = $branchInput;
      } elseif ($employeeId && $emp && !empty($emp['branch_id']) && isset($branchMap[(int)$emp['branch_id']])) {
        $branchId = (int)$emp['branch_id'];
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

    $systemAdmin = isset($_POST['is_system_admin']) ? 1 : 0;

    if ($email==='' || $name==='' || $pwd==='' || $pwd2==='' || $pwd!==$pwd2 || strlen($pwd) < 8) {
      $error = 'Please fill all fields. Password must be at least 8 characters and match.';
    } else {
    $pdo->beginTransaction();
      try {
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, branch_id, is_system_admin) VALUES (:email,:hash,:name,:role,:status,:branch,:sysadmin) RETURNING id');
        $stmt->execute([':email'=>$email, ':hash'=>$hash, ':name'=>$name, ':role'=>$role, ':status'=>$status, ':branch'=>$branchId, ':sysadmin'=>$systemAdmin]);
        $uid = (int)($stmt->fetchColumn() ?: 0);
        // Bind to employee if provided (and only if not already bound)
        if ($employeeId > 0) {
          $chk = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id FOR UPDATE');
          $chk->execute([':id'=>$employeeId]);
          $curr = (int)($chk->fetchColumn() ?: 0);
          if ($curr) { throw new Exception('Employee already bound to a user'); }
          $up = $pdo->prepare('UPDATE employees SET user_id = :uid WHERE id = :id');
          $up->execute([':uid'=>$uid, ':id'=>$employeeId]);
          if ($branchId !== null) {
            $pdo->prepare('UPDATE employees SET branch_id = :branch WHERE id = :id')->execute([':branch'=>$branchId, ':id'=>$employeeId]);
          }
        }
        // Position-based permissions will be inherited from employee's position
        $pdo->commit();
  audit('create_account', 'user_id=' . $uid);
  flash_success('Changes have been saved');
  header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $uid); exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        sys_log('DB3101', 'Account create failed - ' . $e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__]);
  flash_error('Changes could not be saved');
  header('Location: ' . BASE_URL . '/modules/account/index'); exit;
      }
    }
  }
}
$pageTitle = 'Create New User Account';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto p-4">
  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-800">Create User Account</h1>
      <p class="text-sm text-gray-600 mt-1">Create a new user account for the system</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/account/index" class="btn btn-light">
      <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to List
    </a>
  </div>

  <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php $currentBranchFormId = (int)($selectedBranchId ?? 0); ?>

  <!-- Main Form Card -->
  <div class="max-w-3xl">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-gray-800">Account Information</h2>
      </div>
      
      <form method="post" class="p-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php if ($employeeId): ?><input type="hidden" name="employee_id" value="<?= $employeeId ?>"><?php endif; ?>
        
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
            <input name="full_name" type="text" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
                   value="<?= htmlspecialchars($emp ? ($emp['first_name'].' '.$emp['last_name']) : '') ?>" 
                   <?= $employeeId ? 'readonly' : '' ?> required>
            <?php if ($employeeId): ?>
              <p class="mt-1 text-xs text-gray-500">Full name syncs from the Employee record.</p>
            <?php endif; ?>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input name="email" type="email" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
                   value="<?= htmlspecialchars($emp['email'] ?? '') ?>" required>
          </div>
        </div>
        
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
                   placeholder="Min 8 characters" minlength="8" required>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <input type="password" name="password_confirm" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
                   placeholder="Confirm password" required>
          </div>
        </div>
        
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Account Type</label>
            <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
              <option value="employee" <?= (($_POST['role'] ?? 'employee') === 'employee' ? 'selected' : '') ?>>Employee</option>
              <option value="admin" <?= (($_POST['role'] ?? '') === 'admin' ? 'selected' : '') ?>>Admin</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">Permissions are inherited from the employee's position assignment.</p>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
          <?php if ($branches): ?>
            <select name="branch_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
              <?php foreach ($branches as $branch): $bid = (int)$branch['id']; ?>
                <option value="<?= $bid ?>" <?= $currentBranchFormId === $bid ? 'selected' : '' ?>>
                  <?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">Used for branch-scoped payroll batches and submissions.</p>
          <?php else: ?>
            <div class="text-sm text-gray-500">
              No branches configured yet. 
              <a class="text-indigo-600 underline" href="<?= BASE_URL ?>/modules/admin/branches">Create one first</a>.
            </div>
          <?php endif; ?>
        </div>
        
        <div class="flex items-center pt-2">
          <input type="checkbox" name="is_system_admin" id="is_system_admin" value="1" 
                 class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
          <label for="is_system_admin" class="ml-2 text-sm text-gray-700">
            System Administrator <span class="text-gray-500">(Full access to all modules)</span>
          </label>
        </div>
        
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
          <div class="flex items-start">
            <svg class="w-5 h-5 text-indigo-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <div class="text-sm text-indigo-800">
              <strong>Access Control:</strong> User permissions are inherited from their employee position. 
              <?php if ($employeeId && $emp): ?>
                This account will be linked to an employee record automatically.
              <?php else: ?>
                After creating this account, link it to an employee record and assign a position to grant access.
              <?php endif; ?>
            </div>
          </div>
        </div>
        
        <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
          <a href="<?= BASE_URL ?>/modules/account/index" class="btn btn-light">Cancel</a>
          <button type="submit" class="btn btn-primary">Create Account</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
