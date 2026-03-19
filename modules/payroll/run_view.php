<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('payroll', 'payroll_runs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/payroll.php';
require_once __DIR__ . '/../../includes/utils.php';

// Prevent any caching of this page to ensure complaint status updates are always fresh
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = get_db_conn();
$runId = (int)($_GET['id'] ?? 0);
if ($runId <= 0) {
    flash_error('Payroll run not found');
    header('Location: ' . BASE_URL . '/modules/payroll/index');
    exit;
}
$run = payroll_get_run($pdo, $runId);
if (!$run) {
    flash_error('Payroll run not found');
    header('Location: ' . BASE_URL . '/modules/payroll/index');
    exit;
}

$branches = payroll_get_branches($pdo);

// Load compensation templates for adjustment categories
$allowanceTemplates = payroll_get_compensation_templates($pdo, 'allowance', true, false);
$deductionTemplates = payroll_get_compensation_templates($pdo, 'deduction', true, false);
$contributionTemplates = payroll_get_compensation_templates($pdo, 'contribution', true, false);
$taxTemplates = payroll_get_compensation_templates($pdo, 'tax', true, false);

// Auto-initialize branch submissions if they don't exist yet
$submissions = payroll_get_run_submissions($pdo, $runId);
if (empty($submissions) && !empty($branches)) {
    payroll_init_branch_submissions_for_run($pdo, $runId);
    // Reload submissions after initialization
    $submissions = payroll_get_run_submissions($pdo, $runId);
}

$subMap = [];
foreach ($submissions as $sub) { $subMap[(int)$sub['id']] = $sub; }

$batches = payroll_list_batches($pdo, $runId);

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserRole = $currentUser['role'] ?? 'employee';
$currentAccessLevel = $currentUserId ? user_access_level($currentUserId, 'payroll') : 'none';
$isSystemAdmin = in_array($currentUserRole, ['admin', 'hr_manager'], true);

// DEBUG: Log user info for troubleshooting
// Debug logging disabled for production
// sys_log('USER-INFO-DEBUG', ...);

payroll_initialize_approvals($pdo, $runId);
$approvals = payroll_get_run_approvals($pdo, $runId);

// FORCE FRESH COMPLAINT DATA - NO CACHING
$complaintsQuery = "SELECT pc.*, e.employee_code, e.first_name, e.last_name
                    FROM payroll_complaints pc
                    LEFT JOIN employees e ON e.id = pc.employee_id
                    WHERE pc.payroll_run_id = :run
                    ORDER BY pc.created_at DESC";
$complaintsStmt = $pdo->prepare($complaintsQuery);
$complaintsStmt->execute([':run' => $runId]);
$complaints = $complaintsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Debug logging disabled for production
// sys_log('COMPLAINTS-LOADED', ...);

$complaintCategories = payroll_get_complaint_categories();
$complaintPriorities = payroll_get_complaint_priorities();
$pendingAdjustments = payroll_get_pending_adjustments($pdo, $runId);
$isAdjustmentApprover = payroll_is_adjustment_approver($pdo, $currentUserId);
$payslips = payroll_get_run_payslips($pdo, $runId);
$runYearLabel = !empty($run['period_start']) ? date('Y', strtotime($run['period_start'])) : date('Y');
$payslipTotals = [
  'count' => count($payslips),
  'basic' => 0,
  'earnings' => 0,
  'deductions' => 0,
  'net' => 0,
];
foreach ($payslips as $payslipRow) {
  $payslipTotals['basic'] += (float)($payslipRow['basic_pay'] ?? 0);
  $payslipTotals['earnings'] += (float)($payslipRow['total_earnings'] ?? 0);
  $payslipTotals['deductions'] += (float)($payslipRow['total_deductions'] ?? 0);
  $payslipTotals['net'] += (float)($payslipRow['net_pay'] ?? 0);
}
$hasPayslipSummary = $payslipTotals['count'] > 0;
$payslipStatusOptions = [];
$payslipDepartmentOptions = [];
foreach ($payslips as $payslipRow) {
  $statusLabel = trim((string)($payslipRow['status'] ?? ''));
  $statusKey = strtolower($statusLabel);
  if ($statusKey !== '') {
    $payslipStatusOptions[$statusKey] = $statusLabel !== '' ? $statusLabel : strtoupper($statusKey);
  }
  $deptLabel = trim((string)($payslipRow['department_name'] ?? '')) ?: 'Unassigned';
  $payslipDepartmentOptions[$deptLabel] = $deptLabel;
}
ksort($payslipStatusOptions);
ksort($payslipDepartmentOptions, SORT_NATURAL | SORT_FLAG_CASE);
$payslipItems = $payslips ? payroll_get_payslip_items($pdo, array_column($payslips, 'id')) : [];
$runBreakdown = [
  'earning' => [],
  'deduction' => [],
];
foreach ($payslipItems as $psItems) {
  foreach ($psItems as $item) {
    $type = $item['type'] ?? '';
    if ($type !== 'earning' && $type !== 'deduction') {
      continue;
    }
    $amount = (float)($item['amount'] ?? 0);
    if ($amount === 0.0) {
      continue;
    }
    $label = trim((string)($item['label'] ?? '')) ?: trim((string)($item['code'] ?? 'Misc')) ?: 'Misc';
    $key = strtolower($item['code'] ?? $label);
    if (!isset($runBreakdown[$type][$key])) {
      $runBreakdown[$type][$key] = [
        'label' => $label,
        'code' => $item['code'] ?? null,
        'amount' => 0,
      ];
    }
    $runBreakdown[$type][$key]['amount'] += $amount;
  }
}
foreach ($runBreakdown as $typeKey => $lines) {
  uasort($lines, static function (array $a, array $b): int {
    return ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0);
  });
  $runBreakdown[$typeKey] = array_values($lines);
}

$uploadBase = realpath(__DIR__ . '/../../assets/uploads/payroll_submissions');
if (!$uploadBase) {
  $uploadBase = __DIR__ . '/../../assets/uploads/payroll_submissions';
  if (!is_dir($uploadBase)) {
    @mkdir($uploadBase, 0775, true);
  }
}

$branchStatuses = [
  'pending' => 'Pending',
  'awaiting_dtr' => 'Awaiting DTR',
  'submitted' => 'Submitted',
  'computing' => 'Computing',
  'for_review' => 'For Review',
  'for_revision' => 'For Revision',
  'accepted' => 'Accepted',
  'approved' => 'Approved',
  'released' => 'Released',
  'closed' => 'Closed',
  'rejected' => 'Rejected',
  'error' => 'Error',
  'missing' => 'Missing',
];

$complaintStatusLabels = [
  'pending' => 'Pending',
  'in_review' => 'In Review',
  'resolved' => 'Resolved',
  'confirmed' => 'Confirmed',
  'rejected' => 'Rejected',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token.');
    header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
    exit;
  }
  $action = $_POST['action'] ?? 'update_submission';
  
  switch ($action) {
    case 'submit_dtr':
      $batchId = (int)($_POST['batch_id'] ?? 0);
      if ($batchId <= 0) {
        flash_error('Invalid batch ID.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      try {
        // First, get batch details before transaction
        $batch = payroll_get_batch($pdo, $batchId);
        if (!$batch) {
          flash_error('Batch not found.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }

        $pdo->beginTransaction();

        // Update batch status to mark DTR as submitted
        $updateBatchSql = "UPDATE payroll_batches 
                   SET status = 'submitted', 
                     submitted_at = CURRENT_TIMESTAMP,
                     submitted_by = :user_id
                   WHERE id = :batch_id AND payroll_run_id = :run_id";
        $stmt = $pdo->prepare($updateBatchSql);
        $stmt->execute([
          ':user_id' => $currentUserId,
          ':batch_id' => $batchId,
          ':run_id' => $runId
        ]);

        // Mark attendance records as locked/submitted for this batch
        $lockAttSql = "UPDATE attendance 
                       SET status = 'submitted'
                       WHERE employee_id IN (
                         SELECT id FROM employees WHERE branch_id = :branch_id
                       )
                       AND date BETWEEN :period_start AND :period_end";
        $lockStmt = $pdo->prepare($lockAttSql);
        $lockStmt->execute([
          ':branch_id' => $batch['branch_id'],
          ':period_start' => $run['period_start'],
          ':period_end' => $run['period_end']
        ]);

        // Sync branch submission tracker to reflect the successful DTR submission
        $submissionUpdate = $pdo->prepare("UPDATE payroll_branch_submissions
            SET status = 'submitted',
                submitted_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE payroll_run_id = :run_id AND branch_id = :branch_id");
        $submissionUpdate->execute([
          ':run_id' => $runId,
          ':branch_id' => $batch['branch_id'],
        ]);

        $pdo->commit();

        action_log('payroll', 'submit_dtr', 'success', [
          'batch_id' => $batchId,
          'run_id' => $runId
        ]);

        flash_success('DTR submitted successfully. Attendance records are now locked for approval workflow.');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        sys_log('DTR-SUBMIT-001', 'Failed to submit DTR: ' . $e->getMessage(), [
          'module' => 'payroll',
          'file' => __FILE__,
          'line' => __LINE__,
          'context' => ['batch_id' => $batchId]
        ]);
        flash_error('Failed to submit DTR. Please try again.');
      }

      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'generate_payslips':
      $authGen = ensure_action_authorized('payroll', 'compute', 'write');
      if (!$authGen['ok']) {
        flash_error('Generating the payroll summary requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $actingUserId = (int)($authGen['as_user'] ?? $currentUserId);
      $result = payroll_generate_payslips_for_run($pdo, $runId, null, $actingUserId);
      if (!$result['ok']) {
        flash_error('Payroll summary generation completed with issues: ' . implode('; ', $result['errors']));
      } else {
        $successMsg = 'Payroll summary generated: ' . (int)$result['generated'] . ' new, ' . (int)$result['updated'] . ' updated';
        if (!empty($result['warnings'])) {
          $successMsg .= '. Warnings: ' . implode('; ', $result['warnings']);
        }
        flash_success($successMsg);
        // Only mark run as submitted on successful generation
        payroll_mark_run_submitted($pdo, $runId, $actingUserId);
      }
      action_log('payroll', 'run_generate_payslips', $result['ok'] ? 'success' : 'error', ['run_id' => $runId, 'summary' => $result]);
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'compute_batch':
      $batchId = (int)($_POST['batch_id'] ?? 0);
      if ($batchId <= 0) {
        flash_error('Batch not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $authComp = ensure_action_authorized('payroll', 'compute', 'write');
      if (!$authComp['ok']) {
        flash_error('Batch payroll computation requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $actingUserId = (int)($authComp['as_user'] ?? $currentUserId);
      $resB = payroll_generate_payslips_for_batch($pdo, $batchId, $actingUserId);
      if (!$resB['ok']) {
        flash_error('Batch payroll computation finished with issues: ' . implode('; ', $resB['errors']));
      } else {
        $successMsg = 'Batch summary computed: ' . (int)$resB['generated'] . ' new, ' . (int)$resB['updated'] . ' updated.';
        if (!empty($resB['warnings'])) {
          $successMsg .= ' Warnings: ' . implode('; ', $resB['warnings']);
        }
        flash_success($successMsg);
      }
      action_log('payroll', 'batch_compute', $resB['ok'] ? 'success' : 'error', ['batch_id' => $batchId, 'summary' => $resB]);
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'batch_update':
      $batchId = (int)($_POST['batch_id'] ?? 0);
      $newStatus = $_POST['status'] ?? 'pending';
      if ($batchId <= 0) {
        flash_error('Batch not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $authBatch = ensure_action_authorized('payroll', 'batch_update', 'write');
      if (!$authBatch['ok']) {
        flash_error('Batch update requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $actingUserId = (int)($authBatch['as_user'] ?? $currentUserId);
      $remarks = trim($_POST['remarks'] ?? '');
      if (!payroll_update_batch_status($pdo, $batchId, $newStatus, $actingUserId, $remarks ?: null)) {
        flash_error('Unable to update batch status.');
      } else {
        action_log('payroll', 'batch_updated', 'success', ['batch_id' => $batchId, 'status' => $newStatus]);
        flash_success('Batch updated.');
      }
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'batch_approval_decision':
      $batchId = (int)($_POST['batch_id'] ?? 0);
      $stepOrder = (int)($_POST['step_order'] ?? 0);
      $decision = $_POST['decision'] ?? 'approved';
      $decision = in_array($decision, ['approved','rejected'], true) ? $decision : 'approved';
      $remarks = trim($_POST['remarks'] ?? '');

      if ($batchId <= 0 || $stepOrder <= 0) {
        flash_error('Invalid batch approval request.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $batch = payroll_get_batch($pdo, $batchId);
      if (!$batch || (int)$batch['payroll_run_id'] !== $runId) {
        flash_error('Batch not found for this run.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $state = payroll_get_batch_approval_state($pdo, $batchId);
      $targetStep = null;
      foreach ($state['steps'] as $st) { if ((int)$st['step_order'] === $stepOrder) { $targetStep = $st; break; } }
      if (!$targetStep) {
        flash_error('Approval step not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      if ($targetStep['status'] !== 'pending') {
        flash_error('This batch approval step has already been processed.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      // Determine auth requirement
      $isSelf = $currentUserId > 0 && (int)($targetStep['user_id'] ?? 0) === $currentUserId;
      $requiresOverride = !empty($targetStep['requires_override']);
      $authLevel = ($isSelf && !$requiresOverride) ? 'write' : 'admin';
      $authRes = ensure_action_authorized('payroll', 'batch_approval', $authLevel);
      if (!$authRes['ok']) {
        flash_error('Batch approval requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $actingUserId = (int)($authRes['as_user'] ?? $currentUserId);
      $upd = payroll_update_batch_approval($pdo, $batchId, $stepOrder, $decision, $remarks ?: null, $actingUserId);
      if (!$upd['ok']) {
        flash_error('Unable to update batch approval: ' . ($upd['error'] ?: 'Unknown error'));
      } else {
        action_log('payroll', 'batch_approval_decision', 'success', ['batch_id' => $batchId, 'step' => $stepOrder, 'decision' => $decision]);
        flash_success('Batch approval updated.');
      }
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'approval_decision':
      $approvalId = (int)($_POST['approval_id'] ?? 0);
      $decision = $_POST['decision'] ?? 'approved';
      $decision = in_array($decision, ['approved', 'rejected'], true) ? $decision : 'approved';
      $remarks = trim($_POST['remarks'] ?? '');

      $targetApproval = null;
      foreach ($approvals as $appr) {
        if ((int)$appr['id'] === $approvalId) {
          $targetApproval = $appr;
          break;
        }
      }
      if (!$targetApproval) {
        flash_error('Approval record not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      if ($targetApproval['status'] !== 'pending') {
        flash_error('This approval step has already been processed.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $pendingStepOrders = [];
      foreach ($approvals as $appr) {
        if ($appr['status'] === 'pending') {
          $pendingStepOrders[] = (int)$appr['step_order'];
        }
      }
      $lowestPendingStep = $pendingStepOrders ? min($pendingStepOrders) : null;
      if ($decision === 'approved' && $lowestPendingStep !== null && (int)$targetApproval['step_order'] !== $lowestPendingStep) {
        flash_error('Please complete earlier approval steps first.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      $actingUserId = $currentUserId;
      if ($actingUserId <= 0 || (int)$targetApproval['user_id'] !== $actingUserId) {
        $authResult = ensure_action_authorized('payroll', 'approval_override', 'admin');
        if (!$authResult['ok']) {
          flash_error('Approval requires an authorized override.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        $actingUserId = (int)($authResult['as_user'] ?? $actingUserId);
      }

      if (!payroll_update_approval($pdo, $approvalId, $decision, $remarks ?: null, $actingUserId)) {
        flash_error('Unable to update approval status.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      $updatedApprovals = payroll_get_run_approvals($pdo, $runId);
      $pendingRemaining = 0;
      $hasRejected = false;
      $isFinalStep = (int)$targetApproval['step_order'] === count($updatedApprovals);
      
      foreach ($updatedApprovals as $appr) {
        if ($appr['status'] === 'pending') {
          $pendingRemaining++;
        }
        if ($appr['status'] === 'rejected') {
          $hasRejected = true;
        }
      }

      // Log approval state for debugging
      sys_log('APPROVAL-STATE', 'Approval decision processed', [
        'run_id' => $runId,
        'approval_id' => $approvalId,
        'decision' => $decision,
        'step' => $targetApproval['step_order'],
        'total_steps' => count($updatedApprovals),
        'pending_remaining' => $pendingRemaining,
        'has_rejected' => $hasRejected,
        'is_final_step' => $isFinalStep
      ]);

      // Handle rejection restart logic
      if ($decision === 'rejected' && !$isFinalStep) {
        // Rejection before final step: restart approval from step 1
        try {
          $pdo->beginTransaction();

          // Reset all approval steps to pending
            $resetSql = "UPDATE payroll_run_approvals 
                       SET status = 'pending', 
                           acted_at = NULL,
                           remarks = NULL 
                   WHERE payroll_run_id = :run_id";
          $resetStmt = $pdo->prepare($resetSql);
          $resetStmt->execute([':run_id' => $runId]);

          // Unlock all related batch submissions for re-submission
            $unlockSql = "UPDATE payroll_batches 
                        SET status = 'pending',
                            submitted_at = NULL,
                            submitted_by = NULL
                  WHERE payroll_run_id = :run_id";
          $unlockStmt = $pdo->prepare($unlockSql);
          $unlockStmt->execute([':run_id' => $runId]);

          payroll_update_run_status($pdo, $runId, 'pending');

          $pdo->commit();
          
          action_log('payroll', 'approval_rejected_restart', 'success', [
            'run_id' => $runId,
            'approval_id' => $approvalId,
            'step' => $targetApproval['step_order'],
            'restarted' => true
          ]);

          flash_success('Approval rejected. Workflow has been restarted from step 1. Branch submissions have been unlocked for re-submission.');
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          sys_log('APPROVAL-RESTART-001', 'Failed to restart approval workflow: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__
          ]);
          flash_error('Approval rejected but failed to restart workflow. Please check system logs.');
        }
      } elseif ($decision === 'rejected' || $hasRejected) {
        // Final step rejection: mark as rejected without restart
        sys_log('APPROVAL-STATUS', 'Setting run to rejected', ['run_id' => $runId]);
        payroll_update_run_status($pdo, $runId, 'rejected');
      } elseif ($pendingRemaining === 0) {
        sys_log('APPROVAL-STATUS', 'Setting run to approved - all steps complete', ['run_id' => $runId]);
        payroll_update_run_status($pdo, $runId, 'approved');
      } else {
        sys_log('APPROVAL-STATUS', 'Setting run to for_review', ['run_id' => $runId, 'pending' => $pendingRemaining]);
        payroll_update_run_status($pdo, $runId, 'for_review');
      }
      action_log('payroll', 'approval_decision', 'success', [
        'run_id' => $runId,
        'approval_id' => $approvalId,
        'decision' => $decision,
      ]);
      flash_success('Approval updated.');
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'delete_run':
      $authDelete = ensure_action_authorized('payroll', 'delete_run', 'admin');
      if (!$authDelete['ok']) {
        flash_error('Deleting a payroll run requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      try {
        $pdo->beginTransaction();

        // Clean up child tables first
        $delComplaints = $pdo->prepare('DELETE FROM payroll_complaints WHERE payroll_run_id = :run');
        $delComplaints->execute([':run' => $runId]);

        $delAdjQueue = $pdo->prepare('DELETE FROM payroll_adjustment_queue WHERE payroll_run_id = :run');
        $delAdjQueue->execute([':run' => $runId]);

        $delApprovals = $pdo->prepare('DELETE FROM payroll_run_approvals WHERE payroll_run_id = :run');
        $delApprovals->execute([':run' => $runId]);

        $delSubmissions = $pdo->prepare('DELETE FROM payroll_branch_submissions WHERE payroll_run_id = :run');
        $delSubmissions->execute([':run' => $runId]);

        $delBatches = $pdo->prepare('DELETE FROM payroll_batches WHERE payroll_run_id = :run');
        $delBatches->execute([':run' => $runId]);

        $delItems = $pdo->prepare('DELETE FROM payslip_items WHERE payslip_id = ANY (SELECT id FROM payslips WHERE payroll_run_id = :run)');
        $delItems->execute([':run' => $runId]);

        $delPayslips = $pdo->prepare('DELETE FROM payslips WHERE payroll_run_id = :run');
        $delPayslips->execute([':run' => $runId]);

        $delRun = $pdo->prepare('DELETE FROM payroll_runs WHERE id = :run');
        $delRun->execute([':run' => $runId]);

        $pdo->commit();
        action_log('payroll', 'run_deleted', 'success', ['run_id' => $runId]);
        flash_success('Payroll run deleted.');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        sys_log('PAYROLL-RUN-DELETE', 'Failed deleting payroll run via UI: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        flash_error('Unable to delete payroll run. See system logs for details.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      header('Location: ' . BASE_URL . '/modules/payroll/index');
      exit;

    case 'release_run':
      $authRelease = ensure_action_authorized('payroll', 'release_run', 'admin');
      if (!$authRelease['ok']) {
        flash_error('Release requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $evaluation = payroll_evaluate_release($pdo, $runId);
      if (!empty($evaluation['already_released'])) {
        flash_success('Payroll run is already released.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      if (!$evaluation['ok']) {
        $issueSummary = implode(' ', $evaluation['issues'] ?? []);
        flash_error('Cannot release run: ' . ($issueSummary ?: 'Outstanding blockers remain.'));
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $releasedBy = (int)($authRelease['as_user'] ?? $currentUserId);
      if (!payroll_mark_run_released($pdo, $runId, $releasedBy)) {
        flash_error('Unable to mark payroll run as released.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      action_log('payroll', 'run_released', 'success', [
        'run_id' => $runId,
        'released_by' => $releasedBy,
      ]);
      flash_success('Payroll run released. Payslips may now be distributed.');
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'complaint_update':
      $complaintId = (int)($_POST['complaint_id'] ?? 0);
      $requestedStatus = strtolower(trim((string)($_POST['status'] ?? 'pending')));
      $newStatus = isset($complaintStatusLabels[$requestedStatus]) ? $requestedStatus : 'pending';
      $resolutionNotes = trim((string)($_POST['resolution_notes'] ?? ''));
      $notes = $resolutionNotes !== '' ? $resolutionNotes : null;

      if ($complaintId <= 0) {
        flash_error('Invalid complaint ID.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      $targetComplaint = payroll_get_complaint($pdo, $complaintId);
      if (!$targetComplaint || (int)$targetComplaint['run_id'] !== $runId) {
        flash_error('Complaint record not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      $oldStatus = strtolower((string)($targetComplaint['status'] ?? 'pending'));

      $authComplUpdate = ensure_action_authorized('payroll', 'complaint_update', 'write');
      if (!$authComplUpdate['ok']) {
        flash_error('Complaint updates require an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $actingUserId = (int)($authComplUpdate['as_user'] ?? $currentUserId);

      // Adjustment intent (resolved complaints only)
      $hasAdjustment = isset($_POST['add_adjustment']) && $_POST['add_adjustment'] === '1';
      $adjustmentAmount = $hasAdjustment ? round((float)($_POST['adjustment_amount'] ?? 0), 2) : 0.0;
      if ($adjustmentAmount <= 0) {
        $hasAdjustment = false;
        $adjustmentAmount = 0.0;
      }
      $adjustmentType = in_array(($_POST['adjustment_type'] ?? ''), ['earning','deduction'], true) ? $_POST['adjustment_type'] : 'earning';
      $adjustmentLabel = trim((string)($_POST['adjustment_label'] ?? ''));
      $adjustmentCode = trim((string)($_POST['adjustment_code'] ?? 'adj'));
      $adjustmentCategory = trim((string)($_POST['adjustment_category'] ?? ''));
      $adjustmentCustomCategory = trim((string)($_POST['adjustment_custom_category'] ?? ''));
      $adjustmentNotes = trim((string)($_POST['adjustment_notes'] ?? ''));

      if ($adjustmentCategory === 'Other' && $adjustmentCustomCategory !== '') {
        $adjustmentCategory = $adjustmentCustomCategory;
      }

      $combinedAdjustmentNotes = '';
      if ($adjustmentCategory !== '') {
        $combinedAdjustmentNotes = 'Category: ' . $adjustmentCategory;
        if ($adjustmentNotes !== '') {
          $combinedAdjustmentNotes .= "\n\n" . $adjustmentNotes;
        }
      } else {
        $combinedAdjustmentNotes = $adjustmentNotes;
      }

      $result = ['ok' => false, 'status' => $newStatus];
      switch ($newStatus) {
        case 'in_review':
          $result = payroll_mark_complaint_in_review($pdo, $complaintId, $actingUserId, $notes);
          break;
        case 'resolved':
        case 'rejected':
          $resolutionData = [
            'status' => $newStatus,
            'notes' => $notes,
            'payroll_run_id' => $runId,
            'adjustment_amount' => ($newStatus === 'resolved' && $hasAdjustment) ? $adjustmentAmount : 0,
            'adjustment_type' => $adjustmentType,
            'adjustment_label' => $adjustmentLabel !== '' ? $adjustmentLabel : null,
            'adjustment_code' => $adjustmentCode !== '' ? $adjustmentCode : null,
            'adjustment_notes' => $combinedAdjustmentNotes !== '' ? $combinedAdjustmentNotes : null,
          ];
          $result = payroll_resolve_complaint($pdo, $complaintId, $resolutionData, $actingUserId);
          break;
        case 'confirmed':
          $result = payroll_confirm_complaint($pdo, $complaintId, $actingUserId, $notes);
          break;
        default:
          $result = [
            'ok' => payroll_update_complaint_status($pdo, $complaintId, $newStatus, $notes, ['acting_user_id' => $actingUserId]),
            'status' => $newStatus,
          ];
          break;
      }

      if (empty($result['ok'])) {
        $errorMsg = $result['error'] ?? 'Unable to update complaint status.';
        sys_log('PAYROLL-COMPLAINT-UPDATE-FAIL', 'Complaint status update failed', [
          'module' => 'payroll',
          'context' => [
            'complaint_id' => $complaintId,
            'requested_status' => $newStatus,
            'error' => $errorMsg,
          ],
        ]);
        flash_error($errorMsg);
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }

      $finalStatus = strtolower((string)($result['status'] ?? $newStatus));
      $queuedAdjustment = ($finalStatus === 'resolved' && $hasAdjustment && $adjustmentAmount > 0);

      action_log('payroll', 'complaint_update', 'success', [
        'complaint_id' => $complaintId,
        'run_id' => $runId,
        'old_status' => $oldStatus,
        'new_status' => $finalStatus,
        'has_adjustment' => $queuedAdjustment,
        'resolution_notes' => $notes,
        'employee_id' => (int)$targetComplaint['employee_id'],
      ]);

      $statusLabel = $complaintStatusLabels[$finalStatus] ?? ucfirst($finalStatus);
      $successMessage = sprintf('Complaint status updated to "%s".', $statusLabel);
      if ($queuedAdjustment) {
        $successMessage .= sprintf(' A %s adjustment of ₱%s (%s) has been queued for the next payroll run.',
          $adjustmentType === 'earning' ? 'earning' : 'deduction',
          number_format($adjustmentAmount, 2),
          $adjustmentLabel !== '' ? $adjustmentLabel : 'Complaint Adjustment'
        );
      }

      flash_success($successMessage);
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId . '&_t=' . time());
      exit;

    case 'approve_adjustment':
      $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
      if ($adjustmentId <= 0) {
        flash_error('Invalid adjustment ID.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      
      // Check if user is an adjustment approver
      if (!payroll_is_adjustment_approver($pdo, $currentUserId)) {
        $authAdj = ensure_action_authorized('payroll', 'adjustment_approval', 'write');
        if (!$authAdj['ok']) {
          flash_error('Adjustment approval requires authorization.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        $currentUserId = (int)($authAdj['as_user'] ?? $currentUserId);
      }
      
      $result = payroll_approve_adjustment($pdo, $adjustmentId, $currentUserId);
      if (!$result['ok']) {
        flash_error('Failed to approve adjustment: ' . ($result['error'] ?? 'Unknown error'));
      } else {
        flash_success('Adjustment approved successfully. It will be applied in the next payroll run.');
      }
      
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    case 'reject_adjustment':
      $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
      $rejectionReason = trim($_POST['rejection_reason'] ?? '');
      
      if ($adjustmentId <= 0) {
        flash_error('Invalid adjustment ID.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      
      if (empty($rejectionReason)) {
        flash_error('Rejection reason is required.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      
      // Check if user is an adjustment approver
      if (!payroll_is_adjustment_approver($pdo, $currentUserId)) {
        $authAdj = ensure_action_authorized('payroll', 'adjustment_approval', 'write');
        if (!$authAdj['ok']) {
          flash_error('Adjustment rejection requires authorization.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        $currentUserId = (int)($authAdj['as_user'] ?? $currentUserId);
      }
      
      $result = payroll_reject_adjustment($pdo, $adjustmentId, $currentUserId, $rejectionReason);
      if (!$result['ok']) {
        flash_error('Failed to reject adjustment: ' . ($result['error'] ?? 'Unknown error'));
      } else {
        flash_success('Adjustment rejected successfully.');
      }
      
      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;

    default:
      $submissionId = (int)($_POST['submission_id'] ?? 0);
      if (!$submissionId || !isset($subMap[$submissionId])) {
        flash_error('Submission record not found.');
        header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
        exit;
      }
      $status = $_POST['status'] ?? 'pending';
      if (!isset($branchStatuses[$status])) {
        $status = 'pending';
      }
      $remarks = trim($_POST['remarks'] ?? '');

      $targetDir = $uploadBase . '/' . $runId . '/' . $submissionId;
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
      }

      $paths = [
        'biometric_path' => $subMap[$submissionId]['biometric_path'] ?? null,
        'logbook_path' => $subMap[$submissionId]['logbook_path'] ?? null,
        'supporting_docs_path' => $subMap[$submissionId]['supporting_docs_path'] ?? null,
      ];

      $fileMap = [
        'biometric' => 'biometric_path',
        'logbook' => 'logbook_path',
        'supporting_docs' => 'supporting_docs_path',
      ];
      foreach ($fileMap as $field => $column) {
        if (empty($_FILES[$field]['name'])) {
          continue;
        }
        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK) {
          flash_error('Failed uploading ' . $field . '.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        // Validate file extension (whitelist)
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'csv', 'xlsx', 'xls', 'doc', 'docx', 'txt', 'zip'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
          flash_error('File type not allowed for ' . $field . '. Allowed: ' . implode(', ', $allowedExts));
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
          flash_error('File too large for ' . $field . '. Maximum 10MB allowed.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }

        // M-05 fix matching: Actual content type validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
      
        $validMime = false;
        if ($ext === 'pdf' && $mimeType === 'application/pdf') { $validMime = true; }
        elseif (in_array($ext, ['jpg', 'jpeg'], true) && $mimeType === 'image/jpeg') { $validMime = true; }
        elseif ($ext === 'png' && $mimeType === 'image/png') { $validMime = true; }
        elseif ($ext === 'gif' && $mimeType === 'image/gif') { $validMime = true; }
        elseif (in_array($ext, ['doc', 'docx'], true) && in_array($mimeType, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) { $validMime = true; }
        elseif (in_array($ext, ['xls', 'xlsx'], true) && in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) { $validMime = true; }
        elseif ($ext === 'csv' && in_array($mimeType, ['text/csv', 'text/plain'], true)) { $validMime = true; }
        elseif ($ext === 'txt' && $mimeType === 'text/plain') { $validMime = true; }
        elseif ($ext === 'zip' && in_array($mimeType, ['application/zip', 'application/x-zip-compressed'], true)) { $validMime = true; }

        if (!$validMime) {
            flash_error('File ' . $field . ' contains invalid content for its extension.');
            header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
            exit;
        }

        $safeName = sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME));
        $finalName = $safeName . '_' . time() . '.' . $ext;
        $dest = $targetDir . '/' . $finalName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
          flash_error('Could not save uploaded file for ' . $field . '.');
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        $repoRoot = realpath(__DIR__ . '/../../');
        $normalizedDest = str_replace('\\', '/', realpath($dest));
        $relative = $repoRoot ? str_replace(str_replace('\\', '/', $repoRoot), '', $normalizedDest) : '';
        if (!$relative || $relative === $normalizedDest) {
          $relative = '/assets/uploads/payroll_submissions/' . $runId . '/' . $submissionId . '/' . $finalName;
        }
        $paths[$column] = $relative;
      }

      try {
        $sql = "UPDATE payroll_branch_submissions
            SET status = :status,
              submitted_at = CASE WHEN :status IN ('submitted','accepted') THEN COALESCE(submitted_at, NOW()) ELSE submitted_at END,
              biometric_path = :bio,
              logbook_path = :log,
              supporting_docs_path = :sup,
              remarks = :remarks,
              updated_at = NOW()
            WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':status' => $status,
          ':bio' => $paths['biometric_path'],
          ':log' => $paths['logbook_path'],
          ':sup' => $paths['supporting_docs_path'],
          ':remarks' => $remarks ?: null,
          ':id' => $submissionId,
        ]);
        action_log('payroll', 'submission_update', 'success', [
          'run_id' => $runId,
          'submission_id' => $submissionId,
          'status' => $status,
        ]);
        flash_success('Submission updated.');
      } catch (Throwable $e) {
        sys_log('PAYROLL-SUBMISSION-UPDATE', 'Failed updating submission: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['submission_id' => $submissionId]]);
        flash_error('Unable to update submission.');
      }

      header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
      exit;
  }
}

require_once __DIR__ . '/../../includes/header.php';

$submittedCount = 0;
$blockingBranchNames = [];
foreach ($submissions as $submission) {
  $statusKey = strtolower(trim((string)($submission['status'] ?? '')));
  if (in_array($statusKey, ['submitted', 'accepted'], true)) {
    $submittedCount++;
  } else {
    $blockingBranchNames[] = $submission['name'] ?: ('Branch #' . $submission['branch_id']);
  }
}
$submissionTotal = count($submissions);
$submissionProgress = $submissionTotal > 0 ? $submittedCount . '/' . $submissionTotal : '0/0';
$incompleteBranchCount = count($blockingBranchNames);

$approvalStatusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'skipped' => 0];
$pendingStepOrders = [];
$latestApproval = null;
foreach ($approvals as $appr) {
  $statusKey = strtolower((string)$appr['status']);
  if (isset($approvalStatusCounts[$statusKey])) {
    $approvalStatusCounts[$statusKey]++;
  }
  if ($statusKey === 'pending') {
    $pendingStepOrders[] = (int)$appr['step_order'];
  }
  if ($statusKey === 'approved') {
    if (!$latestApproval || (int)$appr['step_order'] >= (int)$latestApproval['step_order']) {
      $latestApproval = $appr;
    }
  }
  if ($statusKey === 'rejected') {
    $latestApproval = $appr;
  }
}
$lowestPendingStep = $pendingStepOrders ? min($pendingStepOrders) : null;
$activeStepApprovers = [];
if ($lowestPendingStep !== null) {
  foreach ($approvals as $appr) {
    if ($appr['status'] === 'pending' && (int)$appr['step_order'] === $lowestPendingStep) {
      $activeStepApprovers[] = $appr['approver_name'] ?: 'Approver Step ' . $appr['step_order'];
    }
  }
}
$certifiedBy = ($latestApproval && strtolower($latestApproval['status']) === 'approved')
  ? ($latestApproval['approver_name'] ?: 'Step ' . $latestApproval['step_order'])
  : '-';

// BRUTE FORCE: Recalculate complaint counts from fresh database query
$complaintStatusCounts = ['pending' => 0, 'in_review' => 0, 'resolved' => 0, 'confirmed' => 0, 'rejected' => 0];
$freshCountStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM payroll_complaints WHERE payroll_run_id = :run GROUP BY status");
$freshCountStmt->execute([':run' => $runId]);
while ($countRow = $freshCountStmt->fetch(PDO::FETCH_ASSOC)) {
  $statusKey = strtolower((string)$countRow['status']);
  if (isset($complaintStatusCounts[$statusKey])) {
    $complaintStatusCounts[$statusKey] = (int)$countRow['cnt'];
  }
}

$openComplaints = $complaintStatusCounts['pending'] + $complaintStatusCounts['in_review'];

$releaseBlockers = [];
if ($run['status'] !== 'released') {
  // Allow release if status is approved OR if all approval steps are completed
  $allApprovalsComplete = ($approvalStatusCounts['pending'] === 0 && $approvalStatusCounts['rejected'] === 0 && count($approvals) > 0);
  
  if ($run['status'] !== 'approved' && !$allApprovalsComplete) {
    $releaseBlockers[] = 'Run status must be Approved before release.';
  }
  if ($approvalStatusCounts['pending'] > 0) {
    $releaseBlockers[] = $approvalStatusCounts['pending'] . ' approval step(s) remain pending.';
  }
  if ($approvalStatusCounts['rejected'] > 0) {
    $releaseBlockers[] = 'Resolve the rejected approval decision(s) before releasing.';
  }
  if ($openComplaints > 0) {
    $releaseBlockers[] = $openComplaints . ' complaint(s) are still open.';
  }
  if ($submissionTotal === 0) {
    $releaseBlockers[] = 'Branch submissions are not initialized for this run yet.';
  } elseif ($incompleteBranchCount > 0) {
    $branchSamples = array_slice($blockingBranchNames, 0, 3);
    $extraBranches = $incompleteBranchCount - count($branchSamples);
    $branchSummary = $branchSamples ? implode(', ', $branchSamples) : $incompleteBranchCount . ' branch(es)';
    if ($extraBranches > 0) {
      $branchSummary .= ' +' . $extraBranches . ' more';
    }
    $releaseBlockers[] = 'Pending branch submissions: ' . $branchSummary . '.';
  }
}
$canRelease = $run['status'] !== 'released' && empty($releaseBlockers);
$releasedAtDisplay = format_datetime_display($run['released_at'] ?? null, false, '');
$releaseSummaryText = '';
$releaseSummaryClass = 'text-gray-600';
if ($run['status'] === 'released') {
  $releaseSummaryText = ($releasedAtDisplay ? 'Released ' . $releasedAtDisplay : 'Released') . ($run['released_by_name'] ? ' by ' . $run['released_by_name'] : '');
  $releaseSummaryClass = 'text-green-600';
} elseif ($canRelease) {
  $releaseSummaryText = 'Ready for release';
  $releaseSummaryClass = 'text-green-600';
} else {
  $blockerCount = count($releaseBlockers);
  $releaseSummaryText = $blockerCount > 0 ? 'Blocked (' . $blockerCount . ')' : 'Blocked';
  $releaseSummaryClass = $blockerCount > 0 ? 'text-orange-600' : 'text-gray-600';
}

$complianceBadges = [];
if ($submissionTotal > 0 && $submittedCount >= $submissionTotal) {
  $complianceBadges[] = 'Branches Complete';
}
if ($approvalStatusCounts['rejected'] > 0) {
  $complianceBadges[] = 'Approval Blocked';
}
if ($approvalStatusCounts['pending'] > 0 && $approvalStatusCounts['rejected'] === 0) {
  $complianceBadges[] = 'Awaiting Approval';
}
if ($approvalStatusCounts['pending'] === 0 && $approvalStatusCounts['approved'] > 0 && $approvalStatusCounts['rejected'] === 0) {
  $complianceBadges[] = 'Certified';
}
if ($openComplaints > 0) {
  $complianceBadges[] = 'Complaints Open';
}
if ($run['status'] === 'released') {
  $complianceBadges[] = 'Released';
} elseif ($canRelease) {
  $complianceBadges[] = 'Ready for Release';
}
if (!$complianceBadges) {
  $complianceBadges[] = 'On Track';
}

$runStatusKey = strtolower((string)$run['status']);
$releaseActionTooltip = '';
if (!$canRelease && $releaseBlockers) {
  $releaseActionTooltip = implode(' • ', array_slice($releaseBlockers, 0, 3));
}
$releaseButtonLabel = $run['status'] === 'released' ? 'Payroll Released' : 'Release Payroll to Employees';
$releaseButtonTitle = $releaseActionTooltip ?: ($canRelease ? 'All release requirements satisfied.' : 'Complete the listed requirements to enable release.');
if ($run['status'] === 'released') {
  $releaseButtonTitle = 'Payroll has already been released.';
}

$pendingApproverSummary = $activeStepApprovers ? implode(', ', $activeStepApprovers) : 'None';
$complaintSummary = $openComplaints > 0
  ? $openComplaints . ' open / ' . $complaintStatusCounts['resolved'] . ' resolved'
  : 'No open complaints';

$csrf = csrf_token();
?>
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
  <div class="flex items-center gap-3">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/payroll/index">Back</a>
    <div>
      <h1 class="text-2xl font-semibold">Payroll Run</h1>
      <p class="text-sm text-gray-600">
        Period <?= htmlspecialchars(date('M d, Y', strtotime($run['period_start'])) . ' - ' . date('M d, Y', strtotime($run['period_end']))) ?>
        | Status: <span class="font-semibold text-gray-800 uppercase"><?= htmlspecialchars($run['status']) ?></span>
      </p>
    </div>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <div class="flex flex-col gap-2">
      <?php if ($run['status'] === 'released'): ?>
        <span class="btn flex items-center gap-2 bg-green-100 text-green-700 border border-green-200 cursor-not-allowed" title="<?= htmlspecialchars($releaseButtonTitle) ?>">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <span><?= htmlspecialchars($releaseButtonLabel) ?></span>
        </span>
      <?php elseif ($canRelease): ?>
        <form method="post"
              data-authz-module="payroll"
              data-authz-required="admin"
              data-authz-force
              data-authz-action="Release payroll run"
              data-confirm="Release this payroll run now?">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="release_run" />
          <button type="submit" class="btn flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white border border-green-600 shadow-sm" title="<?= htmlspecialchars($releaseButtonTitle) ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span><?= htmlspecialchars($releaseButtonLabel) ?></span>
          </button>
        </form>
      <?php else: ?>
        <button type="button"
                class="btn flex items-center gap-2 bg-gray-200 text-gray-500 border border-gray-300 cursor-not-allowed"
                disabled
                title="<?= htmlspecialchars($releaseButtonTitle) ?>">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          <span><?= htmlspecialchars($releaseButtonLabel) ?></span>
        </button>
        <?php if ($releaseBlockers): ?>
          <button type="button"
                  class="btn btn-outline text-xs flex items-center justify-center gap-1"
                  onclick="document.getElementById('releaseControls')?.scrollIntoView({behavior: 'smooth', block: 'center'});">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            View Requirements
          </button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <form method="post"
          data-authz-module="payroll"
          data-authz-required="admin"
          data-authz-force
          data-authz-action="Delete payroll run"
          data-confirm="Delete this payroll run and all associated payslips?">
      <input type="hidden" name="csrf" value="<?= $csrf ?>" />
      <input type="hidden" name="action" value="delete_run" />
      <button type="submit" class="btn btn-danger">Delete Run</button>
    </form>
  </div>
</div>

<div class="card p-4 mb-4 bg-gradient-to-br from-gray-50 to-white border-l-4 border-blue-500">
  <h2 class="font-semibold text-lg mb-3 flex items-center gap-2">
    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
    </svg>
    Run Summary
  </h2>
  <div class="grid gap-4 md:grid-cols-5 text-sm text-gray-700">
    <div class="bg-white p-3 rounded shadow-sm border border-gray-100">
      <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Certified By</p>
      <p class="text-base font-semibold text-gray-800"><?= htmlspecialchars($certifiedBy) ?></p>
      <?php if ($certifiedBy !== '-'): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full">
          Certified
        </span>
      <?php endif; ?>
    </div>
    <div class="bg-white p-3 rounded shadow-sm border border-gray-100">
      <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Approvals</p>
      <p class="text-base font-semibold text-gray-800">
        <span class="text-green-600"><?= (int)$approvalStatusCounts['approved'] ?></span> approved • 
        <span class="<?= $approvalStatusCounts['pending'] > 0 ? 'text-orange-600' : 'text-gray-600' ?>"><?= (int)$approvalStatusCounts['pending'] ?></span> pending
      </p>
      <?php if ($approvalStatusCounts['pending'] > 0): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-orange-100 text-orange-700 text-xs rounded-full">
          Action Needed
        </span>
      <?php endif; ?>
    </div>
    <div class="bg-white p-3 rounded shadow-sm border border-gray-100">
      <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Pending Approvers</p>
      <p class="text-base font-semibold text-gray-800 truncate" title="<?= htmlspecialchars($pendingApproverSummary) ?>"><?= htmlspecialchars($pendingApproverSummary) ?></p>
    </div>
    <div class="bg-white p-3 rounded shadow-sm border border-gray-100">
      <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Complaints</p>
      <p class="text-base font-semibold text-gray-800"><?= htmlspecialchars($complaintSummary) ?></p>
      <?php if ($openComplaints > 0): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-red-100 text-red-700 text-xs rounded-full">
          <?= $openComplaints ?> Open
        </span>
      <?php else: ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full">
          All Clear
        </span>
      <?php endif; ?>
    </div>
    <div class="bg-white p-3 rounded shadow-sm border border-gray-100">
      <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Release</p>
      <p class="text-base font-semibold <?= htmlspecialchars($releaseSummaryClass) ?>">
        <?= htmlspecialchars($releaseSummaryText ?: 'Not ready') ?>
      </p>
      <?php if ($run['status'] === 'released'): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full">
          Complete
        </span>
      <?php elseif ($canRelease): ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full">
          Ready
        </span>
      <?php else: ?>
        <span class="inline-block mt-1 px-2 py-0.5 bg-orange-100 text-orange-700 text-xs rounded-full">
          Blocked
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="mt-4 pt-3 border-t border-gray-200 flex flex-wrap gap-2">
    <?php foreach ($complianceBadges as $badge): ?>
      <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 border border-blue-200"><?= htmlspecialchars($badge) ?></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="card p-4 mb-4 bg-gradient-to-br from-blue-50 to-white border-l-4 border-green-500">
  <div class="flex items-center justify-between mb-4">
    <h2 class="font-semibold text-lg flex items-center gap-2">
      <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
      Payroll Numbers
    </h2>
    <div class="flex items-center gap-3 flex-wrap justify-end">
      <span class="text-xs text-gray-500 bg-white px-2 py-1 rounded border border-gray-200">Period <?= htmlspecialchars($runYearLabel) ?></span>
      <?php if ($payslips): ?>
        <!-- Payslips already generated - show View and Regenerate -->
        <button type="button" class="btn btn-primary text-sm" data-open-payslip-modal>View Payroll Summary</button>
        <form method="post" class="inline-block" data-authz-module="payroll" data-authz-required="write" data-authz-action="Regenerate payroll summary for run">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="generate_payslips" />
          <button type="submit" class="btn btn-outline text-sm">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Regenerate
          </button>
        </form>
      <?php elseif ($incompleteBranchCount > 0): ?>
        <!-- Branch submissions incomplete - disable generation -->
        <button type="button" class="btn btn-secondary text-sm opacity-50 cursor-not-allowed" disabled title="All branch submissions must be submitted before generating payroll">
          <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Generate Payroll Summary
        </button>
      <?php else: ?>
        <!-- Ready to generate for first time -->
        <form method="post" class="inline-block" data-authz-module="payroll" data-authz-required="write" data-authz-action="Generate payroll summary for run">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="generate_payslips" />
          <button type="submit" class="btn btn-secondary text-sm">Generate Payroll Summary</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="grid gap-4 md:grid-cols-4 text-sm text-gray-700 mb-4">
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-2">
        <p class="text-xs uppercase tracking-wide text-gray-500">Payslips</p>
        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
        </svg>
      </div>
      <p class="text-2xl font-bold text-gray-800"><?= $hasPayslipSummary ? (int)$payslipTotals['count'] : 'N/A' ?></p>
    </div>
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-2">
        <p class="text-xs uppercase tracking-wide text-gray-500">Total Earnings</p>
        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
        </svg>
      </div>
      <p class="text-2xl font-bold text-green-600">
        <?php if ($hasPayslipSummary): ?>
          ₱<?= number_format((float)$payslipTotals['earnings'], 2) ?>
        <?php else: ?>
          N/A
        <?php endif; ?>
      </p>
    </div>
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-2">
        <p class="text-xs uppercase tracking-wide text-gray-500">Total Deductions</p>
        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
        </svg>
      </div>
      <p class="text-2xl font-bold text-red-600">
        <?php if ($hasPayslipSummary): ?>
          ₱<?= number_format((float)$payslipTotals['deductions'], 2) ?>
        <?php else: ?>
          N/A
        <?php endif; ?>
      </p>
    </div>
    <div class="bg-gradient-to-br from-emerald-500 to-green-600 p-4 rounded-lg shadow-md border border-emerald-600 text-white">
      <div class="flex items-center justify-between mb-2">
        <p class="text-xs uppercase tracking-wide opacity-90">Net Payroll</p>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
      </div>
      <p class="text-2xl font-bold">
        <?php if ($hasPayslipSummary): ?>
          ₱<?= number_format((float)$payslipTotals['net'], 2) ?>
        <?php else: ?>
          N/A
        <?php endif; ?>
      </p>
    </div>
  </div>
  <?php if ($payslips): ?>
    <div class="mt-4 grid gap-4 md:grid-cols-2 text-sm text-gray-700">
      <div>
        <p class="text-xs uppercase tracking-wide text-gray-500 mb-2">Earnings Breakdown</p>
        <ul class="space-y-1 text-[13px]">
          <?php
            $earningLines = array_slice($runBreakdown['earning'], 0, 8);
            foreach ($earningLines as $line):
              $percent = $payslipTotals['earnings'] > 0 ? ($line['amount'] / $payslipTotals['earnings']) * 100 : 0;
          ?>
            <li class="flex items-center justify-between gap-3">
              <span class="truncate" title="<?= htmlspecialchars($line['label']) ?>"><?= htmlspecialchars($line['label']) ?></span>
              <span class="text-right font-medium">
                <?= number_format((float)$line['amount'], 2) ?>
                <?php if ($percent > 0): ?>
                  <span class="text-[11px] text-gray-500">(<?= number_format($percent, 1) ?>%)</span>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
          <?php if (!$earningLines): ?>
            <li class="text-gray-400">No earnings components captured.</li>
          <?php elseif (count($runBreakdown['earning']) > count($earningLines)): ?>
            <li class="text-gray-500">+<?= count($runBreakdown['earning']) - count($earningLines) ?> more line item(s)</li>
          <?php endif; ?>
        </ul>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wide text-gray-500 mb-2">Deductions Breakdown</p>
        <ul class="space-y-1 text-[13px]">
          <?php
            $deductionLines = array_slice($runBreakdown['deduction'], 0, 8);
            foreach ($deductionLines as $line):
              $percent = $payslipTotals['deductions'] > 0 ? ($line['amount'] / $payslipTotals['deductions']) * 100 : 0;
          ?>
            <li class="flex items-center justify-between gap-3">
              <span class="truncate" title="<?= htmlspecialchars($line['label']) ?>"><?= htmlspecialchars($line['label']) ?></span>
              <span class="text-right font-medium">
                <?= number_format((float)$line['amount'], 2) ?>
                <?php if ($percent > 0): ?>
                  <span class="text-[11px] text-gray-500">(<?= number_format($percent, 1) ?>%)</span>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
          <?php if (!$deductionLines): ?>
            <li class="text-gray-400">No deduction components captured.</li>
          <?php elseif (count($runBreakdown['deduction']) > count($deductionLines)): ?>
            <li class="text-gray-500">+<?= count($runBreakdown['deduction']) - count($deductionLines) ?> more line item(s)</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
  <p class="text-xs text-gray-500 mt-3">Adjustments made will be included in the next payroll.</p>
  <?php if (!$payslips): ?>
    <p class="text-sm text-gray-600 mt-2">No payroll summaries are attached to this run yet. Generate the payroll summary to populate the approval package.</p>
  <?php endif; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 mb-6">
  <div class="space-y-4 lg:col-span-2">
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2">
        <h2 class="font-semibold">Branch Submissions</h2>
      </div>
      <?php if ($batches): ?>
        <div class="overflow-x-auto">
          <table class="table-basic min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="p-2 text-left">Branch</th>
                <th class="p-2 text-left">Status</th>
                <th class="p-2 text-left">Approvals</th>
                <th class="p-2 text-left">Submitted By</th>
                <th class="p-2 text-left">Job</th>
                <th class="p-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $batchStatusClasses = [
                  'submitted' => 'bg-blue-100 text-blue-700',
                  'accepted' => 'bg-emerald-100 text-emerald-700',
                  'approved' => 'bg-emerald-100 text-emerald-700',
                  'rejected' => 'bg-rose-100 text-rose-700',
                  'computing' => 'bg-indigo-100 text-indigo-700',
                  'error' => 'bg-rose-100 text-rose-700',
                  'pending' => 'bg-amber-100 text-amber-700',
                  'missing' => 'bg-gray-200 text-gray-700',
                ];
              ?>
              <?php foreach ($batches as $batch): ?>
                <?php
                  $statusKey = strtolower(trim((string)($batch['status'] ?? 'pending')));
                  $statusLabel = $branchStatuses[$statusKey] ?? (ucfirst($statusKey) ?: 'Pending');
                  $statusBadgeClass = $batchStatusClasses[$statusKey] ?? 'bg-gray-100 text-gray-600';
                  $isDtrSubmitted = in_array($statusKey, ['submitted', 'accepted'], true);
                ?>
                <tr class="border-t align-top">
                  <td class="p-2 font-medium"><?= htmlspecialchars($batch['branch_name'] ?: ('Branch #' . (int)$batch['branch_id'])) ?></td>
                  <td class="p-2 text-sm">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide <?= $statusBadgeClass ?>">
                      <?= htmlspecialchars($statusLabel) ?>
                    </span>
                  </td>
                  <td class="p-2 text-xs text-gray-700 min-w-[280px]">
                    <?php
                      $chain = json_decode($batch['approvers_chain'] ?? '[]', true) ?: [];
                      $state = payroll_get_batch_approval_state($pdo, (int)$batch['id']);
                      $curStep = $state['current_step'];
                      $hasRejected = !empty($state['has_rejected']);
                    ?>
                    <?php if ($chain && !empty($state['steps'])): ?>
                      <details class="group">
                        <summary class="cursor-pointer text-blue-600 select-none">View chain (<?= count($state['steps']) ?> steps)</summary>
                        <div class="mt-2 space-y-2">
                          <?php foreach ($state['steps'] as $step): ?>
                            <?php
                              $isActive = ($step['status'] === 'pending' && $curStep !== null && (int)$step['step_order'] === (int)$curStep);
                              $isSelf = $currentUserId > 0 && (int)($step['user_id'] ?? 0) === $currentUserId;
                              $badgeClass = 'bg-gray-100 text-gray-700';
                              if ($step['status'] === 'approved') { $badgeClass = 'bg-emerald-100 text-emerald-700'; }
                              if ($step['status'] === 'rejected') { $badgeClass = 'bg-rose-100 text-rose-700'; }
                            ?>
                            <div class="border rounded p-2">
                              <div class="flex items-center justify-between">
                                <div>
                                  <div class="font-medium text-gray-800">Step <?= (int)$step['step_order'] ?> • <?= htmlspecialchars($step['user_name'] ?: strtoupper((string)$step['role'])) ?></div>
                                  <div class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide <?= $badgeClass ?>"><?= htmlspecialchars($step['status']) ?></div>
                                  <?php if (!empty($step['acted_at'])): ?>
                                    <div class="text-[11px] text-gray-500 mt-1">Acted <?= htmlspecialchars($step['acted_at']) ?></div>
                                  <?php endif; ?>
                                  <?php if (!empty($step['remarks'])): ?>
                                    <div class="text-[12px] text-gray-600 mt-1">Notes: <?= htmlspecialchars($step['remarks']) ?></div>
                                  <?php endif; ?>
                                </div>
                              </div>
                              <?php if ($isActive && !$hasRejected): ?>
                                <form method="post" class="mt-2 grid grid-cols-1 gap-2 text-xs"<?php if (!$isSelf || !empty($step['requires_override'])): ?> data-authz-module="payroll" data-authz-required="admin" data-authz-force data-authz-action="Submit batch approval for step <?= (int)$step['step_order'] ?>"<?php else: ?> data-authz-module="payroll" data-authz-required="write" data-authz-action="Submit batch approval for step <?= (int)$step['step_order'] ?>"<?php endif; ?>>
                                  <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                                  <input type="hidden" name="action" value="batch_approval_decision" />
                                  <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>" />
                                  <input type="hidden" name="step_order" value="<?= (int)$step['step_order'] ?>" />
                                  <label class="block">
                                    <span class="text-gray-600">Remarks</span>
                                    <textarea name="remarks" rows="2" class="input-text w-full" placeholder="Optional notes"></textarea>
                                  </label>
                                  <div class="grid grid-cols-2 gap-2">
                                    <button type="submit" name="decision" value="approved" class="btn btn-primary">Approve</button>
                                    <button type="submit" name="decision" value="rejected" class="btn btn-danger">Reject</button>
                                  </div>
                                </form>
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    <?php elseif ($chain): ?>
                      <span class="text-gray-400 text-xs">Chain configured but no steps resolved</span>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="p-2 text-xs text-gray-600">
                    <?php if (!empty($batch['submitted_by_name'])): ?>
                      <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($batch['submitted_by_name']) ?></div>
                    <?php elseif (!empty($batch['submitted_by'])): ?>
                      <div class="text-sm text-gray-700">User #<?= (int)$batch['submitted_by'] ?></div>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                    <?php if (!empty($batch['submitted_at'])): ?>
                      <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(format_datetime_display($batch['submitted_at'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="p-2 text-xs text-gray-600">
                    <?php if (!empty($batch['computation_job_id'])): ?>
                      <div>
                        <div class="text-gray-800 font-mono text-xs">#<?= htmlspecialchars($batch['computation_job_id']) ?></div>
                        <div class="text-xs job-status" data-job-id="<?= htmlspecialchars($batch['computation_job_id']) ?>">Queued…</div>
                      </div>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="p-2 w-48">
                    <?php if ($isDtrSubmitted): ?>
                      <button type="button" class="btn btn-secondary w-full opacity-60 cursor-not-allowed" disabled title="DTR already submitted for this branch">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Submitted
                      </button>
                    <?php else: ?>
                      <button type="button" onclick="openSubmitDTRModal(<?= (int)$batch['id'] ?>, '<?= htmlspecialchars($batch['branch_name'], ENT_QUOTES) ?>')" class="btn btn-primary w-full">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Submit DTR
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-600">No branch batches are linked to this run yet.</p>
      <?php endif; ?>
    </div>

    <div class="card p-4">
      <h2 class="font-semibold mb-3">Approval Workflow</h2>
      <?php if ($incompleteBranchCount > 0): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-3">
          <div class="flex items-start gap-2">
            <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div class="text-sm">
              <p class="font-semibold text-amber-800">Approval workflow is disabled</p>
              <p class="text-amber-700 mt-1">All branch submissions must be submitted successfully before the approval workflow can begin. <?= $incompleteBranchCount ?> branch submission(s) are still pending.</p>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <p class="text-xs text-gray-500 mb-2">Approvals must be completed in sequence. Only the current step can submit a decision.</p>
      <div class="overflow-x-auto">
        <table class="table-basic min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="p-2 text-left">Step</th>
              <th class="p-2 text-left">Approver</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Remarks</th>
              <th class="p-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($approvals as $approval): ?>
              <?php
                $isActiveStep = $approval['status'] === 'pending' && $lowestPendingStep !== null && (int)$approval['step_order'] === $lowestPendingStep;
                $isSelf = $currentUserId > 0 && (int)$approval['user_id'] === $currentUserId;
              ?>
              <tr class="border-t align-top">
                <td class="p-2 font-medium">Step <?= (int)$approval['step_order'] ?></td>
                <td class="p-2">
                  <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($approval['approver_name'] ?: 'Approver #' . $approval['approver_id']) ?></div>
                  <?php if (!empty($approval['approver_email'])): ?>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($approval['approver_email']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-sm">
                  <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-gray-600">
                    <?= htmlspecialchars($approval['status']) ?>
                  </span>
                  <?php if (!empty($approval['acted_at'])): ?>
                    <div class="text-xs text-gray-500 mt-1">Acted <?= htmlspecialchars($approval['acted_at']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-xs text-gray-600">
                  <?= $approval['remarks'] ? nl2br(htmlspecialchars($approval['remarks'])) : '—' ?>
                </td>
                <td class="p-2 w-72">
                  <?php if ($approval['status'] === 'pending'): ?>
                    <?php if ($incompleteBranchCount > 0): ?>
                      <p class="text-xs text-amber-600">Waiting for all branch submissions to be submitted.</p>
                    <?php elseif ($isActiveStep): ?>
                      <form method="post" class="space-y-2 text-xs"<?php if (!$isSelf): ?> data-authz-module="payroll" data-authz-required="admin" data-authz-force data-authz-action="Submit decision for step <?= (int)$approval['step_order'] ?>"<?php endif; ?>>
                        <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                        <input type="hidden" name="action" value="approval_decision" />
                        <input type="hidden" name="approval_id" value="<?= (int)$approval['id'] ?>" />
                        <label class="block">
                          <span class="text-gray-600">Remarks</span>
                          <textarea name="remarks" rows="2" class="input-text w-full" placeholder="Optional notes"></textarea>
                        </label>
                        <?php if (!$isSelf): ?>
                        <p class="text-xs text-amber-600">Submitting on behalf of another approver requires authorization credentials.</p>
                        <?php endif; ?>
                        <div class="grid grid-cols-2 gap-2">
                          <button type="submit" name="decision" value="approved" class="btn btn-primary" data-authz-action="Approve payroll step <?= (int)$approval['step_order'] ?>">Approve</button>
                          <button type="submit" name="decision" value="rejected" class="btn btn-danger" data-authz-action="Reject payroll step <?= (int)$approval['step_order'] ?>">Reject</button>
                        </div>
                      </form>
                    <?php else: ?>
                      <p class="text-xs text-gray-500">Waiting for Step <?= (int)$lowestPendingStep ?> to complete.</p>
                    <?php endif; ?>
                  <?php else: ?>
                    <p class="text-xs text-gray-500">No actions available.</p>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; if (!$approvals): ?>
              <tr><td class="p-3" colspan="5">No approval steps defined. Configure payroll approvers to enable routing.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pending Adjustments Section -->
    <?php if (payroll_table_exists($pdo, 'payroll_adjustment_queue')): ?>
    <div class="card p-4">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h2 class="font-semibold">Pending Payroll Adjustments</h2>
          <p class="text-xs text-gray-500 mt-1">Review and approve adjustment requests from complaint resolutions before they can be applied to payroll.</p>
        </div>
        <?php if ($isAdjustmentApprover): ?>
          <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            You are an approver
          </span>
        <?php endif; ?>
      </div>

      <?php if (empty($pendingAdjustments)): ?>
        <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-8 text-center">
          <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <p class="mt-2 text-sm text-slate-600">No pending adjustments for this payroll run.</p>
          <p class="mt-1 text-xs text-slate-500">Adjustments from complaint resolutions will appear here for approval.</p>
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($pendingAdjustments as $adj): ?>
            <?php
              $adjType = strtolower($adj['adjustment_type'] ?? 'earning');
              $adjAmount = (float)($adj['amount'] ?? 0);
              $adjLabel = htmlspecialchars($adj['label'] ?? 'Adjustment');
              $adjCode = htmlspecialchars($adj['code'] ?? 'ADJ');
              $empName = trim(($adj['first_name'] ?? '') . ' ' . ($adj['last_name'] ?? ''));
              $empCode = htmlspecialchars($adj['employee_code'] ?? 'N/A');
              $deptName = htmlspecialchars($adj['department_name'] ?? 'Unassigned');
              $complaintTopic = htmlspecialchars($adj['complaint_topic'] ?? 'N/A');
              $createdBy = htmlspecialchars($adj['created_by_username'] ?? 'System');
              $createdAt = !empty($adj['created_at']) ? format_datetime_display($adj['created_at'], true, 'N/A') : 'N/A';
              $notes = htmlspecialchars($adj['notes'] ?? '');
              
              $typeColor = $adjType === 'earning' ? 'emerald' : 'rose';
              $typeLabel = $adjType === 'earning' ? 'Earning' : 'Deduction';
              $typeIcon = $adjType === 'earning' ? 'M12 4v16m8-8H4' : 'M20 12H4';
            ?>
            
            <div class="rounded-lg border-2 border-<?= $typeColor ?>-200 bg-<?= $typeColor ?>-50 p-4">
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1 space-y-2">
                  <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-<?= $typeColor ?>-100 text-<?= $typeColor ?>-700">
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $typeIcon ?>"></path>
                      </svg>
                    </div>
                    <div class="flex-1">
                      <div class="flex items-center gap-2">
                        <h3 class="font-semibold text-slate-900"><?= $adjLabel ?></h3>
                        <span class="inline-flex items-center rounded-full bg-<?= $typeColor ?>-100 px-2 py-0.5 text-xs font-semibold text-<?= $typeColor ?>-700">
                          <?= $typeLabel ?>
                        </span>
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-mono text-slate-700">
                          <?= $adjCode ?>
                        </span>
                      </div>
                      <div class="mt-1 text-2xl font-bold text-<?= $typeColor ?>-700">
                        ₱<?= number_format($adjAmount, 2) ?>
                      </div>
                    </div>
                  </div>
                  
                  <div class="grid md:grid-cols-2 gap-3 text-sm">
                    <div>
                      <span class="text-xs font-medium text-slate-600">Employee:</span>
                      <div class="font-semibold text-slate-900"><?= htmlspecialchars($empName) ?> (<?= $empCode ?>)</div>
                      <div class="text-xs text-slate-600"><?= $deptName ?></div>
                    </div>
                    <div>
                      <span class="text-xs font-medium text-slate-600">From Complaint:</span>
                      <div class="font-medium text-slate-900"><?= $complaintTopic ?></div>
                      <div class="text-xs text-slate-600">ID: #<?= (int)($adj['complaint_id'] ?? 0) ?></div>
                    </div>
                  </div>
                  
                  <?php if ($notes): ?>
                    <div class="rounded-lg bg-white border border-<?= $typeColor ?>-200 p-3">
                      <div class="text-xs font-medium text-slate-600 mb-1">Notes:</div>
                      <div class="text-sm text-slate-700"><?= nl2br($notes) ?></div>
                    </div>
                  <?php endif; ?>
                  
                  <div class="flex items-center gap-4 text-xs text-slate-600">
                    <div>Created by: <span class="font-medium"><?= $createdBy ?></span></div>
                    <div><?= $createdAt ?></div>
                  </div>
                </div>
                
                <?php if ($isAdjustmentApprover || $isSystemAdmin): ?>
                  <div class="flex-shrink-0 space-y-2">
                    <form method="POST" class="inline-block">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="approve_adjustment">
                      <input type="hidden" name="adjustment_id" value="<?= (int)$adj['id'] ?>">
                      <button type="submit" class="btn-primary bg-emerald-600 hover:bg-emerald-700 text-sm">
                        <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Approve
                      </button>
                    </form>
                    
                    <button type="button" onclick="openRejectAdjustmentModal(<?= (int)$adj['id'] ?>, '<?= addslashes($adjLabel) ?>', '<?= addslashes($empName) ?>')" class="btn-danger text-sm w-full">
                      <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                      </svg>
                      Reject
                    </button>
                  </div>
                <?php else: ?>
                  <div class="text-xs text-slate-500 italic">
                    Awaiting approval
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card p-4">
      <h2 class="font-semibold mb-3">Complaints &amp; Escalations</h2>
      <p class="text-xs text-gray-500 mb-3">Review and address payroll complaints filed by employees for this run.</p>

      <?php if ($complaints): ?>
        <div class="flex flex-wrap gap-2 text-xs text-gray-600 mb-3">
          <?php foreach ($complaintStatusCounts as $statusKey => $statusCount): ?>
            <?php if ((int)$statusCount > 0): ?>
              <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                <?= htmlspecialchars(($complaintStatusLabels[$statusKey] ?? ucfirst($statusKey)) . ': ' . (int)$statusCount) ?>
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="overflow-x-auto">
        <table class="table-basic min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="p-2 text-left">Complaint</th>
              <th class="p-2 text-left">Priority</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Filed</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($complaints as $complaint): ?>
              <?php
                // BRUTE FORCE: Query fresh status from database for each complaint
                $freshStatusStmt = $pdo->prepare("SELECT status, resolution_notes, resolution_by, resolution_at FROM payroll_complaints WHERE id = :id");
                $freshStatusStmt->execute([':id' => (int)$complaint['id']]);
                $freshStatus = $freshStatusStmt->fetch(PDO::FETCH_ASSOC);
                if ($freshStatus) {
                  $complaint['status'] = $freshStatus['status'];
                  $complaint['resolution_notes'] = $freshStatus['resolution_notes'];
                  $complaint['resolution_by'] = $freshStatus['resolution_by'];
                  $complaint['resolution_at'] = $freshStatus['resolution_at'];
                }
                
                $categoryCode = strtolower((string)($complaint['category_code'] ?? ''));
                $subcategoryCode = strtolower((string)($complaint['subcategory_code'] ?? ''));
                $priorityCode = strtolower((string)($complaint['priority'] ?? 'normal')) ?: 'normal';
                $statusKey = strtolower((string)($complaint['status'] ?? 'pending')) ?: 'pending';
                $categoryLabel = isset($complaintCategories[$categoryCode]) ? ($complaintCategories[$categoryCode]['label'] ?? null) : null;
                $topicLabel = ($categoryLabel && isset($complaintCategories[$categoryCode]['items'][$subcategoryCode])) ? $complaintCategories[$categoryCode]['items'][$subcategoryCode] : null;
                $priorityLabel = $complaintPriorities[$priorityCode] ?? ucfirst($priorityCode);
                $statusLabel = $complaintStatusLabels[$statusKey] ?? ucfirst($statusKey);
                $employeeId = (int)($complaint['employee_id'] ?? 0);
                $employeeName = trim(($complaint['last_name'] ?? '') . ', ' . ($complaint['first_name'] ?? ''));
                if ($employeeName === ',' || $employeeName === '') {
                  $employeeName = $employeeId > 0 ? 'Employee #' . $employeeId : 'Employee';
                }
                $employeeLabel = '';
                if (($complaint['employee_code'] ?? '') !== '') {
                  $employeeLabel .= $complaint['employee_code'] . ' — ';
                }
                $employeeLabel .= $employeeName;
                $employeeLabel = trim($employeeLabel);
                $summaryTopic = $topicLabel ?: ($complaint['issue_type'] ?: 'General Issue');
                $submittedAtLabel = format_datetime_display($complaint['submitted_at'] ?? null, false, '—');
                $resolvedAtLabel = format_datetime_display($complaint['resolved_at'] ?? null, false, '—');
                $descSnippet = trim((string)($complaint['description'] ?? ''));
                if ($descSnippet !== '' && strlen($descSnippet) > 140) {
                  $descSnippet = substr($descSnippet, 0, 137) . '…';
                }
                $resolutionSnippet = trim((string)($complaint['resolution_notes'] ?? ''));
                if ($resolutionSnippet !== '' && strlen($resolutionSnippet) > 120) {
                  $resolutionSnippet = substr($resolutionSnippet, 0, 117) . '…';
                }
                $priorityBadgeClass = 'bg-blue-100 text-blue-700';
                if ($priorityCode === 'urgent') {
                  $priorityBadgeClass = 'bg-red-100 text-red-700';
                } elseif ($priorityCode !== 'normal') {
                  $priorityBadgeClass = 'bg-slate-100 text-slate-700';
                }
                $statusBadgeClass = 'bg-gray-100 text-gray-700';
                if ($statusKey === 'pending') {
                  $statusBadgeClass = 'bg-amber-100 text-amber-700';
                } elseif ($statusKey === 'in_review') {
                  $statusBadgeClass = 'bg-sky-100 text-sky-700';
                } elseif ($statusKey === 'resolved') {
                  $statusBadgeClass = 'bg-emerald-100 text-emerald-700';
                } elseif ($statusKey === 'rejected') {
                  $statusBadgeClass = 'bg-rose-100 text-rose-700';
                }
              ?>
              <tr class="border-t align-top">
                <td class="p-2">
                  <div class="font-semibold text-gray-800"><?= htmlspecialchars($employeeLabel) ?></div>
                  <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <?php if ($categoryLabel): ?>
                      <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700"><?= htmlspecialchars($categoryLabel) ?></span>
                    <?php endif; ?>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700"><?= htmlspecialchars($summaryTopic) ?></span>
                  </div>
                  <?php if ($descSnippet !== ''): ?>
                    <div class="mt-2 text-xs text-gray-600"><?= htmlspecialchars($descSnippet) ?></div>
                  <?php endif; ?>
                  <?php if ($resolutionSnippet !== ''): ?>
                    <div class="mt-2 text-xs text-gray-500">Resolution: <?= htmlspecialchars($resolutionSnippet) ?></div>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-sm">
                  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide <?= $priorityBadgeClass ?>"><?= htmlspecialchars($priorityLabel) ?></span>
                </td>
                <td class="p-2 text-sm">
                  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide <?= $statusBadgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                  <?php if ($resolvedAtLabel !== '—'): ?>
                    <div class="text-xs text-gray-500 mt-1">Resolved <?= htmlspecialchars($resolvedAtLabel) ?></div>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-sm text-gray-600"><?= htmlspecialchars($submittedAtLabel) ?></td>
                <td class="p-2 text-sm">
                  <div class="flex gap-2">
                    <button type="button"
                      class="btn btn-outline text-xs"
                      data-complaint-open
                      data-complaint-id="<?= (int)$complaint['id'] ?>"
                      data-complaint-employee="<?= htmlspecialchars($employeeLabel, ENT_QUOTES) ?>"
                      data-complaint-category="<?= htmlspecialchars($categoryLabel ?: '—', ENT_QUOTES) ?>"
                      data-complaint-topic="<?= htmlspecialchars($summaryTopic, ENT_QUOTES) ?>"
                      data-complaint-issue="<?= htmlspecialchars($complaint['issue_type'] ?: $summaryTopic, ENT_QUOTES) ?>"
                      data-complaint-priority="<?= htmlspecialchars($priorityLabel, ENT_QUOTES) ?>"
                      data-complaint-priority-code="<?= htmlspecialchars($priorityCode, ENT_QUOTES) ?>"
                      data-complaint-status="<?= htmlspecialchars($statusKey, ENT_QUOTES) ?>"
                      data-complaint-status-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>"
                      data-complaint-submitted="<?= htmlspecialchars($submittedAtLabel, ENT_QUOTES) ?>"
                      data-complaint-resolved="<?= htmlspecialchars($resolvedAtLabel, ENT_QUOTES) ?>"
                      data-complaint-description="<?= htmlspecialchars($complaint['description'] ?? '', ENT_QUOTES) ?>"
                      data-complaint-resolution="<?= htmlspecialchars($complaint['resolution_notes'] ?? '', ENT_QUOTES) ?>"
                    >View Details</button>
                    <button type="button"
                      class="btn btn-outline text-xs"
                      data-complaint-history
                      data-complaint-id="<?= (int)$complaint['id'] ?>"
                      data-complaint-employee="<?= htmlspecialchars($employeeLabel, ENT_QUOTES) ?>"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                      History
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; if (!$complaints): ?>
              <tr><td class="p-3" colspan="5">No complaints have been filed for this run.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card p-4">
      <h2 class="font-semibold mb-2">Run Details</h2>
      <dl class="text-sm text-gray-700 space-y-2">
        <div><dt class="font-medium inline">Created:</dt> <dd class="inline"><?= htmlspecialchars($run['created_at']) ?></dd></div>
        <div><dt class="font-medium inline">Generated By:</dt> <dd class="inline"><?= htmlspecialchars($run['generated_by_name'] ?: '-') ?></dd></div>
        <div><dt class="font-medium inline">Released At:</dt> <dd class="inline"><?= $run['released_at'] ? htmlspecialchars($run['released_at']) : '—' ?></dd></div>
        <div><dt class="font-medium inline">Released By:</dt> <dd class="inline"><?= $run['released_by_name'] ? htmlspecialchars($run['released_by_name']) : '—' ?></dd></div>
        <div><dt class="font-medium inline">Branch Progress:</dt> <dd class="inline"><?= htmlspecialchars($submissionProgress) ?></dd></div>
        <div><dt class="font-medium inline">Approvals Remaining:</dt> <dd class="inline"><?= (int)$approvalStatusCounts['pending'] ?></dd></div>
        <div><dt class="font-medium inline">Next Approver:</dt> <dd class="inline"><?= htmlspecialchars($pendingApproverSummary) ?></dd></div>
        <div><dt class="font-medium inline">Complaints Open:</dt> <dd class="inline"><?= (int)$openComplaints ?></dd></div>
        <div><dt class="font-medium inline">Notes:</dt> <dd class="inline"><?= $run['notes'] ? htmlspecialchars($run['notes']) : '—' ?></dd></div>
      </dl>
    </div>
    <div class="card p-4 border-2 <?= $run['status'] === 'released' ? 'border-green-500 bg-green-50' : ($canRelease ? 'border-blue-500 bg-blue-50' : 'border-orange-500 bg-orange-50') ?></div>" id="releaseControls">
      <div class="flex items-center gap-2 mb-3">
        <?php if ($run['status'] === 'released'): ?>
          <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
        <?php elseif ($canRelease): ?>
          <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
          </svg>
        <?php else: ?>
          <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
          </svg>
        <?php endif; ?>
        <h2 class="font-semibold text-lg">Release Controls</h2>
      </div>
      <?php if ($run['status'] === 'released'): ?>
        <div class="space-y-2">
          <div class="flex items-center gap-2 p-3 bg-white rounded border border-green-200">
            <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <div>
              <p class="text-sm font-semibold text-green-800">Payroll Released</p>
              <p class="text-xs text-gray-600"><?= $releasedAtDisplay ? htmlspecialchars($releasedAtDisplay) : 'Released' ?></p>
            </div>
          </div>
          <?php if ($run['released_by_name']): ?>
            <p class="text-xs text-gray-600 px-3">Authorized by <span class="font-medium"><?= htmlspecialchars($run['released_by_name']) ?></span></p>
          <?php endif; ?>
          <p class="text-xs text-gray-500 px-3">Refer to audit logs for detailed release context.</p>
        </div>
      <?php elseif ($canRelease): ?>
        <div class="space-y-3">
          <div class="flex items-start gap-2 p-3 bg-white rounded border border-blue-200">
            <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-sm text-gray-700">All compliance checks passed. This payroll run is ready for release.</p>
          </div>
          <form method="post" class="space-y-2" data-authz-module="payroll" data-authz-required="admin" data-authz-force data-authz-action="Release payroll run" data-confirm="Release this payroll run now?">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="release_run" />
            <button type="submit" class="btn btn-primary w-full flex items-center justify-center gap-2" data-authz-action="Release payroll run">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              Release Payroll Run
            </button>
          </form>
          <p class="text-xs text-gray-500 px-1">Releasing requires credential confirmation and locks the run status to released.</p>
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <div class="flex items-start gap-2 p-3 bg-white rounded border border-orange-200">
            <svg class="w-5 h-5 text-orange-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <div class="flex-1">
              <p class="text-sm font-semibold text-orange-800 mb-2">Release Blocked</p>
              <p class="text-xs text-gray-700 mb-2">Resolve these items before releasing:</p>
            </div>
          </div>
          <ul class="space-y-2 px-1">
            <?php foreach ($releaseBlockers as $blocker): ?>
              <li class="flex items-start gap-2 text-xs text-gray-700 p-2 bg-white rounded border border-gray-200">
                <svg class="w-4 h-4 text-orange-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                <span><?= htmlspecialchars($blocker) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-2">Next Suggested Actions</h3>
      <?php
        $nextActions = [];
        if ($run['status'] === 'released') {
          $nextActions[] = 'Distribute payslips and notify branch payroll contacts of the release.';
          $nextActions[] = 'Monitor post-release complaints within the SLA window.';
          $nextActions[] = 'Archive submission artifacts for audit readiness.';
        } else {
          if ($incompleteBranchCount > 0) {
            $nextActions[] = 'Follow up with branches to submit or correct outstanding attendance files.';
          }
          if ($approvalStatusCounts['pending'] > 0) {
            $nextActions[] = 'Coordinate with ' . $pendingApproverSummary . ' to complete the remaining approval step(s).';
          }
          if ($openComplaints > 0) {
            $nextActions[] = 'Resolve open complaints so they don\'t block release.';
          }
          if ($canRelease) {
            $nextActions[] = 'Authorize the payroll release once finance and HR sign-offs are confirmed.';
          } else {
            $nextActions[] = 'Work through the listed release blockers before finalizing the run.';
          }
        }
      ?>
      <ul class="list-disc pl-5 text-sm text-gray-700 space-y-1">
        <?php foreach ($nextActions as $action): ?>
          <li><?= htmlspecialchars($action) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-2">Helpful Links</h3>
      <ul class="list-disc pl-5 text-sm text-blue-600 space-y-1">
        <li><a href="<?= BASE_URL ?>/modules/payroll/index">Payroll Runs Dashboard</a></li>
        <li><a href="<?= BASE_URL ?>/modules/attendance/index">Attendance Records</a></li>
  <li><a href="<?= BASE_URL ?>/modules/leave/admin">Leave Approvals</a></li>
      </ul>
    </div>
  </div>
</div>

<?php if ($payslips): ?>
  <div id="payslipModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl">
        <div class="flex items-center justify-between px-4 py-3 border-b">
          <h3 class="font-semibold text-lg">Payroll Summary</h3>
          <button type="button" class="text-gray-500 hover:text-gray-700" data-close aria-label="Close">✕</button>
        </div>
        <div class="p-4 space-y-4 text-sm text-gray-700">
          <div class="grid gap-2 md:grid-cols-4">
            <label class="block text-xs uppercase tracking-wide text-gray-500">
              Search
              <input type="search" class="input-text w-full" placeholder="Employee code or name" data-payslip-search />
            </label>
            <label class="block text-xs uppercase tracking-wide text-gray-500">
              Status
              <select class="input-text w-full" data-payslip-status>
                <option value="">All statuses</option>
                <?php foreach ($payslipStatusOptions as $statusKey => $statusLabel): ?>
                  <option value="<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars($statusLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block text-xs uppercase tracking-wide text-gray-500">
              Department
              <select class="input-text w-full" data-payslip-dept>
                <option value="">All departments</option>
                <?php foreach ($payslipDepartmentOptions as $deptLabel): ?>
                  <option value="<?= htmlspecialchars(strtolower($deptLabel)) ?>"><?= htmlspecialchars($deptLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="flex items-end justify-end text-xs text-gray-500">
              <span><span data-payslip-visible-count><?= (int)$payslipTotals['count'] ?></span> record(s)</span>
            </div>
          </div>
          <div class="overflow-x-auto max-h-[70vh] border rounded">
            <table class="table-basic min-w-full text-sm" data-payslip-table>
              <thead class="bg-gray-50">
                <tr>
                  <th class="p-2 text-left">Employee</th>
                  <th class="p-2 text-left">Department</th>
                  <th class="p-2 text-right">Basic</th>
                  <th class="p-2 text-right">Earnings</th>
                  <th class="p-2 text-right">Deductions</th>
                  <th class="p-2 text-right">Net</th>
                  <th class="p-2 text-left">Status</th>
                  <th class="p-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payslips as $payslipRow): ?>
                  <?php
                    $psId = (int)$payslipRow['id'];
                    $employeeLabel = trim(($payslipRow['employee_code'] ?? '') . ' — ' . ($payslipRow['last_name'] ?? '') . ', ' . ($payslipRow['first_name'] ?? ''));
                    $deptLabel = trim((string)($payslipRow['department_name'] ?? '')) ?: 'Unassigned';
                    $statusLabel = trim((string)($payslipRow['status'] ?? '')) ?: 'locked';
                    $items = $payslipItems[$psId] ?? [];
                    $groupedItems = ['earning' => [], 'deduction' => []];
                    foreach ($items as $item) {
                      $type = $item['type'] ?? '';
                      if ($type === 'earning' || $type === 'deduction') {
                        $groupedItems[$type][] = $item;
                      }
                    }
                    $earningLines = array_map(static function (array $line): array {
                      return [
                        'label' => $line['label'] ?? ($line['code'] ?? 'Item'),
                        'amount' => (float)($line['amount'] ?? 0),
                      ];
                    }, $groupedItems['earning']);
                    $deductionLines = array_map(static function (array $line): array {
                      return [
                        'label' => $line['label'] ?? ($line['code'] ?? 'Item'),
                        'amount' => (float)($line['amount'] ?? 0),
                      ];
                    }, $groupedItems['deduction']);
                    $displayEarningLines = array_slice($earningLines, 0, 6);
                    $displayDeductionLines = array_slice($deductionLines, 0, 6);
                    $lineTokens = array_map(static function (array $line): string {
                      return strtolower(trim((string)$line['label']));
                    }, array_merge($earningLines, $deductionLines));
                    $searchBlob = strtolower($employeeLabel . ' ' . $deptLabel . ' ' . ($payslipRow['employee_code'] ?? '') . ' ' . $statusLabel . ' ' . implode(' ', $lineTokens));
                  ?>
                  <tr
                    class="border-t align-top"
                    data-payslip-row
                    data-status="<?= htmlspecialchars(strtolower($statusLabel)) ?>"
                    data-dept="<?= htmlspecialchars(strtolower($deptLabel)) ?>"
                    data-search="<?= htmlspecialchars($searchBlob) ?>"
                  >
                    <td class="p-2 font-medium text-gray-800">
                      <?= htmlspecialchars($employeeLabel) ?><br>
                      <span class="text-xs text-gray-500">Summary #<?= $psId ?></span>
                    </td>
                    <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($deptLabel) ?></td>
                    <td class="p-2 text-right text-sm text-gray-700"><?= number_format((float)$payslipRow['basic_pay'], 2) ?></td>
                    <td class="p-2 align-top text-sm text-gray-700">
                      <div class="text-right font-medium"><?= number_format((float)$payslipRow['total_earnings'], 2) ?></div>
                      <?php if ($displayEarningLines): ?>
                        <ul class="mt-1 space-y-0.5 text-[11px] text-gray-600">
                          <?php foreach ($displayEarningLines as $line): ?>
                            <li class="flex items-center justify-between gap-2">
                              <span class="truncate text-left" title="<?= htmlspecialchars($line['label']) ?>"><?= htmlspecialchars($line['label']) ?></span>
                              <span><?= number_format((float)$line['amount'], 2) ?></span>
                            </li>
                          <?php endforeach; ?>
                          <?php if (count($earningLines) > count($displayEarningLines)): ?>
                            <li class="text-gray-500 text-right">+<?= count($earningLines) - count($displayEarningLines) ?> more</li>
                          <?php endif; ?>
                        </ul>
                      <?php else: ?>
                        <div class="mt-1 text-[11px] text-gray-400 text-right">No components</div>
                      <?php endif; ?>
                    </td>
                    <td class="p-2 align-top text-sm text-gray-700">
                      <div class="text-right font-medium"><?= number_format((float)$payslipRow['total_deductions'], 2) ?></div>
                      <?php if ($displayDeductionLines): ?>
                        <ul class="mt-1 space-y-0.5 text-[11px] text-gray-600">
                          <?php foreach ($displayDeductionLines as $line): ?>
                            <li class="flex items-center justify-between gap-2">
                              <span class="truncate text-left" title="<?= htmlspecialchars($line['label']) ?>"><?= htmlspecialchars($line['label']) ?></span>
                              <span><?= number_format((float)$line['amount'], 2) ?></span>
                            </li>
                          <?php endforeach; ?>
                          <?php if (count($deductionLines) > count($displayDeductionLines)): ?>
                            <li class="text-gray-500 text-right">+<?= count($deductionLines) - count($displayDeductionLines) ?> more</li>
                          <?php endif; ?>
                        </ul>
                      <?php else: ?>
                        <div class="mt-1 text-[11px] text-gray-400 text-right">No components</div>
                      <?php endif; ?>
                    </td>
                    <td class="p-2 text-right text-sm text-gray-800 font-semibold"><?= number_format((float)$payslipRow['net_pay'], 2) ?></td>
                    <td class="p-2 text-sm text-gray-700">
                      <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <?= htmlspecialchars($statusLabel) ?>
                      </span>
                      <?php if (!empty($payslipRow['generated_by_name'])): ?>
                        <div class="text-xs text-gray-500 mt-1">Generated by <?= htmlspecialchars($payslipRow['generated_by_name']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="p-2 text-sm">
                      <div class="flex flex-col gap-1">
                        <?php
                          $detailPayload = [
                            'id' => $psId,
                            'employee' => $employeeLabel,
                            'department' => $deptLabel,
                            'basic' => (float)$payslipRow['basic_pay'],
                            'earnings_total' => (float)$payslipRow['total_earnings'],
                            'deductions_total' => (float)$payslipRow['total_deductions'],
                            'net' => (float)$payslipRow['net_pay'],
                            'status' => $statusLabel,
                            'generated_by' => $payslipRow['generated_by_name'] ?? null,
                            'period' => [
                              $payslipRow['period_start'] ?? '',
                              $payslipRow['period_end'] ?? '',
                            ],
                            'earnings' => $earningLines,
                            'deductions' => $deductionLines,
                            'pdf_url' => BASE_URL . '/modules/payroll/pdf_payslip?id=' . $psId,
                          ];
                        ?>
                        <button type="button" class="text-blue-600 text-left" data-open-payslip-detail data-payslip-detail='<?= htmlspecialchars(json_encode($detailPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>View Details</button>
                        <a class="text-blue-600" href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= $psId ?>" target="_blank" rel="noopener">Download PDF</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div id="payslipDetailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl">
        <div class="flex items-center justify-between px-4 py-3 border-b">
          <h3 class="font-semibold text-lg" id="payslipDetailTitle">Payroll Summary Detail</h3>
          <button type="button" class="text-gray-500 hover:text-gray-700" data-close aria-label="Close">✕</button>
        </div>
        <div class="p-4 text-sm text-gray-700 space-y-4">
          <div class="grid md:grid-cols-2 gap-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Employee</p>
              <p class="font-semibold text-gray-800" id="payslipDetailEmployee">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Department</p>
              <p class="text-gray-800" id="payslipDetailDepartment">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Pay Period</p>
              <p class="text-gray-800" id="payslipDetailPeriod">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Status</p>
              <span id="payslipDetailStatus" class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-gray-600">—</span>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Generated By</p>
              <p class="text-gray-800" id="payslipDetailGeneratedBy">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Summary #</p>
              <p class="text-gray-800" id="payslipDetailId">—</p>
            </div>
          </div>
          <div class="grid md:grid-cols-4 gap-3 text-sm">
            <div class="bg-gray-50 rounded p-3">
              <p class="text-xs uppercase tracking-wide text-gray-500">Basic Pay</p>
              <p class="text-lg font-semibold" id="payslipDetailBasic">0.00</p>
            </div>
            <div class="bg-gray-50 rounded p-3">
              <p class="text-xs uppercase tracking-wide text-gray-500">Total Earnings</p>
              <p class="text-lg font-semibold" id="payslipDetailEarnings">0.00</p>
            </div>
            <div class="bg-gray-50 rounded p-3">
              <p class="text-xs uppercase tracking-wide text-gray-500">Total Deductions</p>
              <p class="text-lg font-semibold" id="payslipDetailDeductions">0.00</p>
            </div>
            <div class="bg-gray-50 rounded p-3">
              <p class="text-xs uppercase tracking-wide text-gray-500">Net Pay</p>
              <p class="text-lg font-semibold text-emerald-600" id="payslipDetailNet">0.00</p>
            </div>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <h4 class="font-semibold mb-2">Earnings</h4>
              <ul class="space-y-1 text-sm" id="payslipDetailEarningList">
                <li class="text-gray-500">No earnings to display.</li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-2">Deductions</h4>
              <ul class="space-y-1 text-sm" id="payslipDetailDeductionList">
                <li class="text-gray-500">No deductions to display.</li>
              </ul>
            </div>
          </div>
          <div class="flex items-center justify-between pt-2">
            <button type="button" class="btn btn-outline" data-close>Close</button>
            <a href="#" class="btn btn-secondary" target="_blank" rel="noopener" id="payslipDetailPdf">Download PDF</a>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

  <div id="complaintModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-4 py-3 border-b flex-shrink-0">
          <h3 class="font-semibold text-lg">Complaint Details</h3>
          <button type="button" class="text-gray-500 hover:text-gray-700" data-close aria-label="Close">✕</button>
        </div>
        <div class="p-4 text-sm text-gray-700 space-y-4 overflow-y-auto">
          <div class="grid md:grid-cols-2 gap-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Employee</p>
              <p id="complaintModalEmployee" class="font-semibold text-gray-800">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Status</p>
              <span id="complaintModalStatus" data-base-class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">Pending</span>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Category</p>
              <p id="complaintModalCategory" class="text-gray-800">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Topic</p>
              <p id="complaintModalTopic" class="text-gray-800">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Priority</p>
              <span id="complaintModalPriority" data-base-class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700">Normal</span>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Filed</p>
              <p id="complaintModalSubmitted" class="text-gray-800">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Resolved</p>
              <p id="complaintModalResolved" class="text-gray-800">—</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-gray-500">Issue Label</p>
              <p id="complaintModalIssue" class="text-gray-800">—</p>
            </div>
          </div>
          <div class="bg-gray-50 rounded p-3">
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Description</p>
            <p id="complaintModalDescription" class="whitespace-pre-wrap text-gray-800">—</p>
          </div>
          <div class="bg-gray-50 rounded p-3">
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Current Resolution Notes</p>
            <p id="complaintModalResolution" class="whitespace-pre-wrap text-gray-800">—</p>
          </div>
          <form method="post" id="complaintModalForm" class="space-y-3"<?php if (!$isSystemAdmin && !in_array($currentAccessLevel, ['write', 'admin'], true)): ?> data-authz-module="payroll" data-authz-required="write" data-authz-action="Update payroll complaint"<?php endif; ?>>
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="complaint_update" />
            <input type="hidden" name="complaint_id" value="" />
            <label class="block">
              <span class="text-xs uppercase tracking-wide text-gray-500 mb-1">Update Status</span>
              <select name="status" id="complaintStatusUpdate" class="input-text w-full">
                <?php foreach ($complaintStatusLabels as $value => $label): ?>
                  <option value="<?= $value ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block">
              <span class="text-xs uppercase tracking-wide text-gray-500 mb-1">Resolution Notes</span>
              <textarea name="resolution_notes" rows="2" class="input-text w-full" placeholder="Document the steps taken"></textarea>
            </label>
            
            <!-- Adjustment Section - Always visible -->
            <div id="adjustmentSection" class="border-t pt-3 mt-3">
              <div class="flex items-center space-x-2 mb-3">
                <input type="checkbox" id="addAdjustment" name="add_adjustment" value="1" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="addAdjustment" class="text-sm font-medium text-gray-700">Queue payroll adjustment for next cutoff</label>
              </div>
              
              <div id="adjustmentFields" class="hidden bg-blue-50 border border-blue-200 rounded p-3 space-y-3">
                <div class="flex items-start gap-2">
                  <svg class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                  <p class="text-xs text-blue-700">Adjustment will be queued for approval and applied to employee's next payroll run after approval.</p>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                  <label class="block">
                    <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Type *</span>
                    <select name="adjustment_type" id="adjustmentType" class="input-text w-full text-sm" data-required="true">
                      <option value="">Select type...</option>
                      <option value="earning">Earning</option>
                      <option value="deduction">Deduction</option>
                    </select>
                  </label>
                  
                  <label class="block">
                    <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Category *</span>
                    <select name="adjustment_category" id="adjustmentCategory" class="input-text w-full text-sm" data-required="true" disabled>
                      <option value="">Select type first...</option>
                    </select>
                  </label>
                  
                  <label class="block">
                    <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Amount (₱) *</span>
                    <input type="number" name="adjustment_amount" id="adjustmentAmount" step="0.01" min="0.01" class="input-text w-full text-sm" placeholder="0.00" data-required="true">
                  </label>
                </div>
                
                <div id="customCategoryWrapper" class="hidden">
                  <label class="block">
                    <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Custom Category *</span>
                    <input type="text" name="adjustment_custom_category" id="adjustmentCustomCategory" class="input-text w-full text-sm" placeholder="Enter custom category name">
                  </label>
                </div>
                
                <label class="block">
                  <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Label/Description</span>
                  <input type="text" name="adjustment_label" id="adjustmentLabel" class="input-text w-full text-sm" placeholder="e.g., OT correction for Dec 10-15">
                </label>
                
                <label class="block">
                  <span class="text-xs uppercase tracking-wide text-gray-600 mb-1">Notes/Remarks</span>
                  <textarea name="adjustment_notes" id="adjustmentNotes" rows="2" class="input-text w-full text-sm" placeholder="Add any additional notes or remarks about this adjustment..."></textarea>
                </label>
              </div>
            </div>
            
            <div class="flex justify-between items-center pt-2">
              <button type="button" class="btn btn-outline" data-close>Close</button>
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Complaint History Modal -->
  <div id="complaintHistoryModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-4 py-3 border-b flex-shrink-0">
          <div>
            <h3 class="font-semibold text-lg">Complaint History</h3>
            <p class="text-sm text-gray-600" id="complaintHistoryEmployee">—</p>
          </div>
          <button type="button" class="text-gray-500 hover:text-gray-700" data-close aria-label="Close">✕</button>
        </div>
        <div class="p-4 overflow-y-auto">
          <div id="complaintHistoryContent" class="space-y-3">
            <div class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          </div>
        </div>
        <div class="flex justify-end items-center px-4 py-3 border-t flex-shrink-0">
          <button type="button" class="btn btn-outline" data-close>Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function(){
    function initPayrollRunView(){
      const payslipModal = document.getElementById('payslipModal');
      if (payslipModal && !payslipModal.dataset.bound) {
        const searchInput = payslipModal.querySelector('[data-payslip-search]');
        const statusSelect = payslipModal.querySelector('[data-payslip-status]');
        const deptSelect = payslipModal.querySelector('[data-payslip-dept]');
        const rows = Array.from(payslipModal.querySelectorAll('[data-payslip-row]'));
        const visibleCountEl = payslipModal.querySelector('[data-payslip-visible-count]');
        const openButtons = document.querySelectorAll('[data-open-payslip-modal]');

        const applyFilters = () => {
          const query = (searchInput?.value || '').toLowerCase();
          const status = (statusSelect?.value || '').toLowerCase();
          const dept = (deptSelect?.value || '').toLowerCase();
          let visible = 0;
          rows.forEach((row) => {
            const rowStatus = (row.dataset.status || '').toLowerCase();
            const rowDept = (row.dataset.dept || '').toLowerCase();
            const rowSearch = (row.dataset.search || '').toLowerCase();
            const matchesSearch = !query || rowSearch.includes(query);
            const matchesStatus = !status || rowStatus === status;
            const matchesDept = !dept || rowDept === dept;
            const show = matchesSearch && matchesStatus && matchesDept;
            row.classList.toggle('hidden', !show);
            if (show) {
              visible += 1;
            }
          });
          if (visibleCountEl) {
            visibleCountEl.textContent = String(visible);
          }
        };

        const bindFilter = (element) => {
          if (!element) {
            return;
          }
          const eventName = element.tagName === 'INPUT' ? 'input' : 'change';
          element.addEventListener(eventName, () => {
            applyFilters();
          });
        };

        bindFilter(searchInput);
        bindFilter(statusSelect);
        bindFilter(deptSelect);

        openButtons.forEach((btn) => {
          if (btn.dataset.modalBound === '1') {
            return;
          }
          btn.dataset.modalBound = '1';
          btn.addEventListener('click', () => {
            applyFilters();
            openModal('payslipModal');
          });
        });

        payslipModal.addEventListener('click', (evt) => {
          const target = evt.target;
          if (target && target.dataset && target.dataset.close !== undefined) {
            closeModal('payslipModal');
          }
        });

        if (!window.__payslipModalEscBound) {
          document.addEventListener('keydown', (evt) => {
            if (evt.key === 'Escape' && !payslipModal.classList.contains('hidden')) {
              closeModal('payslipModal');
            }
          });
          window.__payslipModalEscBound = true;
        }

        const payslipDetailModal = document.getElementById('payslipDetailModal');
        if (payslipDetailModal) {
          const detailButtons = payslipModal.querySelectorAll('[data-open-payslip-detail]');
          const el = {
            title: document.getElementById('payslipDetailTitle'),
            employee: document.getElementById('payslipDetailEmployee'),
            department: document.getElementById('payslipDetailDepartment'),
            period: document.getElementById('payslipDetailPeriod'),
            status: document.getElementById('payslipDetailStatus'),
            generatedBy: document.getElementById('payslipDetailGeneratedBy'),
            id: document.getElementById('payslipDetailId'),
            basic: document.getElementById('payslipDetailBasic'),
            earnings: document.getElementById('payslipDetailEarnings'),
            deductions: document.getElementById('payslipDetailDeductions'),
            net: document.getElementById('payslipDetailNet'),
            earningList: document.getElementById('payslipDetailEarningList'),
            deductionList: document.getElementById('payslipDetailDeductionList'),
            pdf: document.getElementById('payslipDetailPdf'),
          };

          const formatCurrency = (value) => {
            if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
              return new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0);
            }
            const number = Math.round((Number(value) || 0) * 100) / 100;
            return number.toFixed(2);
          };

          const renderList = (container, items) => {
            if (!container) {
              return;
            }
            container.innerHTML = '';
            if (!items || !items.length) {
              const li = document.createElement('li');
              li.className = 'text-gray-500';
              li.textContent = 'No items to display.';
              container.appendChild(li);
              return;
            }
            items.forEach((item) => {
              const li = document.createElement('li');
              li.className = 'flex justify-between';
              const label = document.createElement('span');
              label.textContent = item.label || 'Item';
              const amount = document.createElement('span');
              amount.textContent = formatCurrency(item.amount);
              li.appendChild(label);
              li.appendChild(amount);
              container.appendChild(li);
            });
          };

          detailButtons.forEach((button) => {
            button.addEventListener('click', () => {
              let payload = null;
              try {
                payload = JSON.parse(button.dataset.payslipDetail || '{}');
              } catch (err) {
                payload = null;
              }
              if (!payload) {
                return;
              }
              if (el.title) {
                el.title.textContent = 'Payroll Summary #' + (payload.id || '');
              }
              if (el.employee) {
                el.employee.textContent = payload.employee || '—';
              }
              if (el.department) {
                el.department.textContent = payload.department || '—';
              }
              if (el.period) {
                const period = Array.isArray(payload.period) ? payload.period : [];
                el.period.textContent = period.filter(Boolean).join(' to ') || '—';
              }
              if (el.status) {
                el.status.textContent = payload.status || '—';
              }
              if (el.generatedBy) {
                el.generatedBy.textContent = payload.generated_by || '—';
              }
              if (el.id) {
                el.id.textContent = payload.id || '—';
              }
              if (el.basic) {
                el.basic.textContent = formatCurrency(payload.basic);
              }
              if (el.earnings) {
                el.earnings.textContent = formatCurrency(payload.earnings_total);
              }
              if (el.deductions) {
                el.deductions.textContent = formatCurrency(payload.deductions_total);
              }
              if (el.net) {
                el.net.textContent = formatCurrency(payload.net);
              }
              renderList(el.earningList, payload.earnings);
              renderList(el.deductionList, payload.deductions);
              if (el.pdf) {
                el.pdf.href = payload.pdf_url || '#';
              }
              openModal('payslipDetailModal');
            });
          });

          payslipDetailModal.addEventListener('click', (evt) => {
            const target = evt.target;
            if (target && target.dataset && target.dataset.close !== undefined) {
              closeModal('payslipDetailModal');
            }
          });

          if (!window.__payslipDetailEscBound) {
            document.addEventListener('keydown', (evt) => {
              if (evt.key === 'Escape' && !payslipDetailModal.classList.contains('hidden')) {
                closeModal('payslipDetailModal');
              }
            });
            window.__payslipDetailEscBound = true;
          }
        }

        payslipModal.dataset.bound = '1';
      }

      const complaintModal = document.getElementById('complaintModal');
      if (!complaintModal || complaintModal.dataset.bound === '1') {
        return;
      }

      const complaintButtons = document.querySelectorAll('[data-complaint-open]');
      const form = document.getElementById('complaintModalForm');
      const employeeEl = document.getElementById('complaintModalEmployee');
      const categoryEl = document.getElementById('complaintModalCategory');
      const topicEl = document.getElementById('complaintModalTopic');
      const issueEl = document.getElementById('complaintModalIssue');
      const priorityBadge = document.getElementById('complaintModalPriority');
      const statusBadge = document.getElementById('complaintModalStatus');
      const submittedEl = document.getElementById('complaintModalSubmitted');
      const resolvedEl = document.getElementById('complaintModalResolved');
      const descriptionEl = document.getElementById('complaintModalDescription');
      const resolutionEl = document.getElementById('complaintModalResolution');
      const idField = form?.querySelector('input[name="complaint_id"]');
      const statusField = form?.querySelector('select[name="status"]');
      const resolutionField = form?.querySelector('textarea[name="resolution_notes"]');
      const priorityBase = priorityBadge?.dataset.baseClass || '';
      const statusBase = statusBadge?.dataset.baseClass || '';

      const priorityClassMap = {
        urgent: 'bg-red-100 text-red-700',
        normal: 'bg-blue-100 text-blue-700'
      };
      const statusClassMap = {
        pending: 'bg-amber-100 text-amber-700',
        in_review: 'bg-sky-100 text-sky-700',
        resolved: 'bg-emerald-100 text-emerald-700',
        rejected: 'bg-rose-100 text-rose-700'
      };

      const applyClass = (el, base, cls) => {
        if (!el) return;
        el.className = (base + ' ' + cls).trim();
      };

      complaintButtons.forEach((btn) => {
        if (btn.dataset.modalBound === '1') {
          return;
        }
        btn.dataset.modalBound = '1';
        btn.addEventListener('click', () => {
          const ds = btn.dataset;
          if (employeeEl) employeeEl.textContent = ds.complaintEmployee || '—';
          if (categoryEl) categoryEl.textContent = ds.complaintCategory || '—';
          if (topicEl) topicEl.textContent = ds.complaintTopic || '—';
          if (issueEl) issueEl.textContent = ds.complaintIssue || '—';
          if (priorityBadge) {
            priorityBadge.textContent = ds.complaintPriority || 'Normal';
            const priorityCode = (ds.complaintPriorityCode || 'normal');
            const cls = priorityClassMap[priorityCode] || 'bg-slate-100 text-slate-700';
            applyClass(priorityBadge, priorityBase, cls);
          }
          if (statusBadge) {
            statusBadge.textContent = ds.complaintStatusLabel || (ds.complaintStatus || 'Pending');
            const statusCode = (ds.complaintStatus || 'pending');
            const cls = statusClassMap[statusCode] || 'bg-gray-100 text-gray-700';
            applyClass(statusBadge, statusBase, cls);
          }
          if (submittedEl) submittedEl.textContent = ds.complaintSubmitted || '—';
          if (resolvedEl) resolvedEl.textContent = ds.complaintResolved || '—';
          if (descriptionEl) descriptionEl.textContent = ds.complaintDescription || '—';
          if (resolutionEl) resolutionEl.textContent = ds.complaintResolution || '—';
          if (idField) idField.value = ds.complaintId || '';
          
          // Set status dropdown with explicit validation
          if (statusField) {
            const currentStatus = ds.complaintStatus || 'pending';
            statusField.value = currentStatus;
            
            // Verify the value was set correctly
            if (statusField.value !== currentStatus) {
              console.warn('Status field value mismatch:', {
                intended: currentStatus,
                actual: statusField.value,
                options: Array.from(statusField.options).map(o => o.value)
              });
              // Force set by finding matching option
              Array.from(statusField.options).forEach(opt => {
                if (opt.value === currentStatus) {
                  opt.selected = true;
                }
              });
            } else {
              console.log('Complaint modal opened with status:', currentStatus);
            }
          }
          
          if (resolutionField) {
            resolutionField.value = ds.complaintResolution || '';
          }
          openModal('complaintModal');
        });
      });

      complaintModal.addEventListener('click', (e) => {
        const target = e.target;
        if (target && target.dataset && target.dataset.close !== undefined) {
          closeModal('complaintModal');
        }
      });

      if (!window.__complaintModalEscBound) {
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && !complaintModal.classList.contains('hidden')) {
            closeModal('complaintModal');
          }
        });
        window.__complaintModalEscBound = true;
      }

      complaintModal.dataset.bound = '1';

      // Adjustment section toggle logic
      const statusUpdateSelect = document.getElementById('complaintStatusUpdate');
      const adjustmentSection = document.getElementById('adjustmentSection');
      const addAdjustmentCheckbox = document.getElementById('addAdjustment');
      const adjustmentFields = document.getElementById('adjustmentFields');
      
      // Compensation templates data
      const allowanceTemplates = <?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'code' => $t['code']], $allowanceTemplates)) ?>;
      const deductionTemplates = <?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'code' => $t['code']], $deductionTemplates)) ?>;
      const contributionTemplates = <?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'code' => $t['code']], $contributionTemplates)) ?>;
      const taxTemplates = <?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'code' => $t['code']], $taxTemplates)) ?>;
      
      const typeField = document.getElementById('adjustmentType');
      const categoryField = document.getElementById('adjustmentCategory');
      
          // Common categories for adjustments
      const commonEarningCategories = [
        'Overtime Correction',
        'Holiday Pay Adjustment',
        'Night Differential Correction',
        'Allowance Correction',
        'Incentive/Bonus',
        'Retroactive Pay',
        'Commission Adjustment',
        'Missing Pay Period',
        'Performance Bonus',
        'Back Pay',
        '13th Month Pay Adjustment',
        'Premium Pay',
        'Attendance Bonus'
      ];
      
      const commonDeductionCategories = [
        'Tax Withholding Correction',
        'SSS Contribution Adjustment',
        'PhilHealth Adjustment',
        'Pag-IBIG Adjustment',
        'Loan Deduction',
        'Cash Advance Deduction',
        'Tardiness/Absence Correction',
        'Uniform/Equipment Deduction',
        'Insurance Premium',
        'AWOL Deduction',
        'Undertime Deduction',
        'Damage/Loss Deduction',
        'Over-Payment Recovery'
      ];
      
      // Populate category dropdown based on type selection
      if (typeField && categoryField) {
        typeField.addEventListener('change', function() {
          const selectedType = this.value;
          categoryField.innerHTML = '<option value="">Select category...</option>';
          
          if (selectedType === 'earning') {
            categoryField.disabled = false;
            
            // Add common earning categories
            commonEarningCategories.forEach(cat => {
              const option = document.createElement('option');
              option.value = cat;
              option.textContent = cat;
              categoryField.appendChild(option);
            });
            
            // Add separator
            const separator = document.createElement('option');
            separator.disabled = true;
            separator.textContent = '───────────────';
            categoryField.appendChild(separator);
            
            // Add template-based earnings
            allowanceTemplates.forEach(template => {
              const option = document.createElement('option');
              option.value = template.name;
              option.textContent = template.name + ' (Template)';
              categoryField.appendChild(option);
            });
            
            // Add 'Other' option
            const otherOption = document.createElement('option');
            otherOption.value = 'Other';
            otherOption.textContent = 'Other (Specify below)';
            categoryField.appendChild(otherOption);
            
          } else if (selectedType === 'deduction') {
            categoryField.disabled = false;
            
            // Add common deduction categories
            commonDeductionCategories.forEach(cat => {
              const option = document.createElement('option');
              option.value = cat;
              option.textContent = cat;
              categoryField.appendChild(option);
            });
            
            // Add separator
            const separator = document.createElement('option');
            separator.disabled = true;
            separator.textContent = '───────────────';
            categoryField.appendChild(separator);
            
            // Add deduction templates
            deductionTemplates.forEach(template => {
              const option = document.createElement('option');
              option.value = template.name;
              option.textContent = template.name + ' (Template)';
              categoryField.appendChild(option);
            });
            
            // Add contribution templates
            contributionTemplates.forEach(template => {
              const option = document.createElement('option');
              option.value = template.name;
              option.textContent = template.name + ' (Template)';
              categoryField.appendChild(option);
            });
            
            // Add tax templates
            taxTemplates.forEach(template => {
              const option = document.createElement('option');
              option.value = template.name;
              option.textContent = template.name + ' (Template)';
              categoryField.appendChild(option);
            });
            
            // Add 'Other' option
            const otherOption = document.createElement('option');
            otherOption.value = 'Other';
            otherOption.textContent = 'Other (Specify below)';
            categoryField.appendChild(otherOption);
            
          } else {
            categoryField.disabled = true;
            categoryField.innerHTML = '<option value="">Select type first...</option>';
          }
        });
      }      // Handle custom category field visibility
      const customCategoryWrapper = document.getElementById('customCategoryWrapper');
      const customCategoryField = document.getElementById('adjustmentCustomCategory');
      
      if (categoryField && customCategoryWrapper && customCategoryField) {
        categoryField.addEventListener('change', function() {
          if (this.value === 'Other') {
            customCategoryWrapper.classList.remove('hidden');
            customCategoryField.setAttribute('required', 'required');
          } else {
            customCategoryWrapper.classList.add('hidden');
            customCategoryField.removeAttribute('required');
            customCategoryField.value = '';
          }
        });
      }
      
      if (addAdjustmentCheckbox && adjustmentFields) {
        // Function to toggle required attributes
        const toggleRequired = (isRequired) => {
          adjustmentFields.querySelectorAll('[data-required="true"]').forEach(field => {
            if (isRequired) {
              field.setAttribute('required', 'required');
            } else {
              field.removeAttribute('required');
            }
          });
        };
        
        // Initialize: ensure fields are not required when hidden
        toggleRequired(false);
        
        addAdjustmentCheckbox.addEventListener('change', function() {
          if (this.checked) {
            adjustmentFields.classList.remove('hidden');
            toggleRequired(true);
          } else {
            adjustmentFields.classList.add('hidden');
            toggleRequired(false);
            // Clear adjustment values when unchecked
            if (typeField) typeField.value = '';
            if (categoryField) {
              categoryField.value = '';
              categoryField.disabled = true;
              categoryField.innerHTML = '<option value="">Select type first...</option>';
            }
            const amountField = document.getElementById('adjustmentAmount');
            const labelField = document.getElementById('adjustmentLabel');
            const notesField = document.getElementById('adjustmentNotes');
            if (amountField) amountField.value = '';
            if (labelField) labelField.value = '';
            if (notesField) notesField.value = '';
          }
        });
      }

      // Log form submission values for debugging
      if (form) {
        form.addEventListener('submit', function(e) {
          const formData = new FormData(form);
          console.log('Complaint form submitting with values:', {
            complaint_id: formData.get('complaint_id'),
            status: formData.get('status'),
            resolution_notes: formData.get('resolution_notes'),
            add_adjustment: formData.get('add_adjustment')
          });
        });
      }

      complaintModal.dataset.bound = '1';
    }

    // Complaint History Modal Handler
    const complaintHistoryModal = document.getElementById('complaintHistoryModal');
    if (complaintHistoryModal && !complaintHistoryModal.dataset.bound) {
      const historyButtons = document.querySelectorAll('[data-complaint-history]');
      const historyContent = document.getElementById('complaintHistoryContent');
      const employeeEl = document.getElementById('complaintHistoryEmployee');
      
      historyButtons.forEach(btn => {
        if (btn.dataset.historyBound === '1') return;
        btn.dataset.historyBound = '1';
        
        btn.addEventListener('click', async () => {
          const complaintId = btn.dataset.complaintId;
          const employeeName = btn.dataset.complaintEmployee;
          
          if (employeeEl) employeeEl.textContent = employeeName || 'Employee';
          
          // Show loading state
          if (historyContent) {
            historyContent.innerHTML = `
              <div class="flex items-center justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
              </div>
            `;
          }
          
          openModal('complaintHistoryModal');
          
          // Fetch complaint history via AJAX
          try {
            const response = await fetch('<?= BASE_URL ?>/modules/payroll/ajax_complaint_history.php?id=' + complaintId, {
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin'
            });
            
            if (!response.ok) {
              throw new Error('Failed to load history (HTTP ' + response.status + ')');
            }

            const contentType = response.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') === -1) {
              throw new Error('Invalid response content type');
            }

            const data = await response.json();
            
            if (data.success && data.history) {
              renderComplaintHistory(data.history, data.complaint);
            } else {
              historyContent.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                  <p>${data.error || 'No history available for this complaint.'}</p>
                </div>
              `;
            }
          } catch (error) {
            console.error('Error loading complaint history:', error);
            const friendly = error && error.message ? error.message : 'Error loading complaint history. Please try again.';
            historyContent.innerHTML = `
              <div class="text-center py-8 text-red-600">
                <p>${friendly}</p>
              </div>
            `;
          }
        });
      });
      
      function renderComplaintHistory(history, complaint) {
        if (!historyContent) return;
        
        if (!history || history.length === 0) {
          historyContent.innerHTML = `
            <div class="text-center py-8 text-gray-500">
              <p>No history available for this complaint.</p>
            </div>
          `;
          return;
        }
        
        let html = '';
        
        // Complaint Details Card
        if (complaint) {
          html += `
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
              <h4 class="font-semibold text-blue-900 mb-2">Complaint Details</h4>
              <div class="grid grid-cols-2 gap-3 text-sm">
                <div><span class="font-medium text-gray-700">Issue:</span> <span class="text-gray-900">${complaint.issue_type || '—'}</span></div>
                <div><span class="font-medium text-gray-700">Status:</span> <span class="text-gray-900">${complaint.status || '—'}</span></div>
                <div><span class="font-medium text-gray-700">Priority:</span> <span class="text-gray-900">${complaint.priority || 'Normal'}</span></div>
                <div><span class="font-medium text-gray-700">Filed:</span> <span class="text-gray-900">${complaint.created_at || '—'}</span></div>
              </div>
              ${complaint.description ? `<div class="mt-2 text-sm text-gray-700"><span class="font-medium">Description:</span> ${complaint.description}</div>` : ''}
            </div>
          `;
        }
        
        // Timeline
        html += '<div class="relative border-l-2 border-gray-300 ml-4 pl-6 space-y-6">';
        
        history.forEach((entry, index) => {
          const isFirst = index === 0;
          const iconColor = entry.action_type === 'resolved' ? 'bg-green-500' : 
                           entry.action_type === 'rejected' ? 'bg-red-500' : 
                           entry.action_type === 'in_review' ? 'bg-blue-500' : 'bg-gray-400';
          
          html += `
            <div class="relative">
              <div class="absolute -left-[1.875rem] top-1.5 w-4 h-4 rounded-full ${iconColor} border-4 border-white"></div>
              <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
                <div class="flex items-start justify-between mb-2">
                  <div>
                    <h5 class="font-semibold text-gray-900">${entry.action_label || 'Status Update'}</h5>
                    <p class="text-xs text-gray-500">${entry.action_date || '—'}</p>
                  </div>
                  <span class="text-xs font-medium px-2 py-1 rounded ${
                    entry.action_type === 'resolved' ? 'bg-green-100 text-green-700' :
                    entry.action_type === 'rejected' ? 'bg-red-100 text-red-700' :
                    entry.action_type === 'in_review' ? 'bg-blue-100 text-blue-700' :
                    'bg-gray-100 text-gray-700'
                  }">${entry.status_label || entry.action_type || '—'}</span>
                </div>
                ${entry.actor ? `<p class="text-sm text-gray-600 mb-1"><span class="font-medium">By:</span> ${entry.actor}</p>` : ''}
                ${entry.notes ? `<div class="text-sm text-gray-700 bg-gray-50 rounded p-2 mt-2">${entry.notes}</div>` : ''}
                ${entry.adjustment_details ? `
                  <div class="mt-2 text-sm bg-yellow-50 border border-yellow-200 rounded p-2">
                    <p class="font-medium text-yellow-900">Adjustment Queued:</p>
                    <p class="text-yellow-800">${entry.adjustment_details}</p>
                  </div>
                ` : ''}
              </div>
            </div>
          `;
        });
        
        html += '</div>';
        
        historyContent.innerHTML = html;
      }
      
      complaintHistoryModal.addEventListener('click', (e) => {
        if (e.target && e.target.dataset && e.target.dataset.close !== undefined) {
          closeModal('complaintHistoryModal');
        }
      });
      
      if (!window.__complaintHistoryEscBound) {
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && !complaintHistoryModal.classList.contains('hidden')) {
            closeModal('complaintHistoryModal');
          }
        });
        window.__complaintHistoryEscBound = true;
      }
      
      complaintHistoryModal.dataset.bound = '1';
    }

    document.addEventListener('DOMContentLoaded', initPayrollRunView);
    document.addEventListener('spa:loaded', initPayrollRunView);
  })();

  // Submit DTR Modal Functions
  function openSubmitDTRModal(batchId, branchName) {
    document.getElementById('submitDTRBatchId').value = batchId;
    document.getElementById('submitDTRBranchName').textContent = branchName;
    document.getElementById('submitDTRModal').classList.remove('hidden');
    
    // Load attendance preview for this batch
    loadAttendancePreview(batchId);
  }

  function closeSubmitDTRModal() {
    document.getElementById('submitDTRModal').classList.add('hidden');
  }

  function loadAttendancePreview(batchId) {
    const previewContainer = document.getElementById('attendancePreview');
    previewContainer.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">Loading attendance records...</p>';
    
    // Fetch attendance data via AJAX
    fetch('<?= BASE_URL ?>/modules/payroll/ajax_attendance_preview.php?batch_id=' + batchId)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.records.length > 0) {
          let html = '<div class="overflow-x-auto max-h-96"><table class="table-basic min-w-full text-sm">';
          html += '<thead class="bg-gray-50"><tr>';
          html += '<th class="p-2 text-left">Employee</th>';
          html += '<th class="p-2 text-left">Date</th>';
          html += '<th class="p-2 text-left">Time In</th>';
          html += '<th class="p-2 text-left">Time Out</th>';
          html += '</tr></thead><tbody>';
          
          data.records.forEach(record => {
            html += '<tr class="border-t">';
            html += '<td class="p-2">' + escapeHtml(record.employee_name) + '</td>';
            html += '<td class="p-2">' + escapeHtml(record.date) + '</td>';
            html += '<td class="p-2">' + escapeHtml(record.time_in) + '</td>';
            html += '<td class="p-2">' + escapeHtml(record.time_out) + '</td>';
            html += '</tr>';
          });
          
          html += '</tbody></table></div>';
          html += '<p class="text-xs text-gray-500 mt-3">Total: ' + data.records.length + ' attendance records</p>';
          previewContainer.innerHTML = html;
        } else {
          previewContainer.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">No attendance records found for this batch.</p>';
        }
      })
      .catch(error => {
        console.error('Failed to load attendance preview:', error);
        previewContainer.innerHTML = '<p class="text-sm text-red-600 text-center py-4">Failed to load attendance records.</p>';
      });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  </script>

<!-- Submit DTR Modal -->
<div id="submitDTRModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
    <div class="sticky top-0 bg-white border-b border-gray-200 p-6 flex items-center justify-between">
      <div>
        <h3 class="text-lg font-semibold text-gray-900">Submit DTR for <span id="submitDTRBranchName"></span></h3>
        <p class="text-sm text-gray-500 mt-1">Review attendance records before submission</p>
      </div>
      <button type="button" onclick="closeSubmitDTRModal()" class="text-gray-400 hover:text-gray-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="p-6">
      <div id="attendancePreview" class="mb-6">
        <p class="text-sm text-gray-500 text-center py-4">Loading...</p>
      </div>

      <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded mb-6">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
          </div>
          <div class="ml-3">
            <h4 class="text-sm font-medium text-yellow-800">Submission Confirmation</h4>
            <p class="mt-1 text-sm text-yellow-700">
              Once submitted, attendance records will be locked and cannot be edited. 
              The batch will proceed to the approval workflow.
            </p>
          </div>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="submit_dtr">
        <input type="hidden" name="batch_id" id="submitDTRBatchId" value="">

        <div class="flex gap-3">
          <button type="submit" class="btn btn-primary flex-1">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Confirm & Submit DTR
          </button>
          <button type="button" onclick="closeSubmitDTRModal()" class="btn btn-outline">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reject Adjustment Modal -->
<div id="rejectAdjustmentModal" class="fixed inset-0 z-50 hidden">
  <div class="fixed inset-0 bg-black bg-opacity-50" onclick="closeModal('rejectAdjustmentModal')"></div>
  <div class="fixed inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full">
      <div class="border-b border-gray-200 p-4">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-gray-900">Reject Adjustment</h3>
          <button type="button" onclick="closeModal('rejectAdjustmentModal')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>
      
      <form method="POST" id="rejectAdjustmentForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reject_adjustment">
        <input type="hidden" name="adjustment_id" id="rejectAdjustmentId">
        
        <div class="p-4 space-y-4">
          <div class="rounded-lg bg-red-50 border border-red-200 p-3">
            <div class="font-medium text-red-900" id="rejectAdjustmentTitle"></div>
            <div class="text-sm text-red-700 mt-1" id="rejectAdjustmentEmployee"></div>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Rejection Reason <span class="text-red-500">*</span>
            </label>
            <textarea name="rejection_reason" 
                      id="rejectionReasonInput"
                      rows="4" 
                      class="input-text w-full" 
                      placeholder="Explain why this adjustment is being rejected..."
                      required></textarea>
            <p class="mt-1 text-xs text-gray-500">This reason will be recorded and visible in the audit trail.</p>
          </div>
        </div>
        
        <div class="border-t border-gray-200 p-4 flex items-center justify-end gap-3">
          <button type="button" onclick="closeModal('rejectAdjustmentModal')" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-danger">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            Reject Adjustment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openRejectAdjustmentModal(adjustmentId, adjLabel, empName) {
  document.getElementById('rejectAdjustmentId').value = adjustmentId;
  document.getElementById('rejectAdjustmentTitle').textContent = adjLabel;
  document.getElementById('rejectAdjustmentEmployee').textContent = 'Employee: ' + empName;
  document.getElementById('rejectionReasonInput').value = '';
  openModal('rejectAdjustmentModal');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


