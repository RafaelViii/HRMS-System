<?php
/**
 * Access Control — Settings
 * Toggle system on/off, configure enforcement mode, and feature flags.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_settings', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

$pageTitle = 'Access Control Settings';
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$redirectUrl = BASE_URL . '/modules/admin/access-control/settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $settings = [
        'enabled' => !empty($_POST['enabled']) ? 'true' : 'false',
        'enforcement_mode' => in_array($_POST['enforcement_mode'] ?? '', ['blacklist', 'whitelist', 'both'], true)
            ? $_POST['enforcement_mode'] : 'blacklist',
        'device_binding_enabled' => !empty($_POST['device_binding_enabled']) ? 'true' : 'false',
        'module_restriction_enabled' => !empty($_POST['module_restriction_enabled']) ? 'true' : 'false',
        'unregistered_device_action' => in_array($_POST['unregistered_device_action'] ?? '', ['allow', 'log', 'block'], true)
            ? $_POST['unregistered_device_action'] : 'allow',
        'override_duration_minutes' => max(5, min(1440, (int)($_POST['override_duration_minutes'] ?? 60))),
        'cache_ttl_seconds' => max(30, min(3600, (int)($_POST['cache_ttl_seconds'] ?? 300))),
    ];

    $ok = true;
    foreach ($settings as $key => $value) {
        if (!acl_set_setting($key, (string)$value, $currentUserId)) {
            $ok = false;
        }
    }

    if ($ok) {
        action_log('admin', 'access_control_settings_updated', 'success', $settings);
        audit('access_control_settings', json_encode($settings, JSON_UNESCAPED_SLASHES));
        acl_log('settings_updated', array_merge($settings, ['by' => $currentUserId]));
        flash_success('Access control settings updated.');
    } else {
        flash_error('Some settings could not be saved.');
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Load current settings
$enabled = strtolower((string)acl_get_setting('enabled', 'false')) === 'true';
$mode = acl_get_setting('enforcement_mode', 'blacklist');
$deviceBinding = strtolower((string)acl_get_setting('device_binding_enabled', 'false')) === 'true';
$moduleRestriction = strtolower((string)acl_get_setting('module_restriction_enabled', 'false')) === 'true';
$unregisteredAction = acl_get_setting('unregistered_device_action', 'allow');
$overrideDuration = (int)acl_get_setting('override_duration_minutes', '60');
$cacheTtl = (int)acl_get_setting('cache_ttl_seconds', '300');

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
        <h1 class="text-xl font-bold text-slate-900">Access Control Settings</h1>
      </div>
      <p class="text-sm text-slate-500">Configure how the whitelist/blacklist system behaves. The system is currently <strong class="<?= $enabled ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $enabled ? 'active' : 'inactive' ?></strong>.</p>
    </div>
  </div>

  <form method="post" class="space-y-6">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <!-- Master Switch -->
    <div class="card p-6">
      <div class="flex items-start gap-4">
        <div class="flex h-12 w-12 items-center justify-center rounded-xl <?= $enabled ? 'bg-emerald-100' : 'bg-slate-100' ?>">
          <svg class="h-6 w-6 <?= $enabled ? 'text-emerald-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
        <div class="flex-1">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Enable Access Control</h2>
              <p class="text-sm text-gray-500 mt-0.5">When enabled, all configured rules will be enforced. When disabled, rules are saved but not checked.</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enabled" value="1" class="sr-only peer" <?= $enabled ? 'checked' : '' ?>>
              <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Enforcement Mode -->
    <div class="card p-6">
      <h2 class="text-base font-semibold text-gray-900 mb-1">Enforcement Mode</h2>
      <p class="text-sm text-gray-500 mb-4">Choose how rules are evaluated when a request comes in.</p>
      <div class="grid gap-3 sm:grid-cols-3">
        <label class="relative cursor-pointer rounded-xl border-2 p-4 transition <?= $mode === 'blacklist' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' ?>">
          <input type="radio" name="enforcement_mode" value="blacklist" class="sr-only" <?= $mode === 'blacklist' ? 'checked' : '' ?>>
          <div class="text-sm font-semibold text-gray-900">Blacklist</div>
          <p class="mt-1 text-xs text-gray-500">Everything is <strong>allowed</strong> by default. Only listed entries are blocked.</p>
          <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 uppercase">Recommended</div>
        </label>
        <label class="relative cursor-pointer rounded-xl border-2 p-4 transition <?= $mode === 'whitelist' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' ?>">
          <input type="radio" name="enforcement_mode" value="whitelist" class="sr-only" <?= $mode === 'whitelist' ? 'checked' : '' ?>>
          <div class="text-sm font-semibold text-gray-900">Whitelist</div>
          <p class="mt-1 text-xs text-gray-500">Everything is <strong>blocked</strong> by default. Only listed entries are allowed.</p>
          <div class="mt-2 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 uppercase">Strict</div>
        </label>
        <label class="relative cursor-pointer rounded-xl border-2 p-4 transition <?= $mode === 'both' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' ?>">
          <input type="radio" name="enforcement_mode" value="both" class="sr-only" <?= $mode === 'both' ? 'checked' : '' ?>>
          <div class="text-sm font-semibold text-gray-900">Combined</div>
          <p class="mt-1 text-xs text-gray-500">Both lists active. <strong>Whitelist takes precedence</strong> over blacklist.</p>
          <div class="mt-2 inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700 uppercase">Advanced</div>
        </label>
      </div>
    </div>

    <!-- Feature Toggles -->
    <div class="card p-6">
      <h2 class="text-base font-semibold text-gray-900 mb-4">Feature Toggles</h2>
      <div class="space-y-5">
        <!-- Device Binding -->
        <div class="flex items-start justify-between gap-4 pb-5 border-b border-gray-200">
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Device–Account Binding</h3>
            <p class="text-xs text-gray-500 mt-0.5">When enabled, you can bind devices to specific user accounts. Only the bound account(s) can log in from that device.</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input type="checkbox" name="device_binding_enabled" value="1" class="sr-only peer" <?= $deviceBinding ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
          </label>
        </div>

        <!-- Module Restrictions -->
        <div class="flex items-start justify-between gap-4 pb-5 border-b border-gray-200">
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Module Access Restrictions</h3>
            <p class="text-xs text-gray-500 mt-0.5">When enabled, you can restrict devices to specific modules. A device bound to "attendance" can only access attendance pages.</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input type="checkbox" name="module_restriction_enabled" value="1" class="sr-only peer" <?= $moduleRestriction ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
          </label>
        </div>

        <!-- Unregistered Device Action -->
        <div>
          <h3 class="text-sm font-semibold text-gray-900">Unregistered Device Behavior</h3>
          <p class="text-xs text-gray-500 mt-0.5 mb-3">What happens when a device that isn't registered accesses the system.</p>
          <div class="flex flex-wrap gap-3">
            <?php foreach (['allow' => ['Allow silently', 'bg-emerald-100 text-emerald-700'], 'log' => ['Allow but log', 'bg-blue-100 text-blue-700'], 'block' => ['Block access', 'bg-red-100 text-red-700']] as $val => [$lbl, $cls]): ?>
              <label class="cursor-pointer">
                <input type="radio" name="unregistered_device_action" value="<?= $val ?>" class="sr-only peer" <?= $unregisteredAction === $val ? 'checked' : '' ?>>
                <span class="inline-flex items-center rounded-lg border-2 px-3 py-2 text-sm transition peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300">
                  <span class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase <?= $cls ?> mr-2"><?= $val ?></span>
                  <?= $lbl ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Advanced Settings -->
    <div class="card p-6">
      <h2 class="text-base font-semibold text-gray-900 mb-4">Advanced</h2>
      <div class="grid gap-6 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Override Duration (minutes)</label>
          <input type="number" name="override_duration_minutes" min="5" max="1440" value="<?= $overrideDuration ?>" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
          <p class="text-xs text-gray-500 mt-1">Default duration when an admin grants a temporary access override. 5–1440 min.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Cache TTL (seconds)</label>
          <input type="number" name="cache_ttl_seconds" min="30" max="3600" value="<?= $cacheTtl ?>" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
          <p class="text-xs text-gray-500 mt-1">How long access rules stay cached in the user's session. Lower = more up-to-date. 30–3600 sec.</p>
        </div>
      </div>
    </div>

    <!-- Save -->
    <div class="flex items-center gap-3">
      <button type="submit" class="btn btn-primary" data-confirm="Save access control settings?">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Save Settings
      </button>
      <a href="<?= BASE_URL ?>/modules/admin/access-control/index" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</div>

<script>
// Radio card visual selection
document.querySelectorAll('input[type="radio"]').forEach(radio => {
  radio.addEventListener('change', function() {
    const name = this.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
      const label = r.closest('label');
      if (label) {
        label.classList.toggle('border-indigo-500', r.checked);
        label.classList.toggle('bg-indigo-50', r.checked);
        label.classList.toggle('border-gray-200', !r.checked);
      }
    });
  });
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
