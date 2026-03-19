<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('hr_core', 'branches', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db_conn();
$defaultBranchId = branches_get_default_id($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token. Please try again.');
        header('Location: ' . BASE_URL . '/modules/admin/branches');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    try {
        switch ($action) {
            case 'create':
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $code = preg_replace('/[^A-Z0-9_-]/', '', $code ?? '');
                $name = trim((string)($_POST['name'] ?? ''));
                $address = trim((string)($_POST['address'] ?? '')) ?: null;
                if ($code === '' || $name === '') {
                    flash_error('Branch code and name are required.');
                    break;
                }
                $chk = $pdo->prepare('SELECT id FROM branches WHERE LOWER(code) = LOWER(:code) LIMIT 1');
                $chk->execute([':code' => $code]);
                if ($chk->fetchColumn()) {
                    flash_error('Branch code already exists.');
                    break;
                }
                $stmt = $pdo->prepare('INSERT INTO branches (code, name, address) VALUES (:code, :name, :address) RETURNING id');
                $stmt->bindValue(':code', $code, PDO::PARAM_STR);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->execute();
                $branchId = (int)($stmt->fetchColumn() ?: 0);
                action_log('branches', 'create', 'success', ['branch_id' => $branchId, 'code' => $code]);
                flash_success('Branch created.');
                break;

            case 'update':
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $code = preg_replace('/[^A-Z0-9_-]/', '', $code ?? '');
                $name = trim((string)($_POST['name'] ?? ''));
                $address = trim((string)($_POST['address'] ?? '')) ?: null;
                if ($branchId <= 0 || $code === '' || $name === '') {
                    flash_error('Branch details are incomplete.');
                    break;
                }
                $chk = $pdo->prepare('SELECT id FROM branches WHERE LOWER(code) = LOWER(:code) AND id <> :id LIMIT 1');
                $chk->execute([':code' => $code, ':id' => $branchId]);
                if ($chk->fetchColumn()) {
                    flash_error('Branch code already exists.');
                    break;
                }
                $update = $pdo->prepare('UPDATE branches SET code = :code, name = :name, address = :address, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $update->bindValue(':code', $code, PDO::PARAM_STR);
                $update->bindValue(':name', $name, PDO::PARAM_STR);
                $update->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $update->bindValue(':id', $branchId, PDO::PARAM_INT);
                $update->execute();
                action_log('branches', 'update', 'success', ['branch_id' => $branchId, 'code' => $code]);
                flash_success('Branch updated.');
                break;

            case 'delete':
                $branchId = (int)($_POST['branch_id'] ?? 0);
                if ($branchId <= 0) {
                    flash_error('Branch not found.');
                    break;
                }
                if ($defaultBranchId && $branchId === (int)$defaultBranchId) {
                    flash_error('The default branch cannot be deleted.');
                    break;
                }
                $authz = ensure_action_authorized('hr', 'delete_branch', 'admin');
                if (!$authz['ok']) {
                    flash_error('Changes could not be saved');
                    break;
                }
                $empCountStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE branch_id = :id');
                $empCountStmt->execute([':id' => $branchId]);
                $employeeCount = (int)$empCountStmt->fetchColumn();
                $userCountStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE branch_id = :id');
                $userCountStmt->execute([':id' => $branchId]);
                $userCount = (int)$userCountStmt->fetchColumn();
                if ($employeeCount > 0 || $userCount > 0) {
                    flash_error('Remove or reassign employees and accounts linked to this branch first.');
                    break;
                }
                if (backup_then_delete($pdo, 'branches', 'id', $branchId)) {
                    action_log('branches', 'delete', 'success', ['branch_id' => $branchId]);
                    flash_success('Branch deleted.');
                } else {
                    flash_error('Unable to delete branch.');
                }
                break;

            default:
                flash_error('Unsupported action.');
                break;
        }
    } catch (Throwable $e) {
        sys_log('ADMIN-BRANCH', 'Branch management failed: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['action' => $action]]);
        flash_error('Changes could not be saved');
    }

    header('Location: ' . BASE_URL . '/modules/admin/branches');
    exit;
}

$branches = branches_fetch_all($pdo);
$employeeCounts = [];
$userCounts = [];
try {
    $st = $pdo->query('SELECT branch_id, COUNT(*) AS total FROM employees WHERE branch_id IS NOT NULL GROUP BY branch_id');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $employeeCounts[(int)$row['branch_id']] = (int)$row['total'];
    }
} catch (Throwable $e) {
    sys_log('ADMIN-BRANCH-EMP', 'Failed counting employees by branch: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}
try {
    $st = $pdo->query('SELECT branch_id, COUNT(*) AS total FROM users WHERE branch_id IS NOT NULL GROUP BY branch_id');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userCounts[(int)$row['branch_id']] = (int)$row['total'];
    }
} catch (Throwable $e) {
    sys_log('ADMIN-BRANCH-USER', 'Failed counting users by branch: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
}

foreach ($branches as &$branch) {
    $id = (int)$branch['id'];
    $branch['employee_count'] = $employeeCounts[$id] ?? 0;
    $branch['user_count'] = $userCounts[$id] ?? 0;
    $branch['is_default'] = $defaultBranchId && $id === (int)$defaultBranchId;
}
unset($branch);

$defaultBranch = null;
foreach ($branches as $branch) {
  if (!empty($branch['is_default'])) {
    $defaultBranch = $branch;
    break;
  }
}

$editingId = (int)($_GET['edit'] ?? 0);
$editingBranch = null;
if ($editingId > 0) {
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $editingId) {
            $editingBranch = $branch;
            break;
        }
    }
    if (!$editingBranch) {
        flash_error('Branch not found.');
        header('Location: ' . BASE_URL . '/modules/admin/branches');
        exit;
    }
}

action_log('branches', 'view_directory', 'success', ['count' => count($branches)]);

require_once __DIR__ . '/../../../includes/header.php';
?>
<div class="space-y-6">
  <div>
    <a
      href="<?= BASE_URL ?>/modules/admin/management"
      class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900"
      data-no-loader
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
      </svg>
      <span>Back to Management Hub</span>
    </a>
  </div>
  <div class="rounded-2xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-700 text-white shadow-lg">
    <div class="p-6 md:p-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div class="space-y-2">
        <h1 class="text-2xl font-semibold">Branch Directory</h1>
        <p class="text-sm text-slate-200/90 max-w-2xl">Manage company branches used for payroll submissions, attendance uploads, and location-aware reporting.</p>
      </div>
      <div class="grid grid-cols-2 gap-3 text-xs text-slate-100/80">
        <div class="rounded-xl border border-slate-500/30 bg-white/10 px-3 py-2">
          <div class="uppercase tracking-wide text-[10px] text-slate-300/80">Total Branches</div>
          <div class="mt-1 text-lg font-semibold"><?= count($branches) ?></div>
        </div>
        <div class="rounded-xl border border-slate-500/30 bg-white/10 px-3 py-2">
          <div class="uppercase tracking-wide text-[10px] text-slate-300/80">Default Branch</div>
          <div class="mt-1 text-sm font-semibold">
            <?= $defaultBranch ? htmlspecialchars($defaultBranch['name']) . (isset($defaultBranch['code']) && $defaultBranch['code'] !== '' ? ' (' . htmlspecialchars($defaultBranch['code']) . ')' : '') : 'Not set' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-[2fr,1fr]">
    <section class="card p-0 overflow-hidden">
      <div class="border-b px-4 py-3 bg-gray-50">
        <h2 class="text-sm font-semibold text-gray-700">Registered Branches</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="table-basic min-w-full">
          <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th class="p-2 text-left">Code</th>
              <th class="p-2 text-left">Name</th>
              <th class="p-2 text-left">Employees</th>
              <th class="p-2 text-left">Accounts</th>
              <th class="p-2 text-left">Address</th>
              <th class="p-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <?php foreach ($branches as $branch): ?>
              <tr class="border-t">
                <td class="p-2 font-mono text-xs"><?= htmlspecialchars($branch['code']) ?></td>
                <td class="p-2 font-semibold text-gray-800">
                  <?= htmlspecialchars($branch['name']) ?>
                  <?php if (!empty($branch['is_default'])): ?>
                    <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">Default</span>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-gray-700"><?= number_format((int)$branch['employee_count']) ?></td>
                <td class="p-2 text-gray-700"><?= number_format((int)$branch['user_count']) ?></td>
                <td class="p-2 text-gray-600 max-w-[220px]"><?= $branch['address'] ? htmlspecialchars($branch['address']) : '<span class="text-gray-400">—</span>' ?></td>
                <td class="p-2">
                  <div class="flex flex-wrap items-center gap-2 text-sm">
                    <a class="text-blue-600" href="<?= BASE_URL ?>/modules/admin/branches?edit=<?= (int)$branch['id'] ?>">Edit</a>
                    <?php $canDelete = empty($branch['is_default']) && !(int)$branch['employee_count'] && !(int)$branch['user_count']; ?>
                    <form method="post" class="inline"
                      data-confirm="Delete branch '<?= htmlspecialchars($branch['name']) ?>'?"
                      data-authz-module="hr" data-authz-required="admin" data-authz-force
                      data-authz-action="Delete branch <?= htmlspecialchars($branch['name']) ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="branch_id" value="<?= (int)$branch['id'] ?>">
                      <button type="submit" class="<?= $canDelete ? 'text-red-600' : 'text-gray-400 cursor-not-allowed' ?>" <?= $canDelete ? '' : 'disabled' ?>>Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; if (!$branches): ?>
              <tr><td class="p-3 text-sm text-gray-500" colspan="6">No branches configured yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card p-4 space-y-4">
      <?php if ($editingBranch): ?>
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-gray-700">Edit Branch</h2>
          <a href="<?= BASE_URL ?>/modules/admin/branches" class="text-xs text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="branch_id" value="<?= (int)$editingBranch['id'] ?>">
          <div>
            <label class="form-label">Code</label>
            <input name="code" value="<?= htmlspecialchars($editingBranch['code']) ?>" class="w-full border rounded px-3 py-2 uppercase" maxlength="20" required>
          </div>
          <div>
            <label class="form-label">Name</label>
            <input name="name" value="<?= htmlspecialchars($editingBranch['name']) ?>" class="w-full border rounded px-3 py-2" required>
          </div>
          <div>
            <label class="form-label">Address</label>
            <textarea name="address" class="w-full border rounded px-3 py-2" rows="3" placeholder="Optional branch address"><?= htmlspecialchars($editingBranch['address'] ?? '') ?></textarea>
          </div>
          <div class="flex gap-2">
            <button class="btn btn-primary">Save Changes</button>
            <a class="btn" href="<?= BASE_URL ?>/modules/admin/branches">Cancel</a>
          </div>
        </form>
      <?php else: ?>
        <h2 class="text-sm font-semibold text-gray-700">Create Branch</h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="create">
          <div>
            <label class="form-label">Code</label>
            <input name="code" class="w-full border rounded px-3 py-2 uppercase" maxlength="20" placeholder="e.g. QC" required>
          </div>
          <div>
            <label class="form-label">Name</label>
            <input name="name" class="w-full border rounded px-3 py-2" placeholder="Quezon City" required>
          </div>
          <div>
            <label class="form-label">Address</label>
            <textarea name="address" class="w-full border rounded px-3 py-2" rows="3" placeholder="Optional branch address"></textarea>
          </div>
          <button class="btn btn-primary w-full">Add Branch</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
