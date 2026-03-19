<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_management', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pageTitle = 'System Management';
$pdo = get_db_conn();
action_log('system', 'view_system_management');

// Fetch system statistics
$stats = [
    'database' => [],
    'connections' => [],
    'users' => [],
    'logs' => [],
    'performance' => []
];

// Database statistics - optimized single query
try {
    // Combine multiple queries into one for better performance
    $stmt = $pdo->query("
        SELECT 
            pg_database_size(current_database()) AS db_size,
            (SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database()) AS total_conns,
            (SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND state = 'active') AS active_conns,
            (SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND state = 'idle') AS idle_conns,
            (SELECT ROUND(100.0 * sum(blks_hit) / NULLIF(sum(blks_hit) + sum(blks_read), 0), 2) 
             FROM pg_stat_database WHERE datname = current_database()) AS cache_ratio,
            (SELECT xact_commit + xact_rollback FROM pg_stat_database WHERE datname = current_database()) AS transactions
    ");
    $dbStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dbStats) {
        $stats['database']['size'] = (int)$dbStats['db_size'];
        $stats['database']['size_formatted'] = format_bytes($dbStats['db_size']);
        $stats['connections']['total'] = (int)$dbStats['total_conns'];
        $stats['connections']['active'] = (int)$dbStats['active_conns'];
        $stats['connections']['idle'] = (int)$dbStats['idle_conns'];
        $stats['performance']['cache_hit_ratio'] = (float)$dbStats['cache_ratio'];
        $stats['performance']['transactions'] = (int)$dbStats['transactions'];
    }
    
    // Database connections limit (separate as SHOW command can't be in subquery)
    try {
        $stmt = $pdo->query("SHOW max_connections");
        $stats['connections']['limit'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $stats['connections']['limit'] = null;
    }
    
} catch (Throwable $e) {
    sys_log('SYSTEM-STATS', 'Failed loading database statistics: ' . $e->getMessage(), [
        'module' => 'system',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

// User and log statistics - optimized combined query
try {
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE created_at >= NOW() - INTERVAL '15 minutes') AS active_users,
            (SELECT COUNT(*) FROM users WHERE status = 'active') AS total_users,
            (SELECT COUNT(*) FROM system_logs WHERE created_at >= NOW() - INTERVAL '24 hours') AS logs_24h,
            (SELECT COUNT(*) FROM system_logs) AS total_logs,
            (SELECT COUNT(*) FROM system_logs WHERE code LIKE 'ERR%' AND created_at >= NOW() - INTERVAL '24 hours') AS errors_24h
    ");
    $userLogStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userLogStats) {
        $stats['users']['active'] = (int)$userLogStats['active_users'];
        $stats['users']['total'] = (int)$userLogStats['total_users'];
        $stats['logs']['last_24h'] = (int)$userLogStats['logs_24h'];
        $stats['logs']['total'] = (int)$userLogStats['total_logs'];
        $stats['logs']['errors_24h'] = (int)$userLogStats['errors_24h'];
    }
} catch (Throwable $e) {
    $stats['users']['active'] = 0;
    $stats['users']['total'] = 0;
    $stats['logs']['last_24h'] = 0;
    $stats['logs']['total'] = 0;
    $stats['logs']['errors_24h'] = 0;
    sys_log('SYSTEM-USER-LOG-STATS', 'Failed loading user/log statistics: ' . $e->getMessage(), [
        'module' => 'system',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

// Fetch recent system logs for quick view
$recentLogs = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY id DESC LIMIT 10");
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('SYSTEM-LOGS-FETCH', 'Failed loading recent logs: ' . $e->getMessage(), [
        'module' => 'system',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

// Fetch system configuration
$systemConfig = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
    'base_url' => BASE_URL,
    'upload_dir' => UPLOAD_DIR ?? 'Not defined',
    'max_upload_size' => ini_get('upload_max_filesize'),
    'max_post_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'timezone' => date_default_timezone_get(),
];

$csrf = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-8">
  <section class="card p-6 md:p-8">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="space-y-2">
        <div class="flex items-center gap-3 text-sm">
          <a href="<?= BASE_URL ?>/modules/admin/index" class="inline-flex items-center gap-2 font-semibold text-indigo-600 transition hover:text-indigo-700" data-no-loader>
            <span class="text-base">←</span>
            <span>HR Admin</span>
          </a>
          <span class="text-slate-400">/</span>
          <span class="uppercase tracking-[0.2em] text-slate-500">System</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">System Management</h1>
        <p class="text-sm text-slate-600">Monitor database performance, system logs, and configuration settings.</p>
      </div>
      <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-indigo-500">Active users</p>
          <p class="mt-1 text-2xl font-semibold text-indigo-900"><?= $stats['users']['active'] ?> <span class="text-sm font-medium text-indigo-600">/ <?= $stats['users']['total'] ?></span></p>
          <p class="text-xs text-indigo-600">Last 15 minutes</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">DB connections</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= $stats['connections']['active'] ?> <span class="text-sm font-medium text-emerald-600">/ <?= $stats['connections']['total'] ?></span></p>
          <p class="text-xs text-emerald-600">Active / Total</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-slate-500">DB size</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900"><?= $stats['database']['size_formatted'] ?></p>
          <p class="text-xs text-slate-500">Current database</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Database & Performance Stats -->
  <section class="grid gap-6 lg:grid-cols-2">
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">Database Status</h2>
        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
          <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
          Operational
        </span>
      </div>
      <div class="space-y-4">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-xs uppercase tracking-wide text-slate-500">Database Size</p>
              <p class="mt-1 text-2xl font-semibold text-slate-900"><?= $stats['database']['size_formatted'] ?></p>
            </div>
            <svg class="h-10 w-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
            </svg>
          </div>
        </div>
        
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-slate-200 bg-white p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500">Connections</p>
            <p class="mt-1 text-lg font-semibold text-slate-900"><?= $stats['connections']['total'] ?></p>
            <p class="text-xs text-slate-500">
              <?= $stats['connections']['active'] ?> active, <?= $stats['connections']['idle'] ?> idle
              <?php if ($stats['connections']['limit']): ?>
                <br>Limit: <?= $stats['connections']['limit'] ?>
              <?php endif; ?>
            </p>
          </div>
          
          <?php if ($stats['performance']['cache_hit_ratio'] !== null): ?>
          <div class="rounded-lg border border-slate-200 bg-white p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500">Cache Hit Ratio</p>
            <p class="mt-1 text-lg font-semibold text-slate-900"><?= number_format($stats['performance']['cache_hit_ratio'], 2) ?>%</p>
            <p class="text-xs text-slate-500">
              <?php if ($stats['performance']['cache_hit_ratio'] >= 90): ?>
                <span class="text-emerald-600">Excellent</span>
              <?php elseif ($stats['performance']['cache_hit_ratio'] >= 80): ?>
                <span class="text-amber-600">Good</span>
              <?php else: ?>
                <span class="text-red-600">Needs attention</span>
              <?php endif; ?>
            </p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">System Logs</h2>
        <a href="<?= BASE_URL ?>/modules/admin/system/logs" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">View all →</a>
      </div>
      <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg border border-slate-200 bg-white p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500">Last 24h</p>
            <p class="mt-1 text-lg font-semibold text-slate-900"><?= number_format($stats['logs']['last_24h']) ?></p>
            <p class="text-xs text-slate-500">Total logs</p>
          </div>
          
          <div class="rounded-lg border border-red-100 bg-red-50 p-3">
            <p class="text-xs uppercase tracking-wide text-red-500">Errors</p>
            <p class="mt-1 text-lg font-semibold text-red-900"><?= number_format($stats['logs']['errors_24h']) ?></p>
            <p class="text-xs text-red-600">Last 24h</p>
          </div>
          
          <div class="rounded-lg border border-slate-200 bg-white p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500">All time</p>
            <p class="mt-1 text-lg font-semibold text-slate-900"><?= number_format($stats['logs']['total']) ?></p>
            <p class="text-xs text-slate-500">Total logs</p>
          </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent Activity</p>
          <div class="space-y-2">
            <?php if ($recentLogs): ?>
              <?php foreach (array_slice($recentLogs, 0, 5) as $log): ?>
                <div class="flex items-start gap-2 text-xs">
                  <span class="inline-flex items-center rounded px-2 py-0.5 font-mono text-[10px] <?= str_starts_with($log['code'], 'ERR') ? 'bg-red-100 text-red-700' : 'bg-slate-200 text-slate-700' ?>">
                    <?= htmlspecialchars($log['code'] ?? 'N/A') ?>
                  </span>
                  <span class="flex-1 truncate text-slate-600" title="<?= htmlspecialchars($log['message'] ?? '') ?>">
                    <?= htmlspecialchars(substr($log['message'] ?? '', 0, 50)) ?><?= strlen($log['message'] ?? '') > 50 ? '...' : '' ?>
                  </span>
                  <span class="text-slate-400"><?= htmlspecialchars(date('H:i', strtotime($log['created_at']))) ?></span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-xs text-slate-500">No recent logs</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- System Configuration -->
  <section class="card p-6">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-900">System Configuration</h2>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">PHP Version</p>
        <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($systemConfig['php_version']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Server</p>
        <p class="mt-1 text-sm font-semibold text-slate-900 break-all"><?= htmlspecialchars($systemConfig['server_software']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Memory Limit</p>
        <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($systemConfig['memory_limit']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Max Upload Size</p>
        <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($systemConfig['max_upload_size']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Max POST Size</p>
        <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($systemConfig['max_post_size']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Timezone</p>
        <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($systemConfig['timezone']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
        <p class="text-xs uppercase tracking-wide text-slate-500">Base URL</p>
        <p class="mt-1 text-sm font-semibold text-slate-900 break-all"><?= htmlspecialchars($systemConfig['base_url']) ?></p>
      </div>
      
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Upload Directory</p>
        <p class="mt-1 text-sm font-semibold text-slate-900 break-all"><?= htmlspecialchars($systemConfig['upload_dir']) ?></p>
      </div>
    </div>
  </section>

  <!-- System Tools & Management -->
  <section class="grid gap-6 lg:grid-cols-2">
    <!-- Action Log -->
    <?php if (user_can('user_management', 'audit_logs', 'read')): ?>
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100">
            <svg class="h-5 w-5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Action Log</h2>
            <p class="text-xs text-slate-500">Track all user actions and system changes</p>
          </div>
        </div>
      </div>
      <p class="text-sm text-slate-600 mb-4">
        Comprehensive activity logging provides detailed visibility into user activities, permission changes, data modifications, and system events.
      </p>
      <a href="<?= BASE_URL ?>/modules/admin/audit_trail" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        View Action Log
      </a>
    </div>
    <?php endif; ?>

    <!-- Database Backup -->
    <?php if (user_can('user_management', 'system_management', 'write')): ?>
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-cyan-100">
            <svg class="h-5 w-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Database Backup</h2>
            <p class="text-xs text-slate-500">Create and download database snapshots</p>
          </div>
        </div>
      </div>
      <p class="text-sm text-slate-600 mb-4">
        View database backup history and download previous backups. Create new backups from the backup history page.
      </p>
      <div class="flex gap-3">
        <a href="<?= BASE_URL ?>/modules/admin/system/backup_history" class="inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
          </svg>
          View Database Backups
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Archive Management -->
    <?php if (user_can('user_management', 'system_management', 'write')): ?>
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100">
            <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Archive Management</h2>
            <p class="text-xs text-slate-500">Manage deleted records and recovery</p>
          </div>
        </div>
      </div>
      <p class="text-sm text-slate-600 mb-4">
        View archived (soft-deleted) records, recover deleted items, or permanently remove data with automatic retention policies.
      </p>
      <a href="<?= BASE_URL ?>/modules/admin/system/archive" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
        </svg>
        Manage Archive
      </a>
    </div>
    <?php endif; ?>

    <!-- Active Database Connections -->
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100">
            <svg class="h-5 w-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Database Connections</h2>
            <p class="text-xs text-slate-500"><?= $stats['connections']['active'] ?> active of <?= $stats['connections']['total'] ?> total</p>
          </div>
        </div>
      </div>
      
      <!-- Connection utilization bar -->
      <?php 
        $max_connections = 20;
        $active = $stats['connections']['active'];
        $usage_percent = ($active / $max_connections) * 100;
        $bar_color = $usage_percent > 80 ? 'bg-red-500' : ($usage_percent > 60 ? 'bg-yellow-500' : 'bg-emerald-500');
      ?>
      <div class="mb-4">
        <div class="flex justify-between text-xs text-slate-600 mb-1">
          <span>Active: <?= $active ?> / <?= $max_connections ?> connections</span>
          <span><?= number_format($usage_percent, 1) ?>%</span>
        </div>
        <div class="w-full bg-slate-200 rounded-full h-2.5">
          <div class="<?= $bar_color ?> h-2.5 rounded-full transition-all duration-300" style="width: <?= $usage_percent ?>%"></div>
        </div>
      </div>
      
      <p class="text-sm text-slate-600 mb-4">
        Monitor active database connections, query performance, and connection pool usage for optimal database health.
      </p>
      <a href="<?= BASE_URL ?>/modules/admin/system/connections" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        View Connection Details
      </a>
    </div>
    
    <!-- Database Storage -->
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100">
            <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Database Storage</h2>
            <p class="text-xs text-slate-500">15.5 MB of 1 GB used</p>
          </div>
        </div>
      </div>
      
      <!-- Storage utilization bar -->
      <?php 
        $max_storage_gb = 1;
        $used_mb = 15.5;
        $max_storage_mb = $max_storage_gb * 1024;
        $storage_percent = ($used_mb / $max_storage_mb) * 100;
        $storage_bar_color = $storage_percent > 80 ? 'bg-red-500' : ($storage_percent > 60 ? 'bg-yellow-500' : 'bg-blue-500');
      ?>
      <div class="mb-4">
        <div class="flex justify-between text-xs text-slate-600 mb-1">
          <span>Used: <?= number_format($used_mb, 1) ?> MB / <?= $max_storage_gb ?> GB</span>
          <span><?= number_format($storage_percent, 2) ?>%</span>
        </div>
        <div class="w-full bg-slate-200 rounded-full h-2.5">
          <div class="<?= $storage_bar_color ?> h-2.5 rounded-full transition-all duration-300" style="width: <?= $storage_percent ?>%"></div>
        </div>
      </div>
      
      <p class="text-sm text-slate-600 mb-4">
        Track database storage consumption and ensure adequate capacity for data growth and operations.
      </p>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-lg bg-slate-50 p-3">
          <div class="text-xs text-slate-500 mb-1">Available</div>
          <div class="font-semibold text-slate-900"><?= number_format($max_storage_mb - $used_mb, 1) ?> MB</div>
        </div>
        <div class="rounded-lg bg-slate-50 p-3">
          <div class="text-xs text-slate-500 mb-1">Total Capacity</div>
          <div class="font-semibold text-slate-900"><?= $max_storage_gb ?> GB</div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
// System management page scripts
// All backup functionality has been moved to backup_history.php
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
