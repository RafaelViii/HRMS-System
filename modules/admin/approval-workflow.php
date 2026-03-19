<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'system_settings', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pageTitle = 'Approval Workflow Management';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_error('Invalid security token. Please try again.');
        header('Location: ' . BASE_URL . '/modules/admin/approval-workflow');
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_payroll_approvers') {
        try {
            $approvers = json_decode($_POST['approvers'] ?? '[]', true);
            if (!is_array($approvers)) {
                throw new Exception('Invalid approvers data');
            }
            
            $pdo->beginTransaction();
            // Delete all existing payroll approvers
            $pdo->exec('DELETE FROM payroll_approvers');
            
            // Insert new approvers
            $stmt = $pdo->prepare('INSERT INTO payroll_approvers (user_id, approval_order, active, notes) VALUES (:user_id, :order, :active, :notes)');
            
            foreach ($approvers as $approver) {
                $stmt->execute([
                    ':user_id' => (int)$approver['user_id'],
                    ':order' => (int)$approver['order'],
                    ':active' => !empty($approver['active']),
                    ':notes' => $approver['notes'] ?? null,
                ]);
            }
            
            action_log('approval_workflow', 'update_payroll_approvers', 'success', ['count' => count($approvers)]);
            $pdo->commit();
            flash_success('Payroll run approvers updated successfully');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sys_log('APPROVAL-WORKFLOW-SAVE', 'Failed to save payroll approvers: ' . $e->getMessage(), [
                'module' => 'admin',
                'user_id' => $currentUserId,
            ]);
            flash_error('Failed to save payroll approvers. Please try again.');
        }
        
        header('Location: ' . BASE_URL . '/modules/admin/approval-workflow');
        exit;
    }
    
    if ($action === 'save_adjustment_approvers') {
        try {
            $approvers = json_decode($_POST['approvers'] ?? '[]', true);
            if (!is_array($approvers)) {
                throw new Exception('Invalid approvers data');
            }
            
            $pdo->beginTransaction();
            // Delete all existing adjustment approvers
            $pdo->exec('DELETE FROM payroll_adjustment_approvers');
            
            // Insert new approvers
            $stmt = $pdo->prepare('INSERT INTO payroll_adjustment_approvers (user_id, approval_order, active, notes) VALUES (:user_id, :order, :active, :notes)');
            
            foreach ($approvers as $approver) {
                $stmt->execute([
                    ':user_id' => (int)$approver['user_id'],
                    ':order' => (int)$approver['order'],
                    ':active' => !empty($approver['active']),
                    ':notes' => $approver['notes'] ?? null,
                ]);
            }
            
            action_log('approval_workflow', 'update_adjustment_approvers', 'success', ['count' => count($approvers)]);
            $pdo->commit();
            flash_success('Payroll adjustment approvers updated successfully');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sys_log('APPROVAL-WORKFLOW-SAVE', 'Failed to save adjustment approvers: ' . $e->getMessage(), [
                'module' => 'admin',
                'user_id' => $currentUserId,
            ]);
            flash_error('Failed to save adjustment approvers. Please try again.');
        }
        
        header('Location: ' . BASE_URL . '/modules/admin/approval-workflow');
        exit;
    }
}

// Fetch all eligible users (admin, hr, hr_manager, accountant)
$eligibleUsers = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.full_name, u.email, u.role, e.first_name, e.last_name 
                         FROM users u 
                         LEFT JOIN employees e ON e.user_id = u.id 
                         WHERE u.status = 'active' 
                           AND u.role IN ('admin', 'hr', 'hr_manager', 'accountant', 'hr_payroll')
                         ORDER BY u.full_name");
    $eligibleUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('APPROVAL-WORKFLOW-FETCH', 'Failed to fetch eligible users: ' . $e->getMessage(), [
        'module' => 'admin',
    ]);
}

// Fetch current payroll run approvers
$payrollApprovers = [];
try {
    $stmt = $pdo->query("SELECT pa.*, u.full_name, u.email, e.first_name, e.last_name 
                         FROM payroll_approvers pa 
                         JOIN users u ON pa.user_id = u.id 
                         LEFT JOIN employees e ON e.user_id = u.id 
                         ORDER BY pa.approval_order");
    $payrollApprovers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('APPROVAL-WORKFLOW-FETCH', 'Failed to fetch payroll approvers: ' . $e->getMessage(), [
        'module' => 'admin',
    ]);
}

// Fetch current adjustment approvers
$adjustmentApprovers = [];
try {
    if (payroll_table_exists($pdo, 'payroll_adjustment_approvers')) {
        $stmt = $pdo->query("SELECT paa.*, u.full_name, u.email, e.first_name, e.last_name 
                             FROM payroll_adjustment_approvers paa 
                             JOIN users u ON paa.user_id = u.id 
                             LEFT JOIN employees e ON e.user_id = u.id 
                             ORDER BY paa.approval_order");
        $adjustmentApprovers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    sys_log('APPROVAL-WORKFLOW-FETCH', 'Failed to fetch adjustment approvers: ' . $e->getMessage(), [
        'module' => 'admin',
    ]);
}

action_log('approval_workflow', 'view_approval_workflow_page');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Page Header -->
  <div class="rounded-xl bg-gradient-to-br from-indigo-900 via-blue-900 to-slate-900 p-6 text-white shadow-lg">
    <div class="flex items-start justify-between">
      <div>
        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white/75">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          Approval Workflow
        </div>
        <h1 class="text-3xl font-bold">Approval Workflow Management</h1>
        <p class="mt-2 text-sm text-white/80">Configure who can approve payroll runs and payroll adjustments. Each workflow operates independently.</p>
      </div>
    </div>
  </div>

  <!-- Important Notice -->
  <div class="rounded-lg border-l-4 border-amber-500 bg-amber-50 p-4">
    <div class="flex items-start">
      <svg class="h-5 w-5 text-amber-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
      </svg>
      <div>
        <h3 class="text-sm font-semibold text-amber-900">Two Separate Approval Workflows</h3>
        <p class="mt-1 text-sm text-amber-800">
          <strong>Payroll Run Approvers</strong> approve entire payroll runs before release. 
          <strong>Adjustment Approvers</strong> approve individual adjustments from complaint resolutions before they can be applied to payroll.
        </p>
      </div>
    </div>
  </div>

  <!-- Payroll Run Approvers Section -->
  <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Payroll Run Approvers</h2>
          <p class="mt-1 text-sm text-slate-600">These approvers review and approve entire payroll runs before they can be released.</p>
        </div>
        <button type="button" onclick="addPayrollApprover()" class="btn-primary">
          <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Add Approver
        </button>
      </div>
    </div>
    
    <div class="p-6">
      <form id="payrollApproversForm" method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_payroll_approvers">
        <input type="hidden" name="approvers" id="payrollApproversData">
        
        <div id="payrollApproversList" class="space-y-3">
          <?php if (empty($payrollApprovers)): ?>
            <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-8 text-center">
              <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
              </svg>
              <p class="mt-2 text-sm text-slate-600">No payroll run approvers configured yet.</p>
              <p class="mt-1 text-xs text-slate-500">Click "Add Approver" to configure the approval workflow.</p>
            </div>
          <?php endif; ?>
        </div>
        
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
          <button type="button" onclick="cancelPayrollApprovers()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary">Save Payroll Run Approvers</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Payroll Adjustment Approvers Section -->
  <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 bg-emerald-50 px-6 py-4">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Payroll Adjustment Approvers</h2>
          <p class="mt-1 text-sm text-slate-600">These approvers review and approve individual payroll adjustments (from complaint resolutions) before they can be applied to payroll.</p>
        </div>
        <button type="button" onclick="addAdjustmentApprover()" class="btn-primary bg-emerald-600 hover:bg-emerald-700">
          <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Add Approver
        </button>
      </div>
    </div>
    
    <div class="p-6">
      <form id="adjustmentApproversForm" method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_adjustment_approvers">
        <input type="hidden" name="approvers" id="adjustmentApproversData">
        
        <div id="adjustmentApproversList" class="space-y-3">
          <?php if (empty($adjustmentApprovers)): ?>
            <div class="rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50 p-8 text-center">
              <svg class="mx-auto h-12 w-12 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <p class="mt-2 text-sm text-slate-600">No payroll adjustment approvers configured yet.</p>
              <p class="mt-1 text-xs text-slate-500">Click "Add Approver" to configure the adjustment approval workflow.</p>
            </div>
          <?php endif; ?>
        </div>
        
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
          <button type="button" onclick="cancelAdjustmentApprovers()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary bg-emerald-600 hover:bg-emerald-700">Save Adjustment Approvers</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const eligibleUsers = <?= json_encode($eligibleUsers) ?>;
const initialPayrollApprovers = <?= json_encode($payrollApprovers) ?>;
const initialAdjustmentApprovers = <?= json_encode($adjustmentApprovers) ?>;

let payrollApprovers = [];
let adjustmentApprovers = [];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  // Load existing payroll approvers
  if (initialPayrollApprovers && initialPayrollApprovers.length > 0) {
    payrollApprovers = initialPayrollApprovers.map(a => ({
      user_id: parseInt(a.user_id),
      order: parseInt(a.approval_order),
      active: a.active === true || a.active === 't' || a.active === '1',
      notes: a.notes || '',
      username: a.username,
      email: a.email,
      first_name: a.first_name,
      last_name: a.last_name
    }));
    renderPayrollApprovers();
  }
  
  // Load existing adjustment approvers
  if (initialAdjustmentApprovers && initialAdjustmentApprovers.length > 0) {
    adjustmentApprovers = initialAdjustmentApprovers.map(a => ({
      user_id: parseInt(a.user_id),
      order: parseInt(a.approval_order),
      active: a.active === true || a.active === 't' || a.active === '1',
      notes: a.notes || '',
      username: a.username,
      email: a.email,
      first_name: a.first_name,
      last_name: a.last_name
    }));
    renderAdjustmentApprovers();
  }
});

function addPayrollApprover() {
  const newOrder = payrollApprovers.length + 1;
  payrollApprovers.push({
    user_id: null,
    order: newOrder,
    active: true,
    notes: ''
  });
  renderPayrollApprovers();
}

function removePayrollApprover(index) {
  payrollApprovers.splice(index, 1);
  // Reorder
  payrollApprovers.forEach((a, i) => a.order = i + 1);
  renderPayrollApprovers();
}

function renderPayrollApprovers() {
  const container = document.getElementById('payrollApproversList');
  if (payrollApprovers.length === 0) {
    container.innerHTML = `
      <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-8 text-center">
        <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
        </svg>
        <p class="mt-2 text-sm text-slate-600">No payroll run approvers configured yet.</p>
        <p class="mt-1 text-xs text-slate-500">Click "Add Approver" to configure the approval workflow.</p>
      </div>
    `;
    return;
  }
  
  container.innerHTML = payrollApprovers.map((approver, index) => {
    const selectedUser = eligibleUsers.find(u => u.id == approver.user_id);
    return `
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="flex items-start gap-4">
          <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-blue-100 text-blue-700 font-semibold">
            ${index + 1}
          </div>
          <div class="flex-1 space-y-3">
            <div class="grid md:grid-cols-2 gap-3">
              <label class="block">
                <span class="text-xs font-medium text-slate-700">Select User</span>
                <select onchange="updatePayrollApprover(${index}, 'user_id', parseInt(this.value))" class="input-text mt-1" required>
                  <option value="">-- Select User --</option>
                  ${eligibleUsers.map(u => `
                    <option value="${u.id}" ${approver.user_id == u.id ? 'selected' : ''}>
                      ${u.full_name} ${u.first_name || u.last_name ? '(' + [u.first_name, u.last_name].filter(Boolean).join(' ') + ')' : ''}
                    </option>
                  `).join('')}
                </select>
              </label>
              
              <label class="flex items-center gap-2 pt-6">
                <input type="checkbox" ${approver.active ? 'checked' : ''} 
                       onchange="updatePayrollApprover(${index}, 'active', this.checked)"
                       class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm font-medium text-slate-700">Active</span>
              </label>
            </div>
            
            <label class="block">
              <span class="text-xs font-medium text-slate-700">Notes (optional)</span>
              <input type="text" value="${approver.notes || ''}" 
                     onchange="updatePayrollApprover(${index}, 'notes', this.value)"
                     placeholder="e.g., Primary approver for IT department"
                     class="input-text mt-1">
            </label>
          </div>
          
          <button type="button" onclick="removePayrollApprover(${index})" 
                  class="flex-shrink-0 text-red-600 hover:text-red-700 p-2">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function updatePayrollApprover(index, field, value) {
  payrollApprovers[index][field] = value;
}

function cancelPayrollApprovers() {
  payrollApprovers = initialPayrollApprovers.map(a => ({
    user_id: parseInt(a.user_id),
    order: parseInt(a.approval_order),
    active: a.active === true || a.active === 't' || a.active === '1',
    notes: a.notes || '',
    username: a.username,
    email: a.email,
    first_name: a.first_name,
    last_name: a.last_name
  }));
  renderPayrollApprovers();
}

document.getElementById('payrollApproversForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  // Validate
  for (let i = 0; i < payrollApprovers.length; i++) {
    if (!payrollApprovers[i].user_id) {
      alert('Please select a user for approver #' + (i + 1));
      return;
    }
  }
  
  // Serialize
  document.getElementById('payrollApproversData').value = JSON.stringify(payrollApprovers);
  this.submit();
});

// Adjustment Approvers Functions
function addAdjustmentApprover() {
  const newOrder = adjustmentApprovers.length + 1;
  adjustmentApprovers.push({
    user_id: null,
    order: newOrder,
    active: true,
    notes: ''
  });
  renderAdjustmentApprovers();
}

function removeAdjustmentApprover(index) {
  adjustmentApprovers.splice(index, 1);
  // Reorder
  adjustmentApprovers.forEach((a, i) => a.order = i + 1);
  renderAdjustmentApprovers();
}

function renderAdjustmentApprovers() {
  const container = document.getElementById('adjustmentApproversList');
  if (adjustmentApprovers.length === 0) {
    container.innerHTML = `
      <div class="rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50 p-8 text-center">
        <svg class="mx-auto h-12 w-12 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <p class="mt-2 text-sm text-slate-600">No payroll adjustment approvers configured yet.</p>
        <p class="mt-1 text-xs text-slate-500">Click "Add Approver" to configure the adjustment approval workflow.</p>
      </div>
    `;
    return;
  }
  
  container.innerHTML = adjustmentApprovers.map((approver, index) => {
    return `
      <div class="rounded-lg border border-emerald-200 bg-white p-4">
        <div class="flex items-start gap-4">
          <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 font-semibold">
            ${index + 1}
          </div>
          <div class="flex-1 space-y-3">
            <div class="grid md:grid-cols-2 gap-3">
              <label class="block">
                <span class="text-xs font-medium text-slate-700">Select User</span>
                <select onchange="updateAdjustmentApprover(${index}, 'user_id', parseInt(this.value))" class="input-text mt-1" required>
                  <option value="">-- Select User --</option>
                  ${eligibleUsers.map(u => `
                    <option value="${u.id}" ${approver.user_id == u.id ? 'selected' : ''}>
                      ${u.full_name} ${u.first_name || u.last_name ? '(' + [u.first_name, u.last_name].filter(Boolean).join(' ') + ')' : ''}
                    </option>
                  `).join('')}
                </select>
              </label>
              
              <label class="flex items-center gap-2 pt-6">
                <input type="checkbox" ${approver.active ? 'checked' : ''} 
                       onchange="updateAdjustmentApprover(${index}, 'active', this.checked)"
                       class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm font-medium text-slate-700">Active</span>
              </label>
            </div>
            
            <label class="block">
              <span class="text-xs font-medium text-slate-700">Notes (optional)</span>
              <input type="text" value="${approver.notes || ''}" 
                     onchange="updateAdjustmentApprover(${index}, 'notes', this.value)"
                     placeholder="e.g., Approves adjustments up to ₱5,000"
                     class="input-text mt-1">
            </label>
          </div>
          
          <button type="button" onclick="removeAdjustmentApprover(${index})" 
                  class="flex-shrink-0 text-red-600 hover:text-red-700 p-2">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function updateAdjustmentApprover(index, field, value) {
  adjustmentApprovers[index][field] = value;
}

function cancelAdjustmentApprovers() {
  adjustmentApprovers = initialAdjustmentApprovers.map(a => ({
    user_id: parseInt(a.user_id),
    order: parseInt(a.approval_order),
    active: a.active === true || a.active === 't' || a.active === '1',
    notes: a.notes || '',
    username: a.username,
    email: a.email,
    first_name: a.first_name,
    last_name: a.last_name
  }));
  renderAdjustmentApprovers();
}

document.getElementById('adjustmentApproversForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  // Validate
  for (let i = 0; i < adjustmentApprovers.length; i++) {
    if (!adjustmentApprovers[i].user_id) {
      alert('Please select a user for approver #' + (i + 1));
      return;
    }
  }
  
  // Serialize
  document.getElementById('adjustmentApproversData').value = JSON.stringify(adjustmentApprovers);
  this.submit();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
