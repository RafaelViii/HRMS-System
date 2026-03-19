<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'departments', 'manage');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

$dept_id = (int)($_GET['dept_id'] ?? 0);
if ($dept_id <= 0) {
  flash_error('Invalid department.');
  header('Location: ' . BASE_URL . '/modules/departments/index');
  exit;
}

// Fetch department
try {
  $deptStmt = $pdo->prepare('SELECT * FROM departments WHERE id = :id AND deleted_at IS NULL LIMIT 1');
  $deptStmt->execute([':id' => $dept_id]);
  $dept = $deptStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('DB2530', 'Failed to fetch department - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
  $dept = null;
}

if (!$dept) {
  flash_error('Department not found.');
  header('Location: ' . BASE_URL . '/modules/departments/index');
  exit;
}

// Handle Add Supervisor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supervisor'])) {
  if (csrf_verify($_POST['csrf'] ?? '')) {
    $supervisor_id = (int)($_POST['supervisor_id'] ?? 0);
    $is_override = isset($_POST['is_override']) ? 1 : 0;
    
    if ($supervisor_id > 0) {
      try {
        // Check if user exists and has appropriate role
        $userCheck = $pdo->prepare('SELECT id, role FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
        $userCheck->execute([':id' => $supervisor_id]);
        $userExists = $userCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($userExists) {
          // Check if already assigned
          $checkStmt = $pdo->prepare('SELECT id FROM department_supervisors WHERE department_id = :dept AND supervisor_user_id = :user LIMIT 1');
          $checkStmt->execute([':dept' => $dept_id, ':user' => $supervisor_id]);
          
          if (!$checkStmt->fetch()) {
            $ins = $pdo->prepare('INSERT INTO department_supervisors (department_id, supervisor_user_id, is_override, assigned_by) VALUES (:dept, :user, :override, :by)');
            $ins->execute([
              ':dept' => $dept_id,
              ':user' => $supervisor_id,
              ':override' => $is_override,
              ':by' => $uid
            ]);
            audit('add_dept_supervisor', "Department={$dept_id}, Supervisor={$supervisor_id}, Override={$is_override}");
            action_log('departments', 'add_supervisor', 'success', ['department_id' => $dept_id, 'supervisor_id' => $supervisor_id]);
            flash_success('Supervisor assigned successfully.');
          } else {
            flash_error('This user is already a supervisor for this department.');
          }
        } else {
          flash_error('Selected user not found or inactive.');
        }
      } catch (Throwable $e) {
        sys_log('DB2531', 'Failed to add supervisor - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
        flash_error('Failed to assign supervisor.');
      }
    }
    header('Location: ' . BASE_URL . '/modules/departments/supervisors?dept_id=' . $dept_id);
    exit;
  }
}

// Handle Remove Supervisor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_supervisor'])) {
  if (csrf_verify($_POST['csrf'] ?? '')) {
    $supervisor_record_id = (int)($_POST['supervisor_record_id'] ?? 0);
    
    if ($supervisor_record_id > 0) {
      try {
        $del = $pdo->prepare('DELETE FROM department_supervisors WHERE id = :id AND department_id = :dept');
        $del->execute([':id' => $supervisor_record_id, ':dept' => $dept_id]);
        audit('remove_dept_supervisor', "Department={$dept_id}, RecordID={$supervisor_record_id}");
        action_log('departments', 'remove_supervisor', 'success', ['department_id' => $dept_id, 'record_id' => $supervisor_record_id]);
        flash_success('Supervisor removed successfully.');
      } catch (Throwable $e) {
        sys_log('DB2532', 'Failed to remove supervisor - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
        flash_error('Failed to remove supervisor.');
      }
    }
    header('Location: ' . BASE_URL . '/modules/departments/supervisors?dept_id=' . $dept_id);
    exit;
  }
}

// Fetch current supervisors
try {
  $supStmt = $pdo->prepare('
    SELECT ds.id as record_id, ds.is_override, ds.assigned_at, u.id as user_id, u.full_name, u.email, u.role,
           e.employee_code, d.name as dept_name, e.department_id
    FROM department_supervisors ds
    JOIN users u ON u.id = ds.supervisor_user_id
    LEFT JOIN employees e ON e.user_id = u.id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE ds.department_id = :dept
    ORDER BY ds.assigned_at DESC
  ');
  $supStmt->execute([':dept' => $dept_id]);
  $supervisors = $supStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('DB2533', 'Failed to fetch supervisors - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
  $supervisors = [];
}

// Fetch available users for assignment (any active user can be assigned as supervisor)
try {
  $availStmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.full_name, u.email, u.role, e.employee_code, e.department_id, d.name as dept_name
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE u.status = \'active\' 
      AND u.id NOT IN (SELECT supervisor_user_id FROM department_supervisors WHERE department_id = :dept)
    ORDER BY 
      CASE WHEN e.department_id = :dept THEN 0 ELSE 1 END,
      u.full_name ASC
  ');
  $availStmt->execute([':dept' => $dept_id]);
  $availableUsers = $availStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('DB2534', 'Failed to fetch available users - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
  $availableUsers = [];
}

$pageTitle = 'Manage Supervisors - ' . htmlspecialchars($dept['name']);
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="mb-4">
  <a href="<?= BASE_URL ?>/modules/departments/index" class="inline-flex items-center text-sm text-slate-600 hover:text-slate-800">
    <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    Back to Departments
  </a>
</div>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-slate-800">Manage Supervisors</h1>
  <p class="text-sm text-slate-600 mt-1">Department: <span class="font-semibold"><?= htmlspecialchars($dept['name']) ?></span></p>
  <p class="text-xs text-slate-500 mt-1">Supervisors can approve leave requests from employees in this department.</p>
</div>

<div class="grid gap-6 lg:grid-cols-2">
  <!-- Add Supervisor Card -->
  <div class="card">
    <div class="border-b border-slate-100 px-5 py-4">
      <h2 class="text-lg font-semibold text-slate-800">Assign New Supervisor</h2>
      <p class="text-xs text-slate-500 mt-1">Select a user to supervise this department's leave approvals</p>
    </div>
    <div class="p-5">
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="add_supervisor" value="1">
        
        <div>
          <label for="supervisor_id" class="block text-sm font-medium text-slate-700 mb-2">Select Supervisor</label>
          <select name="supervisor_id" id="supervisor_id" class="input-text w-full" required>
            <option value="">-- Choose a user --</option>
            <?php 
            // Group by department
            $sameDept = [];
            $otherDept = [];
            $noDept = [];
            
            foreach ($availableUsers as $u) {
              $userDeptId = (int)($u['department_id'] ?? 0);
              if ($userDeptId === (int)$dept_id) {
                $sameDept[] = $u;
              } elseif ($userDeptId > 0) {
                $otherDept[] = $u;
              } else {
                $noDept[] = $u;
              }
            }
            
            if ($sameDept): ?>
              <optgroup label="From This Department">
                <?php foreach ($sameDept as $u): ?>
                  <option value="<?= $u['id'] ?>">
                    <?= htmlspecialchars($u['full_name']) ?> 
                    (<?= htmlspecialchars($u['email']) ?>) 
                    - <?= htmlspecialchars(ucfirst($u['role'])) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            
            <?php if ($otherDept): ?>
              <optgroup label="From Other Departments (Override)">
                <?php foreach ($otherDept as $u): ?>
                  <option value="<?= $u['id'] ?>" data-override="1">
                    <?= htmlspecialchars($u['full_name']) ?> 
                    (<?= htmlspecialchars($u['dept_name']) ?>) 
                    - <?= htmlspecialchars(ucfirst($u['role'])) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            
            <?php if ($noDept): ?>
              <optgroup label="Users Without Department Assignment">
                <?php foreach ($noDept as $u): ?>
                  <option value="<?= $u['id'] ?>">
                    <?= htmlspecialchars($u['full_name']) ?> 
                    (<?= htmlspecialchars($u['email']) ?>) 
                    - <?= htmlspecialchars(ucfirst($u['role'])) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>
        
        <div class="flex items-start">
          <input type="checkbox" name="is_override" id="is_override" class="mt-1 h-4 w-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500">
          <label for="is_override" class="ml-2 text-sm text-slate-700">
            <span class="font-medium">Override (Cross-Department Supervision)</span>
            <span class="block text-xs text-slate-500 mt-0.5">Enable if this supervisor is from a different department</span>
          </label>
        </div>
        
        <div class="flex gap-2">
          <button type="submit" class="btn btn-primary">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Assign Supervisor
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Current Supervisors Card -->
  <div class="card">
    <div class="border-b border-slate-100 px-5 py-4">
      <h2 class="text-lg font-semibold text-slate-800">Current Supervisors (<?= count($supervisors) ?>)</h2>
      <p class="text-xs text-slate-500 mt-1">Users who can approve leave requests for this department</p>
    </div>
    <div class="divide-y divide-slate-100">
      <?php if ($supervisors): ?>
        <?php foreach ($supervisors as $sup): ?>
          <div class="p-4 hover:bg-slate-50 transition-colors">
            <div class="flex items-start justify-between">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="font-medium text-slate-800 truncate"><?= htmlspecialchars($sup['full_name']) ?></p>
                  <?php if ((int)$sup['is_override']): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200">
                      Override
                    </span>
                  <?php endif; ?>
                </div>
                <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($sup['email']) ?></p>
                <div class="flex items-center gap-3 mt-1">
                  <span class="text-xs text-slate-600">
                    <span class="font-medium">Role:</span> <?= htmlspecialchars(ucfirst($sup['role'])) ?>
                  </span>
                  <?php if (!empty($sup['emp_dept_name'])): ?>
                    <span class="text-xs text-slate-600">
                      <span class="font-medium">Dept:</span> <?= htmlspecialchars($sup['emp_dept_name']) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <p class="text-xs text-slate-400 mt-1">Assigned: <?= format_datetime_display($sup['assigned_at'], false, 'M d, Y') ?></p>
              </div>
              <form method="post" class="ml-3" data-confirm="Remove <?= htmlspecialchars($sup['full_name']) ?> as supervisor?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="remove_supervisor" value="1">
                <input type="hidden" name="supervisor_record_id" value="<?= $sup['record_id'] ?>">
                <button type="submit" class="text-red-600 hover:text-red-700 p-1" title="Remove supervisor">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="p-8 text-center">
          <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          <p class="mt-2 text-sm text-slate-600">No supervisors assigned yet</p>
          <p class="text-xs text-slate-500 mt-1">Assign users to approve leave requests for this department</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function() {
  const supervisorSelect = document.getElementById('supervisor_id');
  const overrideCheckbox = document.getElementById('is_override');
  
  // Store all options with their optgroup info
  const allOptions = [];
  const optgroups = supervisorSelect.querySelectorAll('optgroup');
  
  optgroups.forEach(group => {
    const label = group.getAttribute('label');
    const isOverrideGroup = label.includes('Other Departments');
    
    Array.from(group.querySelectorAll('option')).forEach(opt => {
      allOptions.push({
        element: opt.cloneNode(true),
        groupLabel: label,
        isOverrideGroup: isOverrideGroup,
        value: opt.value
      });
    });
  });
  
  // Function to update dropdown options based on override checkbox
  function updateDropdownOptions() {
    const showOverride = overrideCheckbox.checked;
    const currentValue = supervisorSelect.value;
    
    // Clear current options
    supervisorSelect.innerHTML = '';
    
    // Rebuild optgroups with filtered options
    const groups = {};
    
    allOptions.forEach(opt => {
      // Skip override group options if checkbox not checked
      if (opt.isOverrideGroup && !showOverride) {
        return;
      }
      
      if (!groups[opt.groupLabel]) {
        groups[opt.groupLabel] = document.createElement('optgroup');
        groups[opt.groupLabel].label = opt.groupLabel;
      }
      
      groups[opt.groupLabel].appendChild(opt.element.cloneNode(true));
    });
    
    // Append all groups to select
    Object.values(groups).forEach(group => {
      supervisorSelect.appendChild(group);
    });
    
    // Restore selection if still available
    if (currentValue) {
      supervisorSelect.value = currentValue;
    }
  }
  
  // Update dropdown when checkbox changes
  overrideCheckbox.addEventListener('change', updateDropdownOptions);
  
  // Auto-check override and update dropdown when selecting user from other department
  supervisorSelect.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const isOverride = selected.getAttribute('data-override') === '1';
    
    if (isOverride && !overrideCheckbox.checked) {
      overrideCheckbox.checked = true;
      updateDropdownOptions();
    }
  });
  
  // Initial update on page load
  updateDropdownOptions();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
