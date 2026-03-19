<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'recruitment', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$me = current_user();
$level = user_access_level((int)($me['id'] ?? 0), 'recruitment');

$statusOptions = [
  'new' => 'Pending',
  'shortlist' => 'For Final Interview',
  'interviewed' => 'Interviewed',
  'hired' => 'Hired',
  'rejected' => 'Rejected',
];

$statusColors = [
  'new'         => ['bg' => 'bg-blue-100',    'text' => 'text-blue-700',    'dot' => 'bg-blue-500'],
  'shortlist'   => ['bg' => 'bg-amber-100',   'text' => 'text-amber-700',   'dot' => 'bg-amber-500'],
  'interviewed' => ['bg' => 'bg-indigo-100',  'text' => 'text-indigo-700',  'dot' => 'bg-indigo-500'],
  'hired'       => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500'],
  'rejected'    => ['bg' => 'bg-red-100',     'text' => 'text-red-700',     'dot' => 'bg-red-500'],
];

// Handle inline status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  if (access_level_rank($level) < access_level_rank('write')) {
    header('Location: ' . BASE_URL . '/unauthorized');
    exit;
  }
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid CSRF token.');
  } else {
    $rid = (int)($_POST['recruitment_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if (!array_key_exists($newStatus, $statusOptions)) {
      flash_error('Invalid status selected.');
    } else {
      $convertedId = 0;
      try {
        $chk = $pdo->prepare('SELECT converted_employee_id FROM recruitment WHERE id = :id');
        $chk->execute([':id' => $rid]);
        $convertedId = (int)($chk->fetchColumn() ?? 0);
      } catch (Throwable $e) {
        sys_log('RECRUIT2104', 'Fetch converted flag failed - ' . $e->getMessage(), ['module' => 'recruitment', 'file' => __FILE__, 'line' => __LINE__]);
      }

      if ($newStatus === 'hired' && $convertedId === 0) {
        flash_error('Use the Transition to Employee action to mark an applicant as hired.');
      } else {
        try {
          $up = $pdo->prepare('UPDATE recruitment SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
          $up->execute([':status' => $newStatus, ':id' => $rid]);
          audit('recruitment_status_update', json_encode(['id' => $rid, 'status' => $newStatus]));
          action_log('recruitment', 'update_status', 'success', ['id' => $rid, 'status' => $newStatus]);
          flash_success('Status updated.');
        } catch (Throwable $e) {
          sys_log('RECRUIT2101', 'Status update failed - ' . $e->getMessage(), ['module' => 'recruitment', 'file' => __FILE__, 'line' => __LINE__]);
          flash_error('Could not update status.');
        }
      }
    }
  }
  header('Location: ' . BASE_URL . '/modules/recruitment/index?' . http_build_query(['q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '']));
  exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$whereParts = [];
$params = [];
if ($q !== '') {
  $whereParts[] = '(full_name ILIKE :q OR email ILIKE :q OR position_applied ILIKE :q)';
  $params[':q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && array_key_exists($statusFilter, $statusOptions)) {
  $whereParts[] = 'status = :status';
  $params[':status'] = $statusFilter;
}
$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Status counts for summary cards
$statusCounts = ['new' => 0, 'shortlist' => 0, 'interviewed' => 0, 'hired' => 0, 'rejected' => 0];
try {
  $cStmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM recruitment GROUP BY status');
  foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($statusCounts[$row['status']])) {
      $statusCounts[$row['status']] = (int)$row['cnt'];
    }
  }
} catch (Throwable $e) {}
$totalAll = array_sum($statusCounts);

// Pagination
$total = 0;
try {
  $countSql = 'SELECT COUNT(*) FROM recruitment ' . $whereSql;
  $stmt = $pdo->prepare($countSql);
  foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
  $stmt->execute();
  $total = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
  sys_log('RECRUIT2102', 'Count query failed - ' . $e->getMessage(), ['module' => 'recruitment', 'file' => __FILE__, 'line' => __LINE__]);
}

$page = (int)($_GET['page'] ?? 1);
[$offset, $limit, $page, $pages] = paginate($total, $page, 15);

$sql = 'SELECT id, full_name, email, phone, position_applied, status, created_at, updated_at FROM recruitment ' . $whereSql . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
$list = [];
try {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
  $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('RECRUIT2103', 'List query failed - ' . $e->getMessage(), ['module' => 'recruitment', 'file' => __FILE__, 'line' => __LINE__]);
}

$pageTitle = 'Recruitment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">

  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Recruitment Pipeline</h1>
      <p class="text-sm text-slate-500 mt-0.5"><?= $totalAll ?> total applicants &middot; manage hiring workflow</p>
    </div>
    <div class="flex items-center gap-2">
      <?php if (access_level_rank($level) >= access_level_rank('write')): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/recruitment/create">
          <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add Applicant
        </a>
      <?php endif; ?>
      <?php if (access_level_rank($level) >= access_level_rank('manage')): ?>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/recruitment/templates">Templates</a>
      <?php endif; ?>
      <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/recruitment/csv" target="_blank" rel="noopener" data-no-loader>
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        Export
      </a>
    </div>
  </div>

  <!-- Status Summary Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    <a href="<?= BASE_URL ?>/modules/recruitment/index" class="rounded-xl border <?= $statusFilter === '' ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200 bg-white hover:border-indigo-200' ?> p-3 text-center transition shadow-sm">
      <div class="text-2xl font-bold text-slate-900"><?= $totalAll ?></div>
      <div class="text-xs font-medium text-slate-500 mt-0.5">All</div>
    </a>
    <?php foreach ($statusCounts as $sKey => $sCount): $sc = $statusColors[$sKey] ?? []; ?>
      <a href="<?= BASE_URL ?>/modules/recruitment/index?status=<?= $sKey ?>" class="rounded-xl border <?= $statusFilter === $sKey ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200 bg-white hover:border-indigo-200' ?> p-3 text-center transition shadow-sm">
        <div class="text-2xl font-bold text-slate-900"><?= $sCount ?></div>
        <div class="text-xs font-medium <?= $sc['text'] ?? 'text-slate-500' ?> mt-0.5"><?= htmlspecialchars($statusOptions[$sKey]) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Search & Filter Bar -->
  <div class="card">
    <div class="card-body p-4">
      <form class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3" method="get">
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <input name="q" value="<?= htmlspecialchars($q) ?>" class="w-full rounded-lg border border-slate-200 bg-slate-50 pl-10 pr-4 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" placeholder="Search by name, email, or position...">
        </div>
        <select name="status" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition min-w-[160px]">
          <option value="">All Statuses</option>
          <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="flex gap-2">
          <button class="btn btn-primary" type="submit">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter
          </button>
          <?php if ($q !== '' || $statusFilter !== ''): ?>
            <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/recruitment/index">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Results Table -->
  <div class="card overflow-hidden">
    <div class="card-header flex items-center justify-between">
      <span class="text-sm font-semibold text-slate-700">
        Applicants
        <?php if ($total > 0): ?>
          <span class="text-slate-400 font-normal">&middot; Showing <?= $offset + 1 ?>–<?= min($offset + $limit, $total) ?> of <?= $total ?></span>
        <?php endif; ?>
      </span>
    </div>
    <div class="overflow-x-auto">
      <table class="table-basic min-w-full">
        <thead>
          <tr>
            <th class="text-left">Applicant</th>
            <th class="text-left">Position</th>
            <th class="text-left">Contact</th>
            <th class="text-left">Status</th>
            <th class="text-left">Applied</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$list): ?>
            <tr>
              <td colspan="6" class="text-center py-10">
                <svg class="mx-auto h-10 w-10 text-slate-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                <p class="text-sm font-medium text-slate-500">No applicants found</p>
                <p class="text-xs text-slate-400 mt-1">Try adjusting your search or filters.</p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($list as $row):
              $sc = $statusColors[$row['status']] ?? ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'dot' => 'bg-slate-500'];
              $initials = '';
              $nameParts = explode(' ', trim($row['full_name'] ?? ''));
              $initials = strtoupper(substr($nameParts[0] ?? '', 0, 1));
              if (count($nameParts) > 1) $initials .= strtoupper(substr(end($nameParts), 0, 1));
            ?>
              <tr class="hover:bg-slate-50/50 transition-colors">
                <td>
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-xs font-bold text-white">
                      <?= $initials ?>
                    </div>
                    <div>
                      <a href="<?= BASE_URL ?>/modules/recruitment/view?id=<?= (int)$row['id'] ?>" class="text-sm font-semibold text-slate-900 hover:text-indigo-600 transition"><?= htmlspecialchars($row['full_name'] ?? '') ?></a>
                      <div class="text-xs text-slate-500"><?= htmlspecialchars($row['email'] ?? '—') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="text-sm text-slate-700"><?= htmlspecialchars($row['position_applied'] ?? '—') ?></span>
                </td>
                <td>
                  <span class="text-sm text-slate-600"><?= htmlspecialchars($row['phone'] ?? '—') ?></span>
                </td>
                <td>
                  <span class="inline-flex items-center gap-1.5 rounded-full <?= $sc['bg'] ?> <?= $sc['text'] ?> px-2.5 py-1 text-xs font-semibold">
                    <span class="h-1.5 w-1.5 rounded-full <?= $sc['dot'] ?>"></span>
                    <?= htmlspecialchars($statusOptions[$row['status']] ?? $row['status']) ?>
                  </span>
                </td>
                <td>
                  <?php
                    $createdDisp = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—';
                    $updatedDisp = !empty($row['updated_at']) ? date('M d, Y', strtotime($row['updated_at'])) : '';
                  ?>
                  <div class="text-sm text-slate-600"><?= htmlspecialchars($createdDisp) ?></div>
                  <?php if ($updatedDisp): ?>
                    <div class="text-xs text-slate-400">Updated <?= htmlspecialchars($updatedDisp) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <a href="<?= BASE_URL ?>/modules/recruitment/view?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 transition">
                    View
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <div class="flex items-center justify-between">
      <p class="text-sm text-slate-500">Page <?= $page ?> of <?= $pages ?></p>
      <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
          <a class="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition" href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'page' => $page - 1]) ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </a>
        <?php endif; ?>
        <?php
          $startP = max(1, $page - 2);
          $endP = min($pages, $page + 2);
          if ($startP > 1): ?>
            <a class="inline-flex items-center justify-center h-9 min-w-[2.25rem] rounded-lg border border-slate-200 bg-white px-2 text-sm text-slate-600 hover:bg-slate-50 transition" href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'page' => 1]) ?>">1</a>
            <?php if ($startP > 2): ?><span class="text-slate-400 px-1">...</span><?php endif; ?>
          <?php endif; ?>
          <?php for ($i = $startP; $i <= $endP; $i++): ?>
            <a class="inline-flex items-center justify-center h-9 min-w-[2.25rem] rounded-lg border px-2 text-sm font-medium transition <?= $i === $page ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>" href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'page' => $i]) ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($endP < $pages): ?>
            <?php if ($endP < $pages - 1): ?><span class="text-slate-400 px-1">...</span><?php endif; ?>
            <a class="inline-flex items-center justify-center h-9 min-w-[2.25rem] rounded-lg border border-slate-200 bg-white px-2 text-sm text-slate-600 hover:bg-slate-50 transition" href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'page' => $pages]) ?>"><?= $pages ?></a>
          <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition" href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'page' => $page + 1]) ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
