<?php
/**
 * Overtime Management Hub - Admin View
 * Similar to Leave Management Hub - shows all OT requests with filtering, stats,
 * and batch approval. Aggregated view for HR/Admin.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'attendance', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// Handle batch approval/rejection via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $action = $_POST['batch_action'] ?? '';
    $ids = $_POST['ot_ids'] ?? [];
    $remarks = trim($_POST['batch_remarks'] ?? '');

    if (!empty($ids) && is_array($ids)) {
        $processed = 0;
        foreach ($ids as $otId) {
            $otId = (int)$otId;
            if ($otId <= 0) continue;

            if ($action === 'approve') {
                try {
                    $stmt = $pdo->prepare("UPDATE overtime_requests SET status = 'approved', approved_by = :uid, approved_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'");
                    $stmt->execute([':uid' => $currentUserId, ':id' => $otId]);
                    if ($stmt->rowCount() > 0) {
                        $processed++;
                        action_log('overtime', 'batch_approve', 'success', ['overtime_id' => $otId]);
                    }
                } catch (Throwable $e) { /* skip */ }
            } elseif ($action === 'reject') {
                if (empty($remarks)) {
                    flash_error('Rejection reason is required for batch rejection.');
                    header('Location: ' . BASE_URL . '/modules/overtime/admin');
                    exit;
                }
                try {
                    $stmt = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', approved_by = :uid, approved_at = CURRENT_TIMESTAMP, rejection_reason = :reason WHERE id = :id AND status = 'pending'");
                    $stmt->execute([':uid' => $currentUserId, ':id' => $otId, ':reason' => $remarks]);
                    if ($stmt->rowCount() > 0) {
                        $processed++;
                        action_log('overtime', 'batch_reject', 'success', ['overtime_id' => $otId, 'reason' => $remarks]);
                    }
                } catch (Throwable $e) { /* skip */ }
            }
        }
        if ($processed > 0) {
            flash_success("$processed overtime request(s) " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully.");
        }
    }
    header('Location: ' . BASE_URL . '/modules/overtime/admin');
    exit;
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-t');

// Stats
$statsAll = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $rows = $pdo->query("SELECT status, COUNT(*) c FROM overtime_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $statsAll[$r['status']] = (int)$r['c'];
        $statsAll['total'] += (int)$r['c'];
    }
} catch (Throwable $e) { /* ignore */ }

// Build query for list
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'ot.status = :status';
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(e.employee_code ILIKE :q OR e.first_name ILIKE :q OR e.last_name ILIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($dateFrom) {
    $where[] = 'ot.overtime_date >= :from';
    $params[':from'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'ot.overtime_date <= :to';
    $params[':to'] = $dateTo;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT ot.*, e.employee_code, e.first_name, e.last_name, d.name as department_name,
               u.full_name as approver_name
        FROM overtime_requests ot
        JOIN employees e ON e.id = ot.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN users u ON u.id = ot.approved_by
        {$whereClause}
        ORDER BY ot.overtime_date DESC, ot.created_at DESC
        LIMIT 200";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $requests = [];
}

$pageTitle = 'Overtime Management Hub';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-5">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Overtime Management</h1>
      <p class="text-sm text-slate-500 mt-0.5">Review, approve, or decline overtime requests across your workforce.</p>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <a href="<?= BASE_URL ?>/modules/overtime/admin" class="card card-body flex items-center gap-3 p-4 transition hover:shadow-md <?= $statusFilter === 'all' ? 'ring-2 ring-indigo-400' : '' ?>">
      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= $statsAll['total'] ?></div>
        <div class="text-xs text-slate-500">Total Requests</div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/overtime/admin?status=pending" class="card card-body flex items-center gap-3 p-4 transition hover:shadow-md <?= $statusFilter === 'pending' ? 'ring-2 ring-amber-400' : '' ?>">
      <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-amber-600"><?= $statsAll['pending'] ?></div>
        <div class="text-xs text-slate-500">Pending</div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/overtime/admin?status=approved" class="card card-body flex items-center gap-3 p-4 transition hover:shadow-md <?= $statusFilter === 'approved' ? 'ring-2 ring-emerald-400' : '' ?>">
      <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-emerald-600"><?= $statsAll['approved'] ?></div>
        <div class="text-xs text-slate-500">Approved</div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/overtime/admin?status=rejected" class="card card-body flex items-center gap-3 p-4 transition hover:shadow-md <?= $statusFilter === 'rejected' ? 'ring-2 ring-red-400' : '' ?>">
      <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-red-600"><?= $statsAll['rejected'] ?></div>
        <div class="text-xs text-slate-500">Rejected</div>
      </div>
    </a>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-400">0</div>
        <div class="text-xs text-slate-500">Cancelled</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card">
    <div class="card-body">
      <form method="get" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-0 sm:min-w-[200px]">
          <label class="block text-xs font-medium text-slate-500 mb-1">Search Employee</label>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or employee code..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">To</label>
          <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <?php if ($statusFilter !== 'all'): ?>
          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_URL ?>/modules/overtime/admin" class="btn btn-outline">Clear</a>
      </form>
    </div>
  </div>

  <!-- Overtime Requests Table -->
  <form method="post" id="batchForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="batch_action" id="batchAction" value="">

    <div class="card">
      <div class="card-header flex items-center justify-between flex-wrap gap-2">
        <span class="font-semibold text-slate-800">Overtime Requests <span class="text-sm font-normal text-slate-500">(<?= count($requests) ?> showing)</span></span>
        <?php if ($statusFilter === 'pending' || $statusFilter === 'all'): ?>
        <div class="flex gap-2">
          <button type="button" onclick="submitBatch('approve')" class="btn text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white" data-confirm="Approve all selected OT requests?">Approve Selected</button>
          <button type="button" onclick="promptBatchReject()" class="btn btn-danger text-xs px-3 py-1.5">Reject Selected</button>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="table-basic w-full">
            <thead>
              <tr>
                <th class="w-8"><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
                <th>Employee</th>
                <th>Department</th>
                <th>Date</th>
                <th>Time</th>
                <th>Hours</th>
                <th>Type</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($requests)): ?>
                <tr><td colspan="9" class="text-center py-8 text-sm text-slate-500">No overtime requests found.</td></tr>
              <?php else: ?>
                <?php foreach ($requests as $ot): ?>
                <tr class="hover:bg-slate-50">
                  <td>
                    <?php if ($ot['status'] === 'pending'): ?>
                    <input type="checkbox" name="ot_ids[]" value="<?= $ot['id'] ?>" class="ot-check">
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="text-sm font-medium text-slate-900"><?= htmlspecialchars($ot['first_name'] . ' ' . $ot['last_name']) ?></div>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars($ot['employee_code']) ?></div>
                  </td>
                  <td class="text-sm"><?= htmlspecialchars($ot['department_name'] ?? 'N/A') ?></td>
                  <td class="whitespace-nowrap"><?= date('M d, Y', strtotime($ot['overtime_date'])) ?></td>
                  <td class="whitespace-nowrap text-xs"><?= date('h:i A', strtotime($ot['start_time'])) ?> - <?= date('h:i A', strtotime($ot['end_time'])) ?></td>
                  <td class="font-semibold text-indigo-600"><?= number_format((float)$ot['hours_worked'], 2) ?></td>
                  <td>
                    <?php $typeColors = ['regular'=>'bg-blue-100 text-blue-700','holiday'=>'bg-purple-100 text-purple-700','restday'=>'bg-orange-100 text-orange-700']; ?>
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $typeColors[$ot['overtime_type']] ?? 'bg-slate-100 text-slate-700' ?>"><?= ucfirst($ot['overtime_type']) ?></span>
                  </td>
                  <td>
                    <?php $sc = ['pending'=>'bg-amber-100 text-amber-700','approved'=>'bg-emerald-100 text-emerald-700','rejected'=>'bg-red-100 text-red-700']; ?>
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $sc[$ot['status']] ?? 'bg-slate-100 text-slate-700' ?>"><?= ucfirst($ot['status']) ?></span>
                  </td>
                  <td>
                    <?php if ($ot['status'] === 'pending'): ?>
                      <a href="<?= BASE_URL ?>/modules/overtime/approve?id=<?= $ot['id'] ?>" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Review</a>
                    <?php else: ?>
                      <span class="text-xs text-slate-400">&mdash;</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <input type="hidden" name="batch_remarks" id="batchRemarks" value="">
  </form>
</div>

<script>
function toggleAll(el) {
    document.querySelectorAll('.ot-check').forEach(cb => cb.checked = el.checked);
}
function submitBatch(action) {
    const checked = document.querySelectorAll('.ot-check:checked');
    if (checked.length === 0) { alert('Please select at least one overtime request.'); return; }
    document.getElementById('batchAction').value = action;
    document.getElementById('batchForm').submit();
}
function promptBatchReject() {
    const checked = document.querySelectorAll('.ot-check:checked');
    if (checked.length === 0) { alert('Please select at least one overtime request.'); return; }
    const reason = prompt('Enter rejection reason (required):');
    if (!reason || reason.trim() === '') { alert('Rejection reason is required.'); return; }
    document.getElementById('batchAction').value = 'reject';
    document.getElementById('batchRemarks').value = reason.trim();
    document.getElementById('batchForm').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
