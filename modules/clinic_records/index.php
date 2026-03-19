<?php
/**
 * Clinic Records — Main listing with search, filters, stats, view/create modals
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

$canWrite = user_has_access($uid, 'healthcare', 'clinic_records', 'write');
$canManage = user_has_access($uid, 'healthcare', 'clinic_records', 'manage');

// Current user's employee info for create form defaults
$myEmployeeId = null;
$myEmpName = '';
if ($canWrite) {
    $empStmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
    $empStmt->execute([':uid' => $uid]);
    $myEmp = $empStmt->fetch(PDO::FETCH_ASSOC);
    if ($myEmp) {
        $myEmployeeId = (int)$myEmp['id'];
        $myEmpName = $myEmp['first_name'] . ' ' . $myEmp['last_name'];
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

// Build WHERE clauses
$where = ['cr.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = "(cr.patient_name ILIKE :q OR ne.first_name ILIKE :q OR ne.last_name ILIKE :q OR me.first_name ILIKE :q OR me.last_name ILIKE :q OR CONCAT(ne.first_name, ' ', ne.last_name) ILIKE :q OR CONCAT(me.first_name, ' ', me.last_name) ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($dateFrom) {
    $where[] = 'cr.record_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'cr.record_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
if ($status && in_array($status, ['open', 'completed', 'cancelled'])) {
    $where[] = 'cr.status = :status';
    $params[':status'] = $status;
}
if ($type === 'nurse') {
    $where[] = 'cr.nurse_employee_id IS NOT NULL AND cr.medtech_employee_id IS NULL';
} elseif ($type === 'medtech') {
    $where[] = 'cr.medtech_employee_id IS NOT NULL AND cr.nurse_employee_id IS NULL';
} elseif ($type === 'both') {
    $where[] = 'cr.nurse_employee_id IS NOT NULL AND cr.medtech_employee_id IS NOT NULL';
}

$whereSQL = implode(' AND ', $where);

// Count total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM clinic_records cr
    LEFT JOIN employees ne ON ne.id = cr.nurse_employee_id
    LEFT JOIN employees me ON me.id = cr.medtech_employee_id
    WHERE {$whereSQL}
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

[$offset, $limit, $page, $pages] = paginate($total, $page, $perPage);

// Fetch records
$stmt = $pdo->prepare("
    SELECT cr.id, cr.patient_name, cr.record_date, cr.status,
           cr.nurse_service_datetime, cr.medtech_pickup_datetime,
           cr.nurse_employee_id, cr.medtech_employee_id,
           CONCAT(ne.first_name, ' ', ne.last_name) AS nurse_name,
           CONCAT(me.first_name, ' ', me.last_name) AS medtech_name
    FROM clinic_records cr
    LEFT JOIN employees ne ON ne.id = cr.nurse_employee_id
    LEFT JOIN employees me ON me.id = cr.medtech_employee_id
    WHERE {$whereSQL}
    ORDER BY cr.record_date DESC, cr.nurse_service_datetime DESC NULLS LAST, cr.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$today = date('Y-m-d');
$stats = $pdo->prepare("
    SELECT
        COUNT(*) FILTER (WHERE deleted_at IS NULL) AS total,
        COUNT(*) FILTER (WHERE deleted_at IS NULL AND record_date = :today) AS today_total,
        COUNT(*) FILTER (WHERE deleted_at IS NULL AND status = 'open') AS open_count,
        COUNT(*) FILTER (WHERE deleted_at IS NULL AND status = 'completed' AND record_date = :today2) AS completed_today
    FROM clinic_records
");
$stats->execute([':today' => $today, ':today2' => $today]);
$st = $stats->fetch(PDO::FETCH_ASSOC);

$filterQs = http_build_query(array_filter(['q'=>$q,'date_from'=>$dateFrom,'date_to'=>$dateTo,'status'=>$status,'type'=>$type]));

$pageTitle = 'Clinic Records';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
  .cr-stat-icon { display: none; }
  .cr-stat-label-full { display: none; }
  .cr-stat-label-short { display: block; }
  .cr-stats { gap: 0; }
  .cr-stat-card { padding: 0.625rem 0.5rem; text-align: center; flex: 1; }
  .cr-stat-num { font-size: 1rem; }
  .cr-subtitle { display: none; }
  .cr-btn-text { display: none; }
  .cr-filter-desktop { display: none; }
  .cr-filter-mobile { display: flex; }
  .cr-filter-dates-mobile { display: flex; }
  .cr-filter-dates-desktop { display: none; }
  .cr-filter-body { padding: 0.625rem; }
  .cr-mobile-cards { display: block; }
  .cr-desktop-table { display: none; }
  .cr-mobile-collapse { display: none; }
  @media (min-width: 640px) {
    .cr-stat-icon { display: flex; }
    .cr-stat-label-full { display: block; }
    .cr-stat-label-short { display: none; }
    .cr-stats { gap: 1rem; display: grid; grid-template-columns: repeat(2, 1fr); }
    .cr-stat-card { padding: 1.25rem; text-align: left; flex: none; }
    .cr-stat-num { font-size: 1.5rem; }
    .cr-subtitle { display: block; }
    .cr-btn-text { display: inline; }
    .cr-filter-desktop { display: flex; }
    .cr-filter-mobile { display: none; }
    .cr-filter-dates-mobile { display: none; }
    .cr-filter-dates-desktop { display: flex; }
    .cr-filter-body { padding: 1.25rem; }
    .cr-mobile-cards { display: none; }
    .cr-desktop-table { display: block; }
    .cr-mobile-collapse { display: block !important; }
  }
  @media (min-width: 1024px) {
    .cr-stats { grid-template-columns: repeat(4, 1fr); }
  }
</style>

<!-- Stat Cards -->
<div class="cr-stats flex divide-x divide-slate-100 mb-6" style="display:flex;">
  <div class="card cr-stat-card">
    <div class="flex items-center gap-4">
      <div class="cr-stat-icon w-12 h-12 rounded-xl bg-indigo-100 items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <div>
        <div class="cr-stat-num font-bold text-slate-900"><?= number_format($st['total']) ?></div>
        <div class="cr-stat-label-full text-sm text-slate-500">Total Records</div>
        <div class="cr-stat-label-short text-[9px] text-slate-500 uppercase tracking-wider font-medium">Total</div>
      </div>
    </div>
  </div>
  <div class="card cr-stat-card">
    <div class="flex items-center gap-4">
      <div class="cr-stat-icon w-12 h-12 rounded-xl bg-blue-100 items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="cr-stat-num font-bold text-blue-600"><?= number_format($st['today_total']) ?></div>
        <div class="cr-stat-label-full text-sm text-slate-500">Today's Records</div>
        <div class="cr-stat-label-short text-[9px] text-slate-500 uppercase tracking-wider font-medium">Today</div>
      </div>
    </div>
  </div>
  <div class="card cr-stat-card">
    <div class="flex items-center gap-4">
      <div class="cr-stat-icon w-12 h-12 rounded-xl bg-amber-100 items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      </div>
      <div>
        <div class="cr-stat-num font-bold text-amber-600"><?= number_format($st['open_count']) ?></div>
        <div class="cr-stat-label-full text-sm text-slate-500">Open Records</div>
        <div class="cr-stat-label-short text-[9px] text-slate-500 uppercase tracking-wider font-medium">Open</div>
      </div>
    </div>
  </div>
  <div class="card cr-stat-card">
    <div class="flex items-center gap-4">
      <div class="cr-stat-icon w-12 h-12 rounded-xl bg-emerald-100 items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="cr-stat-num font-bold text-emerald-600"><?= number_format($st['completed_today']) ?></div>
        <div class="cr-stat-label-full text-sm text-slate-500">Completed Today</div>
        <div class="cr-stat-label-short text-[9px] text-slate-500 uppercase tracking-wider font-medium">Done</div>
      </div>
    </div>
  </div>
</div>

<!-- Page Header -->
<div class="flex items-center justify-between gap-3 mb-6">
  <div class="min-w-0">
    <h1 class="text-xl font-bold text-slate-900">Clinic Records</h1>
    <p class="cr-subtitle text-sm text-slate-500 mt-0.5">Nurse and MedTech service log</p>
  </div>
  <div class="flex items-center gap-3 flex-shrink-0">
    <!-- Export Dropdown -->
    <div class="relative">
      <button type="button" onclick="document.getElementById('exportDd').classList.toggle('hidden')" class="btn btn-outline text-sm px-4 flex items-center gap-1.5">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <span class="cr-btn-text">Export</span>
        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
      </button>
      <div id="exportDd" class="hidden absolute right-0 mt-1 w-44 bg-white rounded-lg shadow-lg border border-slate-200 z-20 py-1">
        <a href="<?= BASE_URL ?>/modules/clinic_records/csv?<?= $filterQs ?>" class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50" data-no-loader>
          <svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Export as CSV
        </a>
        <a href="<?= BASE_URL ?>/modules/clinic_records/pdf?<?= $filterQs ?>" class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50" data-no-loader>
          <svg class="w-4 h-4 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
          Export as PDF
        </a>
      </div>
    </div>
    <?php if ($canWrite): ?>
    <button type="button" onclick="clinicOpenCreateModal()" class="btn btn-primary text-sm px-4 flex items-center gap-1.5">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      <span class="cr-btn-text">New Record</span>
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<div class="card mb-6">
  <div class="card-body cr-filter-body">
    <form method="get">
      <!-- Mobile: compact search row -->
      <div class="cr-filter-mobile gap-1.5 mb-2">
        <div class="flex-1 relative">
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search..." class="w-full border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs">
          <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="m21 21-4.35-4.35"/></svg>
        </div>
        <button type="button" onclick="document.getElementById('mobileFilters').classList.toggle('cr-mobile-collapse')" class="border border-slate-200 rounded-lg px-2 py-1.5 text-slate-500 hover:bg-slate-50">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
        </button>
        <button type="submit" class="btn btn-secondary text-xs py-1.5 px-3">Go</button>
      </div>
      <div id="mobileFilters" class="<?= ($dateFrom || $dateTo || $status || $type) ? '' : 'cr-mobile-collapse' ?>">
        <!-- Desktop: full row search -->
        <div class="cr-filter-desktop gap-3 mb-4">
          <div class="flex-1">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search patient, nurse, medtech..." class="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
          </div>
        </div>
        <!-- Date pickers row -->
        <div class="cr-filter-dates-desktop gap-3 mb-4">
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="border border-slate-200 rounded-lg px-3 py-2.5 text-sm" title="From date">
          <span class="text-slate-400 self-center">to</span>
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="border border-slate-200 rounded-lg px-3 py-2.5 text-sm" title="To date">
        </div>
        <div class="cr-filter-dates-mobile gap-1.5 mb-2">
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="flex-1 border border-slate-200 rounded-lg px-2 py-1.5 text-xs" title="From">
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="flex-1 border border-slate-200 rounded-lg px-2 py-1.5 text-xs" title="To">
        </div>
        <!-- Dropdowns row -->
        <div class="flex flex-wrap gap-3 items-center">
          <select name="status" class="border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
            <option value="">All Status</option>
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
          <select name="type" class="border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
            <option value="">All Types</option>
            <option value="nurse" <?= $type === 'nurse' ? 'selected' : '' ?>>Nurse Only</option>
            <option value="medtech" <?= $type === 'medtech' ? 'selected' : '' ?>>MedTech Only</option>
            <option value="both" <?= $type === 'both' ? 'selected' : '' ?>>Both</option>
          </select>
          <button type="submit" class="cr-filter-desktop btn btn-secondary text-sm px-5 py-2.5">Filter</button>
          <?php if ($q || $dateFrom || $dateTo || $status || $type): ?>
          <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="text-sm text-slate-500 hover:text-indigo-600">Clear</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Records Table / Cards -->
<div class="card">
  <div class="card-body p-0">
    <?php if (empty($records)): ?>
    <div class="text-center py-12 px-4">
      <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      <p class="text-sm text-slate-500 mb-1">No clinic records found</p>
      <p class="text-xs text-slate-400 mb-4">Try adjusting your filters or create a new record</p>
      <?php if ($canWrite): ?>
      <button type="button" onclick="clinicOpenCreateModal()" class="btn btn-primary text-sm">+ Create First Record</button>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- Desktop Table -->
    <div class="cr-desktop-table overflow-x-auto">
      <table class="table-basic w-full">
        <thead>
          <tr>
            <th class="px-4 py-3 text-left text-xs">Date & Time</th>
            <th class="px-4 py-3 text-left text-xs">Patient</th>
            <th class="px-4 py-3 text-left text-xs">Nurse</th>
            <th class="px-4 py-3 text-left text-xs">MedTech</th>
            <th class="px-4 py-3 text-center text-xs">Status</th>
            <th class="px-4 py-3 text-center text-xs w-14"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
          <tr class="hover:bg-slate-50 cursor-pointer transition-colors" onclick="clinicViewRecord(<?= $r['id'] ?>)">
            <td class="px-4 py-3 text-sm text-slate-700 whitespace-nowrap">
              <?php
                $dt = $r['nurse_service_datetime'] ?? $r['medtech_pickup_datetime'] ?? $r['record_date'];
                echo date('M d, Y h:i A', strtotime($dt));
              ?>
            </td>
            <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($r['patient_name']) ?></td>
            <td class="px-4 py-3 text-sm text-slate-700">
              <?= $r['nurse_name'] ? htmlspecialchars($r['nurse_name']) : '<span class="text-slate-400">—</span>' ?>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700">
              <?= $r['medtech_name'] ? htmlspecialchars($r['medtech_name']) : '<span class="text-slate-400">—</span>' ?>
            </td>
            <td class="px-4 py-3 text-center">
              <?php
                $statusColors = [
                    'open' => 'bg-amber-100 text-amber-700',
                    'completed' => 'bg-emerald-100 text-emerald-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                ];
                $sc = $statusColors[$r['status']] ?? 'bg-slate-100 text-slate-700';
              ?>
              <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $sc ?>"><?= ucfirst($r['status']) ?></span>
            </td>
            <td class="px-4 py-3 text-center">
              <button type="button" class="btn-icon text-slate-400 hover:text-indigo-600 transition-colors" onclick="event.stopPropagation(); clinicViewRecord(<?= $r['id'] ?>)" title="View Details">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card List -->
    <div class="cr-mobile-cards divide-y divide-slate-100">
      <?php foreach ($records as $r): ?>
      <?php
        $dt = $r['nurse_service_datetime'] ?? $r['medtech_pickup_datetime'] ?? $r['record_date'];
        $statusColors = [
            'open' => 'bg-amber-100 text-amber-700',
            'completed' => 'bg-emerald-100 text-emerald-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
        $sc = $statusColors[$r['status']] ?? 'bg-slate-100 text-slate-700';
      ?>
      <div class="p-3 active:bg-slate-50 cursor-pointer" onclick="clinicViewRecord(<?= $r['id'] ?>)">
        <div class="flex items-start justify-between gap-2 mb-1.5">
          <div class="min-w-0 flex-1">
            <div class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($r['patient_name']) ?></div>
            <div class="text-[11px] text-slate-500"><?= date('M d, Y h:i A', strtotime($dt)) ?></div>
          </div>
          <span class="px-2 py-0.5 text-[10px] font-medium rounded-full <?= $sc ?> flex-shrink-0"><?= ucfirst($r['status']) ?></span>
        </div>
        <div class="flex items-center gap-3 text-[11px] text-slate-500">
          <?php if ($r['nurse_name']): ?>
          <span class="flex items-center gap-1">
            <svg class="w-3 h-3 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            <?= htmlspecialchars($r['nurse_name']) ?>
          </span>
          <?php endif; ?>
          <?php if ($r['medtech_name']): ?>
          <span class="flex items-center gap-1">
            <svg class="w-3 h-3 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
            <?= htmlspecialchars($r['medtech_name']) ?>
          </span>
          <?php endif; ?>
          <?php if (!$r['nurse_name'] && !$r['medtech_name']): ?>
          <span class="text-slate-400">No staff assigned</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="flex items-center justify-between px-3 sm:px-4 py-3 border-t border-slate-200">
      <div class="text-xs sm:text-sm text-slate-500">
        <span class="hidden sm:inline">Showing </span><?= $offset + 1 ?>–<?= min($offset + $limit, $total) ?> of <?= $total ?>
      </div>
      <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
        <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <?php endif; ?>
        <?php
          $start = max(1, $page - 2);
          $end = min($pages, $page + 2);
          for ($i = $start; $i <= $end; $i++):
        ?>
        <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border text-xs sm:text-sm font-medium <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
          <?= $i ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
        <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================ -->
<!-- View Record Modal -->
<!-- ============================================================ -->
<div id="clinicViewModal" class="hidden fixed inset-0 z-50" style="display:none;">
  <div class="fixed inset-0 bg-black/40 backdrop-blur-sm transition-opacity" onclick="clinicCloseModal()"></div>
  <div class="fixed inset-0 overflow-y-auto">
    <div class="flex min-h-full items-end sm:items-center justify-center p-0 sm:p-6">
      <div class="relative w-full sm:max-w-2xl bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[90vh] sm:max-h-[85vh] flex flex-col transform transition-all">
        <!-- Modal Header -->
        <div class="px-5 sm:px-6 py-4 sm:py-5 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
          <div class="min-w-0 flex-1 mr-3">
            <h3 class="text-lg sm:text-xl font-bold text-slate-900 truncate" id="crModalTitle">Clinic Record</h3>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5" id="crModalSubtitle"></p>
          </div>
          <div class="flex items-center gap-2.5 flex-shrink-0">
            <span id="crModalStatus" class="px-2.5 py-1 text-[10px] sm:text-xs font-medium rounded-full"></span>
            <button type="button" onclick="clinicCloseModal()" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition-colors">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <!-- Modal Body (scrollable) -->
        <div class="px-5 sm:px-6 py-5 sm:py-6 overflow-y-auto flex-1" id="crModalBody">
          <div class="text-center py-8"><span class="spinner-mini"></span> Loading...</div>
        </div>

        <!-- Modal Footer -->
        <div class="px-5 sm:px-6 py-4 border-t border-slate-100 flex flex-wrap items-center gap-2.5 bg-slate-50 rounded-b-2xl flex-shrink-0" id="crModalFooter">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- Create Record Modal -->
<!-- ============================================================ -->
<?php if ($canWrite): ?>
<div id="clinicCreateModal" class="hidden fixed inset-0 z-50" style="display:none;">
  <div class="fixed inset-0 bg-black/40 backdrop-blur-sm transition-opacity" onclick="clinicCloseCreateModal()"></div>
  <div class="fixed inset-0 overflow-y-auto">
    <div class="flex min-h-full items-end sm:items-center justify-center p-0 sm:p-6">
      <div class="relative w-full sm:max-w-lg bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[92vh] sm:max-h-[85vh] flex flex-col transform transition-all">
        <!-- Header -->
        <div class="px-5 sm:px-6 py-4 sm:py-5 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
          <div>
            <h3 class="text-lg sm:text-xl font-bold text-slate-900">New Clinic Record</h3>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Log a nurse or medtech service</p>
          </div>
          <button type="button" onclick="clinicCloseCreateModal()" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition-colors">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <!-- Form Body -->
        <div class="px-5 sm:px-6 py-5 overflow-y-auto flex-1" id="createFormBody">
          <div class="space-y-5">
            <!-- Patient Name -->
            <div>
              <label class="required block text-sm font-medium text-slate-700 mb-1.5">Patient Name</label>
              <input type="text" id="crPatientName" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors" placeholder="Enter patient full name...">
            </div>

            <!-- Record Date -->
            <div>
              <label class="required block text-sm font-medium text-slate-700 mb-1.5">Record Date</label>
              <input type="date" id="crRecordDate" value="<?= date('Y-m-d') ?>" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors">
            </div>

            <!-- Nurse Section -->
            <div class="rounded-xl border border-indigo-200 overflow-hidden">
              <label class="flex items-center gap-2.5 px-4 py-3 bg-indigo-50 cursor-pointer">
                <input type="checkbox" id="crHasNurse" checked class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 rounded border-slate-300">
                <svg class="w-4 h-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                <span class="text-sm font-semibold text-indigo-800">Nurse Service</span>
              </label>
              <div id="crNurseSection" class="p-4 space-y-3.5 bg-white">
                <div>
                  <label class="required block text-xs font-medium text-slate-600 mb-1">Nurse</label>
                  <div class="relative">
                    <input type="text" id="crNurseSearch" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm pl-10 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Search nurse..." autocomplete="off" value="<?= htmlspecialchars($myEmpName) ?>">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="m21 21-4.35-4.35"/></svg>
                  </div>
                  <div id="crNurseResults" class="mt-1 max-h-36 overflow-y-auto border border-slate-200 rounded-lg bg-white shadow-lg" style="display:none;"></div>
                  <input type="hidden" id="crNurseEmpId" value="<?= $myEmployeeId ?>">
                  <div id="crNurseSelected" class="mt-1 text-xs text-emerald-600 font-medium"><?= $myEmpName ? 'Selected: ' . htmlspecialchars($myEmpName) . ' (you)' : '' ?></div>
                </div>
                <div>
                  <label class="required block text-xs font-medium text-slate-600 mb-1">Service Date & Time</label>
                  <input type="datetime-local" id="crNurseDatetime" value="<?= date('Y-m-d\TH:i') ?>" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                  <textarea id="crNurseNotes" rows="2" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Service details..."></textarea>
                </div>
              </div>
            </div>

            <!-- MedTech Section -->
            <div class="rounded-xl border border-emerald-200 overflow-hidden">
              <label class="flex items-center gap-2.5 px-4 py-3 bg-emerald-50 cursor-pointer">
                <input type="checkbox" id="crHasMedtech" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 rounded border-slate-300">
                <svg class="w-4 h-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                <span class="text-sm font-semibold text-emerald-800">MedTech Service</span>
              </label>
              <div id="crMedtechSection" class="p-4 space-y-3.5 bg-white" style="display:none;">
                <div>
                  <label class="required block text-xs font-medium text-slate-600 mb-1">MedTech</label>
                  <div class="relative">
                    <input type="text" id="crMedtechSearch" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm pl-10 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="Search medtech..." autocomplete="off">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="m21 21-4.35-4.35"/></svg>
                  </div>
                  <div id="crMedtechResults" class="mt-1 max-h-36 overflow-y-auto border border-slate-200 rounded-lg bg-white shadow-lg" style="display:none;"></div>
                  <input type="hidden" id="crMedtechEmpId">
                  <div id="crMedtechSelected" class="mt-1 text-xs text-emerald-600 font-medium"></div>
                </div>
                <div>
                  <label class="required block text-xs font-medium text-slate-600 mb-1">Pickup Date & Time</label>
                  <input type="datetime-local" id="crMedtechDatetime" value="<?= date('Y-m-d\TH:i') ?>" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                  <textarea id="crMedtechNotes" rows="2" class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="Medtech details..."></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-5 sm:px-6 py-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-slate-50 rounded-b-2xl flex-shrink-0">
          <button type="button" onclick="clinicCloseCreateModal()" class="btn btn-outline text-sm px-5">Cancel</button>
          <button type="button" onclick="clinicSubmitCreate()" id="crCreateBtn" class="btn btn-primary text-sm px-5">Save Record</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const _crBaseUrl = '<?= BASE_URL ?>';
const _crCsrf = '<?= csrf_token() ?>';
const _crCanWrite = <?= $canWrite ? 'true' : 'false' ?>;
const _crCanManage = <?= $canManage ? 'true' : 'false' ?>;
let _crCurrentId = null;
let _crCurrentData = null;

// ─── Close export dropdown on click outside ───
document.addEventListener('click', function(e) {
    const dd = document.getElementById('exportDd');
    if (dd && !dd.parentElement.contains(e.target)) dd.classList.add('hidden');
});

// ─── UTILITY FUNCTIONS ───
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
}

function formatDateTime(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    return dt.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) + ' ' +
           dt.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', hour12:true});
}

function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 z-[9999] px-4 py-2.5 rounded-lg shadow-lg text-sm font-medium transition-all duration-300 ' +
        (type === 'error' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

// ─── AUTOCOMPLETE HELPER ───
function crSetupAutocomplete(searchId, resultsId, hiddenId, selectedId, nameHiddenId, searchType) {
    const search = document.getElementById(searchId);
    const results = document.getElementById(resultsId);
    if (!search) return;

    let timer;
    search.addEventListener('input', function() {
        clearTimeout(timer);
        const val = this.value.trim();
        if (val.length < 2) { results.style.display = 'none'; return; }

        timer = setTimeout(() => {
            let url = _crBaseUrl + '/modules/clinic_records/api_employee_search?q=' + encodeURIComponent(val);
            if (searchType) url += '&type=' + encodeURIComponent(searchType);
            fetch(url)
                .then(r => r.json())
                .then(emps => {
                    let h = '';
                    emps.forEach(e => {
                        const name = e.first_name + ' ' + e.last_name;
                        h += '<div class="px-3 py-2 hover:bg-indigo-50 cursor-pointer text-sm" data-id="' + e.id + '" data-name="' + escapeHtml(name) + '">';
                        h += '<span class="font-medium">' + escapeHtml(name) + '</span>';
                        h += ' <span class="text-xs text-slate-400">' + escapeHtml(e.employee_code || '') + '</span>';
                        if (e.position_name) h += ' · <span class="text-xs text-slate-400">' + escapeHtml(e.position_name) + '</span>';
                        h += '</div>';
                    });
                    if (!emps.length) h = '<div class="px-3 py-2 text-sm text-slate-400">No results</div>';
                    results.innerHTML = h;
                    results.style.display = '';

                    results.querySelectorAll('[data-id]').forEach(el => {
                        el.addEventListener('click', function() {
                            document.getElementById(hiddenId).value = this.dataset.id;
                            search.value = this.dataset.name;
                            results.style.display = 'none';
                            const sel = document.getElementById(selectedId);
                            sel.textContent = 'Selected: ' + this.dataset.name;
                            sel.style.display = '';
                            if (nameHiddenId) document.getElementById(nameHiddenId).value = this.dataset.name;
                        });
                    });
                });
        }, 300);
    });

    search.addEventListener('blur', function() {
        setTimeout(() => { results.style.display = 'none'; }, 200);
    });
}

// ─── VIEW RECORD MODAL ───
function clinicViewRecord(id) {
    _crCurrentId = id;
    const modal = document.getElementById('clinicViewModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.getElementById('crModalBody').innerHTML = '<div class="text-center py-8"><span class="spinner-mini"></span> Loading...</div>';
    document.getElementById('crModalFooter').innerHTML = '';

    fetch(_crBaseUrl + '/modules/clinic_records/api_view?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('crModalBody').innerHTML = '<div class="text-center py-8 text-red-500">' + escapeHtml(data.error) + '</div>';
                return;
            }
            _crCurrentData = data;
            renderClinicModal(data);
        })
        .catch(() => {
            document.getElementById('crModalBody').innerHTML = '<div class="text-center py-8 text-red-500">Failed to load record</div>';
        });
}

function renderClinicModal(d) {
    const p = d.permissions;
    const statusColors = {open:'bg-amber-100 text-amber-700', completed:'bg-emerald-100 text-emerald-700', cancelled:'bg-red-100 text-red-700'};
    const sc = statusColors[d.status] || 'bg-slate-100 text-slate-700';

    document.getElementById('crModalTitle').textContent = 'Clinic Record #' + d.id;
    document.getElementById('crModalSubtitle').textContent = formatDate(d.record_date);
    document.getElementById('crModalStatus').className = 'px-2.5 py-1 text-[10px] sm:text-xs font-medium rounded-full ' + sc;
    document.getElementById('crModalStatus').textContent = d.status.charAt(0).toUpperCase() + d.status.slice(1);

    let html = '';

    // Patient Info
    html += '<div class="mb-4">';
    html += '<div class="flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
    html += '<span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Patient</span></div>';
    html += '<div class="bg-slate-50 rounded-lg p-3 sm:p-4">';
    html += '<div class="text-sm sm:text-base font-semibold text-slate-900">' + escapeHtml(d.patient_name) + '</div>';
    if (d.employee_id) {
        html += '<div class="text-[11px] text-slate-500 mt-0.5">Employee' + (d.patient_code ? ' (' + escapeHtml(d.patient_code) + ')' : '') + '</div>';
    } else {
        html += '<div class="text-[11px] text-slate-500 mt-0.5">External Patient</div>';
    }
    html += '</div></div>';

    // Nurse Service
    html += '<div class="mb-4">';
    html += '<div class="flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>';
    html += '<span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Nurse Service</span></div>';
    html += '<div class="bg-indigo-50 rounded-lg p-3 sm:p-4 border border-indigo-100">';
    if (d.nurse_name) {
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">';
        html += '<div><span class="text-slate-500 text-xs">Nurse</span><div class="font-medium text-slate-900">' + escapeHtml(d.nurse_name) + '</div></div>';
        html += '<div><span class="text-slate-500 text-xs">Date/Time</span><div class="font-medium text-slate-900">' + (d.nurse_service_datetime ? formatDateTime(d.nurse_service_datetime) : '—') + '</div></div>';
        html += '</div>';
        if (d.nurse_notes) {
            html += '<div class="mt-3"><span class="text-xs text-slate-500">Notes</span><div class="mt-1 p-2 bg-white rounded border border-indigo-100 text-sm text-slate-700 whitespace-pre-wrap">' + escapeHtml(d.nurse_notes) + '</div></div>';
        }
        if ((p.nurse_can_edit || p.can_manage) && !p.has_medtech) {
            html += '<div class="mt-3"><button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium" onclick="clinicEditNurseNotes()">Edit Notes</button></div>';
        }
    } else {
        html += '<div class="text-sm text-slate-500 italic">No nurse service recorded</div>';
    }
    html += '</div></div>';

    // MedTech Service
    html += '<div class="mb-4">';
    html += '<div class="flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>';
    html += '<span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">MedTech Service</span></div>';
    html += '<div class="bg-emerald-50 rounded-lg p-3 sm:p-4 border border-emerald-100">';
    if (d.medtech_name) {
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">';
        html += '<div><span class="text-slate-500 text-xs">MedTech</span><div class="font-medium text-slate-900">' + escapeHtml(d.medtech_name) + '</div></div>';
        html += '<div><span class="text-slate-500 text-xs">Pickup</span><div class="font-medium text-slate-900">' + (d.medtech_pickup_datetime ? formatDateTime(d.medtech_pickup_datetime) : '—') + '</div></div>';
        html += '</div>';
        if (d.medtech_notes) {
            html += '<div class="mt-3"><span class="text-xs text-slate-500">Notes</span><div class="mt-1 p-2 bg-white rounded border border-emerald-100 text-sm text-slate-700 whitespace-pre-wrap">' + escapeHtml(d.medtech_notes) + '</div></div>';
        }
        if (p.medtech_can_edit_notes || p.can_manage) {
            html += '<div class="mt-3"><button type="button" class="text-xs text-emerald-600 hover:text-emerald-800 font-medium" onclick="clinicEditMedtechNotes()">Edit Notes</button></div>';
        }
    } else {
        if (p.can_assign_medtech) {
            html += '<div class="text-center py-3">';
            html += '<div class="text-sm text-slate-500 mb-3">No MedTech assigned yet</div>';
            html += '<div class="flex flex-col sm:flex-row justify-center gap-2">';
            html += '<button type="button" class="btn btn-primary text-xs" onclick="clinicAssignMedtechSelf()">Assign Self as MedTech</button>';
            if (p.nurse_can_assign_medtech || p.can_manage) {
                html += '<button type="button" class="btn btn-outline text-xs" onclick="clinicAssignMedtechOther()">Assign MedTech</button>';
            }
            html += '</div></div>';
        } else {
            html += '<div class="text-sm text-slate-500 italic">No MedTech assigned</div>';
        }
    }
    html += '</div></div>';

    // Meta
    html += '<div class="text-[11px] text-slate-400 border-t border-slate-100 pt-3">';
    html += 'Created by ' + escapeHtml(d.created_by_name || 'Unknown') + ' · ' + formatDateTime(d.created_at);
    if (d.updated_at !== d.created_at) {
        html += ' · Updated ' + formatDateTime(d.updated_at);
    }
    html += '</div>';

    document.getElementById('crModalBody').innerHTML = html;

    // Footer buttons
    let footer = '<button type="button" class="btn btn-outline text-xs sm:text-sm" onclick="clinicShowHistory(' + d.id + ')">';
    footer += '<svg class="w-3.5 h-3.5 mr-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>History</button>';
    if ((p.nurse_can_edit || p.can_manage) && !p.has_medtech) {
        footer += '<a href="' + _crBaseUrl + '/modules/clinic_records/edit?id=' + d.id + '" class="btn btn-secondary text-xs sm:text-sm spa" onclick="clinicCloseModal()">Edit</a>';
    }
    if (p.nurse_can_delete || p.can_manage) {
        footer += '<button type="button" class="btn btn-danger text-xs sm:text-sm" onclick="clinicDeleteRecord(' + d.id + ')">Delete</button>';
    }
    footer += '<button type="button" class="btn btn-outline text-xs sm:text-sm ml-auto" onclick="clinicCloseModal()">Close</button>';
    document.getElementById('crModalFooter').innerHTML = footer;
}

function clinicCloseModal() {
    const modal = document.getElementById('clinicViewModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = '';
    _crCurrentId = null;
    _crCurrentData = null;
}

// ─── MODAL ACTIONS ───
function clinicAssignMedtechSelf() {
    const body = document.getElementById('crModalBody');
    let formHtml = '<div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4 mb-4">';
    formHtml += '<h4 class="text-sm font-semibold text-emerald-800 mb-3">Assign Self as MedTech</h4>';
    formHtml += '<div><label class="block text-xs font-medium text-slate-600 mb-1">Notes (optional)</label>';
    formHtml += '<textarea id="crSelfMtNotes" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Add any notes..."></textarea></div>';
    formHtml += '<div class="flex gap-2 mt-3">';
    formHtml += '<button type="button" class="btn btn-primary text-xs" onclick="clinicConfirmSelfAssign()">Confirm</button>';
    formHtml += '<button type="button" class="btn btn-outline text-xs" onclick="clinicViewRecord(_crCurrentId)">Cancel</button>';
    formHtml += '</div></div>';
    body.innerHTML = formHtml;
}

function clinicConfirmSelfAssign() {
    const notes = document.getElementById('crSelfMtNotes')?.value || '';
    clinicApiAction({
        action: 'assign_medtech_self',
        record_id: _crCurrentId,
        medtech_notes: notes,
        csrf_token: _crCsrf
    });
}

function clinicAssignMedtechOther() {
    const body = document.getElementById('crModalBody');
    let formHtml = '<div class="bg-white border border-slate-200 rounded-lg p-4 mb-4">';
    formHtml += '<h4 class="text-sm font-semibold text-slate-700 mb-3">Assign MedTech</h4>';
    formHtml += '<div class="mb-3"><label class="text-xs text-slate-600 mb-1 block">Search Employee</label>';
    formHtml += '<div class="relative"><input type="text" id="crMtAssignSearch" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm pl-9" placeholder="Name or code..." autocomplete="off">';
    formHtml += '<svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="m21 21-4.35-4.35"/></svg></div></div>';
    formHtml += '<div id="crMtAssignResults" class="mb-3 max-h-40 overflow-y-auto"></div>';
    formHtml += '<input type="hidden" id="crMtAssignId" value="">';
    formHtml += '<div class="flex gap-2"><button type="button" class="btn btn-primary text-xs" onclick="clinicConfirmAssignMedtech()">Assign</button>';
    formHtml += '<button type="button" class="btn btn-outline text-xs" onclick="clinicViewRecord(_crCurrentId)">Cancel</button></div>';
    formHtml += '</div>';
    body.innerHTML = formHtml;

    // Wire up search
    let timer;
    document.getElementById('crMtAssignSearch').addEventListener('input', function() {
        clearTimeout(timer);
        const val = this.value.trim();
        if (val.length < 2) { document.getElementById('crMtAssignResults').innerHTML = ''; return; }
        timer = setTimeout(() => {
            fetch(_crBaseUrl + '/modules/clinic_records/api_employee_search?q=' + encodeURIComponent(val) + '&type=medtech')
                .then(r => r.json())
                .then(emps => {
                    let h = '';
                    emps.forEach(e => {
                        h += '<div class="px-3 py-2 hover:bg-indigo-50 cursor-pointer rounded text-sm" onclick="clinicSelectMedtech(' + e.id + ', \'' + escapeHtml(e.first_name + ' ' + e.last_name) + '\')">';
                        h += '<span class="font-medium">' + escapeHtml(e.first_name + ' ' + e.last_name) + '</span>';
                        h += ' <span class="text-xs text-slate-400">' + escapeHtml(e.employee_code || '') + '</span>';
                        if (e.position_name) h += ' · <span class="text-xs text-slate-400">' + escapeHtml(e.position_name) + '</span>';
                        h += '</div>';
                    });
                    if (!emps.length) h = '<div class="px-3 py-2 text-sm text-slate-400">No employees found</div>';
                    document.getElementById('crMtAssignResults').innerHTML = h;
                });
        }, 300);
    });
    document.getElementById('crMtAssignSearch').focus();
}

function clinicSelectMedtech(id, name) {
    document.getElementById('crMtAssignId').value = id;
    document.getElementById('crMtAssignSearch').value = name;
    document.getElementById('crMtAssignResults').innerHTML = '<div class="px-3 py-1 text-xs text-emerald-600 font-medium">Selected: ' + escapeHtml(name) + '</div>';
}

function clinicConfirmAssignMedtech() {
    const mtId = document.getElementById('crMtAssignId').value;
    if (!mtId) { showToast('Please select a MedTech employee', 'error'); return; }
    clinicApiAction({
        action: 'assign_medtech_by_nurse',
        record_id: _crCurrentId,
        medtech_employee_id: parseInt(mtId),
        csrf_token: _crCsrf
    });
}

function clinicEditMedtechNotes() {
    const current = _crCurrentData?.medtech_notes || '';
    const body = document.getElementById('crModalBody');
    let formHtml = '<div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">';
    formHtml += '<h4 class="text-sm font-semibold text-emerald-800 mb-2">Edit MedTech Notes</h4>';
    formHtml += '<textarea id="crEditMtNotes" rows="4" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">' + escapeHtml(current) + '</textarea>';
    formHtml += '<div class="flex gap-2 mt-3">';
    formHtml += '<button type="button" class="btn btn-primary text-xs" onclick="clinicSaveMedtechNotes()">Save</button>';
    formHtml += '<button type="button" class="btn btn-outline text-xs" onclick="clinicViewRecord(_crCurrentId)">Cancel</button>';
    formHtml += '</div></div>';
    body.innerHTML = formHtml;
}

function clinicSaveMedtechNotes() {
    clinicApiAction({
        action: 'update_medtech_notes',
        record_id: _crCurrentId,
        medtech_notes: document.getElementById('crEditMtNotes').value,
        csrf_token: _crCsrf
    });
}

function clinicEditNurseNotes() {
    const current = _crCurrentData?.nurse_notes || '';
    const body = document.getElementById('crModalBody');
    let formHtml = '<div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">';
    formHtml += '<h4 class="text-sm font-semibold text-indigo-800 mb-2">Edit Nurse Notes</h4>';
    formHtml += '<textarea id="crEditNrNotes" rows="4" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">' + escapeHtml(current) + '</textarea>';
    formHtml += '<div class="flex gap-2 mt-3">';
    formHtml += '<button type="button" class="btn btn-primary text-xs" onclick="clinicSaveNurseNotes()">Save</button>';
    formHtml += '<button type="button" class="btn btn-outline text-xs" onclick="clinicViewRecord(_crCurrentId)">Cancel</button>';
    formHtml += '</div></div>';
    body.innerHTML = formHtml;
}

function clinicSaveNurseNotes() {
    clinicApiAction({
        action: 'update_nurse',
        record_id: _crCurrentId,
        nurse_notes: document.getElementById('crEditNrNotes').value,
        csrf_token: _crCsrf
    });
}

function clinicDeleteRecord(id) {
    if (!confirm('Are you sure you want to delete this clinic record? This action will be logged.')) return;
    clinicApiAction({ action: 'delete', record_id: id, csrf_token: _crCsrf });
}

function clinicApiAction(payload) {
    fetch(_crBaseUrl + '/modules/clinic_records/api_action', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            showToast(data.error, 'error');
            return;
        }
        showToast(data.message || 'Action completed', 'success');
        if (payload.action === 'delete') {
            clinicCloseModal();
            location.reload();
        } else {
            clinicViewRecord(_crCurrentId);
        }
    })
    .catch(() => showToast('Action failed', 'error'));
}

// ─── HISTORY VIEW ───
function clinicShowHistory(id) {
    const body = document.getElementById('crModalBody');
    body.innerHTML = '<div class="text-center py-8"><span class="spinner-mini"></span> Loading history...</div>';

    fetch(_crBaseUrl + '/modules/clinic_records/api_history?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { body.innerHTML = '<div class="text-red-500 text-center py-4">' + escapeHtml(data.error) + '</div>'; return; }

            let html = '<div class="flex items-center justify-between mb-4">';
            html += '<h4 class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Record History</h4>';
            html += '<button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium" onclick="clinicViewRecord(' + id + ')">Back to Details</button>';
            html += '</div>';

            if (!data.history.length) {
                html += '<div class="text-sm text-slate-400 text-center py-6">No history entries</div>';
            } else {
                html += '<div class="space-y-2.5">';
                data.history.forEach(h => {
                    const actionColors = {
                        created:'bg-blue-100 text-blue-700', nurse_updated:'bg-indigo-100 text-indigo-700',
                        medtech_assigned:'bg-emerald-100 text-emerald-700', medtech_updated:'bg-teal-100 text-teal-700',
                        deleted:'bg-red-100 text-red-700', restored:'bg-green-100 text-green-700', edited:'bg-amber-100 text-amber-700'
                    };
                    const ac = actionColors[h.action] || 'bg-slate-100 text-slate-700';

                    html += '<div class="border border-slate-200 rounded-lg p-3">';
                    html += '<div class="flex items-center justify-between mb-1">';
                    html += '<span class="px-2 py-0.5 text-[10px] font-medium rounded-full ' + ac + '">' + escapeHtml(h.action_label) + '</span>';
                    html += '<span class="text-[11px] text-slate-400">' + formatDateTime(h.created_at) + '</span>';
                    html += '</div>';
                    html += '<div class="text-xs text-slate-600">By: <span class="font-medium">' + escapeHtml(h.changed_by) + '</span></div>';

                    if (h.notes) {
                        html += '<div class="text-[11px] text-slate-500 mt-1">' + escapeHtml(h.notes) + '</div>';
                    }

                    if (h.old_values || h.new_values) {
                        html += '<div class="mt-2 text-[11px] space-y-0.5">';
                        if (h.old_values) {
                            html += '<div class="text-slate-500">Previous: <code class="bg-red-50 px-1 rounded text-[10px]">' + escapeHtml(JSON.stringify(h.old_values)) + '</code></div>';
                        }
                        if (h.new_values) {
                            html += '<div class="text-slate-500">New: <code class="bg-green-50 px-1 rounded text-[10px]">' + escapeHtml(JSON.stringify(h.new_values)) + '</code></div>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                });
                html += '</div>';
            }

            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div class="text-red-500 text-center py-8">Failed to load history</div>'; });
}

// ─── CREATE RECORD MODAL ───
function clinicOpenCreateModal() {
    const modal = document.getElementById('clinicCreateModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.style.display = '';
    document.body.style.overflow = 'hidden';

    // Reset patient fields
    document.getElementById('crPatientName').value = '';
    document.getElementById('crRecordDate').value = new Date().toISOString().slice(0,10);

    // Nurse section
    document.getElementById('crHasNurse').checked = true;
    document.getElementById('crNurseSection').style.display = '';
    document.getElementById('crNurseSearch').value = '<?= addslashes($myEmpName) ?>';
    document.getElementById('crNurseEmpId').value = '<?= $myEmployeeId ?>';
    document.getElementById('crNurseSelected').textContent = '<?= $myEmpName ? 'Selected: ' . addslashes($myEmpName) . ' (you)' : '' ?>';
    document.getElementById('crNurseDatetime').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0,16);
    document.getElementById('crNurseNotes').value = '';

    // MedTech section
    document.getElementById('crHasMedtech').checked = false;
    document.getElementById('crMedtechSection').style.display = 'none';
    document.getElementById('crMedtechSearch').value = '';
    document.getElementById('crMedtechEmpId').value = '';
    document.getElementById('crMedtechSelected').textContent = '';
    document.getElementById('crMedtechNotes').value = '';
}

function clinicCloseCreateModal() {
    const modal = document.getElementById('clinicCreateModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

function clinicSubmitCreate() {
    const btn = document.getElementById('crCreateBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const patientName = document.getElementById('crPatientName').value.trim();
    const patientType = 'external';
    const employeeId = null;

    const hasNurse = document.getElementById('crHasNurse').checked;
    const hasMedtech = document.getElementById('crHasMedtech').checked;

    if (!patientName) {
        showToast('Patient name is required', 'error');
        btn.disabled = false; btn.textContent = 'Save Record';
        return;
    }
    if (!hasNurse && !hasMedtech) {
        showToast('At least one service (Nurse or MedTech) is required', 'error');
        btn.disabled = false; btn.textContent = 'Save Record';
        return;
    }

    const payload = {
        action: 'create',
        csrf_token: _crCsrf,
        patient_type: patientType,
        employee_id: employeeId ? parseInt(employeeId) : null,
        patient_name: patientName,
        record_date: document.getElementById('crRecordDate').value,
        has_nurse: hasNurse ? 1 : 0,
        nurse_employee_id: hasNurse ? (parseInt(document.getElementById('crNurseEmpId').value) || null) : null,
        nurse_service_datetime: hasNurse ? document.getElementById('crNurseDatetime').value : null,
        nurse_notes: hasNurse ? document.getElementById('crNurseNotes').value : '',
        has_medtech: hasMedtech ? 1 : 0,
        medtech_employee_id: hasMedtech ? (parseInt(document.getElementById('crMedtechEmpId').value) || null) : null,
        medtech_pickup_datetime: hasMedtech ? document.getElementById('crMedtechDatetime').value : null,
        medtech_notes: hasMedtech ? document.getElementById('crMedtechNotes').value : '',
    };

    fetch(_crBaseUrl + '/modules/clinic_records/api_action', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Save Record';
        if (data.error) {
            showToast(data.error, 'error');
            return;
        }
        showToast('Record created successfully', 'success');
        clinicCloseCreateModal();
        location.reload();
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Save Record';
        showToast('Failed to create record', 'error');
    });
}

// ─── WIRE UP CREATE MODAL INTERACTIVITY ───
document.addEventListener('DOMContentLoaded', function() {
    // Nurse/MedTech section toggles
    const chkNurse = document.getElementById('crHasNurse');
    const chkMedtech = document.getElementById('crHasMedtech');
    if (chkNurse) chkNurse.addEventListener('change', function() {
        document.getElementById('crNurseSection').style.display = this.checked ? '' : 'none';
    });
    if (chkMedtech) chkMedtech.addEventListener('change', function() {
        document.getElementById('crMedtechSection').style.display = this.checked ? '' : 'none';
    });

    // Setup autocompletes for create modal (nurse/medtech only)
    crSetupAutocomplete('crNurseSearch', 'crNurseResults', 'crNurseEmpId', 'crNurseSelected', null, 'nurse');
    crSetupAutocomplete('crMedtechSearch', 'crMedtechResults', 'crMedtechEmpId', 'crMedtechSelected', null, 'medtech');
});

// Reinitialize on SPA navigation
document.addEventListener('spa:loaded', function() {
    crSetupAutocomplete('crNurseSearch', 'crNurseResults', 'crNurseEmpId', 'crNurseSelected', null, 'nurse');
    crSetupAutocomplete('crMedtechSearch', 'crMedtechResults', 'crMedtechEmpId', 'crMedtechSelected', null, 'medtech');
});

// Close modals on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('clinicCreateModal')?.style.display === 'flex') {
            clinicCloseCreateModal();
        } else {
            clinicCloseModal();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
