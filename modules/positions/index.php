<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'positions', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// Fetch departments for create modal
$departments = [];
try { $departments = $pdo->query('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $departments = []; }

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
  if (!user_can('hr_core', 'positions', 'write')) {
    flash_error('You do not have permission to create positions.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid security token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
  $base = is_numeric($_POST['base_salary'] ?? '') ? (float)$_POST['base_salary'] : 0;
  if ($name === '') {
    flash_error('Position name is required.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  try {
    $stmt = $pdo->prepare('INSERT INTO positions (department_id, name, description, base_salary) VALUES (:dept, :name, :desc, :base)');
    $stmt->execute([':dept' => $dept, ':name' => $name, ':desc' => $desc, ':base' => $base]);
    audit('create_position', $name);
    action_log('positions', 'create_position', 'success', ['name' => $name]);
    flash_success('Position created successfully.');
  } catch (Throwable $e) {
    if (method_exists($e, 'getCode') && (string)$e->getCode() === '23505') {
      flash_error('A position with that name already exists.');
    } else {
      sys_log('DB2602', 'Execute failed: positions insert - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]);
      flash_error('Could not save position.');
    }
  }
  header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  // Require write access for destructive operations
  if (!user_can('hr_core', 'positions', 'write')) {
    flash_error('You do not have permission to delete positions.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid security token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  $id = (int)$_POST['delete_id'];
  // Check for active employees before deleting
  try {
    $empStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE position_id = :id AND deleted_at IS NULL AND status = \'active\'');
    $empStmt->execute([':id' => $id]);
    $empCount = (int)$empStmt->fetchColumn();
  } catch (Throwable $e) { $empCount = 0; }
  if ($empCount > 0) {
    flash_error('Cannot delete position: ' . $empCount . ' active employee(s) are still assigned. Please reassign them first.');
    header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
  }
  if (backup_then_delete($pdo, 'positions', 'id', $id)) {
    audit('delete_position', 'ID=' . $id);
    action_log('positions', 'delete_position', 'success', ['position_id' => $id]);
    flash_success('Position deleted successfully');
  } else {
    sys_log('DB2621', 'backup_then_delete failed for positions', ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]);
    flash_error('Changes could not be saved');
  }
  header('Location: ' . BASE_URL . '/modules/positions/index'); exit;
}

$q = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per = 10;
$where = '';
if ($q !== '') { $where = "WHERE (p.name ILIKE :like1 OR d.name ILIKE :like2) AND p.deleted_at IS NULL"; }
else { $where = "WHERE p.deleted_at IS NULL"; }
$countSql = 'SELECT COUNT(*) FROM positions p LEFT JOIN departments d ON d.id=p.department_id AND d.deleted_at IS NULL ' . $where;
if ($where) {
  try { $stmt = $pdo->prepare($countSql); $like = "%$q%"; $stmt->execute([':like1'=>$like, ':like2'=>$like]); $total = (int)$stmt->fetchColumn(); }
  catch (Throwable $e) { sys_log('DB2622', 'Prepare/execute failed: positions count - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]); $total = 0; }
}
else {
  try { $total = (int)$pdo->query($countSql)->fetchColumn(); }
  catch (Throwable $e) { sys_log('DB2623', 'Query failed: positions count - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]); $total = 0; }
}
[$offset,$limit,$page,$pages] = paginate($total, $page, $per);

$sql = 'SELECT p.*, d.name AS dept,
  (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id AND e.status = \'active\' AND e.deleted_at IS NULL) as employee_count
FROM positions p LEFT JOIN departments d ON d.id=p.department_id AND d.deleted_at IS NULL '
  . $where . ' ORDER BY p.id DESC LIMIT :limit OFFSET :offset';
try {
  $stmt = $pdo->prepare($sql);
  if ($q !== '') { $like = "%$q%"; $stmt->bindValue(':like1', $like, PDO::PARAM_STR); $stmt->bindValue(':like2', $like, PDO::PARAM_STR); }
  $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { sys_log('DB2624', 'Prepare/execute failed: positions list - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]); $rows = []; }
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="flex flex-col gap-3 mb-4 md:flex-row md:items-center md:justify-between">
  <div class="flex items-center gap-3">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/index">Back</a>
    <h1 class="text-xl font-semibold">Positions</h1>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button class="btn btn-primary" onclick="openModal('createPosModal')">+ Add Position</button>
    <a class="btn btn-accent" href="<?= BASE_URL ?>/modules/positions/csv?q=<?= urlencode($q) ?>" target="_blank" rel="noopener" data-no-loader>Export CSV</a>
  </div>
</div>

<form class="mb-3 flex gap-2" method="get">
  <input name="q" value="<?= htmlspecialchars($q) ?>" class="input-text" placeholder="Search position or department">
  <button class="btn btn-outline" type="submit">Filter</button>
  <?php if ($q !== ''): ?>
    <a class="btn btn-light" href="<?= BASE_URL ?>/modules/positions/index">Reset</a>
  <?php endif; ?>
</form>

<div class="card overflow-hidden rounded-xl">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gradient-to-r from-slate-50 to-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Position</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Department</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Employees</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Base Salary</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-100">
        <?php foreach ($rows as $r): ?>
        <tr class="hover:bg-slate-50 transition-colors">
          <td class="px-4 py-3">
            <span class="font-semibold text-gray-900"><?= htmlspecialchars($r['name']) ?></span>
          </td>
          <td class="px-4 py-3">
            <span class="text-sm text-gray-600"><?= htmlspecialchars($r['dept'] ?? '-') ?></span>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-full text-xs font-medium">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <span class="font-semibold"><?= (int)$r['employee_count'] ?></span>
            </span>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-1 font-mono text-sm font-medium text-emerald-700">
              <span class="text-xs text-gray-500">₱</span><?= number_format((float)$r['base_salary'],2) ?>
            </span>
          </td>
          <td class="px-4 py-3 text-sm">
            <div class="flex items-center gap-3">
              <a class="text-blue-600 hover:text-blue-800 font-medium transition-colors" href="<?= BASE_URL ?>/modules/positions/edit?id=<?= $r['id'] ?>">Edit</a>
              <form method="post" class="inline" data-confirm="Delete position '<?= htmlspecialchars($r['name']) ?>'? This cannot be undone.">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                <button class="text-red-600 hover:text-red-800 font-medium transition-colors">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; if (!$rows): ?>
        <tr>
          <td class="px-4 py-8 text-center text-sm text-gray-500" colspan="4">No records found.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 0): ?>
<div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 px-1">
  <div class="text-xs sm:text-sm text-slate-500">
    <?php if ($total > 0): ?>
      <span class="hidden sm:inline">Showing </span><?= $offset + 1 ?>–<?= min($offset + $limit, $total) ?> of <?= $total ?> position<?= $total !== 1 ? 's' : '' ?>
      <?php if ($pages > 1): ?><span class="text-slate-400 ml-1">(Page <?= $page ?> of <?= $pages ?>)</span><?php endif; ?>
    <?php else: ?>
      0 positions
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
  <div class="flex items-center gap-1">
    <?php if ($page > 1): ?>
    <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-colors" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" title="Previous page">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <?php endif; ?>
    <?php
      $start = max(1, $page - 2);
      $end = min($pages, $page + 2);
      if ($start > 1): ?>
        <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 text-xs sm:text-sm font-medium" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
        <?php if ($start > 2): ?><span class="text-slate-400 px-1">…</span><?php endif; ?>
    <?php endif;
      for ($i = $start; $i <= $end; $i++):
    ?>
    <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border text-xs sm:text-sm font-medium transition-colors <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
      <?= $i ?>
    </a>
    <?php endfor;
      if ($end < $pages): ?>
        <?php if ($end < $pages - 1): ?><span class="text-slate-400 px-1">…</span><?php endif; ?>
        <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 text-xs sm:text-sm font-medium" href="?<?= http_build_query(array_merge($_GET, ['page' => $pages])) ?>"><?= $pages ?></a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
    <a class="inline-flex items-center justify-center h-8 w-8 sm:h-9 sm:w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-colors" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" title="Next page">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<!-- Create Position Modal -->
<div id="createPosModal" class="fixed inset-0 z-50 hidden">
  <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" onclick="closeModal('createPosModal')"></div>
  <div class="fixed inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg relative animate-fade-in">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
        <h2 class="text-lg font-semibold text-slate-900">Add Position</h2>
        <button type="button" onclick="closeModal('createPosModal')" class="text-slate-400 hover:text-slate-600 transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <form method="post" action="<?= BASE_URL ?>/modules/positions/index">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <div class="px-6 py-5 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Department</label>
            <select name="department_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition">
              <option value="">— None —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1 required">Position Name</label>
            <input name="name" type="text" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="e.g. Software Engineer">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
            <textarea name="description" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="Brief description of the position"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Base Salary</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">₱</span>
              <input name="base_salary" type="number" step="0.01" min="0" value="0" class="w-full border border-slate-300 rounded-lg pl-8 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition">
            </div>
          </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
          <button type="button" onclick="closeModal('createPosModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Position</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
