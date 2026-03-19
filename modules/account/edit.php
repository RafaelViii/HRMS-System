<?php
/**
 * User Account Edit Module
 * Modern UI - Position-based permissions
 */
require_once __DIR__ . '/../../includes/auth.php';
require_access('user_management', 'user_accounts', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$canManageAccounts = user_has_access($currentUserId, 'user_management', 'user_accounts', 'write');
$readOnly = !$canManageAccounts;

$branches = branches_fetch_all($pdo);
$branchMap = [];
foreach ($branches as $branch) {
  $branchMap[(int)$branch['id']] = $branch;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('
  SELECT u.*, e.id as employee_id, e.employee_code, e.first_name, e.last_name,
         p.name as position_name, p.id as position_id, d.name as department_name
  FROM users u
  LEFT JOIN employees e ON e.user_id = u.id AND e.status = \'active\'
  LEFT JOIN positions p ON p.id = e.position_id
  LEFT JOIN departments d ON d.id = e.department_id
  WHERE u.id = :id
');
$stmt->execute([':id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$user) { 
  require_once __DIR__ . '/../../includes/header.php'; 
  echo '<div class="container mx-auto p-4"><div class="bg-red-50 text-red-700 p-4 rounded">User not found.</div></div>'; 
  require_once __DIR__ . '/../../includes/footer.php'; 
  exit; 
}

// Check if this is the hardcoded superadmin (LOCKED - cannot be edited)
$isSuperadmin = is_superadmin($id);

// Current values
$currentRole = $user['role'] ?? 'employee';
$currentStatus = $user['status'] ?? 'inactive';
$currentEmail = $user['email'] ?? '';
$currentFullName = $user['full_name'] ?? '';
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;
$employeeId = $user['employee_id'] ?? null;
$employeeCode = $user['employee_code'] ?? null;
$employeeName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$positionName = $user['position_name'] ?? null;
$positionId = $user['position_id'] ?? null;
$departmentName = $user['department_name'] ?? null;
$isSystemAdmin = (bool)($user['is_system_admin'] ?? false);
$currentCanGrantPerms = (bool)($user['can_grant_permissions'] ?? false);

$branchSelectId = $currentBranchId;

// Position-based permissions: role is now just admin/employee for system access level
// Actual permissions are inherited from the employee's assigned position
$roleOptions = ['employee', 'admin'];
if ($currentRole && !in_array($currentRole, $roleOptions, true)) { $roleOptions[] = $currentRole; }

$error = '';

// Block all POST actions for read-only users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $readOnly) {
  flash_error('You do not have permission to modify user accounts');
  header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id);
  exit;
}

// POST: Delete Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
  // Superadmin account CANNOT be deleted
  if ($isSuperadmin) {
    flash_error('Superadmin account cannot be deleted');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  $authz = ensure_action_authorized('user_management.user_accounts', 'delete_account', 'write');
  if (!$authz['ok']) {
    $errorMsg = 'Authorization failed';
    if (isset($authz['error'])) {
      if ($authz['error'] === 'no_access') {
        $errorMsg = 'You do not have access to delete user accounts';
      } elseif ($authz['error'] === 'override_failed') {
        $errorMsg = 'Invalid authorization credentials - please verify your email and password';
      }
    }
    flash_error($errorMsg);
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  try {
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL)');
    $bk = $pdo->prepare('INSERT INTO users_backup OVERRIDING SYSTEM VALUE SELECT * FROM users WHERE id = :id');
    try { $bk->execute([':id'=>$id]); } catch (Throwable $e) {}
    $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :id')->execute([':id'=>$id]);
    $pdo->prepare('DELETE FROM user_access_permissions WHERE user_id = :id')->execute([':id'=>$id]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id'=>$id]);
    $pdo->commit();
    audit('delete_account', 'user_id=' . $id . ', email=' . $currentEmail);
    action_log('account','delete_account','success',['user_id'=>$id]);
    flash_success('Account deleted successfully');
    header('Location: ' . BASE_URL . '/modules/account/index'); 
    exit;
  } catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    sys_log('DB3104', 'Account delete failed - '.$e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__]);
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
}

// POST: Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  // Superadmin account CANNOT be edited
  if ($isSuperadmin) {
    flash_error('Superadmin account cannot be modified');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid CSRF token');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  $name = trim($_POST['full_name'] ?? '');
  $role = $_POST['role'] ?? $currentRole;
  $status = $_POST['status'] ?? $currentStatus;
  $branchInput = (int)($_POST['branch_id'] ?? 0);
  $systemAdmin = isset($_POST['is_system_admin']) ? 1 : 0;
  
  // Require authorization override for sensitive privilege changes
  $currentSysAdmin = $isSystemAdmin ? 1 : 0;
  $isPrivilegeChange = ($role !== $currentRole || $status !== $currentStatus || $systemAdmin !== $currentSysAdmin);
  if ($isPrivilegeChange) {
    $authz = ensure_action_authorized('user_management', 'update_privileges', 'manage');
    if (!$authz['ok']) {
      flash_error('Authorization required for privilege changes.');
      header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id);
      exit;
    }
  }
  
  // Sensitive permission: can_grant_permissions (only superadmin can modify this)
  // Ensure we always use integer values (0 or 1) for boolean fields
  $canGrantPerms = $currentCanGrantPerms ? 1 : 0; // Convert current value to int
  if (can_modify_grant_permissions_flag()) {
    $canGrantPerms = isset($_POST['can_grant_permissions']) ? 1 : 0;
  }
  $branchId = $currentBranchId;
  if (!empty($branchMap)) {
    if ($branchInput > 0 && isset($branchMap[$branchInput])) {
      $branchId = $branchInput;
    } elseif ($branchId === 0) {
      $firstBranch = reset($branches);
      if ($firstBranch) { $branchId = (int)$firstBranch['id']; }
    }
  } else {
    $branchId = null;
  }
  $branchSelectId = (int)($branchId ?? 0);
  if (!in_array($role, $roleOptions, true)) { $role = $currentRole; }
  if (!in_array($status, ['active','inactive'], true)) { $status = $currentStatus; }
  try {
    $stmt = $pdo->prepare('UPDATE users SET full_name = :name, role = :role, status = :status, branch_id = :branch, is_system_admin = :sysadmin, can_grant_permissions = :can_grant WHERE id = :id');
    $stmt->execute([':name'=>$name, ':role'=>$role, ':status'=>$status, ':branch'=>$branchId, ':sysadmin'=>$systemAdmin, ':can_grant'=>$canGrantPerms, ':id'=>$id]);
    if ($id === $currentUserId) {
      $_SESSION['user']['full_name'] = $name;
      $_SESSION['user']['name'] = $name;
      $_SESSION['user']['role'] = $role;
      $_SESSION['user']['branch_id'] = $branchId;
      if ($branchId && isset($branchMap[$branchId])) {
        $_SESSION['user']['branch_name'] = $branchMap[$branchId]['name'];
        $_SESSION['user']['branch_code'] = $branchMap[$branchId]['code'] ?? null;
      } else {
        $_SESSION['user']['branch_name'] = null;
        $_SESSION['user']['branch_code'] = null;
      }
    }
    audit('update_account', 'user_id=' . $id . ', email=' . $currentEmail); 
    action_log('account','update_account','success',['user_id'=>$id]); 
    flash_success('Profile updated successfully');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  } catch (Throwable $e) {
    sys_log('DB3105', 'Account update failed - '.$e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__]);
    $error = 'Failed to update profile';
  }
}

// POST: Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
  // Superadmin password CANNOT be reset through UI
  if ($isSuperadmin) {
    flash_error('Superadmin password cannot be reset through UI. Use tools/reset_admin.php');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid CSRF token');
    header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
    exit;
  }
  // Require authorization override for password resets of other users
  if ($id !== $currentUserId) {
    $authz = ensure_action_authorized('user_management', 'reset_password', 'manage');
    if (!$authz['ok']) {
      flash_error('Authorization required for password reset.');
      header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id);
      exit;
    }
  }
  $p = trim($_POST['password'] ?? ''); 
  $p2 = trim($_POST['password_confirm'] ?? '');
  if ($p === '' || strlen($p) < 8) {
    $error = 'Password must be at least 8 characters';
  } elseif ($p !== $p2) {
    $error = 'Passwords do not match';
  } else {
    try {
      $hash = password_hash($p, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
      $stmt->execute([':hash'=>$hash, ':id'=>$id]);
      // Revoke all remember-me tokens to invalidate sessions on other devices
      remember_clear_tokens($id);
      audit('reset_password', 'user_id=' . $id . ', email=' . $currentEmail); 
      action_log('account','reset_password','success',['user_id'=>$id]); 
      flash_success('Password reset successfully');
      header('Location: ' . BASE_URL . '/modules/account/edit?id=' . $id); 
      exit;
    } catch (Throwable $e) {
      sys_log('DB3106', 'Password reset failed - '.$e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__]);
      $error = 'Failed to reset password';
    }
  }
}

$pageTitle = ($readOnly ? 'View' : 'Edit') . ' Account: ' . htmlspecialchars($currentEmail);
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto p-4">
  <?php if ($isSuperadmin): ?>
    <!-- Superadmin Locked Notice -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-lg shadow-lg border-2 border-purple-300 p-6 mb-6">
      <div class="flex items-start gap-4">
        <div class="flex-shrink-0">
          <svg class="w-12 h-12 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
          </svg>
        </div>
        <div class="flex-1">
          <h3 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
            </svg>
            LOCKED SUPERADMIN ACCOUNT
          </h3>
          <p class="text-purple-100 mb-3">
            This is the hardcoded superadmin account (<strong class="text-white"><?= htmlspecialchars(SUPERADMIN_EMAIL) ?></strong>). 
            It has unlimited access to all system features and cannot be edited, deleted, or modified through the UI.
          </p>
          <div class="bg-purple-700 bg-opacity-50 rounded-lg p-3 text-sm text-purple-50">
            <strong><svg class="w-4 h-4 inline mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> Protected Features:</strong> Account details, role, status, permissions, and password are all locked. 
            To reset the password, use <code class="bg-purple-900 px-2 py-1 rounded">tools/reset_admin.php</code> via CLI.
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="flex items-center gap-3 mb-1">
        <h1 class="text-2xl font-semibold text-gray-800">User Account</h1>
        <?php if ($isSuperadmin): ?>
          <span class="px-3 py-1 bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-xs font-bold rounded shadow-sm flex items-center gap-1">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
            </svg>
            SUPERADMIN
          </span>
        <?php elseif ($isSystemAdmin): ?>
          <span class="px-2 py-1 bg-purple-100 text-purple-700 text-xs font-semibold rounded">System Administrator</span>
        <?php endif; ?>
        <span class="px-2 py-1 <?= $currentStatus === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?> text-xs font-semibold rounded"><?= htmlspecialchars($currentStatus) ?></span>
      </div>
      <div class="text-sm text-gray-600">
        <span class="font-mono"><?= htmlspecialchars($currentEmail) ?></span>
        <?php if ($employeeCode): ?>
          <span class="mx-2">•</span>
          <span>Employee: <?= htmlspecialchars($employeeCode) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= BASE_URL ?>/modules/account/index" class="btn btn-light">
        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to List
      </a>
      <?php if (!$isSuperadmin && !$readOnly): ?>
        <form method="post" class="inline" data-confirm="Delete this user account? This action cannot be undone." data-authz-module="user_management.user_accounts" data-authz-required="write" data-authz-force data-authz-action="Delete user account">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="delete_account" value="1">
          <button type="submit" class="btn btn-danger">Delete Account</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
      <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="border-b border-gray-200 px-6 py-4">
          <h2 class="text-lg font-semibold text-gray-800">Account Information</h2>
        </div>
        <form method="post" class="p-6 space-y-4">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="update_profile" value="1">
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
              <input type="text" name="full_name" value="<?= htmlspecialchars($currentFullName) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 <?= ($isSuperadmin || $readOnly) ? 'bg-gray-100 cursor-not-allowed' : '' ?>" <?= ($isSuperadmin || $readOnly) ? 'readonly disabled' : '' ?> required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Account Type</label>
              <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 <?= ($isSuperadmin || $readOnly) ? 'bg-gray-100 cursor-not-allowed' : '' ?>" <?= ($isSuperadmin || $readOnly) ? 'disabled' : '' ?>>
                <?php foreach ($roleOptions as $r): ?>
                  <option value="<?= htmlspecialchars($r) ?>" <?= $currentRole===$r?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r))) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="mt-1 text-xs text-gray-500">Permissions are inherited from the employee's position.</p>
            </div>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
              <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 <?= ($isSuperadmin || $readOnly) ? 'bg-gray-100 cursor-not-allowed' : '' ?>" <?= ($isSuperadmin || $readOnly) ? 'disabled' : '' ?>>
                <option value="active" <?= $currentStatus==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $currentStatus==='inactive'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
              <?php if ($branches): ?>
                <select name="branch_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 <?= ($isSuperadmin || $readOnly) ? 'bg-gray-100 cursor-not-allowed' : '' ?>" <?= ($isSuperadmin || $readOnly) ? 'disabled' : '' ?>>
                  <?php foreach ($branches as $branch): $bid = (int)$branch['id']; ?>
                    <option value="<?= $bid ?>" <?= $branchSelectId === $bid ? 'selected' : '' ?>><?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <div class="text-sm text-gray-500">No branches configured.</div>
              <?php endif; ?>
            </div>
          </div>
          <div class="flex items-center">
            <input type="checkbox" name="is_system_admin" id="is_system_admin" value="1" <?= $isSystemAdmin ? 'checked' : '' ?> class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 <?= ($isSuperadmin || $readOnly) ? 'cursor-not-allowed' : '' ?>" <?= ($isSuperadmin || $readOnly) ? 'disabled' : '' ?>>
            <label for="is_system_admin" class="ml-2 text-sm text-gray-700">System Administrator <span class="text-gray-500">(Full access to all modules)</span></label>
          </div>
          
          <!-- Sensitive Permission: Can Grant Permissions (Superadmin-only) -->
          <?php if (can_modify_grant_permissions_flag()): ?>
          <div class="border-t border-red-200 pt-4 mt-4">
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-3">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-red-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                  <p class="text-sm font-semibold text-red-900 mb-1 flex items-center gap-1.5"><svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg> Sensitive Permission Zone</p>
                  <p class="text-xs text-red-800">This section is only visible to superadmin (<?= htmlspecialchars(SUPERADMIN_EMAIL) ?>)</p>
                </div>
              </div>
            </div>
            <div class="flex items-start gap-3">
              <input type="checkbox" 
                     name="can_grant_permissions" 
                     id="can_grant_permissions" 
                     value="1" 
                     <?= $currentCanGrantPerms ? 'checked' : '' ?> 
                     class="mt-1 w-5 h-5 text-red-600 border-red-300 rounded focus:ring-red-500 <?= $isSuperadmin ? 'cursor-not-allowed' : '' ?>" 
                     <?= $isSuperadmin ? 'disabled' : '' ?>>
              <div class="flex-1">
                <label for="can_grant_permissions" class="text-sm font-semibold text-gray-900 block mb-1">
                  Can Grant Permissions Above Own Level
                </label>
                <p class="text-xs text-gray-600 mb-2">
                  When enabled, this user can assign permissions <strong>equal to or higher than their own position</strong> when managing other accounts.
                </p>
                <div class="bg-yellow-50 border border-yellow-200 rounded p-2 text-xs text-yellow-800">
                  <strong><svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> Security Risk:</strong> This allows privilege escalation. Only grant to trusted executives who need to delegate their own authority (e.g., CEO, CHRO). All permission grants are audited.
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($isSuperadmin): ?>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-purple-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                  <p class="text-sm font-semibold text-purple-800 mb-1">Account Locked</p>
                  <p class="text-xs text-purple-700">This superadmin account cannot be modified through the UI. All fields are disabled.</p>
                </div>
              </div>
            </div>
          <?php elseif ($readOnly): ?>
            <!-- Read-only mode: no Save button -->
          <?php else: ?>
            <div class="flex justify-end pt-2">
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          <?php endif; ?>
        </form>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="border-b border-gray-200 px-6 py-4">
          <h2 class="text-lg font-semibold text-gray-800">Reset Password</h2>
        </div>
        <?php if ($isSuperadmin): ?>
          <div class="p-6">
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-purple-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                  <p class="text-sm font-semibold text-purple-800 mb-1">Password Reset Locked</p>
                  <p class="text-xs text-purple-700">Superadmin password cannot be reset through the UI. To reset, use the CLI tool:</p>
                  <code class="block mt-2 bg-purple-900 text-purple-100 px-3 py-2 rounded text-xs">php tools/reset_admin.php</code>
                </div>
              </div>
            </div>
          </div>
        <?php elseif ($readOnly): ?>
          <div class="p-6">
            <p class="text-sm text-gray-500 italic">You do not have permission to reset passwords.</p>
          </div>
        <?php else: ?>
          <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="reset_password" value="1">
            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Min 8 characters" minlength="8">
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="password_confirm" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Confirm password">
              </div>
            </div>
            <div class="flex justify-end pt-2">
              <button type="submit" class="btn btn-warning">Reset Password</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="space-y-6">
      <?php if ($employeeId): ?>
      <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="border-b border-gray-200 px-6 py-4">
          <h2 class="text-lg font-semibold text-gray-800">Employee Record</h2>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <div class="text-sm text-gray-600 mb-1">Employee Code</div>
            <div class="font-mono font-semibold text-gray-800"><?= htmlspecialchars($employeeCode) ?></div>
          </div>
          <div>
            <div class="text-sm text-gray-600 mb-1">Name</div>
            <div class="font-semibold text-gray-800"><?= htmlspecialchars($employeeName) ?></div>
          </div>
          <?php if ($positionName): ?>
          <div>
            <div class="text-sm text-gray-600 mb-1">Position</div>
            <div class="text-gray-800"><?= htmlspecialchars($positionName) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($departmentName): ?>
          <div>
            <div class="text-sm text-gray-600 mb-1">Department</div>
            <div class="text-gray-800"><?= htmlspecialchars($departmentName) ?></div>
          </div>
          <?php endif; ?>
          <div class="pt-3 border-t border-gray-200">
            <a href="<?= BASE_URL ?>/modules/employees/view?id=<?= $employeeId ?>" class="btn btn-outline w-full text-center">View Employee Profile</a>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
        <div class="flex items-start">
          <svg class="w-5 h-5 text-amber-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
          </svg>
          <div>
            <h3 class="text-sm font-semibold text-amber-800 mb-1">No Employee Record</h3>
            <p class="text-sm text-amber-700">This user account is not linked to an employee record. Access is limited to self-service resources only.</p>
            <a href="<?= BASE_URL ?>/modules/employees/create" class="text-sm text-amber-800 underline mt-2 inline-block">Create Employee Record</a>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-indigo-900 mb-2">Access Control</h3>
        <div class="text-sm text-indigo-800 space-y-2">
          <?php if ($isSystemAdmin): ?>
            <p class="flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <strong>System Administrator</strong>: Full access to all system resources</p>
          <?php elseif ($positionId): ?>
            <p class="flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <strong>Position-Based Access</strong>: Permissions inherited from position</p>
            <a href="<?= BASE_URL ?>/modules/positions/permissions?id=<?= $positionId ?>" class="text-indigo-700 underline text-xs">Manage Position Permissions →</a>
          <?php else: ?>
            <p class="flex items-center gap-1.5"><svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <strong>Self-Service Only</strong>: Limited to own profile and notifications</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
