<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$u = current_user();
$level = user_access_level((int)$u['id'], 'recruitment');
if (access_level_rank($level) < access_level_rank('manage')) { header('Location: ' . BASE_URL . '/unauthorized'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid CSRF'; }
  else {
    if (isset($_POST['create_template'])) {
      $name = trim($_POST['name'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      if ($name !== '') {
        try { $st = $pdo->prepare('INSERT INTO recruitment_templates (name, description) VALUES (:name, :desc)'); $st->execute([':name'=>$name, ':desc'=>$desc]); flash_success('Template created'); header('Location: ' . BASE_URL . '/modules/recruitment/templates'); exit; } catch (Throwable $e) { $error = 'Failed to save'; }
      } else { $error = 'Name is required'; }
    }
    if (isset($_POST['add_field'])) {
      $tid = (int)($_POST['template_id'] ?? 0); $fname = trim($_POST['field_name'] ?? ''); $req = (int)($_POST['is_required'] ?? 1);
      try {
        // Upsert on unique (template_id, field_name)
        $st = $pdo->prepare('INSERT INTO recruitment_template_fields (template_id, field_name, is_required) VALUES (:tid, :fname, :req)
          ON CONFLICT (template_id, field_name) DO UPDATE SET is_required = EXCLUDED.is_required');
        $st->execute([':tid'=>$tid, ':fname'=>$fname, ':req'=>$req]);
      } catch (Throwable $e) {}
    }
    if (isset($_POST['add_file_label'])) {
      $tid = (int)($_POST['template_id'] ?? 0); $lbl = trim($_POST['label'] ?? ''); $req = (int)($_POST['is_required'] ?? 1);
      try {
        // Upsert on unique (template_id, label)
        $st = $pdo->prepare('INSERT INTO recruitment_template_files (template_id, label, is_required) VALUES (:tid, :lbl, :req)
          ON CONFLICT (template_id, label) DO UPDATE SET is_required = EXCLUDED.is_required');
        $st->execute([':tid'=>$tid, ':lbl'=>$lbl, ':req'=>$req]);
      } catch (Throwable $e) {}
    }
  }
}
$tpls = [];
try { $tpls = $pdo->query('SELECT * FROM recruitment_templates ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $tpls = []; }
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-5xl">
  <h1 class="text-xl font-semibold mb-4">Recruitment Templates</h1>
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="bg-white p-4 rounded shadow mb-4">
    <form method="post" class="grid md:grid-cols-3 gap-2">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="create_template" value="1">
      <input name="name" placeholder="Template name" class="border rounded px-2 py-1">
      <input name="description" placeholder="Description" class="border rounded px-2 py-1">
      <button class="px-3 py-2 bg-blue-600 text-white rounded">Add Template</button>
    </form>
  </div>
  <?php foreach ($tpls as $t):
    $fields = [];
    $files = [];
    try { $fields = $pdo->query('SELECT * FROM recruitment_template_fields WHERE template_id='.(int)$t['id'].' ORDER BY field_name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $files = $pdo->query('SELECT * FROM recruitment_template_files WHERE template_id='.(int)$t['id'].' ORDER BY label')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  ?>
    <div class="bg-white p-4 rounded shadow mb-4">
      <div class="font-semibold mb-2"><?= htmlspecialchars($t['name']) ?></div>
      <div class="text-sm text-gray-600 mb-3"><?= htmlspecialchars($t['description'] ?? '') ?></div>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <div class="font-medium mb-1">Required Fields</div>
          <form method="post" class="flex gap-2 items-center mb-2">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="add_field" value="1">
            <select name="field_name" class="border rounded px-2 py-1">
              <?php foreach (['full_name','email','phone','position_applied'] as $fn): ?>
                <option value="<?= $fn ?>"><?= $fn ?></option>
              <?php endforeach; ?>
            </select>
            <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="is_required" value="1" checked> Required</label>
            <button class="px-2 py-1 bg-emerald-600 text-white rounded text-sm">Add</button>
          </form>
          <ul class="text-sm list-disc pl-5">
            <?php foreach ($fields as $f): ?>
              <li><?= htmlspecialchars($f['field_name']) ?> <?= $f['is_required'] ? '(required)' : '' ?></li>
            <?php endforeach; if (!$fields): ?><li class="text-gray-500">None</li><?php endif; ?>
          </ul>
        </div>
        <div>
          <div class="font-medium mb-1">Required Files</div>
          <form method="post" class="flex gap-2 items-center mb-2">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="add_file_label" value="1">
            <input name="label" placeholder="e.g., Resume/CV" class="border rounded px-2 py-1">
            <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="is_required" value="1" checked> Required</label>
            <button class="px-2 py-1 bg-emerald-600 text-white rounded text-sm">Add</button>
          </form>
          <ul class="text-sm list-disc pl-5">
            <?php foreach ($files as $f): ?>
              <li><?= htmlspecialchars($f['label']) ?> <?= $f['is_required'] ? '(required)' : '' ?></li>
            <?php endforeach; if (!$files): ?><li class="text-gray-500">None</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endforeach; if (!$tpls): ?>
    <div class="text-gray-500 text-sm">No templates yet.</div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
