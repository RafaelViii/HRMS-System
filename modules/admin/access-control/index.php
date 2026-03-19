<?php
/**
 * Access Control — Dashboard
 * Central overview of the whitelist/blacklist system with quick actions.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_settings', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

$pageTitle = 'Access Control';
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
action_log('admin', 'view_access_control_dashboard');

$isEnabled = acl_is_enabled();
$deviceBindingEnabled = strtolower((string)acl_get_setting('device_binding_enabled', 'false')) === 'true';
$moduleRestrictionEnabled = strtolower((string)acl_get_setting('module_restriction_enabled', 'false')) === 'true';
$mode = acl_get_setting('enforcement_mode', 'blacklist');
$stats = acl_get_stats();

// Recent logs
$recentLogs = acl_list_logs([], 5);
$recentLogData = $recentLogs['data'] ?? [];

// Get device fingerprint from cookie/JS
$currentFingerprint = $_COOKIE['__acl_fp'] ?? null;
$currentDevice = $currentFingerprint ? acl_get_device_by_hash($currentFingerprint) : null;

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="rounded-xl bg-gradient-to-br from-slate-900 via-indigo-900 to-blue-900 p-6 text-white shadow-lg">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
      <div>
        <div class="mb-2 inline-flex items-center gap-2 rounded-full <?= $isEnabled ? 'bg-emerald-500/20' : 'bg-white/10' ?> px-3 py-1 text-xs font-semibold uppercase tracking-wider <?= $isEnabled ? 'text-emerald-300' : 'text-white/75' ?>">
          <?= $isEnabled ? '● System Active' : '○ System Inactive' ?>
        </div>
        <h1 class="text-2xl font-semibold">Access Control</h1>
        <p class="mt-1 text-sm text-white/70">Manage device bindings, IP restrictions, and module access with whitelist/blacklist rules.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/admin/access-control/settings" class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          Settings
        </a>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Active Rules</div>
        <div class="mt-1 text-lg font-semibold"><?= number_format((int)($stats['active_rules'] ?? 0)) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Registered Devices</div>
        <div class="mt-1 text-lg font-semibold"><?= number_format((int)($stats['active_devices'] ?? 0)) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Device Bindings</div>
        <div class="mt-1 text-lg font-semibold"><?= number_format((int)($stats['device_bindings'] ?? 0)) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Blocks Today</div>
        <div class="mt-1 text-lg font-semibold"><?= number_format((int)($stats['blocks_today'] ?? 0)) ?></div>
      </div>
    </div>
  </div>

  <?php if (!$isEnabled): ?>
  <!-- Inactive Notice -->
  <div class="rounded-lg border-2 border-dashed border-amber-300 bg-amber-50 p-6 text-center">
    <svg class="mx-auto h-10 w-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
    <h3 class="mt-3 text-lg font-semibold text-amber-800">Access Control is Currently Off</h3>
    <p class="mt-1 text-sm text-amber-700">No rules are being enforced. You can still configure rules and register devices — they'll take effect when you enable the system.</p>
    <a href="<?= BASE_URL ?>/modules/admin/access-control/settings" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      Enable in Settings
    </a>
  </div>
  <?php endif; ?>

  <!-- Feature Status Cards -->
  <div class="grid gap-4 md:grid-cols-3">
    <div class="card p-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg <?= $deviceBindingEnabled ? 'bg-emerald-100' : 'bg-slate-100' ?>">
            <svg class="h-5 w-5 <?= $deviceBindingEnabled ? 'text-emerald-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Device–Account Binding</h3>
            <p class="text-xs text-gray-500">Bind devices to specific user accounts</p>
          </div>
        </div>
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= $deviceBindingEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
          <?= $deviceBindingEnabled ? 'On' : 'Off' ?>
        </span>
      </div>
    </div>
    <div class="card p-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg <?= $moduleRestrictionEnabled ? 'bg-emerald-100' : 'bg-slate-100' ?>">
            <svg class="h-5 w-5 <?= $moduleRestrictionEnabled ? 'text-emerald-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Module Restrictions</h3>
            <p class="text-xs text-gray-500">Limit device access to specific modules</p>
          </div>
        </div>
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= $moduleRestrictionEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
          <?= $moduleRestrictionEnabled ? 'On' : 'Off' ?>
        </span>
      </div>
    </div>
    <div class="card p-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100">
            <svg class="h-5 w-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Enforcement Mode</h3>
            <p class="text-xs text-gray-500">How rules are evaluated</p>
          </div>
        </div>
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide bg-indigo-100 text-indigo-700">
          <?= ucfirst(htmlspecialchars($mode)) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Quick Actions + Current Device -->
  <div class="grid gap-4 lg:grid-cols-2">
    <!-- Quick Actions -->
    <div class="card p-5">
      <h2 class="text-base font-semibold text-gray-900 mb-4">Quick Actions</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <a href="<?= BASE_URL ?>/modules/admin/access-control/devices" class="group flex items-center gap-3 rounded-lg border border-gray-200 p-4 transition hover:border-indigo-300 hover:shadow-md">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100">
            <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          </div>
          <div>
            <span class="text-sm font-medium text-gray-900 group-hover:text-indigo-600">Manage Devices</span>
            <span class="block text-xs text-gray-500"><?= (int)($stats['active_devices'] ?? 0) ?> registered</span>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/modules/admin/access-control/rules" class="group flex items-center gap-3 rounded-lg border border-gray-200 p-4 transition hover:border-indigo-300 hover:shadow-md">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-purple-100">
            <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          </div>
          <div>
            <span class="text-sm font-medium text-gray-900 group-hover:text-indigo-600">Access Rules</span>
            <span class="block text-xs text-gray-500"><?= (int)($stats['active_rules'] ?? 0) ?> active</span>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/modules/admin/access-control/rules?entry_type=device_user_bind" class="group flex items-center gap-3 rounded-lg border border-gray-200 p-4 transition hover:border-indigo-300 hover:shadow-md">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100">
            <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          </div>
          <div>
            <span class="text-sm font-medium text-gray-900 group-hover:text-indigo-600">Device Bindings</span>
            <span class="block text-xs text-gray-500"><?= (int)($stats['device_bindings'] ?? 0) ?> bindings</span>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/modules/admin/access-control/logs" class="group flex items-center gap-3 rounded-lg border border-gray-200 p-4 transition hover:border-indigo-300 hover:shadow-md">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100">
            <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          </div>
          <div>
            <span class="text-sm font-medium text-gray-900 group-hover:text-indigo-600">Activity Logs</span>
            <span class="block text-xs text-gray-500"><?= (int)($stats['events_today'] ?? 0) ?> events today</span>
          </div>
        </a>
      </div>
    </div>

    <!-- Current Device Info -->
    <div class="card p-5">
      <h2 class="text-base font-semibold text-gray-900 mb-4">This Device</h2>
      <div id="currentDeviceInfo">
        <?php if ($currentDevice): ?>
          <div class="space-y-3">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100">
                <svg class="h-5 w-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              </div>
              <div>
                <span class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($currentDevice['label']) ?></span>
                <span class="block text-xs text-emerald-600 font-medium">Registered</span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <div class="rounded-lg bg-gray-50 p-2">
                <span class="text-gray-500">Fingerprint</span>
                <span class="block font-mono text-gray-700 truncate"><?= substr(htmlspecialchars($currentDevice['fingerprint_hash']), 0, 16) ?>…</span>
              </div>
              <div class="rounded-lg bg-gray-50 p-2">
                <span class="text-gray-500">Platform</span>
                <span class="block text-gray-700"><?= htmlspecialchars($currentDevice['platform'] ?? 'Unknown') ?></span>
              </div>
              <div class="rounded-lg bg-gray-50 p-2">
                <span class="text-gray-500">Last Seen</span>
                <span class="block text-gray-700"><?= $currentDevice['last_seen_at'] ? format_datetime_display($currentDevice['last_seen_at'], true) : 'Never' ?></span>
              </div>
              <div class="rounded-lg bg-gray-50 p-2">
                <span class="text-gray-500">Type</span>
                <span class="block text-gray-700"><?= ucfirst(htmlspecialchars($currentDevice['device_type'] ?? 'desktop')) ?></span>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="text-center py-4">
            <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <p class="mt-2 text-sm text-gray-500" id="deviceStatusText">Detecting device fingerprint…</p>
            <button id="btnRegisterThisDevice" class="mt-3 btn btn-primary btn-sm hidden" onclick="registerCurrentDevice()">
              <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Register This Device
            </button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900">Recent Activity</h2>
      <a href="<?= BASE_URL ?>/modules/admin/access-control/logs" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">View all →</a>
    </div>
    <?php if (empty($recentLogData)): ?>
      <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
        <p class="text-sm text-gray-500">No access control events logged yet.</p>
      </div>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($recentLogData as $log): 
          $eventColors = [
            'blocked' => 'text-red-600 bg-red-50',
            'allowed' => 'text-emerald-600 bg-emerald-50',
            'device_registered' => 'text-blue-600 bg-blue-50',
            'rule_created' => 'text-purple-600 bg-purple-50',
            'rule_updated' => 'text-purple-600 bg-purple-50',
            'rule_deleted' => 'text-amber-600 bg-amber-50',
            'override_granted' => 'text-amber-600 bg-amber-50',
            'override_revoked' => 'text-gray-600 bg-gray-50',
          ];
          $eventClass = $eventColors[$log['event_type']] ?? 'text-gray-600 bg-gray-50';
        ?>
          <div class="flex items-center gap-3 rounded-lg border border-gray-200 p-3">
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide <?= $eventClass ?>"><?= htmlspecialchars($log['event_type']) ?></span>
            <div class="flex-1 min-w-0">
              <span class="text-xs text-gray-700"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></span>
              <?php if ($log['ip_address']): ?>
                <span class="text-xs text-gray-400 ml-1">from <?= htmlspecialchars($log['ip_address']) ?></span>
              <?php endif; ?>
            </div>
            <span class="text-[10px] text-gray-400 whitespace-nowrap"><?= format_datetime_display($log['created_at'] ?? '', true) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Device fingerprint detection and registration
(function() {
  function generateFingerprint() {
    const components = [
      navigator.userAgent || '',
      screen.width + 'x' + screen.height,
      Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      navigator.platform || '',
      navigator.language || '',
      screen.colorDepth || '',
      new Date().getTimezoneOffset().toString()
    ];
    return sha256(components.join('|'));
  }

  async function sha256(message) {
    const msgBuffer = new TextEncoder().encode(message);
    const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
  }

  async function init() {
    try {
      const fp = await generateFingerprint();
      // Store in cookie for server-side reading
      document.cookie = '__acl_fp=' + fp + ';path=/;max-age=31536000;SameSite=Lax';
      
      // Update UI
      const statusText = document.getElementById('deviceStatusText');
      const registerBtn = document.getElementById('btnRegisterThisDevice');
      
      if (statusText && registerBtn) {
        statusText.textContent = 'Device fingerprint: ' + fp.substring(0, 16) + '…';
        registerBtn.classList.remove('hidden');
        registerBtn.dataset.fingerprint = fp;
      }
      
      // Store metadata for registration
      window.__aclDeviceMeta = {
        fingerprint_hash: fp,
        user_agent: navigator.userAgent,
        screen_info: screen.width + 'x' + screen.height,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        platform: navigator.platform || '',
        language: navigator.language || ''
      };
    } catch (e) {
      const statusText = document.getElementById('deviceStatusText');
      if (statusText) statusText.textContent = 'Could not detect device fingerprint.';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  // Also re-init after SPA navigation
  document.addEventListener('spa:loaded', init);
})();

function registerCurrentDevice() {
  const meta = window.__aclDeviceMeta;
  if (!meta) { alert('Device fingerprint not available.'); return; }
  
  const label = prompt('Give this device a name (e.g., "HR Front Desk PC", "Boss Laptop"):');
  if (!label || !label.trim()) return;
  
  meta.label = label.trim();
  
  fetch('<?= BASE_URL ?>/modules/admin/access-control/api_device', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'register', csrf: '<?= csrf_token() ?>', ...meta })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Failed to register device.');
    }
  })
  .catch(() => alert('Network error.'));
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
