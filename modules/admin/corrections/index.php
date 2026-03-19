<?php
/**
 * Data Correction Requests — Admin Review
 * Review, approve, or reject employee data correction requests
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('compliance', 'data_corrections', 'read');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$canWrite = user_has_access($uid, 'compliance', 'data_corrections', 'write');

// Handle POST — approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNotes = trim($_POST['review_notes'] ?? '');

    if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
        flash_error('Invalid action.');
        header('Location: ' . BASE_URL . '/modules/admin/corrections/index');
        exit;
    }

    // Fetch the request
    $reqStmt = $pdo->prepare("SELECT * FROM data_correction_requests WHERE id = :id AND status = 'pending'");
    $reqStmt->execute([':id' => $requestId]);
    $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        flash_error('Correction request not found or already processed.');
        header('Location: ' . BASE_URL . '/modules/admin/corrections/index');
        exit;
    }

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

    try {
        $pdo->beginTransaction();

        // Update request status
        $upd = $pdo->prepare("
            UPDATE data_correction_requests
            SET status = :status, reviewed_by = :uid, reviewed_at = NOW(), review_notes = :notes, updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':status' => $newStatus,
            ':uid' => $uid,
            ':notes' => $reviewNotes,
            ':id' => $requestId,
        ]);

        // If approved, apply the correction to the employee record
        if ($action === 'approve') {
            $field = $request['field_name'];
            $empId = (int)$request['employee_id'];

            // Safety: only allow whitelisted fields
            $allowedFields = [
                'first_name', 'last_name', 'middle_name', 'date_of_birth', 'gender', 'civil_status', 'nationality',
                'email', 'phone', 'address', 'city', 'province', 'zip_code',
                'tin', 'sss_number', 'philhealth_number', 'pagibig_number',
                'employee_code', 'hire_date', 'employment_type',
                'bank_account_number', 'bank_name',
            ];

            if (in_array($field, $allowedFields, true)) {
                $newValue = $request['requested_value'];

                // Encrypt if it's an encrypted field
                if (function_exists('encrypted_employee_fields') && in_array($field, encrypted_employee_fields(), true)) {
                    if (function_exists('encrypt_field')) {
                        $newValue = encrypt_field($newValue);
                    }
                }

                // Get old value for audit
                $oldStmt = $pdo->prepare("SELECT " . $field . " FROM employees WHERE id = :eid");
                $oldStmt->execute([':eid' => $empId]);
                $oldValue = $oldStmt->fetchColumn();

                $updEmp = $pdo->prepare("UPDATE employees SET " . $field . " = :val WHERE id = :eid");
                $updEmp->execute([':val' => $newValue, ':eid' => $empId]);

                // Mark as applied
                $applyStmt = $pdo->prepare("UPDATE data_correction_requests SET status = 'applied' WHERE id = :id");
                $applyStmt->execute([':id' => $requestId]);

                // Audit with old/new values
                audit('data_correction_applied', json_encode([
                    'request_id' => $requestId,
                    'field' => $field,
                ]), [
                    'module' => 'compliance',
                    'action_type' => 'update',
                    'target_type' => 'employee',
                    'target_id' => $empId,
                    'old_values' => json_encode([$field => $oldValue]),
                    'new_values' => json_encode([$field => $request['requested_value']]),
                    'status' => 'success',
                ]);
            }
        }

        $pdo->commit();

        action_log('compliance', 'correction_' . $action, 'success', [
            'request_id' => $requestId,
            'employee_id' => (int)$request['employee_id'],
            'field' => $request['field_name'],
        ]);

        flash_success('Correction request ' . $newStatus . ' successfully.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        sys_log('COMPLIANCE-REVIEW', 'Correction review failed: ' . $e->getMessage(), ['module' => 'compliance']);
        flash_error('Failed to process correction request.');
    }

    header('Location: ' . BASE_URL . '/modules/admin/corrections/index');
    exit;
}

// Fetch requests
$filterStatus = $_GET['status'] ?? 'pending';
$validStatuses = ['all', 'pending', 'approved', 'rejected', 'applied'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = 'pending';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$whereClause = $filterStatus === 'all' ? '' : " AND dcr.status = :status";

$countSql = "SELECT COUNT(*) FROM data_correction_requests dcr WHERE 1=1" . $whereClause;
$countStmt = $pdo->prepare($countSql);
if ($filterStatus !== 'all') $countStmt->bindValue(':status', $filterStatus);
$countStmt->execute();
$totalRequests = (int)$countStmt->fetchColumn();
[$offset, $perPage, $page, $pages] = paginate($totalRequests, $page, $perPage);

$listSql = "
    SELECT dcr.*, e.first_name, e.last_name, e.employee_code,
           u.username AS requester_name, ru.username AS reviewer_name
    FROM data_correction_requests dcr
    JOIN employees e ON e.id = dcr.employee_id
    LEFT JOIN users u ON u.id = dcr.requested_by
    LEFT JOIN users ru ON ru.id = dcr.reviewed_by
    WHERE 1=1 {$whereClause}
    ORDER BY dcr.created_at DESC
    LIMIT :lim OFFSET :off
";
$listStmt = $pdo->prepare($listSql);
if ($filterStatus !== 'all') $listStmt->bindValue(':status', $filterStatus);
$listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$requests = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$statsStmt = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM data_correction_requests GROUP BY status
");
$stats = array_column($statsStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'status');

$statusBadge = [
    'pending' => 'bg-amber-100 text-amber-700',
    'approved' => 'bg-emerald-100 text-emerald-700',
    'rejected' => 'bg-red-100 text-red-700',
    'applied' => 'bg-blue-100 text-blue-700',
];

$pageTitle = 'Data Correction Review';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Data Correction Requests</h1>
      <p class="text-sm text-slate-500 mt-0.5">Review employee data correction requests (RA 10173 — Right to Rectification)</p>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($stats['pending'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Pending</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($stats['approved'] ?? 0) + (int)($stats['applied'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Approved</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($stats['rejected'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Rejected</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= array_sum($stats) ?></div>
        <div class="text-xs text-slate-500">Total</div>
      </div>
    </div>
  </div>

  <!-- Filter Tabs -->
  <div class="border-b border-slate-200 mb-4">
    <nav class="flex gap-4 -mb-px">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'applied' => 'Applied', 'all' => 'All'] as $key => $label): ?>
        <a href="<?= BASE_URL ?>/modules/admin/corrections/index?status=<?= $key ?>"
           class="spa px-3 py-2 text-sm font-medium border-b-2 <?= $filterStatus === $key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">
          <?= $label ?>
          <?php if (isset($stats[$key])): ?>
            <span class="ml-1 text-xs bg-slate-100 text-slate-600 rounded-full px-1.5"><?= $stats[$key] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <!-- Requests List -->
  <div class="card">
    <div class="card-body">
      <?php if (empty($requests)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No correction requests found.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Field</th>
                <th>Current Value</th>
                <th>Requested Value</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Date</th>
                <?php if ($canWrite): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $req): ?>
              <tr>
                <td>
                  <div class="font-medium text-slate-900"><?= htmlspecialchars($req['last_name'] . ', ' . $req['first_name']) ?></div>
                  <div class="text-xs text-slate-400"><?= htmlspecialchars($req['employee_code'] ?? '') ?></div>
                </td>
                <td class="text-sm"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($req['field_name']))) ?></td>
                <td class="text-sm text-slate-500"><?= htmlspecialchars($req['current_value'] ?: '(empty)') ?></td>
                <td class="text-sm font-medium text-indigo-700"><?= htmlspecialchars($req['requested_value']) ?></td>
                <td class="text-sm text-slate-500 max-w-[200px] truncate" title="<?= htmlspecialchars($req['reason'] ?? '') ?>"><?= htmlspecialchars($req['reason'] ?? '') ?></td>
                <td>
                  <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusBadge[$req['status']] ?? 'bg-slate-100 text-slate-700' ?>">
                    <?= ucfirst($req['status']) ?>
                  </span>
                </td>
                <td class="text-sm text-slate-500"><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
                <?php if ($canWrite): ?>
                <td>
                  <?php if ($req['status'] === 'pending'): ?>
                  <div class="flex gap-1">
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                      <input type="hidden" name="review_notes" value="">
                      <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-800 font-medium" data-confirm="Approve this correction request and apply the change?">Approve</button>
                    </form>
                    <span class="text-slate-300">|</span>
                    <button type="button" class="text-xs text-red-600 hover:text-red-800 font-medium"
                      onclick="openRejectModal(<?= (int)$req['id'] ?>)">Reject</button>
                  </div>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">—</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
        <div class="mt-4 flex justify-center gap-1">
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a href="<?= BASE_URL ?>/modules/admin/corrections/index?status=<?= $filterStatus ?>&page=<?= $p ?>"
               class="spa px-3 py-1 text-sm rounded <?= $p === $page ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
              <?= $p ?>
            </a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canWrite): ?>
<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4">
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <h3 class="text-lg font-semibold text-slate-900">Reject Correction Request</h3>
      <button onclick="document.getElementById('rejectModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">&times;</button>
    </div>
    <form method="post" id="rejectForm">
      <div class="px-6 py-4 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="request_id" id="reject_request_id" value="">
        <div>
          <label class="block text-sm font-medium text-slate-700">Rejection Reason</label>
          <textarea name="review_notes" class="input-text mt-1 w-full" rows="3" placeholder="Explain why this correction is being rejected"></textarea>
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t">
        <button type="button" onclick="document.getElementById('rejectModal').classList.add('hidden')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-danger">Reject Request</button>
      </div>
    </form>
  </div>
</div>
<script>
function openRejectModal(id) {
  document.getElementById('reject_request_id').value = id;
  document.getElementById('rejectModal').classList.remove('hidden');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
