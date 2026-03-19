<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('payroll', 'payroll_runs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/payroll.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentAccessLevel = $currentUserId ? (user_has_access($currentUserId, 'payroll', 'view', 'write') ? 'write' : (user_has_access($currentUserId, 'payroll', 'view', 'read') ? 'read' : 'none')) : 'none';

$complaintStatusLabels = [
  'pending' => 'Pending',
  'in_review' => 'In Review',
  'resolved' => 'Resolved',
  'confirmed' => 'Confirmed',
  'rejected' => 'Rejected',
];
$complaintCategories = payroll_get_complaint_categories();
$complaintPriorities = payroll_get_complaint_priorities();
$complaintFilterOptions = [
  'open' => ['label' => 'Open', 'statuses' => ['pending', 'in_review']],
  'pending' => ['label' => 'Pending', 'statuses' => ['pending']],
  'in_review' => ['label' => 'In Review', 'statuses' => ['in_review']],
  'resolved' => ['label' => 'Resolved', 'statuses' => ['resolved']],
  'confirmed' => ['label' => 'Confirmed', 'statuses' => ['confirmed']],
  'rejected' => ['label' => 'Rejected', 'statuses' => ['rejected']],
  'all' => ['label' => 'All', 'statuses' => null],
];

$selectedComplaintFilter = $_GET['complaint_status'] ?? 'open';
if (!isset($complaintFilterOptions[$selectedComplaintFilter])) {
  $selectedComplaintFilter = 'open';
}
$selectedFilterStatuses = $complaintFilterOptions[$selectedComplaintFilter]['statuses'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/payroll/index');
    exit;
  }

  $redirectFilter = $_POST['redirect_filter'] ?? $selectedComplaintFilter;
  if (!isset($complaintFilterOptions[$redirectFilter])) {
    $redirectFilter = 'open';
  }
  $redirectUrl = BASE_URL . '/modules/payroll/index';
  if ($redirectFilter !== 'open') {
    $redirectUrl .= '?complaint_status=' . urlencode($redirectFilter);
  }

  $action = $_POST['action'] ?? '';
  switch ($action) {
    case 'complaint_update':
      $complaintId = (int)($_POST['complaint_id'] ?? 0);
      $newStatus = strtolower((string)($_POST['status'] ?? 'pending')) ?: 'pending';
      if (!isset($complaintStatusLabels[$newStatus])) {
        $newStatus = 'pending';
      }
      $notes = trim($_POST['notes'] ?? ($_POST['resolution_notes'] ?? ''));

      if ($complaintId <= 0) {
        flash_error('Complaint record not found.');
        header('Location: ' . $redirectUrl);
        exit;
      }

      $targetComplaint = payroll_get_complaint($pdo, $complaintId);
      if (!$targetComplaint) {
        flash_error('Complaint record not found.');
        header('Location: ' . $redirectUrl);
        exit;
      }

      $authComplUpdate = ensure_action_authorized('payroll', 'complaint_update', 'write');
      if (!$authComplUpdate['ok']) {
        flash_error('Complaint updates require an authorized override.');
        header('Location: ' . $redirectUrl);
        exit;
      }

      $result = ['ok' => false, 'error' => null, 'status' => $newStatus];
      switch ($newStatus) {
        case 'in_review':
          $result = payroll_mark_complaint_in_review($pdo, $complaintId, $currentUserId, $notes ?: null);
          break;
        case 'resolved':
        case 'rejected':
          $resolutionData = [
            'status' => $newStatus,
            'notes' => $notes,
            'adjustment_amount' => isset($_POST['adjustment_amount']) ? (float)$_POST['adjustment_amount'] : 0,
            'adjustment_type' => $_POST['adjustment_type'] ?? 'earning',
            'adjustment_label' => trim((string)($_POST['adjustment_label'] ?? '')) ?: null,
            'adjustment_code' => trim((string)($_POST['adjustment_code'] ?? '')) ?: null,
            'adjustment_notes' => trim((string)($_POST['adjustment_notes'] ?? '')) ?: null,
            'effective_start' => $_POST['adjustment_effective_start'] ?? null,
            'effective_end' => $_POST['adjustment_effective_end'] ?? null,
          ];
          $result = payroll_resolve_complaint($pdo, $complaintId, $resolutionData, $currentUserId);
          break;
        case 'confirmed':
          $result = payroll_confirm_complaint($pdo, $complaintId, $currentUserId, $notes ?: null);
          break;
        default:
          $result = ['ok' => payroll_update_complaint_status($pdo, $complaintId, $newStatus, $notes ?: null, ['acting_user_id' => $currentUserId]), 'status' => $newStatus];
          break;
      }

      if (empty($result['ok'])) {
        $errorMsg = $result['error'] ?? 'Unable to update complaint status.';
        // Log the error for debugging
        sys_log('PAYROLL-COMPLAINT-UPDATE-FAIL', 'Complaint status update failed: ' . $errorMsg, [
          'module' => 'payroll',
          'file' => __FILE__,
          'line' => __LINE__,
          'context' => [
            'complaint_id' => $complaintId,
            'new_status' => $newStatus,
            'result' => $result,
          ],
        ]);
        flash_error($errorMsg);
        header('Location: ' . $redirectUrl);
        exit;
      }

      $finalStatus = $result['status'] ?? $newStatus;

      action_log('payroll', 'complaint_updated', 'success', [
        'complaint_id' => $complaintId,
        'run_id' => $targetComplaint['run_id'] ?? null,
        'status' => $finalStatus,
      ]);
      flash_success('Complaint updated.');
      header('Location: ' . $redirectUrl);
      exit;

    default:
      flash_error('Unsupported action.');
      header('Location: ' . $redirectUrl);
      exit;
  }
}

$runs = payroll_list_runs($pdo, 50);
$branches = payroll_get_branches($pdo);
$branchCount = count($branches) ?: 1;

$runSummaries = [];
$summaryTotals = [
  'runs' => count($runs),
  'awaitingApproval' => 0,
  'runsApproved' => 0,
  'openComplaints' => 0,
  'totalComplaints' => 0,
  'pendingApproverNames' => [],
];

if ($runs) {
  $submissionStmt = $pdo->prepare('SELECT status FROM payroll_branch_submissions WHERE payroll_run_id = :id');
  foreach ($runs as $run) {
    $submissionStmt->execute([':id' => $run['id']]);
    $subs = $submissionStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $submitted = 0;
    $incompleteBranches = 0;
    foreach ($subs as $st) {
      if (in_array($st, ['submitted','accepted'], true)) { $submitted++; }
      else { $incompleteBranches++; }
    }
    $progress = $branchCount > 0 ? $submitted . '/' . $branchCount : '-';

    $approvals = payroll_get_run_approvals($pdo, (int)$run['id']);
    $batchCounts = payroll_batch_status_counts($pdo, (int)$run['id']);
    $batchesSummary = '';
    if ($batchCounts) {
      $approvedB = (int)($batchCounts['approved'] ?? 0);
      $pendingB = (int)($batchCounts['pending'] ?? 0) + (int)($batchCounts['awaiting_dtr'] ?? 0) + (int)($batchCounts['for_review'] ?? 0) + (int)($batchCounts['for_revision'] ?? 0) + (int)($batchCounts['computing'] ?? 0) + (int)($batchCounts['error'] ?? 0);
      $batchesSummary = $approvedB . ' approved' . ($pendingB > 0 ? (' • ' . $pendingB . ' in-progress') : '');
    }
    $approvalCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'skipped' => 0];
    $pendingApprovers = [];
    $latestApproval = null;
    foreach ($approvals as $approval) {
      $statusKey = strtolower((string)$approval['status']);
      if (isset($approvalCounts[$statusKey])) { $approvalCounts[$statusKey]++; }
      if ($statusKey === 'pending') {
        $pendingApprovers[] = $approval['approver_name'] ?: 'Approver #' . $approval['step_order'];
      }
      if ($statusKey === 'approved') {
        if (!$latestApproval || (int)$approval['step_order'] >= (int)$latestApproval['step_order']) {
          $latestApproval = $approval;
        }
      }
      if ($statusKey === 'rejected') {
        $latestApproval = $approval; // rejected overrides
      }
    }
    $certifiedBy = $latestApproval && strtolower($latestApproval['status']) === 'approved'
      ? ($latestApproval['approver_name'] ?: 'Step ' . $latestApproval['step_order'])
      : '-';
    $finalizedBy = $certifiedBy !== '-' ? $certifiedBy : null;

  $complaints = payroll_get_complaints($pdo, (int)$run['id']);
  $complaintCounts = ['pending' => 0, 'in_review' => 0, 'resolved' => 0, 'confirmed' => 0, 'rejected' => 0];
    foreach ($complaints as $complaint) {
      $cStatus = strtolower((string)$complaint['status']);
      if (isset($complaintCounts[$cStatus])) { $complaintCounts[$cStatus]++; }
    }
  $openComplaints = $complaintCounts['pending'] + $complaintCounts['in_review'];

    $releaseReady = $run['status'] !== 'released'
      && $run['status'] === 'approved'
      && $approvalCounts['pending'] === 0
      && $approvalCounts['rejected'] === 0
      && $openComplaints === 0
      && $incompleteBranches === 0;

    $badges = [];
    if ($submitted >= $branchCount) { $badges[] = 'Branches Complete'; }
    if ($approvalCounts['rejected'] > 0) { $badges[] = 'Approval Blocked'; }
    if ($approvalCounts['pending'] > 0 && $approvalCounts['rejected'] === 0) { $badges[] = 'Awaiting Approval'; }
    if (in_array($run['status'], ['approved','released'], true)) { $badges[] = 'Run Certified'; }
    if ($openComplaints > 0) { $badges[] = 'Complaints Open'; }
    if ($run['status'] === 'released') { $badges[] = 'Released'; }
    elseif ($releaseReady) { $badges[] = 'Ready for Release'; }
    if (!$badges) { $badges[] = 'On Track'; }

    $runSummaries[] = [
      'run' => $run,
      'progress' => $progress,
      'approvalCounts' => $approvalCounts,
      'pendingApprovers' => $pendingApprovers,
      'certifiedBy' => $certifiedBy,
      'finalizedBy' => $finalizedBy,
      'complaintCounts' => $complaintCounts,
      'openComplaints' => $openComplaints,
      'badges' => $badges,
      'releaseReady' => $releaseReady,
      'incompleteBranches' => $incompleteBranches,
      'batchCounts' => $batchCounts,
      'batchesSummary' => $batchesSummary,
    ];

    if ($approvalCounts['pending'] > 0) {
      $summaryTotals['awaitingApproval']++;
      if ($pendingApprovers) {
        $summaryTotals['pendingApproverNames'] = array_merge($summaryTotals['pendingApproverNames'], $pendingApprovers);
      }
    }
    if (in_array($run['status'], ['approved','released'], true)) {
      $summaryTotals['runsApproved']++;
    }
    if ($openComplaints > 0) {
      $summaryTotals['openComplaints'] += $openComplaints;
    }
    $summaryTotals['totalComplaints'] += array_sum($complaintCounts);
  }
  $summaryTotals['pendingApproverNames'] = array_slice(array_unique($summaryTotals['pendingApproverNames']), 0, 5);
}
$complianceSummaryBadges = [];
if ($summaryTotals['awaitingApproval'] > 0) {
  $complianceSummaryBadges[] = $summaryTotals['awaitingApproval'] . ' run(s) awaiting approval';
}
if ($summaryTotals['openComplaints'] > 0) {
  $complianceSummaryBadges[] = $summaryTotals['openComplaints'] . ' open complaint(s)';
}
if ($summaryTotals['runsApproved'] > 0) {
  $complianceSummaryBadges[] = $summaryTotals['runsApproved'] . ' run(s) certified';
}
if (!$complianceSummaryBadges) {
  $complianceSummaryBadges[] = 'All payroll runs are on track';
}

$complaintTotals = payroll_complaint_status_totals($pdo);
$openComplaintTotal = ($complaintTotals['pending'] ?? 0) + ($complaintTotals['in_review'] ?? 0);
$resolvedComplaintTotal = $complaintTotals['resolved'] ?? 0;
$confirmedComplaintTotal = $complaintTotals['confirmed'] ?? 0;
$rejectedComplaintTotal = $complaintTotals['rejected'] ?? 0;
$allComplaintTotal = array_sum($complaintTotals);
$complaintRows = payroll_list_complaints($pdo, [
  'statuses' => $selectedFilterStatuses,
  'limit' => 75,
]);
$selectedComplaintCount = count($complaintRows);
$selectedComplaintLabel = $complaintFilterOptions[$selectedComplaintFilter]['label'];
$csrf = csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="space-y-5">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="flex items-center gap-3">
      <a class="btn btn-outline" href="<?= BASE_URL ?>/index">Back</a>
      <div>
        <h1 class="text-xl font-bold text-slate-900">Payroll Management</h1>
        <p class="text-sm text-slate-500 mt-0.5">Track payroll runs, branch submissions, approvals, and employee complaints.</p>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/payroll/run_create">+ New Payroll Run</a>
    </div>
  </div>

  <!-- Run Health Stats Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= (int)$summaryTotals['runs'] ?></div>
        <div class="text-xs text-slate-500">Active Runs</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-amber-600"><?= (int)$summaryTotals['awaitingApproval'] ?></div>
        <div class="text-xs text-slate-500">Awaiting Approval</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-emerald-600"><?= (int)$summaryTotals['runsApproved'] ?></div>
        <div class="text-xs text-slate-500">Certified Runs</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-red-600"><?= (int)$summaryTotals['openComplaints'] ?></div>
        <div class="text-xs text-slate-500">Open Complaints</div>
      </div>
    </div>
  </div>

  <?php if ($summaryTotals['pendingApproverNames']): ?>
  <div class="flex flex-wrap gap-2 items-center">
    <span class="text-xs text-slate-500">Pending with:</span>
    <?php foreach ($summaryTotals['pendingApproverNames'] as $name): ?>
      <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2.5 py-0.5 text-xs font-medium text-amber-700"><?= htmlspecialchars($name) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Payroll Runs Table -->
  <div class="card">
    <div class="card-header flex items-center justify-between flex-wrap gap-2">
      <span class="font-semibold text-slate-800">Payroll Runs <span class="text-sm font-normal text-slate-500">(<?= count($runs) ?> showing)</span></span>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($complianceSummaryBadges as $badge): ?>
          <span class="inline-flex items-center rounded-full bg-indigo-50 border border-indigo-200 px-2.5 py-0.5 text-xs font-medium text-indigo-700"><?= htmlspecialchars($badge) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="overflow-x-auto">
        <table class="table-basic w-full">
          <thead>
            <tr>
              <th>Period</th>
              <th>Status & Certification</th>
              <th class="hidden lg:table-cell">Approvals</th>
              <th class="hidden lg:table-cell">Complaints</th>
              <th class="hidden md:table-cell">Branches</th>
              <th class="hidden lg:table-cell">Compliance</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($runSummaries)): ?>
              <tr><td colspan="7" class="text-center py-8 text-sm text-slate-500">No payroll runs yet. Click "New Payroll Run" to start.</td></tr>
            <?php else: ?>
              <?php foreach ($runSummaries as $summary): ?>
                <?php
                  $run = $summary['run'];
                  $createdAtDisplay = format_datetime_display($run['created_at'] ?? null, true, '');
                  $releasedAtDisplay = format_datetime_display($run['released_at'] ?? null, false, '');
                  $runStatusClass = 'bg-slate-100 text-slate-700';
                  if ($run['status'] === 'released') $runStatusClass = 'bg-emerald-100 text-emerald-700';
                  elseif ($run['status'] === 'approved') $runStatusClass = 'bg-indigo-100 text-indigo-700';
                  elseif (in_array($run['status'], ['pending','draft'])) $runStatusClass = 'bg-amber-100 text-amber-700';
                ?>
                <tr class="hover:bg-slate-50">
                  <td class="whitespace-nowrap font-medium">
                    <?= htmlspecialchars(date('M d', strtotime($run['period_start'])) . ' - ' . date('M d, Y', strtotime($run['period_end']))) ?>
                  </td>
                  <td>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $runStatusClass ?>">
                      <?= htmlspecialchars(ucfirst($run['status'])) ?>
                    </span>
                    <div class="text-xs text-slate-500 mt-1">By <?= htmlspecialchars($run['generated_by_name'] ?: 'System') ?></div>
                    <div class="text-xs text-slate-500">Certified: <?= htmlspecialchars($summary['certifiedBy']) ?></div>
                    <?php if (!empty($summary['batchesSummary'])): ?>
                      <div class="text-xs text-slate-400">Batches: <?= htmlspecialchars($summary['batchesSummary']) ?></div>
                    <?php endif; ?>
                    <?php if ($run['status'] === 'released'): ?>
                      <div class="text-xs text-emerald-600 mt-1">Released <?= $releasedAtDisplay ? htmlspecialchars($releasedAtDisplay) : '' ?>
                        <?php if (!empty($run['released_by_name'])): ?> by <?= htmlspecialchars($run['released_by_name']) ?><?php endif; ?>
                      </div>
                    <?php elseif (!empty($summary['releaseReady'])): ?>
                      <div class="text-xs text-emerald-600 mt-1">Ready for release</div>
                    <?php endif; ?>
                  </td>
                  <td class="hidden lg:table-cell">
                    <div class="text-sm"><?= (int)$summary['approvalCounts']['approved'] ?> approved</div>
                    <div class="text-sm"><?= (int)$summary['approvalCounts']['pending'] ?> pending</div>
                    <?php if ($summary['pendingApprovers']): ?>
                      <div class="text-xs text-slate-500">Next: <?= htmlspecialchars(implode(', ', $summary['pendingApprovers'])) ?></div>
                    <?php endif; ?>
                    <?php if ((int)$summary['approvalCounts']['rejected'] > 0): ?>
                      <div class="text-xs text-red-600 font-semibold">Rejections present</div>
                    <?php endif; ?>
                  </td>
                  <td class="hidden lg:table-cell">
                    <div class="text-sm"><?= (int)$summary['complaintCounts']['pending'] ?> pending</div>
                    <div class="text-sm"><?= (int)$summary['complaintCounts']['in_review'] ?> in review</div>
                    <div class="text-sm"><?= (int)$summary['complaintCounts']['resolved'] ?> resolved</div>
                  </td>
                  <td class="hidden md:table-cell font-medium text-indigo-600"><?= htmlspecialchars($summary['progress']) ?></td>
                  <td class="hidden lg:table-cell">
                    <div class="flex flex-wrap gap-1">
                      <?php foreach ($summary['badges'] as $badge): ?>
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"><?= htmlspecialchars($badge) ?></span>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td>
                    <div class="flex flex-col gap-1">
                      <a class="text-indigo-600 hover:text-indigo-800 text-xs font-medium" href="<?= BASE_URL ?>/modules/payroll/run_view?id=<?= $run['id'] ?>">Open</a>
                      <?php if (!empty($summary['releaseReady'])): ?>
                        <a class="text-emerald-600 hover:text-emerald-800 text-xs font-medium" href="<?= BASE_URL ?>/modules/payroll/run_view?id=<?= $run['id'] ?>#release">Release</a>
                      <?php elseif ($run['status'] === 'released'): ?>
                        <span class="text-xs text-emerald-600">Released</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Complaint Tracker -->
  <div class="card">
    <div class="card-header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <span class="font-semibold text-slate-800">Complaint Tracker</span>
        <p class="text-xs text-slate-500 mt-0.5">Monitor payroll concerns across runs and resolve them before release.</p>
      </div>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ($complaintFilterOptions as $filterKey => $filterData): ?>
          <?php
            $filterCount = 0;
            switch ($filterKey) {
              case 'open': $filterCount = $openComplaintTotal; break;
              case 'pending': $filterCount = $complaintTotals['pending'] ?? 0; break;
              case 'in_review': $filterCount = $complaintTotals['in_review'] ?? 0; break;
              case 'resolved': $filterCount = $resolvedComplaintTotal; break;
              case 'rejected': $filterCount = $rejectedComplaintTotal; break;
              case 'confirmed': $filterCount = $confirmedComplaintTotal; break;
              default: $filterCount = $allComplaintTotal;
            }
            $isActiveFilter = $filterKey === $selectedComplaintFilter;
            $filterUrl = BASE_URL . '/modules/payroll/index' . ($filterKey !== 'open' ? '?complaint_status=' . urlencode($filterKey) : '');
            $filterClasses = $isActiveFilter
              ? 'bg-indigo-600 text-white'
              : 'bg-slate-100 text-slate-700 hover:bg-slate-200';
          ?>
          <a class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition <?= $filterClasses ?>" href="<?= $filterUrl ?>">
            <?= htmlspecialchars($filterData['label']) ?>
            <span class="text-[10px] font-normal opacity-80"><?= (int)$filterCount ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Complaint Stats Row -->
    <div class="px-5 py-3 border-b border-slate-100">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div>
          <span class="text-xs text-slate-500">Open</span>
          <span class="ml-1 font-bold text-amber-600"><?= (int)$openComplaintTotal ?></span>
        </div>
        <div>
          <span class="text-xs text-slate-500">Pending</span>
          <span class="ml-1 font-bold text-slate-900"><?= (int)($complaintTotals['pending'] ?? 0) ?></span>
        </div>
        <div>
          <span class="text-xs text-slate-500">In Review</span>
          <span class="ml-1 font-bold text-blue-600"><?= (int)($complaintTotals['in_review'] ?? 0) ?></span>
        </div>
        <div>
          <span class="text-xs text-slate-500">Resolved</span>
          <span class="ml-1 font-bold text-emerald-600"><?= (int)$resolvedComplaintTotal ?></span>
        </div>
      </div>
    </div>

    <div class="card-body p-0">
      <?php if ($complaintRows): ?>
      <div class="overflow-x-auto">
        <table class="table-basic w-full">
          <thead>
            <tr>
              <th class="hidden md:table-cell">Run</th>
              <th>Employee & Issue</th>
              <th>Priority</th>
              <th>Status</th>
              <th class="hidden md:table-cell">Filed</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($complaintRows as $complaint): ?>
            <?php
              $runId = (int)($complaint['run_id'] ?? $complaint['payroll_run_id'] ?? 0);
              $runLabel = (!empty($complaint['period_start']) && !empty($complaint['period_end']))
                ? (date('M d, Y', strtotime($complaint['period_start'])) . ' - ' . date('M d, Y', strtotime($complaint['period_end'])))
                : 'Payroll Run #' . $runId;
              $runStatus = strtoupper((string)($complaint['run_status'] ?? '')) ?: 'Pending';
              $runLink = BASE_URL . '/modules/payroll/run_view?id=' . $runId;
              $categoryCode = strtolower((string)($complaint['category_code'] ?? ''));
              $subcategoryCode = strtolower((string)($complaint['subcategory_code'] ?? ''));
              $priorityCode = strtolower((string)($complaint['priority'] ?? 'normal')) ?: 'normal';
              $statusCode = strtolower((string)($complaint['status'] ?? 'pending')) ?: 'pending';
              $categoryLabel = isset($complaintCategories[$categoryCode]) ? ($complaintCategories[$categoryCode]['label'] ?? null) : null;
              $topicLabel = ($categoryLabel && isset($complaintCategories[$categoryCode]['items'][$subcategoryCode])) ? $complaintCategories[$categoryCode]['items'][$subcategoryCode] : null;
              $priorityLabel = $complaintPriorities[$priorityCode] ?? ucfirst($priorityCode);
              $statusLabel = $complaintStatusLabels[$statusCode] ?? ucfirst($statusCode);
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
                $descSnippet = substr($descSnippet, 0, 137) . '...';
              }
              $resolutionSnippet = trim((string)($complaint['resolution_notes'] ?? ''));
              if ($resolutionSnippet !== '' && strlen($resolutionSnippet) > 120) {
                $resolutionSnippet = substr($resolutionSnippet, 0, 117) . '...';
              }
              $priorityBadgeClass = 'bg-blue-100 text-blue-700';
              if ($priorityCode === 'urgent') {
                $priorityBadgeClass = 'bg-red-100 text-red-700';
              } elseif ($priorityCode !== 'normal') {
                $priorityBadgeClass = 'bg-slate-100 text-slate-700';
              }
              $statusBadgeClass = 'bg-gray-100 text-gray-700';
              if ($statusCode === 'pending') {
                $statusBadgeClass = 'bg-amber-100 text-amber-700';
              } elseif ($statusCode === 'in_review') {
                $statusBadgeClass = 'bg-sky-100 text-sky-700';
              } elseif ($statusCode === 'resolved') {
                $statusBadgeClass = 'bg-emerald-100 text-emerald-700';
              } elseif ($statusCode === 'confirmed') {
                $statusBadgeClass = 'bg-indigo-100 text-indigo-700';
              } elseif ($statusCode === 'rejected') {
                $statusBadgeClass = 'bg-rose-100 text-rose-700';
              }
            ?>
            <tr class="hover:bg-slate-50 align-top">
              <td class="hidden md:table-cell">
                <div class="text-sm font-medium text-slate-900"><?= htmlspecialchars($runLabel) ?></div>
                <div class="text-xs text-slate-500 mt-1">Status: <?= htmlspecialchars($runStatus) ?></div>
              </td>
              <td>
                <div class="text-sm font-medium text-slate-900"><?= htmlspecialchars($employeeLabel) ?></div>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                  <?php if ($categoryLabel): ?>
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700"><?= htmlspecialchars($categoryLabel) ?></span>
                  <?php endif; ?>
                  <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700"><?= htmlspecialchars($summaryTopic) ?></span>
                </div>
                <?php if ($descSnippet !== ''): ?>
                  <div class="mt-2 text-xs text-slate-600"><?= htmlspecialchars($descSnippet) ?></div>
                <?php endif; ?>
                <?php if ($resolutionSnippet !== ''): ?>
                  <div class="mt-2 text-xs text-slate-500">Resolution: <?= htmlspecialchars($resolutionSnippet) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $priorityBadgeClass ?>"><?= htmlspecialchars($priorityLabel) ?></span>
              </td>
              <td>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $statusBadgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                <?php if ($resolvedAtLabel !== '—'): ?>
                  <div class="text-xs text-slate-500 mt-1">Resolved <?= htmlspecialchars($resolvedAtLabel) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm text-slate-600 hidden md:table-cell"><?= htmlspecialchars($submittedAtLabel) ?></td>
              <td>
                <div class="flex flex-col gap-2">
                  <button type="button"
                    class="btn btn-outline text-xs"
                    data-payroll-complaint-open
                    data-complaint-id="<?= (int)$complaint['id'] ?>"
                    data-run-label="<?= htmlspecialchars($runLabel, ENT_QUOTES) ?>"
                    data-run-status="<?= htmlspecialchars($runStatus, ENT_QUOTES) ?>"
                    data-run-link="<?= htmlspecialchars($runLink, ENT_QUOTES) ?>"
                    data-employee="<?= htmlspecialchars($employeeLabel, ENT_QUOTES) ?>"
                    data-category="<?= htmlspecialchars($categoryLabel ?: '—', ENT_QUOTES) ?>"
                    data-topic="<?= htmlspecialchars($summaryTopic, ENT_QUOTES) ?>"
                    data-issue="<?= htmlspecialchars($complaint['issue_type'] ?: $summaryTopic, ENT_QUOTES) ?>"
                    data-priority-label="<?= htmlspecialchars($priorityLabel, ENT_QUOTES) ?>"
                    data-priority-code="<?= htmlspecialchars($priorityCode, ENT_QUOTES) ?>"
                    data-status="<?= htmlspecialchars($statusCode, ENT_QUOTES) ?>"
                    data-status-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>"
                    data-submitted="<?= htmlspecialchars($submittedAtLabel, ENT_QUOTES) ?>"
                    data-resolved="<?= htmlspecialchars($resolvedAtLabel, ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($complaint['description'] ?? '', ENT_QUOTES) ?>"
                    data-review-notes="<?= htmlspecialchars($complaint['review_notes'] ?? '', ENT_QUOTES) ?>"
                    data-resolution-notes="<?= htmlspecialchars($complaint['resolution_notes'] ?? '', ENT_QUOTES) ?>"
                    data-confirmation-notes="<?= htmlspecialchars($complaint['confirmation_notes'] ?? '', ENT_QUOTES) ?>"
                    data-adjustment-type="<?= htmlspecialchars((string)($complaint['adjustment_type'] ?? ''), ENT_QUOTES) ?>"
                    data-adjustment-amount="<?= htmlspecialchars($complaint['adjustment_amount'] !== null ? number_format((float)$complaint['adjustment_amount'], 2, '.', '') : '', ENT_QUOTES) ?>"
                    data-adjustment-label="<?= htmlspecialchars($complaint['adjustment_label'] ?? '', ENT_QUOTES) ?>"
                    data-adjustment-code="<?= htmlspecialchars($complaint['adjustment_code'] ?? '', ENT_QUOTES) ?>"
                    data-adjustment-notes="<?= htmlspecialchars($complaint['adjustment_notes'] ?? '', ENT_QUOTES) ?>"
                    data-adjustment-start="<?= htmlspecialchars($complaint['adjustment_effective_start'] ?? '', ENT_QUOTES) ?>"
                    data-adjustment-end="<?= htmlspecialchars($complaint['adjustment_effective_end'] ?? '', ENT_QUOTES) ?>"
                  >View Details</button>
                  <a class="text-indigo-600 hover:text-indigo-800 text-xs font-medium" href="<?= htmlspecialchars($runLink) ?>">Open Run</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="px-5 py-8 text-center text-sm text-slate-500">No complaints match the selected filter.</div>
    <?php endif; ?>
    </div>
  </div>
</div>

<div id="payrollComplaintModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-close></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl">
      <div class="flex items-center justify-between px-4 py-3 border-b">
        <h3 class="font-semibold text-lg">Complaint Details</h3>
        <button type="button" class="text-gray-500 hover:text-gray-700" data-close aria-label="Close">Close</button>
      </div>
      <div class="p-4 text-sm text-gray-700 space-y-4">
        <div class="grid md:grid-cols-2 gap-3">
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Run</p>
            <p id="payrollComplaintRun" class="font-semibold text-gray-800">—</p>
            <a id="payrollComplaintRunLink" class="text-xs text-blue-600" href="#" target="_blank" rel="noopener">Open run</a>
            <p id="payrollComplaintRunStatus" class="text-xs text-gray-500 mt-1">—</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Employee</p>
            <p id="payrollComplaintEmployee" class="font-semibold text-gray-800">—</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Category</p>
            <p id="payrollComplaintCategory" class="text-gray-800">—</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Topic</p>
            <p id="payrollComplaintTopic" class="text-gray-800">—</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Priority</p>
            <span id="payrollComplaintPriority" data-base-class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700">Normal</span>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Status</p>
            <span id="payrollComplaintStatus" data-base-class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">Pending</span>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Filed</p>
            <p id="payrollComplaintSubmitted" class="text-gray-800">—</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Resolved</p>
            <p id="payrollComplaintResolved" class="text-gray-800">—</p>
          </div>
          <div class="md:col-span-2">
            <p class="text-xs uppercase tracking-wide text-gray-500">Issue Label</p>
            <p id="payrollComplaintIssue" class="text-gray-800">—</p>
          </div>
        </div>
        <div class="bg-gray-50 rounded p-3">
          <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Description</p>
          <p id="payrollComplaintDescription" class="whitespace-pre-wrap text-gray-800">—</p>
        </div>
        <div class="bg-gray-50 rounded p-3">
          <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Current Resolution Notes</p>
          <p id="payrollComplaintResolution" class="whitespace-pre-wrap text-gray-800">—</p>
        </div>
        <form method="post" id="payrollComplaintForm" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="complaint_update" />
          <input type="hidden" name="complaint_id" value="" />
          <input type="hidden" name="redirect_filter" value="<?= htmlspecialchars($selectedComplaintFilter) ?>" />
          <label class="block">
            <span class="text-xs uppercase tracking-wide text-gray-500 mb-1">Update Status</span>
            <select name="status" class="input-text w-full" data-status-field>
              <?php foreach ($complaintStatusLabels as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-wide text-gray-500 mb-1" data-notes-label>Notes</span>
            <textarea name="notes" rows="3" class="input-text w-full" placeholder="Add reviewer notes or resolution details"></textarea>
          </label>
          <div class="resolution-fields hidden grid md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Adjustment Type</label>
              <select name="adjustment_type" class="input-text w-full">
                <option value="earning">Earning</option>
                <option value="deduction">Deduction</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Adjustment Amount</label>
              <input type="number" name="adjustment_amount" step="0.01" min="0" class="input-text w-full" placeholder="0.00" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Effective Start</label>
              <input type="date" name="adjustment_effective_start" class="input-text w-full" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Effective End</label>
              <input type="date" name="adjustment_effective_end" class="input-text w-full" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Adjustment Label</label>
              <input type="text" name="adjustment_label" class="input-text w-full" placeholder="e.g. Overtime Correction" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Adjustment Code (optional)</label>
              <input type="text" name="adjustment_code" class="input-text w-full" placeholder="Custom code" />
            </div>
          </div>
          <div class="resolution-fields hidden">
            <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Adjustment Notes</label>
            <textarea name="adjustment_notes" rows="2" class="input-text w-full" placeholder="Additional context for the payroll adjustment"></textarea>
          </div>
          <div class="confirmation-fields hidden text-xs text-gray-500 bg-indigo-50 border border-indigo-100 rounded p-2">
            Mark as confirmed only after the employee acknowledges the resolution and the adjustment is queued for the next cutoff.
          </div>
          <?php if (access_level_rank($currentAccessLevel) < access_level_rank('write')): ?>
            <div class="grid md:grid-cols-2 gap-2">
              <div>
                <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Authorizer Email</label>
                <input type="email" name="override_email" class="input-text w-full" placeholder="Required for override" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 uppercase tracking-wide mb-1">Authorizer Password</label>
                <input type="password" name="override_password" class="input-text w-full" placeholder="Required for override" />
              </div>
            </div>
          <?php endif; ?>
          <div class="flex justify-between items-center pt-2">
            <button type="button" class="btn btn-outline" data-close>Close</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  (function(){
    function initPayrollComplaintModal(){
      const modal = document.getElementById('payrollComplaintModal');
      if (!modal || modal.dataset.bound === '1') {
        return;
      }

      const buttons = document.querySelectorAll('[data-payroll-complaint-open]');
      const form = document.getElementById('payrollComplaintForm');
      const runEl = document.getElementById('payrollComplaintRun');
      const runLinkEl = document.getElementById('payrollComplaintRunLink');
      const runStatusEl = document.getElementById('payrollComplaintRunStatus');
      const employeeEl = document.getElementById('payrollComplaintEmployee');
      const categoryEl = document.getElementById('payrollComplaintCategory');
      const topicEl = document.getElementById('payrollComplaintTopic');
      const issueEl = document.getElementById('payrollComplaintIssue');
      const priorityBadge = document.getElementById('payrollComplaintPriority');
      const statusBadge = document.getElementById('payrollComplaintStatus');
      const submittedEl = document.getElementById('payrollComplaintSubmitted');
      const resolvedEl = document.getElementById('payrollComplaintResolved');
      const descriptionEl = document.getElementById('payrollComplaintDescription');
      const resolutionEl = document.getElementById('payrollComplaintResolution');
      const idField = form?.querySelector('input[name="complaint_id"]');
      const statusField = form?.querySelector('select[name="status"]');
      const notesField = form?.querySelector('textarea[name="notes"]');
      const notesLabelEl = form?.querySelector('[data-notes-label]');
      const resolutionSections = form ? Array.from(form.querySelectorAll('.resolution-fields')) : [];
      const confirmationSections = form ? Array.from(form.querySelectorAll('.confirmation-fields')) : [];
      const adjustmentTypeField = form?.querySelector('select[name="adjustment_type"]');
      const adjustmentAmountField = form?.querySelector('input[name="adjustment_amount"]');
      const adjustmentLabelField = form?.querySelector('input[name="adjustment_label"]');
      const adjustmentCodeField = form?.querySelector('input[name="adjustment_code"]');
      const adjustmentStartField = form?.querySelector('input[name="adjustment_effective_start"]');
      const adjustmentEndField = form?.querySelector('input[name="adjustment_effective_end"]');
      const adjustmentNotesField = form?.querySelector('textarea[name="adjustment_notes"]');
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
        confirmed: 'bg-indigo-100 text-indigo-700',
        rejected: 'bg-rose-100 text-rose-700'
      };

      const applyClass = (el, base, cls) => {
        if (!el) return;
        el.className = (base + ' ' + cls).trim();
      };

      const resolveNotesForStatus = (payload, status) => {
        if (!payload) {
          return '';
        }
        switch ((status || '').toLowerCase()) {
          case 'in_review':
            return payload.reviewNotes || '';
          case 'resolved':
          case 'rejected':
            return payload.resolutionNotes || '';
          case 'confirmed':
            return payload.confirmationNotes || '';
          default:
            return payload.reviewNotes || '';
        }
      };

      const updateStatusUI = (status) => {
        if (!statusField) return;
        const normalizedStatus = (status || '').toLowerCase();
        const notesMap = {
          in_review: 'Review Notes',
          resolved: 'Resolution Notes',
          rejected: 'Rejection Notes',
          confirmed: 'Confirmation Notes'
        };
        if (notesLabelEl) {
          notesLabelEl.textContent = notesMap[normalizedStatus] || 'Notes';
        }
        if (notesField) {
          const placeholders = {
            in_review: 'Capture findings and pending items',
            resolved: 'Explain the fix and adjustment details',
            rejected: 'Indicate why the complaint is rejected',
            confirmed: 'Document employee acknowledgement'
          };
          notesField.placeholder = placeholders[normalizedStatus] || 'Add reviewer notes or resolution details';
        }
        const showResolution = normalizedStatus === 'resolved';
        const showConfirmation = normalizedStatus === 'confirmed';
        resolutionSections.forEach((section) => {
          if (!section) return;
          section.classList.toggle('hidden', !showResolution);
        });
        confirmationSections.forEach((section) => {
          if (!section) return;
          section.classList.toggle('hidden', !showConfirmation);
        });
      };

      const populateFromDataset = (ds) => {
        if (!ds || !form) return;
        const statusValue = ds.status || 'pending';
        if (statusField) {
          statusField.value = statusValue;
        }
        if (notesField) {
          notesField.value = resolveNotesForStatus(ds, statusValue);
          if (form) {
            form.dataset.lastNotesValue = notesField.value || '';
            form.dataset.lastNotesDirty = '0';
          }
        }
        if (adjustmentTypeField) {
          adjustmentTypeField.value = ds.adjustmentType || 'earning';
        }
        if (adjustmentAmountField) {
          adjustmentAmountField.value = ds.adjustmentAmount || '';
        }
        if (adjustmentLabelField) {
          adjustmentLabelField.value = ds.adjustmentLabel || '';
        }
        if (adjustmentCodeField) {
          adjustmentCodeField.value = ds.adjustmentCode || '';
        }
        if (adjustmentNotesField) {
          adjustmentNotesField.value = ds.adjustmentNotes || '';
        }
        if (adjustmentStartField) {
          adjustmentStartField.value = ds.adjustmentStart || '';
        }
        if (adjustmentEndField) {
          adjustmentEndField.value = ds.adjustmentEnd || '';
        }
        updateStatusUI(statusValue);
      };

      buttons.forEach((btn) => {
        if (btn.dataset.bound === '1') {
          return;
        }
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
          const ds = btn.dataset;
          console.log('Opening complaint modal with data:', ds); // Debug log
          if (runEl) runEl.textContent = ds.runLabel || '—';
          if (runStatusEl) runStatusEl.textContent = ds.runStatus ? ('Run Status: ' + ds.runStatus) : '—';
          if (runLinkEl) {
            if (ds.runLink) {
              runLinkEl.href = ds.runLink;
              runLinkEl.classList.remove('hidden');
            } else {
              runLinkEl.href = '#';
              runLinkEl.classList.add('hidden');
            }
          }
          if (employeeEl) employeeEl.textContent = ds.employee || '—';
          if (categoryEl) categoryEl.textContent = ds.category || '—';
          if (topicEl) topicEl.textContent = ds.topic || '—';
          if (issueEl) issueEl.textContent = ds.issue || '—';
          if (priorityBadge) {
            priorityBadge.textContent = ds.priorityLabel || 'Normal';
            const priorityCode = ds.priorityCode || 'normal';
            const cls = priorityClassMap[priorityCode] || 'bg-slate-100 text-slate-700';
            applyClass(priorityBadge, priorityBase, cls);
          }
          if (statusBadge) {
            statusBadge.textContent = ds.statusLabel || (ds.status || 'Pending');
            const statusCode = ds.status || 'pending';
            const cls = statusClassMap[statusCode] || 'bg-gray-100 text-gray-700';
            applyClass(statusBadge, statusBase, cls);
          }
          if (submittedEl) submittedEl.textContent = ds.submitted || '—';
          if (resolvedEl) resolvedEl.textContent = ds.resolved || '—';
          if (descriptionEl) descriptionEl.textContent = ds.description || '—';
          if (resolutionEl) resolutionEl.textContent = ds.resolutionNotes || '—';
          if (idField) idField.value = ds.complaintId || '';
          const payload = {
            status: ds.status || 'pending',
            reviewNotes: ds.reviewNotes || '',
            resolutionNotes: ds.resolutionNotes || '',
            confirmationNotes: ds.confirmationNotes || '',
            adjustmentType: ds.adjustmentType || 'earning',
            adjustmentAmount: ds.adjustmentAmount || '',
            adjustmentLabel: ds.adjustmentLabel || '',
            adjustmentCode: ds.adjustmentCode || '',
            adjustmentNotes: ds.adjustmentNotes || '',
            adjustmentStart: ds.adjustmentStart || '',
            adjustmentEnd: ds.adjustmentEnd || ''
          };
          if (form) {
            form.dataset.activeComplaint = JSON.stringify(payload);
          }
          populateFromDataset(payload);
          openModal('payrollComplaintModal');
        });
      });

      if (statusField) {
        statusField.addEventListener('change', (e) => {
          const statusVal = e.target.value || '';
          updateStatusUI(statusVal);
          if (!notesField || !form || !form.dataset.activeComplaint) {
            return;
          }
          let ds = null;
          try {
            ds = JSON.parse(form.dataset.activeComplaint);
          } catch (err) {
            ds = null;
          }
          if (!ds) {
            return;
          }
          const baseline = form.dataset.lastNotesValue ?? '';
          const dirty = form.dataset.lastNotesDirty === '1';
          if (dirty && notesField.value !== baseline) {
            return;
          }
          const nextValue = resolveNotesForStatus(ds, statusVal);
          notesField.value = nextValue;
          form.dataset.lastNotesValue = nextValue || '';
          form.dataset.lastNotesDirty = '0';
        });
      }

      if (notesField && form) {
        notesField.addEventListener('input', () => {
          const baseline = form.dataset.lastNotesValue ?? '';
          form.dataset.lastNotesDirty = notesField.value === baseline ? '0' : '1';
        });
      }

      modal.addEventListener('click', (e) => {
        const target = e.target;
        if (target && target.dataset && target.dataset.close !== undefined) {
          closeModal('payrollComplaintModal');
        }
      });

      if (!window.__payrollComplaintModalEscBound) {
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal('payrollComplaintModal');
          }
        });
        window.__payrollComplaintModalEscBound = true;
      }

      modal.dataset.bound = '1';
    }

    document.addEventListener('DOMContentLoaded', () => {
      initPayrollComplaintModal();
    });
    document.addEventListener('spa:loaded', () => {
      initPayrollComplaintModal();
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
