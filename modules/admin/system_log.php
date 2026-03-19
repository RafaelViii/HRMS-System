<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'system_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/header.php';
$pdo = get_db_conn();

$q = trim($_GET['q'] ?? '');
$code = trim($_GET['code'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

// Build filters (PostgreSQL)
$where = [];
$params = [];
if ($q !== '') { $where[] = '(message ILIKE :q OR module ILIKE :q OR file ILIKE :q OR func ILIKE :q OR context ILIKE :q)'; $params[':q'] = "%$q%"; }
if ($code !== '') { $where[] = 'code = :code'; $params[':code'] = $code; }
if ($from !== '') { $where[] = 'created_at >= (:from)::timestamp'; $params[':from'] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = "created_at < ((:to)::date + INTERVAL '1 day')"; $params[':to'] = $to; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count
$stmt = $pdo->prepare('SELECT COUNT(*) FROM system_logs ' . $whereSql);
$stmt->execute($params);
$total = (int)($stmt->fetchColumn() ?: 0);

$page = (int)($_GET['page'] ?? 1);
[$offset,$limit,$page,$pages] = paginate($total, $page, 20);

// Fetch
$sql = 'SELECT * FROM system_logs ' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="flex items-center justify-between mb-3">
    <h1 class="text-xl font-semibold">System Log</h1>
    <div class="space-x-2">
      <div class="dropdown">
        <button class="btn btn-accent" data-dd-toggle>Export</button>
        <div class="dropdown-menu hidden">
          <a class="dropdown-item csv" href="<?= BASE_URL ?>/modules/admin/system_log_csv?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener" data-no-loader>CSV</a>
          <a class="dropdown-item pdf" href="<?= BASE_URL ?>/modules/admin/system_log_pdf?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener">PDF</a>
        </div>
      </div>
    </div>
  </div>
  <form class="flex flex-wrap gap-2 mb-3" method="get">
    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search message/module/file" class="input-text">
    <input name="code" value="<?= htmlspecialchars($code) ?>" placeholder="Code (e.g., DB1001)" class="input-text">
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input-text">
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input-text">
    <button class="btn btn-outline btn-icon" aria-label="Filter" title="Filter" type="submit">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="18" height="18" aria-hidden="true">
        <path d="M3 4a1 1 0 011-1h12a1 1 0 01.8 1.6L12 12v5a1 1 0 01-1.447.894l-2-1A1 1 0 018 16v-4L3.2 4.6A1 1 0 013 4z"/>
      </svg>
    </button>
  </form>
  <div class="overflow-x-auto">
    <table class="table-basic text-sm w-full">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left">Time</th>
          <th class="p-2 text-left">Code</th>
          <th class="p-2 text-left">Message</th>
          <th class="p-2 text-left">Module</th>
          <th class="p-2 text-left">File:Line</th>
          <th class="p-2 text-left">Func</th>
          <th class="p-2 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            // Format time as MM-DD-YYYY HH-MM-SS
            $createdFmt = (string)($r['created_at'] ?? '');
            try { $dt = new DateTime($createdFmt); $createdFmt = $dt->format('m-d-Y H-i-s'); } catch (Throwable $e) {}
            // First sentence/quick message snippet
            $fullMsg = (string)($r['message'] ?? '');
            $parts = preg_split('/(?<=[.!?])\s+/', $fullMsg, 2);
            $snippet = $parts && isset($parts[0]) ? $parts[0] : $fullMsg;
            if (mb_strlen($snippet) > 160) { $snippet = mb_substr($snippet, 0, 160) . '…'; }
          ?>
          <tr class="border-t hover:bg-gray-100 transition">
            <td class="p-2 text-gray-600 whitespace-nowrap"><?= htmlspecialchars($createdFmt) ?></td>
            <td class="p-2 font-mono text-xs"><?= htmlspecialchars($r['code']) ?></td>
            <td class="p-2">
              <div class="font-medium text-gray-800 truncate max-w-[36rem]" title=<?= '"' . htmlspecialchars($fullMsg) . '"' ?>><?= htmlspecialchars($snippet) ?></div>
              <?php if (!empty($r['context'])): ?>
                <div class="text-xs text-gray-500 truncate max-w-[36rem]" title=<?= '"' . htmlspecialchars($r['context']) . '"' ?>><?= htmlspecialchars($r['context']) ?></div>
              <?php endif; ?>
            </td>
            <td class="p-2 text-gray-700"><?= htmlspecialchars($r['module'] ?? '-') ?></td>
            <td class="p-2 text-gray-700">
              <?= htmlspecialchars(($r['file'] ?? '-')) ?><?php if (!empty($r['line'])): ?>:<span class="text-xs text-gray-500"><?= (int)$r['line'] ?></span><?php endif; ?>
            </td>
            <td class="p-2 text-gray-700"><?= htmlspecialchars($r['func'] ?? '-') ?></td>
            <td class="p-2 text-gray-700">
              <button type="button"
                class="btn text-xs log-view"
                data-id="<?= (int)$r['id'] ?>"
                data-created="<?= htmlspecialchars($createdFmt) ?>"
                data-code="<?= htmlspecialchars($r['code'], ENT_QUOTES) ?>"
                data-message="<?= htmlspecialchars($r['message'], ENT_QUOTES) ?>"
                data-module="<?= htmlspecialchars($r['module'] ?? '-', ENT_QUOTES) ?>"
                data-file="<?= htmlspecialchars($r['file'] ?? '-', ENT_QUOTES) ?>"
                data-line="<?= htmlspecialchars((string)($r['line'] ?? ''), ENT_QUOTES) ?>"
                data-func="<?= htmlspecialchars($r['func'] ?? '-', ENT_QUOTES) ?>"
                data-context="<?= htmlspecialchars($r['context'] ?? '', ENT_QUOTES) ?>">
                View
              </button>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td class="p-3" colspan="7">No logs.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="mt-3 flex gap-2">
    <?php for ($i=1; $i<=$pages; $i++): ?>
      <a class="btn <?= $i==$page?' bg-gray-200':'' ?>" href="?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $i ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>
<!-- Modal: Log Detail -->
<div id="logModal" class="fixed inset-0 hidden items-center justify-center z-50">
  <div class="absolute inset-0 bg-black/30" data-close="1"></div>
  <div class="relative bg-white w-full max-w-2xl mx-3 p-4 rounded shadow-lg">
    <div class="flex items-center justify-between mb-2">
      <h2 class="text-lg font-semibold">Log Detail</h2>
      <button class="btn btn-accent" data-close="1">Close</button>
    </div>
    <div class="grid grid-cols-3 gap-2 text-sm">
      <div class="text-gray-500">Time</div><div class="col-span-2" id="lg-created">-</div>
      <div class="text-gray-500">Code</div><div class="col-span-2" id="lg-code">-</div>
      <div class="text-gray-500">Module</div><div class="col-span-2" id="lg-module">-</div>
      <div class="text-gray-500">File</div><div class="col-span-2" id="lg-file">-</div>
      <div class="text-gray-500">Line</div><div class="col-span-2" id="lg-line">-</div>
      <div class="text-gray-500">Func</div><div class="col-span-2" id="lg-func">-</div>
    </div>
    <div class="mt-3">
      <div class="text-gray-500 text-sm">Message</div>
      <pre class="whitespace-pre-wrap break-words bg-gray-50 p-2 rounded text-sm" id="lg-message">-</pre>
    </div>
    <div class="mt-3">
      <div class="text-gray-500 text-sm">Context</div>
      <pre class="whitespace-pre-wrap break-words bg-gray-50 p-2 rounded text-sm" id="lg-context">-</pre>
    </div>
  </div>
  <script>
  (function(){
    const modal = document.getElementById('logModal');
    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
    modal.addEventListener('click', (e)=>{ if (e.target.dataset.close) closeModal(); });
    function prettyJsonOnly(val){
      if (!val) return '';
      try { const parsed = JSON.parse(val); return JSON.stringify(parsed, null, 2); } catch (e) { return val; }
    }
    document.querySelectorAll('.log-view').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        document.getElementById('lg-created').textContent = btn.dataset.created || '-';
        document.getElementById('lg-code').textContent = btn.dataset.code || '-';
        document.getElementById('lg-module').textContent = btn.dataset.module || '-';
        document.getElementById('lg-file').textContent = btn.dataset.file || '-';
        document.getElementById('lg-line').textContent = btn.dataset.line || '-';
        document.getElementById('lg-func').textContent = btn.dataset.func || '-';
        document.getElementById('lg-message').textContent = btn.dataset.message || '-';
        document.getElementById('lg-context').textContent = prettyJsonOnly(btn.dataset.context || '');
        openModal();
      });
    });
    window.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closeModal(); });
  })();
  </script>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>