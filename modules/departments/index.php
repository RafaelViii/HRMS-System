<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'departments', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
  if (!user_can('hr_core', 'departments', 'write')) {
    flash_error('You do not have permission to create departments.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid security token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  if ($name === '') {
    flash_error('Department name is required.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  try {
    $stmt = $pdo->prepare('INSERT INTO departments (name, description) VALUES (:name, :desc)');
    $stmt->execute([':name' => $name, ':desc' => $desc]);
    audit('create_department', $name);
    action_log('departments', 'create_department', 'success', ['name' => $name]);
    flash_success('Department created successfully.');
  } catch (Throwable $e) {
    if (method_exists($e, 'getCode') && (string)$e->getCode() === '23505') {
      flash_error('A department with that name already exists.');
    } else {
      sys_log('DB2502', 'Execute failed: departments insert - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
      flash_error('Could not save department.');
    }
  }
  header('Location: ' . BASE_URL . '/modules/departments/index');
  exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  // Require write access for destructive operations
  if (!user_can('hr_core', 'departments', 'write')) {
    flash_error('You do not have permission to delete departments.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid security token. Please try again.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  $id = (int)$_POST['delete_id'];
  // Check for active employees before deleting
  try {
    $empStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE department_id = :id AND deleted_at IS NULL AND status = \'active\'');
    $empStmt->execute([':id' => $id]);
    $empCount = (int)$empStmt->fetchColumn();
  } catch (Throwable $e) { $empCount = 0; }
  if ($empCount > 0) {
    flash_error('Cannot delete department: ' . $empCount . ' active employee(s) are still assigned. Please reassign them first.');
    header('Location: ' . BASE_URL . '/modules/departments/index');
    exit;
  }
  if (backup_then_delete($pdo, 'departments', 'id', $id)) {
    audit('delete_department', 'ID=' . $id);
    action_log('departments', 'delete_department', 'success', ['department_id' => $id]);
    flash_success('Department deleted successfully');
  } else {
    sys_log('DB2521', 'backup_then_delete failed for departments', ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]);
    flash_error('Changes could not be saved');
  }
  header('Location: ' . BASE_URL . '/modules/departments/index');
  exit;
}

$q = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per = 10;
$where = 'WHERE deleted_at IS NULL';
$like = '';
if ($q !== '') {
  $where = "WHERE name ILIKE :q AND deleted_at IS NULL";
  $like = "%$q%";
}
$countSql = 'SELECT COUNT(*) FROM departments ' . $where;
if ($q !== '') {
  $stmt = $pdo->prepare($countSql);
  try { $stmt->execute([':q' => $like]); $total = (int)$stmt->fetchColumn(); }
  catch (Throwable $e) { sys_log('DB2522', 'Prepare failed: departments count - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]); $total = 0; }
}
else { try { $total = (int)$pdo->query($countSql)->fetchColumn(); } catch (Throwable $e) { sys_log('DB2523', 'Query failed: departments count - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]); $total = 0; } }
[$offset, $limit, $page, $pages] = paginate($total, $page, $per);

$sql = 'SELECT d.*, 
  (SELECT COUNT(*) FROM department_supervisors ds WHERE ds.department_id = d.id) as supervisor_count,
  (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.status = \'active\' AND e.deleted_at IS NULL) as employee_count
FROM departments d ' . $where . ' ORDER BY d.id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
if ($q !== '') {
  $stmt->bindValue(':q', $like, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
try { $stmt->execute(); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { sys_log('DB2524', 'Prepare failed: departments list - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]); $rows = []; }
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="flex flex-col gap-3 mb-4 md:flex-row md:items-center md:justify-between">
  <div class="flex items-center gap-3">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/index">Back</a>
    <h1 class="text-xl font-semibold">Departments</h1>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button class="btn btn-primary" onclick="openModal('createDeptModal')">+ Add Department</button>
    <a class="btn btn-accent" href="<?= BASE_URL ?>/modules/departments/csv?q=<?= urlencode($q) ?>" target="_blank" rel="noopener" data-no-loader>Export CSV</a>
  </div>
</div>

<form class="mb-3 flex gap-2" method="get">
  <input name="q" value="<?= htmlspecialchars($q) ?>" class="input-text" placeholder="Search name">
  <button class="btn btn-outline" type="submit">Filter</button>
  <?php if ($q !== ''): ?>
    <a class="btn btn-light" href="<?= BASE_URL ?>/modules/departments/index">Reset</a>
  <?php endif; ?>
</form>

<div class="card overflow-hidden rounded-xl">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gradient-to-r from-slate-50 to-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Description</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Employees</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Supervisors</th>
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
            <span class="text-sm text-gray-600"><?= htmlspecialchars($r['description']) ?></span>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-full text-xs font-medium">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <span class="font-semibold"><?= (int)$r['employee_count'] ?></span>
              <span>employee<?= (int)$r['employee_count'] !== 1 ? 's' : '' ?></span>
            </span>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-medium">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <span class="font-semibold"><?= (int)$r['supervisor_count'] ?></span>
              <span>supervisor<?= (int)$r['supervisor_count'] !== 1 ? 's' : '' ?></span>
            </span>
          </td>
          <td class="px-4 py-3 text-sm">
            <div class="flex items-center gap-3">
              <a class="text-blue-600 hover:text-blue-800 font-medium transition-colors" href="<?= BASE_URL ?>/modules/departments/edit?id=<?= $r['id'] ?>">Edit</a>
              <form method="post" class="inline" data-confirm="Delete department '<?= htmlspecialchars($r['name']) ?>'? This cannot be undone.">
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

<?php if ($pages > 1): ?>
<div class="mt-4 flex flex-wrap gap-2">
  <?php for ($i=1;$i<=$pages;$i++): ?>
  <a class="btn btn-outline <?= $i==$page?' bg-slate-100 border-slate-300':'' ?>" href="?q=<?= urlencode($q) ?>&page=<?= $i ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<!-- Create Department Modal -->
<div id="createDeptModal" class="fixed inset-0 z-50 hidden">
  <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" onclick="closeModal('createDeptModal')"></div>
  <div class="fixed inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg relative animate-fade-in">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
        <h2 class="text-lg font-semibold text-slate-900">Add Department</h2>
        <button type="button" onclick="closeModal('createDeptModal')" class="text-slate-400 hover:text-slate-600 transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <form method="post" action="<?= BASE_URL ?>/modules/departments/index">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <div class="px-6 py-5 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1 required">Department Name</label>
            <input name="name" type="text" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="e.g. Human Resources">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
            <textarea name="description" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="Brief description of the department"></textarea>
          </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
          <button type="button" onclick="closeModal('createDeptModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Department</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
