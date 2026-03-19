<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'departments', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid CSRF token'; }
  else {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name === '') { $error = 'Name is required'; }
    else {
      try {
        $stmt = $pdo->prepare('INSERT INTO departments (name, description) VALUES (:name, :desc)');
        $stmt->execute([':name' => $name, ':desc' => $desc]);
        audit('create_department', $name);
        action_log('departments', 'create_department', 'success', ['name' => $name]);
        header('Location: ' . BASE_URL . '/modules/departments/index');
        exit;
      } catch (Throwable $e) {
        // PostgreSQL unique violation SQLSTATE 23505
        if (method_exists($e, 'getCode') && (string)$e->getCode() === '23505') {
          $error = 'Department already exists.';
        } else {
          sys_log('DB2502', 'Execute failed: departments insert - ' . $e->getMessage(), ['module'=>'departments','file'=>__FILE__,'line'=>__LINE__]);
          show_system_error('Could not save department.');
        }
      }
    }
  }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="max-w-xl">
  <h1 class="text-xl font-semibold mb-4">Add Department</h1>
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="bg-white p-4 rounded shadow space-y-3">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label">Name</label>
      <input name="name" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="form-label">Description</label>
      <textarea name="description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
    </div>
    <div class="flex gap-2">
      <button class="px-3 py-2 bg-blue-600 text-white rounded">Save</button>
  <a class="px-3 py-2 border rounded" href="<?= BASE_URL ?>/modules/departments/index">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
