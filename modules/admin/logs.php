<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/db.php';

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = $user && strtolower((string)($user['role'] ?? '')) === 'admin';
$canAudit = $user && user_has_access($userId, 'system', 'audit_logs', 'read');

if (!$isAdmin && !$canAudit) {
    header('Location: ' . BASE_URL . '/unauthorized');
    exit;
}

$pdo = get_db_conn();
ensure_system_logs_table($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$module = trim((string)($_GET['module'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(message ILIKE :q OR module ILIKE :q OR file ILIKE :q OR context ILIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($code !== '') {
    $where[] = 'code = :code';
    $params[':code'] = $code;
}
if ($module !== '') {
    $where[] = 'module ILIKE :module';
    $params[':module'] = '%' . $module . '%';
}
if ($from !== '') {
    $where[] = 'created_at >= (:from)::timestamp';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = "created_at < ((:to)::date + INTERVAL '1 day')";
    $params[':to'] = $to;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total rows for pagination
$stmt = $pdo->prepare('SELECT COUNT(*) FROM system_logs ' . $whereSql);
$stmt->execute($params);
$totalRows = (int)($stmt->fetchColumn() ?: 0);

$page = (int)($_GET['page'] ?? 1);
[$offset, $limit, $page, $totalPages] = paginate($totalRows, $page, 20);

// Fetch rows
$sql = 'SELECT id, code, message, module, file, line, func, context, created_at FROM system_logs ' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Prefetch distinct codes for filter suggestions
$codesStmt = $pdo->query("SELECT DISTINCT code FROM system_logs WHERE code IS NOT NULL AND code <> '' ORDER BY code ASC LIMIT 200");
$codeOptions = $codesStmt ? ($codesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

action_log('admin', 'view_system_logs');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
  <section class="rounded-2xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-700 text-white shadow-md">
    <div class="p-6 md:p-10 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
      <div>
        <h1 class="text-3xl font-semibold tracking-tight">System Logs</h1>
        <p class="mt-2 text-sm md:text-base text-slate-200/80">Investigate platform events, filter by module or code, and export snapshots for compliance partners.</p>
      </div>
      <div class="flex flex-col items-start gap-2 text-xs uppercase tracking-wide text-slate-300/80">
        <span>Signed in as</span>
        <span class="text-sm font-medium text-white/90"><?= htmlspecialchars((string)($user['full_name'] ?? $user['email'] ?? 'User')) ?></span>
      </div>
    </div>
  </section>

  <div class="card p-6 space-y-6">
    <form method="get" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 items-end">
      <label class="flex flex-col gap-1">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Free text</span>
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Message, file, context" class="input-text" autocomplete="off">
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Code</span>
        <input list="log-codes" name="code" value="<?= htmlspecialchars($code) ?>" placeholder="e.g. DB1001" class="input-text" autocomplete="off">
        <?php if ($codeOptions): ?>
          <datalist id="log-codes">
            <?php foreach ($codeOptions as $codeOpt): ?>
              <option value="<?= htmlspecialchars($codeOpt) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        <?php endif; ?>
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Module</span>
        <input name="module" value="<?= htmlspecialchars($module) ?>" placeholder="Module name" class="input-text" autocomplete="off">
      </label>
      <div class="grid grid-cols-2 gap-3">
        <label class="flex flex-col gap-1">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">From</span>
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input-text">
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">To</span>
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input-text">
        </label>
      </div>
      <div class="md:col-span-2 xl:col-span-4 flex flex-wrap gap-3">
        <button type="submit" class="btn btn-accent">Apply Filters</button>
        <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>" class="btn btn-outline">Reset</a>
        <div class="relative" data-export-menu>
          <button type="button" class="btn btn-outline" data-export-trigger>Export</button>
          <div class="absolute z-10 mt-2 w-40 rounded-lg border border-slate-200 bg-white text-sm shadow-xl hidden" data-export-list>
            <a class="block px-3 py-2 hover:bg-slate-50" href="<?= BASE_URL ?>/modules/admin/system_log_csv?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&module=<?= urlencode($module) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener">CSV</a>
            <a class="block px-3 py-2 hover:bg-slate-50" href="<?= BASE_URL ?>/modules/admin/system_log_pdf?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&module=<?= urlencode($module) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener">PDF</a>
          </div>
        </div>
      </div>
    </form>

    <div class="border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full border-separate border-spacing-0 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3 font-medium">Timestamp</th>
            <th class="px-4 py-3 font-medium">Code</th>
            <th class="px-4 py-3 font-medium">Message</th>
            <th class="px-4 py-3 font-medium">Module</th>
            <th class="px-4 py-3 font-medium">Source</th>
            <th class="px-4 py-3 font-medium">Function</th>
            <th class="px-4 py-3 font-medium">Details</th>
          </tr>
        </thead>
  <tbody class="divide-y divide-slate-100">
          <?php foreach ($logs as $row): ?>
            <?php
              $createdFmt = (string)($row['created_at'] ?? '');
              try {
                  $dt = new DateTime($createdFmt);
                  $createdFmt = $dt->format('M d, Y g:i:s A');
              } catch (Throwable $e) {}
              $message = (string)($row['message'] ?? '');
              $snippet = $message;
              if (mb_strlen($snippet) > 140) {
                  $snippet = mb_substr($snippet, 0, 140) . '…';
              }
              $context = (string)($row['context'] ?? '');
            ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-4 py-3 whitespace-nowrap text-slate-600"><?= htmlspecialchars($createdFmt) ?></td>
              <td class="px-4 py-3 font-mono text-xs text-indigo-600"><?= htmlspecialchars((string)($row['code'] ?? '-')) ?></td>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-800 max-w-xl truncate" title="<?= htmlspecialchars($message) ?>"><?= htmlspecialchars($snippet) ?></div>
                <?php if ($context !== ''): ?>
                  <div class="text-xs text-slate-500 max-w-xl truncate" title="<?= htmlspecialchars($context) ?>"><?= htmlspecialchars($context) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-slate-600 whitespace-nowrap"><?= htmlspecialchars($row['module'] ?? '-') ?></td>
              <td class="px-4 py-3 text-slate-600">
                <?= htmlspecialchars($row['file'] ?? '-') ?><?php if (!empty($row['line'])): ?>:<span class="text-xs text-slate-400"><?= (int)$row['line'] ?></span><?php endif; ?>
              </td>
              <td class="px-4 py-3 text-slate-600 whitespace-nowrap"><?= htmlspecialchars($row['func'] ?? '-') ?></td>
              <td class="px-4 py-3">
                <button type="button"
                  class="inline-flex items-center justify-center rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-indigo-600 hover:border-indigo-500 hover:bg-indigo-50 transition log-view"
                  data-id="<?= (int)$row['id'] ?>"
                  data-created="<?= htmlspecialchars($createdFmt, ENT_QUOTES) ?>"
                  data-code="<?= htmlspecialchars((string)($row['code'] ?? '-'), ENT_QUOTES) ?>"
                  data-message="<?= htmlspecialchars($message, ENT_QUOTES) ?>"
                  data-module="<?= htmlspecialchars($row['module'] ?? '-', ENT_QUOTES) ?>"
                  data-file="<?= htmlspecialchars($row['file'] ?? '-', ENT_QUOTES) ?>"
                  data-line="<?= htmlspecialchars((string)($row['line'] ?? ''), ENT_QUOTES) ?>"
                  data-func="<?= htmlspecialchars($row['func'] ?? '-', ENT_QUOTES) ?>"
                  data-context="<?= htmlspecialchars($context, ENT_QUOTES) ?>">
                  View
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
            <tr>
              <td colspan="7" class="px-4 py-6 text-center text-slate-500">No logs found for the selected filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="flex flex-wrap gap-2" aria-label="Pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a
            class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-sm font-medium transition <?= $i === $page ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm' : 'border-slate-200 text-slate-600 hover:border-indigo-500 hover:text-indigo-600' ?>"
            href="?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&module=<?= urlencode($module) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $i ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>

<div id="logModal" class="fixed inset-0 hidden items-center justify-center z-50">
  <div class="absolute inset-0 bg-black/40" data-close="1"></div>
  <div class="relative bg-white w-full max-w-3xl mx-3 p-6 rounded-2xl shadow-2xl">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold">Log detail</h2>
      <button class="btn btn-outline" data-close="1">Close</button>
    </div>
    <div class="grid md:grid-cols-2 gap-3 text-sm">
      <div>
        <div class="text-slate-500 text-xs uppercase">Timestamp</div>
        <div id="lg-created" class="font-medium text-slate-800">-</div>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">Code</div>
        <div id="lg-code" class="font-medium text-indigo-600">-</div>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">Module</div>
        <div id="lg-module" class="font-medium text-slate-800">-</div>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">Function</div>
        <div id="lg-func" class="font-medium text-slate-800">-</div>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">File</div>
        <div id="lg-file" class="font-medium text-slate-800 break-all">-</div>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">Line</div>
        <div id="lg-line" class="font-medium text-slate-800">-</div>
      </div>
    </div>
    <div class="mt-5 space-y-4 text-sm">
      <div>
        <div class="text-slate-500 text-xs uppercase">Message</div>
        <pre id="lg-message" class="mt-1 whitespace-pre-wrap break-words rounded-xl bg-slate-50 p-3">-</pre>
      </div>
      <div>
        <div class="text-slate-500 text-xs uppercase">Context</div>
        <pre id="lg-context" class="mt-1 whitespace-pre-wrap break-words rounded-xl bg-slate-50 p-3">-</pre>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const menuRoot = document.querySelector('[data-export-menu]');
  if (menuRoot) {
    const trigger = menuRoot.querySelector('[data-export-trigger]');
    const list = menuRoot.querySelector('[data-export-list]');
    const toggle = () => list.classList.toggle('hidden');
    const close = () => list.classList.add('hidden');
    trigger.addEventListener('click', (e)=>{ e.preventDefault(); toggle(); });
    document.addEventListener('click', (e)=>{
      if (!menuRoot.contains(e.target)) { close(); }
    });
  }

  const modal = document.getElementById('logModal');
  if (!modal) return;
  const closeModal = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
  const openModal = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
  modal.addEventListener('click', (e)=>{ if (e.target.dataset.close) { closeModal(); }});
  window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') { closeModal(); }});

  function prettyPrint(val) {
    if (!val) return '';
    try { return JSON.stringify(JSON.parse(val), null, 2); } catch (err) { return val; }
  }

  document.querySelectorAll('.log-view').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('lg-created').textContent = btn.dataset.created || '-';
      document.getElementById('lg-code').textContent = btn.dataset.code || '-';
      document.getElementById('lg-module').textContent = btn.dataset.module || '-';
      document.getElementById('lg-file').textContent = btn.dataset.file || '-';
      document.getElementById('lg-line').textContent = btn.dataset.line || '-';
      document.getElementById('lg-func').textContent = btn.dataset.func || '-';
      document.getElementById('lg-message').textContent = btn.dataset.message || '-';
      document.getElementById('lg-context').textContent = prettyPrint(btn.dataset.context || '');
      openModal();
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
