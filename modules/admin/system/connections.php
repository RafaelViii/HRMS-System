<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_health', 'read');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pageTitle = 'Database Connections';
$pdo = get_db_conn();
action_log('system', 'view_database_connections');

// Fetch connection counts
$connStats = ['total' => 0, 'active' => 0, 'idle' => 0];
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE state = 'active') AS active,
            COUNT(*) FILTER (WHERE state = 'idle') AS idle
        FROM pg_stat_activity 
        WHERE datname = current_database()
    ");
    $connStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('SYSTEM-CONN-STATS', 'Failed loading connection stats: ' . $e->getMessage(), [
        'module' => 'system',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <section class="card p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="space-y-2">
        <div class="flex items-center gap-3 text-sm">
          <a href="<?= BASE_URL ?>/modules/admin/system" class="inline-flex items-center gap-2 font-semibold text-indigo-600 transition hover:text-indigo-700" data-no-loader>
            <span class="text-base">←</span>
            <span>System Management</span>
          </a>
          <span class="text-slate-400">/</span>
          <span class="uppercase tracking-[0.2em] text-slate-500">Connections</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Database Connections</h1>
        <p class="text-sm text-slate-600">Monitor active database connections and query performance.</p>
      </div>
      <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">Active</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= (int)$connStats['active'] ?></p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-slate-500">Idle</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int)$connStats['idle'] ?></p>
        </div>
        <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-indigo-500">Total</p>
          <p class="mt-1 text-2xl font-semibold text-indigo-900"><?= (int)$connStats['total'] ?></p>
        </div>
      </div>
    </div>
  </section>

  <section class="card p-6">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-900">Active Connections</h2>
      <button onclick="location.reload()" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh
      </button>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-3 py-3 text-left">PID</th>
            <th class="px-3 py-3 text-left">User</th>
            <th class="px-3 py-3 text-left">State</th>
            <th class="px-3 py-3 text-left">Query</th>
            <th class="px-3 py-3 text-left">Duration</th>
            <th class="px-3 py-3 text-left">Wait Event</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
          <?php
          try {
            $connStmt = $pdo->query("
              SELECT 
                pid, 
                usename, 
                state,
                wait_event_type,
                wait_event,
                LEFT(query, 80) AS query_snippet,
                EXTRACT(EPOCH FROM (NOW() - state_change)) AS duration_seconds
              FROM pg_stat_activity 
              WHERE datname = current_database()
                AND pid <> pg_backend_pid()
              ORDER BY state_change DESC
              LIMIT 50
            ");
            $connections = $connStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($connections):
              foreach ($connections as $conn):
          ?>
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-3 font-mono text-xs"><?= htmlspecialchars($conn['pid']) ?></td>
              <td class="px-3 py-3 text-xs"><?= htmlspecialchars($conn['usename']) ?></td>
              <td class="px-3 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide <?= $conn['state'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                  <?= htmlspecialchars($conn['state']) ?>
                </span>
              </td>
              <td class="px-3 py-3 font-mono text-xs text-slate-600" title="<?= htmlspecialchars($conn['query_snippet'] ?? '') ?>">
                <?= htmlspecialchars($conn['query_snippet'] ?? '') ?><?= strlen($conn['query_snippet'] ?? '') >= 80 ? '...' : '' ?>
              </td>
              <td class="px-3 py-3 text-xs text-slate-500"><?= number_format((float)$conn['duration_seconds'], 2) ?>s</td>
              <td class="px-3 py-3 text-xs text-slate-500">
                <?php if ($conn['wait_event_type']): ?>
                  <span class="font-mono"><?= htmlspecialchars($conn['wait_event_type']) ?>: <?= htmlspecialchars($conn['wait_event']) ?></span>
                <?php else: ?>
                  <span class="text-slate-400">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php 
              endforeach;
            else:
          ?>
            <tr>
              <td colspan="6" class="px-3 py-8 text-center text-sm text-gray-500">No active connections found</td>
            </tr>
          <?php
            endif;
          } catch (Throwable $e) {
            echo '<tr><td colspan="6" class="px-3 py-8 text-center text-sm text-red-500">Unable to fetch connections: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
