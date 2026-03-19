<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'backup_restore', 'read');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/header.php';
$pdo = get_db_conn();

$targets = [
  'departments','positions','employees','users','payroll','leave_requests'
];

$results = [];
foreach ($targets as $t) {
  $main = $t;
  $backup = $t . '_backup';
  $exists = false;
  try {
    $stmt = $pdo->prepare("SELECT to_regclass(:tbl) AS exists");
    $stmt->execute([':tbl' => $backup]);
    $exists = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { $exists = false; }
  $results[] = [
    'table' => $main,
    'backup' => $backup,
    'exists' => $exists,
  ];
}

// Optionally create missing ones when requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
  $created = 0;
  foreach ($targets as $t) {
    try { $pdo->exec('CREATE TABLE IF NOT EXISTS "' . pg_ident($t . '_backup') . '" (LIKE "' . pg_ident($t) . '" INCLUDING ALL)'); $created++; } catch (Throwable $e) {}
  }
  flash_success('Ensured backup tables exist.');
  header('Location: ' . BASE_URL . '/modules/admin/tools/backup_tables_check'); exit;
}
?>
<div class="bg-white p-4 rounded shadow">
  <h1 class="text-xl font-semibold mb-2">Backup Tables Check</h1>
  <p class="text-gray-600 mb-3">Verifies presence of backup tables used before deletes. You can create missing ones.</p>
  <?php if ($m = flash_get('success')): ?><div class="bg-emerald-50 text-emerald-700 p-2 mb-3 rounded text-sm"><?php echo htmlspecialchars($m) ?></div><?php endif; ?>
  <table class="min-w-full">
    <thead class="bg-gray-50">
      <tr><th class="text-left p-2">Main table</th><th class="text-left p-2">Backup table</th><th class="text-left p-2">Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr class="border-t">
        <td class="p-2 font-mono text-sm"><?php echo htmlspecialchars($r['table']) ?></td>
        <td class="p-2 font-mono text-sm"><?php echo htmlspecialchars($r['backup']) ?></td>
        <td class="p-2 text-sm"><?php echo $r['exists'] ? 'Present' : 'Missing' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" class="mt-3">
    <input type="hidden" name="csrf" value="<?php echo csrf_token() ?>">
    <button class="px-3 py-2 bg-blue-600 text-white rounded">Create Missing</button>
    <a class="px-3 py-2 border rounded ml-2" href="<?php echo BASE_URL ?>/modules/admin/index">Back</a>
  </form>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
