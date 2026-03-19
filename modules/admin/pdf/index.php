<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('reports', 'pdf_reports', 'read');
require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
$pdo = get_db_conn();

$reports = [
  'generic' => 'Generic Report',
  'employees_list' => 'Employees List',
  'employee_profile' => 'Employee Profile',
  'departments' => 'Departments',
  'positions' => 'Positions',
  'attendance' => 'Attendance',
];

// Load selected template
$key = $_GET['key'] ?? 'generic';
if (!isset($reports[$key])) { $key = 'generic'; }

// Handle save/reset
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
  $action = $_POST['action'] ?? '';
  if ($action === 'save') {
    $title = trim($_POST['title'] ?? '');
    $show_company = isset($_POST['show_company']) ? 1 : 0;
    $company_name = trim($_POST['company_name'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $show_pages = isset($_POST['show_pages']) ? 1 : 0;
    $sign_names = $_POST['sig_name'] ?? [];
    $sign_titles = $_POST['sig_title'] ?? [];
    $signs = [];
    for ($i=0; $i<count($sign_names); $i++) {
      $n = trim($sign_names[$i] ?? ''); $t = trim($sign_titles[$i] ?? '');
      if ($n !== '') { $signs[] = ['name'=>$n,'title'=>$t]; }
    }
    $settings = [
      'title' => $title,
      'header' => [ 'show_company' => (bool)$show_company, 'company_name' => $company_name, 'company_address' => $company_address ],
      'footer' => [ 'show_page_numbers' => (bool)$show_pages ],
      'signatories' => $signs,
    ];
  $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
  $stmt = $pdo->prepare("INSERT INTO pdf_templates (report_key, settings) VALUES (:key, :settings)
               ON CONFLICT (report_key) DO UPDATE SET settings = EXCLUDED.settings");
  $stmt->execute([':key'=>$key, ':settings'=>$json]);
    audit('pdf_template_save', $key);
    $msg = 'Saved.';
  } elseif ($action === 'reset') {
  $stmt = $pdo->prepare('DELETE FROM pdf_templates WHERE report_key = :key');
  $stmt->execute([':key'=>$key]);
    audit('pdf_template_reset', $key);
    $msg = 'Reset to default.';
  }
}

// Fetch current
$stmt = $pdo->prepare('SELECT settings FROM pdf_templates WHERE report_key = :key');
$stmt->execute([':key'=>$key]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$cfg = $row ? json_decode($row['settings'], true) : [];

function field($arr, $path, $def=''){
  $cur = $arr;
  foreach (explode('.', $path) as $p) {
    if (!is_array($cur) || !array_key_exists($p, $cur)) return $def;
    $cur = $cur[$p];
  }
  return $cur;
}
?>
<div class="mb-3">
  <a class="btn btn-outline inline-flex items-center gap-2" href="<?= BASE_URL ?>/modules/admin/management">
    <span>&larr;</span>
    <span>Back to Management Hub</span>
  </a>
</div>
<div class="max-w-4xl">
  <h1 class="text-xl font-semibold mb-3">PDF Templates</h1>
  <?php if ($msg): ?><div class="mb-3 p-2 rounded bg-emerald-50 text-emerald-700 border border-emerald-200"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <form class="mb-4" method="get">
    <label class="text-sm text-gray-600 mr-2">Select Report</label>
    <select name="key" class="border rounded px-3 py-2">
      <?php foreach ($reports as $k=>$name): ?>
        <option value="<?= $k ?>" <?= $k===$key?'selected':'' ?>><?= htmlspecialchars($name) ?> (<?= $k ?>)</option>
      <?php endforeach; ?>
    </select>
    <button class="ml-2 px-3 py-2 bg-gray-700 text-white rounded">Load</button>
  </form>

  <form method="post" class="bg-white p-4 rounded shadow space-y-4" data-dirty-watch>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="form-label required">Report Title</label>
        <input name="title" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars(field($cfg,'title','')) ?>" placeholder="Override title (optional)">
      </div>
      <div class="flex items-end gap-2">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_company" <?= field($cfg,'header.show_company',true)?'checked':'' ?>> <span>Show Company Header</span></label>
      </div>
      <div>
        <label class="form-label">Company Name</label>
        <input name="company_name" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars(field($cfg,'header.company_name','')) ?>" placeholder="Defaults to company config">
      </div>
      <div>
        <label class="form-label">Company Address</label>
        <input name="company_address" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars(field($cfg,'header.company_address','')) ?>" placeholder="Optional">
      </div>
      <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_pages" <?= field($cfg,'footer.show_page_numbers',true)?'checked':'' ?>> <span>Show Page Numbers</span></label>
      </div>
    </div>

    <div>
      <div class="font-semibold mb-2">Signatories</div>
      <div id="sigList" class="space-y-2">
        <?php $sigs = field($cfg,'signatories',[]); if (!$sigs) $sigs=[['name'=>'','title'=>'']]; foreach ($sigs as $sig): ?>
        <div class="grid md:grid-cols-2 gap-2">
          <input name="sig_name[]" class="w-full border rounded px-3 py-2" placeholder="Name" value="<?= htmlspecialchars($sig['name'] ?? '') ?>">
          <input name="sig_title[]" class="w-full border rounded px-3 py-2" placeholder="Title" value="<?= htmlspecialchars($sig['title'] ?? '') ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="mt-2 px-3 py-2 border rounded" onclick="addSig()">Add Signatory</button>
    </div>

    <div class="flex gap-2">
      <button name="action" value="save" class="px-3 py-2 bg-blue-600 text-white rounded">Save Template</button>
      <button name="action" value="reset" class="px-3 py-2 border rounded" data-confirm="Reset to default for this report?">Reset to Default</button>
    </div>
  </form>
</div>
<script>
function addSig(){
  const row = document.createElement('div');
  row.className = 'grid md:grid-cols-2 gap-2';
  row.innerHTML = '<input name="sig_name[]" class="w-full border rounded px-3 py-2" placeholder="Name">\
  <input name="sig_title[]" class="w-full border rounded px-3 py-2" placeholder="Title">';
  document.getElementById('sigList').appendChild(row);
}
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
