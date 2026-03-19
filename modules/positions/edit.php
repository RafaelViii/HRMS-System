<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'positions', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$id = (int)($_GET['id'] ?? 0);
try { $stmt = $pdo->prepare('SELECT * FROM positions WHERE id = :id AND deleted_at IS NULL'); $stmt->execute([':id'=>$id]); $pos = $stmt->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { $pos = null; }
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
        $stmt = $pdo->prepare('UPDATE positions SET department_id = :dept, name = :name, description = :desc, base_salary = :base WHERE id = :id');
        $stmt->execute([':dept'=>$dept, ':name'=>$name, ':desc'=>$desc, ':base'=>$base, ':id'=>$id]);
        audit('update_position', $name);
        action_log('positions', 'update_position', 'success', ['id' => $id, 'name' => $name]);
        header('Location: ' . BASE_URL . '/modules/positions/index');
        exit;
      } catch (Throwable $e) { sys_log('DB2613', 'Execute failed: positions update - ' . $e->getMessage(), ['module'=>'positions','file'=>__FILE__,'line'=>__LINE__]); show_system_error('Could not update position.'); }
    }
  }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php if (!$pos) { echo '<div class="p-3">Not found</div>'; require_once __DIR__ . '/../../includes/footer.php'; exit; } ?>

<div class="flex items-center gap-3 mb-6">
  <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/positions/index">Back to List</a>
  <h1 class="text-xl font-semibold">Edit Position</h1>
</div>

<div class="max-w-xl">
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="bg-white p-4 rounded shadow space-y-3">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label">Department</label>
      <select name="department_id" class="w-full border rounded px-3 py-2">
        <option value="">— None —</option>
        <?php foreach ($deps as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $pos['department_id']==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Name</label>
      <input name="name" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($pos['name']) ?>" required>
    </div>
    <div>
      <label class="form-label">Description</label>
      <textarea name="description" class="w-full border rounded px-3 py-2" rows="3"><?= htmlspecialchars($pos['description']) ?></textarea>
    </div>
    <div>
      <label class="form-label">Base Salary</label>
      <input name="base_salary" type="number" step="0.01" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($pos['base_salary']) ?>">
    </div>
    <div class="flex gap-2">
      <button class="px-3 py-2 bg-blue-600 text-white rounded">Update</button>
  <a class="px-3 py-2 border rounded" href="<?= BASE_URL ?>/modules/positions/index">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
