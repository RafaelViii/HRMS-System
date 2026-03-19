<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// Resolve employee and department (if any)
$emp = null; $deptId = null;
try {
  $st = $pdo->prepare('SELECT id, department_id FROM employees WHERE user_id = :uid LIMIT 1');
  $st->execute([':uid' => $uid]);
  $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  $deptId = $emp ? (int)($emp['department_id'] ?? 0) : null;
} catch (Throwable $e) { $emp = null; $deptId = null; }

// List documents addressed to this user, their department, or global (no assignment)
$rows = [];
try {
  $sql = "
    SELECT d.id, d.title, d.doc_type, d.file_path, d.created_at
    FROM documents d
    LEFT JOIN document_assignments da ON da.document_id = d.id
    LEFT JOIN employees e ON e.id = da.employee_id
    WHERE (
      da.employee_id = :eid OR da.department_id = :dept OR (da.employee_id IS NULL AND da.department_id IS NULL)
    )
    GROUP BY d.id
    ORDER BY d.id DESC, d.created_at DESC
    LIMIT 200";
  $q = $pdo->prepare($sql);
  $q->execute([':eid' => (int)($emp['id'] ?? 0), ':dept' => $deptId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="card p-4">
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-2">
    <h1 class="text-xl font-semibold">Documents & Memos</h1>
    <div class="dropdown">
      <button class="btn btn-accent" data-dd-toggle>Export</button>
      <div class="dropdown-menu hidden">
        <a class="dropdown-item csv" href="<?= BASE_URL ?>/modules/documents/csv.php" target="_blank" data-no-loader>CSV</a>
        <a class="dropdown-item pdf" href="#">PDF</a>
      </div>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="table-basic min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left">Title</th>
          <th class="p-2 text-left">Type</th>
          <th class="p-2 text-left">Created</th>
          <th class="p-2 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="border-t">
          <td class="p-2 font-medium"><?= htmlspecialchars($r['title']) ?></td>
          <td class="p-2"><?= htmlspecialchars($r['doc_type']) ?></td>
          <td class="p-2 text-gray-600"><?= htmlspecialchars($r['created_at']) ?></td>
          <td class="p-2">
            <div class="action-links">
              <a class="text-blue-700" href="<?= BASE_URL ?>/<?= ltrim($r['file_path'], '/') ?>" target="_blank" rel="noopener">Open</a>
            </div>
          </td>
        </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td class="p-3 text-gray-500" colspan="4">No documents.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
