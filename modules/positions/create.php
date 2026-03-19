<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'positions', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$deps = [];
try { $deps = $pdo->query('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $deps = []; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid CSRF token'; }
  else {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $base = is_numeric($_POST['base_salary'] ?? '') ? (float)$_POST['base_salary'] : 0;
    if ($name === '') { $error = 'Name is required'; }
    else {
      try {
        $stmt = $pdo->prepare('INSERT INTO positions (department_id, name, description, base_salary) VALUES (:dept, :name, :desc, :base)');
        $stmt->execute([
          ':dept' => $dept,
          ':name' => $name,
          ':desc' => $desc,
          ':base' => $base,
        ]);
        audit('create_position', $name);
        action_log('positions', 'create_position', 'success', ['name' => $name]);
        header('Location: ' . BASE_URL . '/modules/positions/index');
        exit;
      } catch (Throwable $e) { sys_log('DB2602', 'Execute failed: positions insert - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]); show_system_error('Could not save position.'); }
    }
  }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="max-w-xl">
  <h1 class="text-xl font-semibold mb-4">Add Position</h1>
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="bg-white p-4 rounded shadow space-y-3">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label">Department</label>
      <select name="department_id" class="w-full border rounded px-3 py-2">
        <option value="">— None —</option>
        <?php foreach ($deps as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Name</label>
      <input name="name" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">Description</label>
      <textarea name="description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
    </div>
    <div>
      <label class="form-label">Base Salary</label>
      <input name="base_salary" type="number" step="0.01" class="w-full border rounded px-3 py-2" value="0">
    </div>
    <div class="flex gap-2">
      <button class="px-3 py-2 bg-blue-600 text-white rounded">Save</button>
  <a class="px-3 py-2 border rounded" href="<?= BASE_URL ?>/modules/positions/index">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
