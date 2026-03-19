<?php
/**
 * Access Control — Activity Logs
 * Paginated log viewer for all access control events.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_settings', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

$pageTitle = 'Access Control Logs';
$redirectUrl = BASE_URL . '/modules/admin/access-control/logs';

// Filters
$filters = [];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

if (!empty($_GET['event_type'])) $filters['event_type'] = $_GET['event_type'];
if (!empty($_GET['user_id']))    $filters['user_id'] = (int)$_GET['user_id'];
if (!empty($_GET['date_from']))  $filters['date_from'] = $_GET['date_from'];
if (!empty($_GET['date_to']))    $filters['date_to'] = $_GET['date_to'] . ' 23:59:59';

$result = acl_list_logs($filters, $perPage, ($page - 1) * $perPage);
$logs = $result['data'];
$total = $result['total'];
$totalPages = ceil($total / $perPage);

// Event type list for filter
$eventTypes = [
    'blocked' => ['Blocked', 'bg-red-100 text-red-700'],
    'allowed' => ['Allowed', 'bg-emerald-100 text-emerald-700'],
    'device_registered' => ['Device Registered', 'bg-blue-100 text-blue-700'],
    'device_enabled' => ['Device Enabled', 'bg-emerald-100 text-emerald-700'],
    'device_disabled' => ['Device Disabled', 'bg-gray-100 text-gray-600'],
    'device_deleted' => ['Device Deleted', 'bg-red-100 text-red-700'],
    'rule_created' => ['Rule Created', 'bg-purple-100 text-purple-700'],
    'rule_updated' => ['Rule Updated', 'bg-purple-100 text-purple-700'],
    'rule_deleted' => ['Rule Deleted', 'bg-amber-100 text-amber-700'],
    'rule_enabled' => ['Rule Enabled', 'bg-emerald-100 text-emerald-700'],
    'rule_disabled' => ['Rule Disabled', 'bg-gray-100 text-gray-600'],
    'override_granted' => ['Override Granted', 'bg-amber-100 text-amber-700'],
    'override_revoked' => ['Override Revoked', 'bg-gray-100 text-gray-600'],
    'settings_updated' => ['Settings Updated', 'bg-indigo-100 text-indigo-700'],
];

// Users for filter dropdown
$users = acl_get_users_list();

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <div class="flex items-center gap-2 mb-1">
        <a href="<?= BASE_URL ?>/modules/admin/access-control/index" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-bold text-slate-900">Activity Logs</h1>
      </div>
      <p class="text-sm text-slate-500"><?= number_format($total) ?> events total. Showing page <?= $page ?> of <?= max(1, $totalPages) ?>.</p>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="card p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Event Type</label>
        <select name="event_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
          <option value="">All events</option>
          <?php foreach ($eventTypes as $key => [$label, $cls]): ?>
            <option value="<?= $key ?>" <?= ($filters['event_type'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">User</label>
        <select name="user_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
          <option value="">All users</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= ($filters['user_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
      </div>
    </div>
    <div class="flex items-center gap-2 mt-3">
      <button type="submit" class="btn btn-primary text-sm">Apply Filters</button>
      <?php if (!empty(array_filter($filters))): ?>
        <a href="<?= $redirectUrl ?>" class="text-xs text-gray-500 hover:text-gray-700 font-medium">Clear all</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Log Table -->
  <div class="card overflow-hidden">
    <?php if (empty($logs)): ?>
      <div class="p-10 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <p class="mt-3 text-sm text-gray-500">No log entries found for the selected filters.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-gray-500">
            <tr>
              <th class="px-4 py-3 text-left">Event</th>
              <th class="px-4 py-3 text-left">User</th>
              <th class="px-4 py-3 text-left">IP Address</th>
              <th class="px-4 py-3 text-left">Device</th>
              <th class="px-4 py-3 text-left">Details</th>
              <th class="px-4 py-3 text-left">Time</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($logs as $log): 
              $evInfo = $eventTypes[$log['event_type']] ?? [$log['event_type'], 'bg-gray-100 text-gray-600'];
              $details = json_decode($log['details'] ?? '{}', true) ?: [];
            ?>
              <tr class="hover:bg-gray-50 transition">
                <td class="px-4 py-3">
                  <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide <?= $evInfo[1] ?>"><?= htmlspecialchars($evInfo[0]) ?></span>
                  <?php if ($log['scope']): ?>
                    <div class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($log['scope']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <div class="text-xs text-gray-700"><?= htmlspecialchars($log['user_name'] ?? '—') ?></div>
                </td>
                <td class="px-4 py-3">
                  <span class="text-xs font-mono text-gray-600"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span>
                </td>
                <td class="px-4 py-3">
                  <?php if ($log['device_fingerprint']): ?>
                    <span class="text-xs font-mono text-gray-500" title="<?= htmlspecialchars($log['device_fingerprint']) ?>"><?= substr($log['device_fingerprint'], 0, 12) ?>…</span>
                  <?php else: ?>
                    <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 max-w-xs">
                  <?php
                  // Show key detail bits
                  $showKeys = ['reason', 'label', 'rule_id', 'device_id', 'override_id', 'matched_value'];
                  $bits = [];
                  foreach ($showKeys as $k) {
                      if (isset($details[$k]) && $details[$k] !== '' && $details[$k] !== null) {
                          $bits[] = '<span class="text-gray-500">' . $k . ':</span> ' . htmlspecialchars((string)$details[$k]);
                      }
                  }
                  ?>
                  <?php if ($bits): ?>
                    <div class="text-[11px] text-gray-600 space-y-0.5 truncate"><?= implode('<br>', array_slice($bits, 0, 3)) ?></div>
                  <?php else: ?>
                    <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                  <?= date('M d, g:i A', strtotime($log['created_at'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between border-t px-4 py-3">
          <div class="text-xs text-gray-500">
            Showing <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $total) ?> of <?= number_format($total) ?>
          </div>
          <div class="flex items-center gap-1">
            <?php
            $queryBase = $_GET;
            unset($queryBase['page']);
            $qs = http_build_query($queryBase);
            ?>
            <?php if ($page > 1): ?>
              <a href="<?= $redirectUrl ?>?<?= $qs ?>&page=<?= $page - 1 ?>" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 transition">← Prev</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
              <a href="<?= $redirectUrl ?>?<?= $qs ?>&page=<?= $p ?>" class="rounded-lg px-3 py-1.5 text-xs font-medium transition <?= $p === $page ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100' ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= $redirectUrl ?>?<?= $qs ?>&page=<?= $page + 1 ?>" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 transition">Next →</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
