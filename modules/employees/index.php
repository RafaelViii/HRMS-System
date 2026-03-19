<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'employees', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// Determine current user's access to this module for UI behavior
$me = $_SESSION['user'] ?? null; $myId = (int)($me['id'] ?? 0);
$myLevel = user_has_access($myId, 'hr_core', 'employees', 'write') ? 'write' : (user_has_access($myId, 'hr_core', 'employees', 'read') ? 'read' : 'none');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && csrf_verify($_POST['csrf'] ?? '')) {
  // Server-side guard: require write or single-use override by an authorized admin
  $authz = ensure_action_authorized('employees', 'delete_employee', 'write');
  if (!$authz['ok']) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/index'); 
    exit;
  }
  $id = (int)$_POST['delete_id'];
  
  // Before deletion, handle FK constraints that are NO ACTION
  try {
    // Nullify deleted_by references in other tables to prevent FK violation
    $pdo->prepare('UPDATE departments SET deleted_by = NULL WHERE deleted_by = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE documents SET deleted_by = NULL WHERE deleted_by = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE employees SET deleted_by = NULL WHERE deleted_by = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE memos SET deleted_by = NULL WHERE deleted_by = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE positions SET deleted_by = NULL WHERE deleted_by = :id')->execute([':id' => $id]);
    
    // Note: audit_logs has NO ACTION FK but we keep audit trail integrity
    // If employee has audit_logs, we cannot delete them (intentional data preservation)
    $auditCheck = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE employee_id = :id');
    $auditCheck->execute([':id' => $id]);
    $auditCount = (int)$auditCheck->fetchColumn();
    
    if ($auditCount > 0) {
      flash_error('Cannot delete employee with audit history. Archive instead.');
      header('Location: ' . BASE_URL . '/modules/employees/index'); 
      exit;
    }
  } catch (Throwable $e) {
    sys_log('DB2202', 'FK cleanup failed before employee deletion: ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]);
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/index'); 
    exit;
  }
  
  // Fetch linked account (if any) and delete it first so the user cannot log in anymore
  $uid = null;
  $st = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id AND deleted_at IS NULL');
  $st->execute([':id' => $id]);
  $uid = (int)($st->fetchColumn() ?? 0) ?: null;
  if ($uid) {
    // Best-effort cleanup of permissions (table may not exist)
    try { $pdo->prepare('DELETE FROM user_access_permissions WHERE user_id = :uid')->execute([':uid' => $uid]); } catch (Throwable $e) {}
    // Ensure users_backup table exists (PostgreSQL)
    try { $pdo->exec('CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL)'); } catch (Throwable $e) {}
    // Backup user before delete
    try {
      $bk = $pdo->prepare('INSERT INTO users_backup SELECT * FROM users WHERE id = :id');
      $bk->execute([':id' => $uid]);
    } catch (Throwable $e) {}
    $du = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $du->execute([':id' => $uid]);
    audit('delete_account_cascade', 'user_id=' . $uid . ' via employee_id=' . $id);
  }
  if (backup_then_delete($pdo, 'employees', 'id', $id)) {
    audit('delete_employee', 'ID=' . $id);
    action_log('employees', 'delete_employee', 'success', ['id' => $id]);
    flash_success('Changes have been saved');
  } else {
    sys_log('DB2201', 'backup_then_delete failed for employees', ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'func'=>__FUNCTION__,'context'=>['id'=>$id]]);
    flash_error('Changes could not be saved');
  }
  header('Location: ' . BASE_URL . '/modules/employees/index'); exit;
}

// Read-only users can request a single-use override to proceed to Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go_edit_id']) && csrf_verify($_POST['csrf'] ?? '')) {
  $authz = ensure_action_authorized('employees', 'edit_employee', 'write');
  if (!$authz['ok']) {
    flash_error('Changes could not be saved');
    header('Location: ' . BASE_URL . '/modules/employees/index'); 
    exit;
  }
  $id = (int)$_POST['go_edit_id'];
  // Issue a single-use token and redirect to edit page carrying it
  $tok = issue_override_token('employees', 'edit_employee', $id, 180);
  header('Location: ' . BASE_URL . '/modules/employees/edit?id=' . $id . '&authz=' . urlencode($tok));
  exit;
}

$q = trim($_GET['q'] ?? '');
$where = 'WHERE e.deleted_at IS NULL';
$like = '';
if ($q !== '') { 
  $where = 'WHERE (e.first_name ILIKE :q OR e.last_name ILIKE :q OR e.email ILIKE :q) AND e.deleted_at IS NULL'; 
  $like = "%$q%";
}

$countSql = 'SELECT COUNT(*) FROM employees e ' . $where;
$like = "%$q%";
try {
  if ($q !== '') { $stmt = $pdo->prepare($countSql); $stmt->execute([':q' => $like]); $total = (int)$stmt->fetchColumn(); }
  else { $total = (int)$pdo->query($countSql)->fetchColumn(); }
} catch (Throwable $e) { sys_log('DB2203', 'Count failed: employees - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]); $total = 0; }

$page = (int)($_GET['page'] ?? 1);
[$offset,$limit,$page,$pages] = paginate($total, $page, 10);

$sql = 'SELECT e.*, d.name AS dept, p.name AS pos FROM employees e 
LEFT JOIN departments d ON d.id=e.department_id AND d.deleted_at IS NULL
LEFT JOIN positions p ON p.id=e.position_id AND p.deleted_at IS NULL
' . $where . ' ORDER BY e.id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
if ($q !== '') { $stmt->bindValue(':q', $like, PDO::PARAM_STR); }
$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
try { $stmt->execute(); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { sys_log('DB2204', 'List failed: employees - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]); $rows = []; }
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="flex flex-col gap-3 mb-4 md:flex-row md:items-center md:justify-between">
  <div class="flex items-center gap-3">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/index">Back</a>
    <h1 class="text-xl font-semibold">Employees</h1>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/employees/create">Add Employee</a>
    <a class="btn btn-accent" href="<?= BASE_URL ?>/modules/employees/csv_list?q=<?= urlencode($q) ?>" target="_blank" rel="noopener" data-no-loader>Export CSV</a>
  </div>
</div>

<form class="mb-3 flex flex-wrap gap-2" method="get">
  <input name="q" value="<?= htmlspecialchars($q) ?>" class="input-text flex-1 min-w-0" placeholder="Search name/email">
  <button class="btn btn-outline" type="submit">Filter</button>
  <?php if ($q !== ''): ?>
    <a class="btn btn-light" href="<?= BASE_URL ?>/modules/employees/index">Reset</a>
  <?php endif; ?>
</form>

<div class="card overflow-hidden rounded-xl">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gradient-to-r from-slate-50 to-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Code</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Department</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Position</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
          <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-100">
        <?php foreach ($rows as $r): ?>
        <tr class="hover:bg-slate-50 transition-colors">
          <td class="px-4 py-3 whitespace-nowrap">
            <span class="font-mono text-sm font-medium text-slate-700"><?= htmlspecialchars($r['employee_code']) ?></span>
          </td>
          <td class="px-4 py-3">
            <div class="font-medium text-gray-900"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
            <div class="text-xs text-gray-500"><?= htmlspecialchars($r['email']) ?></div>
          </td>
          <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($r['dept'] ?? '-') ?></td>
          <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($r['pos'] ?? '-') ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium <?= $r['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
              <?= htmlspecialchars(ucfirst($r['status'])) ?>
            </span>
          </td>
          <td class="px-4 py-3 text-sm">
            <div class="flex items-center gap-3">
              <a class="text-blue-600 hover:text-blue-800 font-medium transition-colors" href="<?= BASE_URL ?>/modules/employees/view?id=<?= $r['id'] ?>">View</a>
              <?php if ($myLevel !== 'none'): ?>
              <a class="text-emerald-600 hover:text-emerald-800 font-medium transition-colors" href="<?= BASE_URL ?>/modules/employees/pdf_profile?id=<?= $r['id'] ?>" target="_blank" rel="noopener">PDF</a>
              <?php endif; ?>
              <?php if ($myLevel === 'write' || $myLevel === 'admin'): ?>
              <a class="text-indigo-600 hover:text-indigo-800 font-medium transition-colors" href="<?= BASE_URL ?>/modules/employees/edit?id=<?= $r['id'] ?>">Edit</a>
              <?php elseif ($myLevel === 'read'): ?>
              <form method="post" class="inline" data-authz-module="employees" data-authz-required="write">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="go_edit_id" value="<?= $r['id'] ?>">
                <button type="submit" class="text-indigo-600 hover:text-indigo-800 font-medium transition-colors">Edit</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; if (!$rows): ?>
        <tr>
          <td class="px-4 py-8 text-center text-sm text-gray-500" colspan="6">No records found.</td>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
