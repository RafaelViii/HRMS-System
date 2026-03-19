<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_logs', 'read');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pageTitle = 'System Logs';
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
[$offset,$limit,$page,$pages] = paginate($total, $page, 25);

// Fetch
$sql = 'SELECT * FROM system_logs ' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <section class="card p-6">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="mb-1 flex items-center gap-2 text-sm">
          <a href="<?= BASE_URL ?>/modules/admin/index" class="font-semibold text-indigo-600 hover:text-indigo-700">HR Admin</a>
          <span class="text-slate-400">/</span>
          <a href="<?= BASE_URL ?>/modules/admin/system" class="font-semibold text-indigo-600 hover:text-indigo-700">System Management</a>
          <span class="text-slate-400">/</span>
          <span class="text-slate-500">Logs</span>
        </div>
        <h1 class="text-xl font-semibold text-slate-900">System Logs</h1>
        <p class="text-sm text-slate-600">View and search system event logs</p>
      </div>
      <div class="flex gap-2">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/admin/system/logs_csv?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener" data-no-loader>Export CSV</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/admin/system/logs_pdf?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" target="_blank" rel="noopener">Export PDF</a>
      </div>
    </div>

    <form class="mb-4 flex flex-wrap gap-2" method="get">
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search message/module/file" class="input-text flex-1 min-w-[200px]">
      <input name="code" value="<?= htmlspecialchars($code) ?>" placeholder="Code (e.g., DB1001)" class="input-text w-32">
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input-text">
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input-text">
      <button class="btn btn-primary" type="submit">Filter</button>
      <?php if ($q || $code || $from || $to): ?>
        <a href="<?= BASE_URL ?>/modules/admin/system/logs" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>

    <div class="mb-3 text-sm text-slate-600">
      Showing <?= number_format($total) ?> log<?= $total === 1 ? '' : 's' ?>
      <?php if ($pages > 1): ?>
        · Page <?= $page ?> of <?= $pages ?>
      <?php endif; ?>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-3 py-2 text-left">Time</th>
            <th class="px-3 py-2 text-left">Code</th>
            <th class="px-3 py-2 text-left">Message</th>
            <th class="px-3 py-2 text-left">Module</th>
            <th class="px-3 py-2 text-left">File:Line</th>
            <th class="px-3 py-2 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$rows): ?>
            <tr>
              <td colspan="6" class="px-3 py-6 text-center text-slate-500">No logs found. Adjust your filters or check back later.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $createdFmt = $r['created_at'] ? date('m-d-Y H:i:s', strtotime($r['created_at'])) : '';
                $isError = str_starts_with($r['code'] ?? '', 'ERR');
              ?>
              <tr class="bg-white hover:bg-slate-50">
                <td class="px-3 py-2 whitespace-nowrap text-xs text-slate-600"><?= htmlspecialchars($createdFmt) ?></td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="inline-flex items-center rounded px-2 py-0.5 font-mono text-[10px] font-semibold <?= $isError ? 'bg-red-100 text-red-700' : 'bg-slate-200 text-slate-700' ?>">
                    <?= htmlspecialchars($r['code'] ?? 'N/A') ?>
                  </span>
                </td>
                <td class="px-3 py-2 max-w-xs">
                  <div class="truncate text-slate-900" title="<?= htmlspecialchars($r['message'] ?? '') ?>">
                    <?= htmlspecialchars($r['message'] ?? '') ?>
                  </div>
                </td>
                <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($r['module'] ?? '—') ?></td>
                <td class="px-3 py-2 text-xs text-slate-500 font-mono">
                  <?php if ($r['file']): ?>
                    <?= htmlspecialchars(basename($r['file'])) ?>:<?= htmlspecialchars($r['line'] ?? '') ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2">
                  <button type="button" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700" data-log-detail="<?= (int)$r['id'] ?>">Details</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <?php if ($page > 1): ?>
          <a class="btn btn-outline" href="?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $page - 1 ?>" data-no-loader>← Previous</a>
        <?php endif; ?>
        
        <?php
          $showPages = [];
          if ($pages <= 7) {
            $showPages = range(1, $pages);
          } else {
            $showPages = [1];
            if ($page > 3) $showPages[] = '...';
            for ($i = max(2, $page - 1); $i <= min($pages - 1, $page + 1); $i++) {
              $showPages[] = $i;
            }
            if ($page < $pages - 2) $showPages[] = '...';
            $showPages[] = $pages;
          }
          
          foreach ($showPages as $p):
            if ($p === '...'):
        ?>
              <span class="px-2 text-slate-400">...</span>
        <?php
            else:
        ?>
              <a class="btn btn-outline <?= $p === $page ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : '' ?>" href="?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $p ?>" data-no-loader><?= $p ?></a>
        <?php
            endif;
          endforeach;
        ?>
        
        <?php if ($page < $pages): ?>
          <a class="btn btn-outline" href="?q=<?= urlencode($q) ?>&code=<?= urlencode($code) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $page + 1 ?>" data-no-loader>Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- Log Detail Modal -->
<div id="logDetailModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-slate-900/70" data-modal-close></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <h3 class="text-lg font-semibold text-slate-900">Log Details</h3>
        <button type="button" class="rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" data-modal-close aria-label="Close">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="max-h-[70vh] overflow-auto px-6 py-5" data-log-content>
        <p class="text-sm text-slate-500">Loading...</p>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('logDetailModal');
  const content = modal?.querySelector('[data-log-content]');
  
  document.querySelectorAll('[data-log-detail]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const logId = btn.getAttribute('data-log-detail');
      if (!logId) return;
      
      // Show modal
      modal?.classList.remove('hidden');
      if (content) content.innerHTML = '<p class="text-sm text-slate-500">Loading...</p>';
      
      // Find the log data in the current page
      const logs = <?= json_encode($rows) ?>;
      const log = logs.find(l => l.id == logId);
      
      if (log) {
        let html = '<div class="space-y-4">';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Time</span><p class="mt-1 text-sm text-slate-900">' + (log.created_at || 'N/A') + '</p></div>';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Code</span><p class="mt-1 text-sm text-slate-900">' + (log.code || 'N/A') + '</p></div>';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Message</span><p class="mt-1 text-sm text-slate-900">' + (log.message || 'N/A') + '</p></div>';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Module</span><p class="mt-1 text-sm text-slate-900">' + (log.module || 'N/A') + '</p></div>';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">File</span><p class="mt-1 text-sm text-slate-900 font-mono break-all">' + (log.file || 'N/A') + '</p></div>';
        html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Line</span><p class="mt-1 text-sm text-slate-900">' + (log.line || 'N/A') + '</p></div>';
        if (log.func) {
          html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Function</span><p class="mt-1 text-sm text-slate-900 font-mono">' + log.func + '</p></div>';
        }
        if (log.context) {
          html += '<div><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Context</span><pre class="mt-1 max-h-64 overflow-auto rounded bg-slate-50 p-3 text-xs text-slate-900">' + log.context + '</pre></div>';
        }
        html += '</div>';
        if (content) content.innerHTML = html;
      }
    });
  });
  
  // Close modal
  modal?.querySelectorAll('[data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      modal?.classList.add('hidden');
    });
  });
  
  // Close on escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal?.classList.contains('hidden')) {
      modal?.classList.add('hidden');
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
