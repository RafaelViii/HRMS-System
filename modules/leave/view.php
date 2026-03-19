<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));

$currentEmployeeId = null;
try {
  $empStmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = :uid LIMIT 1');
  $empStmt->execute([':uid' => $uid]);
  $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
  if ($empRow) {
    $currentEmployeeId = (int)$empRow['id'];
  }
} catch (Throwable $e) {
  $currentEmployeeId = null;
}

$id = (int)($_GET['id'] ?? 0);
$defaultBackUrl = BASE_URL . '/modules/leave/index';
if ($id <= 0) { header('Location: ' . $defaultBackUrl); exit; }

// Fetch request and employee
try {
  $stmt = $pdo->prepare('SELECT lr.*, e.employee_code, e.first_name, e.last_name, e.user_id, e.department_id, d.name as department_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE lr.id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $req = null;
}
if (!$req) { header('Location: ' . $defaultBackUrl); exit; }

$attachments = [];
try {
  $attStmt = $pdo->prepare('SELECT id, document_type, title, description, file_path, original_name, file_size, uploaded_at FROM leave_request_attachments WHERE leave_request_id = :id ORDER BY uploaded_at ASC');
  $attStmt->execute([':id' => $id]);
  $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $attachments = [];
}

$isOwnRequest = false;
if ($currentEmployeeId !== null && (int)$req['employee_id'] === $currentEmployeeId) {
  $isOwnRequest = true;
}
if ((int)($req['user_id'] ?? 0) === $uid) {
  $isOwnRequest = true;
}

$hasAdminAccess = user_has_access($uid, 'leave', 'leave_approval', 'manage');
$isAdminOrHR = in_array($role, ['admin', 'hr'], true) && user_has_access($uid, 'leave', 'leave_approval', 'write');

$isSupervisorOfDepartment = false;
$employeeDepartmentId = (int)($req['department_id'] ?? 0);
if ($employeeDepartmentId > 0) {
  try {
    $supCheck = $pdo->prepare('SELECT id FROM department_supervisors WHERE department_id = :dept AND supervisor_user_id = :uid LIMIT 1');
    $supCheck->execute([':dept' => $employeeDepartmentId, ':uid' => $uid]);
    $isSupervisorOfDepartment = (bool)$supCheck->fetch();
  } catch (Throwable $e) {
    $isSupervisorOfDepartment = false;
  }
}

$canManage = $hasAdminAccess || $isAdminOrHR || $isSupervisorOfDepartment;

if (!$isOwnRequest && !$canManage) {
  flash_error('You do not have permission to view that leave request.');
  header('Location: ' . $defaultBackUrl);
  exit;
}

$backUrl = $isOwnRequest ? BASE_URL . '/modules/leave/index' : BASE_URL . '/modules/leave/admin';
$showDecisionForm = $canManage && !$isOwnRequest && ($req['status'] === 'pending');

$statusBadgeMap = [
  'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
  'approved' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
  'rejected' => 'bg-rose-100 text-rose-800 border-rose-200',
  'cancelled' => 'bg-slate-100 text-slate-800 border-slate-200',
];
$statusKey = strtolower((string)($req['status'] ?? ''));
$statusBadgeClass = $statusBadgeMap[$statusKey] ?? 'bg-slate-100 text-slate-800 border-slate-200';
$createdDisplay = !empty($req['created_at']) ? format_datetime_display($req['created_at'], false, '') : '—';
$updatedDisplay = !empty($req['updated_at']) ? format_datetime_display($req['updated_at'], false, '') : '—';

$errors = [];

// Handle POST first before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$showDecisionForm) {
    $errors[] = 'You are not allowed to change this request.';
  } else {
    if (!csrf_verify($_POST['csrf'] ?? '')) { 
      $errors[] = 'Invalid CSRF token.'; 
    } else {
      $action = $_POST['action'] ?? '';
      $reason = trim($_POST['reason'] ?? '');
      if (!in_array($action, ['approve','reject'], true)) { 
        $errors[] = 'Invalid action.'; 
      }
      if ($action === 'reject' && $reason === '') { 
        $errors[] = 'Reason is required when rejecting.'; 
      }
      if (!$errors) {
        if ($req['status'] !== 'pending') {
          $errors[] = 'Request is not pending.';
        } else {
          $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
          // Verify leave balance before approving
          if ($newStatus === 'approved') {
            $leaveType = strtolower((string)($req['leave_type'] ?? ''));
            $totalDays = (float)($req['total_days'] ?? 0);
            $balances = leave_calculate_balances($pdo, (int)$req['employee_id']);
            $remaining = $balances[$leaveType] ?? 0;
            if ($leaveType !== 'unpaid' && $totalDays > $remaining) {
              $errors[] = 'Insufficient leave balance. Remaining: ' . $remaining . ' day(s), Requested: ' . $totalDays . ' day(s).';
            }
          }
          if (!$errors) {
            $pdo->beginTransaction();
            try {
              $up = $pdo->prepare('UPDATE leave_requests SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
              $up->execute([':st'=>$newStatus, ':id'=>$id]);
              $ins = $pdo->prepare('INSERT INTO leave_request_actions (leave_request_id, action, reason, acted_by) VALUES (:id, :act, :reason, :by)');
              $ins->execute([':id'=>$id, ':act'=>$newStatus, ':reason'=>$reason, ':by'=>$uid]);
              $pdo->commit();
              action_log('leave', 'leave_decision_recorded', 'success', ['leave_request_id'=>$id,'decision'=>$newStatus]);
              audit('leave_' . $newStatus, json_encode(['leave_request_id'=>$id,'reason'=>$reason]));
              $title = $newStatus === 'approved' ? 'Leave request approved' : 'Leave request rejected';
              $body = 'Your leave request #' . $id . ' was ' . $newStatus . ($reason ? ' — ' . $reason : '.');
              notify($req['user_id'] ?: null, $title, $body);
              flash_success('Leave ' . $newStatus);
              header('Location: ' . BASE_URL . '/modules/leave/view?id=' . $id);
              exit;
            } catch (Throwable $e) {
              $pdo->rollBack();
              sys_log('LEAVE1002', 'Decision failed - ' . $e->getMessage(), ['module'=>'leave','file'=>__FILE__,'line'=>__LINE__]);
              $errors[] = 'Could not save decision. Please try again.';
            }
          }
        }
      }
    }
  }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-5xl mx-auto space-y-6">
  <!-- Page Header -->
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Leave Request #<?= (int)$req['id'] ?></h1>
      <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars($req['last_name'] . ', ' . $req['first_name']) ?> • <?= htmlspecialchars($req['employee_code']) ?></p>
    </div>
    <a href="<?= $backUrl ?>" class="text-sm text-slate-600 hover:text-slate-900">← Back</a>
  </div>

  <!-- Alerts -->
  <?php if ($errors): ?>
    <?php foreach ($errors as $e): ?>
      <div class="border border-red-300 bg-red-50 rounded p-3 text-sm text-red-800">
        <?= htmlspecialchars($e) ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($isOwnRequest && !$canManage): ?>
    <div class="border border-blue-300 bg-blue-50 rounded p-3 text-sm text-blue-800">
      This request is in read-only mode. Your supervisor will handle approval.
    </div>
  <?php elseif (!$showDecisionForm && $canManage): ?>
    <div class="border border-slate-300 bg-slate-50 rounded p-3 text-sm text-slate-700">
      This request is already <?= htmlspecialchars(ucfirst($req['status'])) ?> and cannot be modified.
    </div>
  <?php endif; ?>

  <!-- Status Badge -->
  <div class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-medium border <?= $statusBadgeClass ?>">
    <?= htmlspecialchars(ucfirst($req['status'])) ?>
  </div>

  <!-- Request Details -->
  <div class="bg-white border border-slate-200 rounded-lg">
    <div class="border-b border-slate-200 px-6 py-3">
      <h2 class="text-base font-semibold text-slate-900">Request Details</h2>
    </div>
    <div class="p-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">Employee</p>
          <p class="font-medium text-slate-900"><?= htmlspecialchars($req['last_name'] . ', ' . $req['first_name']) ?></p>
          <p class="text-sm text-slate-600"><?= htmlspecialchars($req['employee_code']) ?></p>
          <?php if (!empty($req['department_name'])): ?>
            <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($req['department_name']) ?></p>
          <?php endif; ?>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">Leave Type</p>
          <p class="inline-flex items-center gap-2 text-sm font-medium text-green-700 bg-green-50 px-3 py-1 rounded-md"><?= htmlspecialchars(leave_label_for_type((string)$req['leave_type'])) ?></p>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">Duration</p>
          <p class="text-2xl font-bold text-green-600"><?= htmlspecialchars(number_format((float)$req['total_days'], 1)) ?> <span class="text-sm font-normal text-slate-600">day<?= (float)$req['total_days'] !== 1.0 ? 's' : '' ?></span></p>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">Start Date</p>
          <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars(date('M d, Y', strtotime($req['start_date']))) ?></p>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">End Date</p>
          <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars(date('M d, Y', strtotime($req['end_date']))) ?></p>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 mb-1">Filed On</p>
          <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($createdDisplay) ?></p>
        </div>
      </div>
      <?php if (!empty($req['remarks'])): ?>
        <div class="mt-6">
          <p class="text-sm font-medium text-slate-900 mb-2">Employee Remarks</p>
          <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm text-slate-700"><?= nl2br(htmlspecialchars($req['remarks'])) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($attachments): ?>
  <div class="bg-white border border-slate-200 rounded-lg">
    <div class="border-b border-slate-200 px-6 py-3">
      <h2 class="text-base font-semibold text-slate-900">Attachments <span class="text-sm font-normal text-slate-500">(<?= count($attachments) ?>)</span></h2>
    </div>
    <ul class="divide-y divide-slate-200">
      <?php foreach ($attachments as $att): ?>
        <?php
          $relativePath = ltrim((string)($att['file_path'] ?? ''), '/');
          $fileUrl = BASE_URL . '/' . $relativePath;
          $extension = strtolower((string)pathinfo((string)($att['file_path'] ?? ''), PATHINFO_EXTENSION));
          $isImage = in_array($extension, ['png','jpg','jpeg'], true);
          $isPdf = $extension === 'pdf';
        ?>
        <li class="p-4 hover:bg-slate-50">
          <div class="flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-slate-900 text-sm"><?= htmlspecialchars($att['title'] ?? $att['original_name']) ?></p>
              <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($att['document_type'] ?: 'Document') ?> • <?= htmlspecialchars(number_format(max(0, (float)($att['file_size'] ?? 0)) / 1024, 1)) ?> KB</p>
            </div>
            <a class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 hover:text-green-700" href="<?= htmlspecialchars($fileUrl) ?>" target="_blank">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
              </svg>
              Download
            </a>
          </div>
          <?php if ($isImage): ?>
            <img src="<?= htmlspecialchars($fileUrl) ?>" alt="Preview" class="mt-3 max-h-64 rounded-lg border border-slate-200">
          <?php elseif ($isPdf): ?>
            <iframe src="<?= htmlspecialchars($fileUrl) ?>" class="mt-3 h-96 w-full rounded-lg border border-slate-200"></iframe>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($showDecisionForm): ?>
  <div class="bg-green-50 border border-green-200 rounded-lg">
    <div class="border-b border-green-200 px-6 py-3">
      <h2 class="text-base font-semibold text-slate-900">Record Decision</h2>
      <p class="text-xs text-slate-600 mt-1">Your decision will be logged and the employee will be notified.</p>
    </div>
    <form method="post" class="p-6" id="decisionForm">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="mb-4">
        <label class="mb-2 block text-sm font-medium text-slate-900">
          Reason or Comments <span class="text-xs text-slate-500">(Required for rejection)</span>
        </label>
        <textarea name="reason" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent" rows="3" placeholder="Provide context for your decision..."></textarea>
      </div>
      <div class="flex gap-3">
        <button class="flex-1 bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition" type="button" data-action="approve">
          Approve
        </button>
        <button class="flex-1 bg-slate-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-700 transition" type="button" data-action="reject">
          Reject
        </button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Decision History -->
  <div class="bg-white border border-slate-200 rounded-lg">
    <div class="border-b border-slate-200 px-6 py-3">
      <h2 class="text-base font-semibold text-slate-900">Decision History</h2>
    </div>
    <?php 
    try { 
      $s = $pdo->prepare('SELECT a.*, u.full_name as actor FROM leave_request_actions a LEFT JOIN users u ON u.id = a.acted_by WHERE a.leave_request_id = :id ORDER BY a.acted_at DESC'); 
      $s->execute([':id'=>(int)$req['id']]); 
      $acts = $s->fetchAll(PDO::FETCH_ASSOC); 
    } catch (Throwable $e) { 
      $acts = []; 
    } 
    ?>
    <div class="p-6">
      <?php if ($acts): ?>
        <div class="space-y-3">
          <?php foreach ($acts as $a): ?>
            <?php
              $isApproved = strtolower($a['action']) === 'approved';
              $bgClass = $isApproved ? 'bg-green-50 border-green-200' : 'bg-slate-50 border-slate-200';
              $textClass = $isApproved ? 'text-green-700' : 'text-slate-700';
            ?>
            <div class="border rounded-lg <?= $bgClass ?> p-4">
              <div class="flex items-start justify-between mb-2">
                <div>
                  <p class="font-medium <?= $textClass ?>"><?= htmlspecialchars(ucfirst($a['action'])) ?></p>
                  <p class="text-sm text-slate-600">by <?= htmlspecialchars($a['actor'] ?? 'System') ?></p>
                </div>
                <p class="text-xs text-slate-500"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($a['acted_at']))) ?></p>
              </div>
              <?php if ($a['reason']): ?>
                <div class="mt-2 text-sm text-slate-700">
                  <span class="font-medium">Reason:</span> <?= nl2br(htmlspecialchars($a['reason'])) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-center text-sm text-slate-500 py-8">No decisions recorded yet</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="w-full max-w-md bg-white rounded-lg shadow-xl">
    <div class="border-b border-slate-200 px-6 py-4">
      <h3 class="font-semibold text-slate-900" id="modalTitle">Confirm Decision</h3>
    </div>
    <div class="px-6 py-4">
      <p class="text-sm text-slate-600" id="modalMessage">Are you sure you want to proceed?</p>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
      <button type="button" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg" id="modalCancel">
        Cancel
      </button>
      <button type="button" class="px-4 py-2 text-sm font-medium text-white rounded-lg" id="modalConfirm">
        Confirm
      </button>
    </div>
  </div>
</div>

<script>
(function() {
  const modal = document.getElementById('confirmModal');
  const modalTitle = document.getElementById('modalTitle');
  const modalMessage = document.getElementById('modalMessage');
  const modalCancel = document.getElementById('modalCancel');
  const modalConfirm = document.getElementById('modalConfirm');
  const form = document.getElementById('decisionForm');
  
  if (!modal || !form) return;
  
  let currentAction = null;
  
  // Handle action buttons
  document.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      currentAction = this.getAttribute('data-action');
      
      // Validate reason for rejection
      const reasonField = form.querySelector('textarea[name="reason"]');
      if (currentAction === 'reject' && reasonField && !reasonField.value.trim()) {
        alert('Please provide a reason for rejecting this leave request.');
        reasonField.focus();
        return;
      }
      
      if (currentAction === 'approve') {
        modalTitle.textContent = 'Approve Leave Request';
        modalMessage.textContent = 'Are you sure you want to approve this leave request? The employee will be notified immediately.';
        modalConfirm.className = 'px-4 py-2 text-sm font-medium text-white rounded-lg bg-green-600 hover:bg-green-700';
        modalConfirm.textContent = 'Approve';
      } else {
        modalTitle.textContent = 'Reject Leave Request';
        modalMessage.textContent = 'Are you sure you want to reject this leave request? The employee will be notified with your reason.';
        modalConfirm.className = 'px-4 py-2 text-sm font-medium text-white rounded-lg bg-slate-600 hover:bg-slate-700';
        modalConfirm.textContent = 'Reject';
      }
      
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    });
  });
  
  // Cancel
  modalCancel.addEventListener('click', function() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    currentAction = null;
  });
  
  // Confirm and submit
  modalConfirm.addEventListener('click', function() {
    if (!currentAction) return;
    
    // Disable button to prevent double submission
    modalConfirm.disabled = true;
    modalConfirm.textContent = 'Processing...';
    
    // Remove any existing action input
    const existingInput = form.querySelector('input[name="action"]');
    if (existingInput) {
      existingInput.remove();
    }
    
    // Create hidden input for action
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = currentAction;
    form.appendChild(input);
    
    // Submit form
    form.submit();
  });
  
  // Close on background click
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      modalCancel.click();
    }
  });
  
  // Close on ESC key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
      modalCancel.click();
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
