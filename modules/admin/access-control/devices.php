<?php
/**
 * Access Control — Device Management
 * View, register, edit, and deactivate devices.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_settings', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

$pageTitle = 'Registered Devices';
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$redirectUrl = BASE_URL . '/modules/admin/access-control/devices';

// ─── Handle POST Actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $deviceType = $_POST['device_type'] ?? 'desktop';
        $notes = trim($_POST['notes'] ?? '');

        if ($deviceId && $label) {
            try {
                $pdo = get_db_conn();
                $stmt = $pdo->prepare('UPDATE device_fingerprints SET label = :label, device_type = :type, notes = :notes, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([':label' => $label, ':type' => $deviceType, ':notes' => $notes, ':id' => $deviceId]);
                action_log('admin', 'device_updated', 'success', ['device_id' => $deviceId, 'label' => $label]);
                flash_success('Device updated.');
            } catch (Throwable $e) {
                flash_error('Failed to update device.');
            }
        } else {
            flash_error('Device label is required.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'toggle_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $active = !empty($_POST['set_active']);
        if ($deviceId) {
            try {
                $pdo = get_db_conn();
                $pdo->prepare('UPDATE device_fingerprints SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute([':active' => $active ? 'true' : 'false', ':id' => $deviceId]);
                acl_log($active ? 'device_enabled' : 'device_disabled', ['device_id' => $deviceId, 'by' => $currentUserId]);
                flash_success('Device ' . ($active ? 'enabled' : 'disabled') . '.');
            } catch (Throwable $e) {
                flash_error('Failed to toggle device.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        if ($deviceId) {
            try {
                $pdo = get_db_conn();
                // Remove related rules first
                $pdo->prepare("DELETE FROM access_rules WHERE device_fingerprint_hash = (SELECT fingerprint_hash FROM device_fingerprints WHERE id = :id)")
                    ->execute([':id' => $deviceId]);
                $pdo->prepare('DELETE FROM device_fingerprints WHERE id = :id')
                    ->execute([':id' => $deviceId]);
                acl_log('device_deleted', ['device_id' => $deviceId, 'by' => $currentUserId]);
                acl_invalidate_cache();
                action_log('admin', 'device_deleted', 'success', ['device_id' => $deviceId]);
                flash_success('Device and its associated rules have been deleted.');
            } catch (Throwable $e) {
                flash_error('Failed to delete device.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// ─── Load Data ───────────────────────────────────────────────────────────
$search = $_GET['search'] ?? '';
$showAll = isset($_GET['show_all']);
$deviceFilters = [];
if (!$showAll) $deviceFilters['active'] = true;
if ($search) $deviceFilters['search'] = $search;

$devices = acl_list_devices($deviceFilters);

// Current device fingerprint
$currentFingerprint = $_COOKIE['__acl_fp'] ?? null;

// Editing?
$editDevice = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($devices as $d) {
        if ($d['id'] == $editId) { $editDevice = $d; break; }
    }
}

// Count rules per device
$deviceRuleCounts = [];
try {
    $pdo = get_db_conn();
    $stmt = $pdo->query("SELECT device_fingerprint_hash, COUNT(*) AS cnt FROM access_rules WHERE device_fingerprint_hash IS NOT NULL GROUP BY device_fingerprint_hash");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $deviceRuleCounts[$row['device_fingerprint_hash']] = (int)$row['cnt'];
    }
} catch (Throwable $e) {}

$deviceTypeIcons = [
    'desktop' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    'laptop' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    'tablet' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    'mobile' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    'kiosk' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>',
];

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
        <h1 class="text-xl font-bold text-slate-900">Registered Devices</h1>
      </div>
      <p class="text-sm text-slate-500"><?= count($devices) ?> device<?= count($devices) !== 1 ? 's' : '' ?> registered. Devices must be registered before they can be used in binding rules.</p>
    </div>
    <div class="flex items-center gap-2">
      <?php if (!$showAll): ?>
        <a href="<?= $redirectUrl ?>?show_all=1" class="btn btn-outline text-sm">Show Disabled</a>
      <?php else: ?>
        <a href="<?= $redirectUrl ?>" class="btn btn-outline text-sm">Active Only</a>
      <?php endif; ?>
      <button onclick="registerThisDevice()" class="btn btn-primary">
        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Register This Device
      </button>
    </div>
  </div>

  <!-- Search -->
  <form method="get" class="flex items-center gap-2">
    <?php if ($showAll): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
    <div class="relative flex-1 max-w-sm">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by label, fingerprint, platform…" class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
    </div>
    <button class="btn btn-secondary text-sm">Search</button>
  </form>

  <!-- Device Grid -->
  <?php if (empty($devices)): ?>
    <div class="card p-10 text-center">
      <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      <h3 class="mt-3 text-lg font-semibold text-gray-700">No Devices Registered</h3>
      <p class="mt-1 text-sm text-gray-500">Click "Register This Device" to add the device you're currently using.</p>
    </div>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($devices as $d): 
        $isCurrent = $currentFingerprint && $d['fingerprint_hash'] === $currentFingerprint;
        $ruleCount = $deviceRuleCounts[$d['fingerprint_hash']] ?? 0;
        $icon = $deviceTypeIcons[$d['device_type'] ?? 'desktop'] ?? $deviceTypeIcons['desktop'];
      ?>
        <div class="card p-5 relative <?= !$d['is_active'] ? 'opacity-60' : '' ?> <?= $isCurrent ? 'ring-2 ring-indigo-500' : '' ?>">
          <?php if ($isCurrent): ?>
            <div class="absolute -top-2 -right-2 rounded-full bg-indigo-600 text-white text-[9px] font-bold px-2 py-0.5 uppercase tracking-wide shadow">This Device</div>
          <?php endif; ?>

          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-lg <?= $d['is_active'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400' ?>">
                <?= $icon ?>
              </div>
              <div>
                <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($d['label'] ?: 'Unnamed Device') ?></h3>
                <span class="text-[11px] font-mono text-gray-400"><?= substr($d['fingerprint_hash'], 0, 16) ?>…</span>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <a href="<?= $redirectUrl ?>?edit=<?= $d['id'] ?>" class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition" title="Edit">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </a>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-2 gap-2">
            <div class="rounded-lg bg-gray-50 p-2">
              <div class="text-[10px] text-gray-500 uppercase">Platform</div>
              <div class="text-xs text-gray-700 truncate"><?= htmlspecialchars($d['platform'] ?: '—') ?></div>
            </div>
            <div class="rounded-lg bg-gray-50 p-2">
              <div class="text-[10px] text-gray-500 uppercase">Type</div>
              <div class="text-xs text-gray-700"><?= ucfirst(htmlspecialchars($d['device_type'] ?? 'desktop')) ?></div>
            </div>
            <div class="rounded-lg bg-gray-50 p-2">
              <div class="text-[10px] text-gray-500 uppercase">Last Seen</div>
              <div class="text-xs text-gray-700"><?= $d['last_seen_at'] ? date('M d, g:iA', strtotime($d['last_seen_at'])) : 'Never' ?></div>
            </div>
            <div class="rounded-lg bg-gray-50 p-2">
              <div class="text-[10px] text-gray-500 uppercase">Last IP</div>
              <div class="text-xs text-gray-700 font-mono"><?= htmlspecialchars($d['last_seen_ip'] ?: '—') ?></div>
            </div>
          </div>

          <div class="mt-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $d['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' ?>">
                <?= $d['is_active'] ? 'Active' : 'Disabled' ?>
              </span>
              <?php if ($ruleCount): ?>
                <span class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700"><?= $ruleCount ?> rule<?= $ruleCount !== 1 ? 's' : '' ?></span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-1">
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="toggle_device">
                <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                <input type="hidden" name="set_active" value="<?= $d['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="text-[11px] text-gray-500 hover:text-indigo-600 font-medium"><?= $d['is_active'] ? 'Disable' : 'Enable' ?></button>
              </form>
              <span class="text-gray-200">|</span>
              <form method="post" class="inline" data-confirm="Delete this device and all its associated rules?">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_device">
                <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                <button type="submit" class="text-[11px] text-red-500 hover:text-red-700 font-medium">Delete</button>
              </form>
            </div>
          </div>

          <?php if ($d['notes']): ?>
            <div class="mt-2 text-[11px] text-gray-400 border-t border-gray-100 pt-2"><?= htmlspecialchars($d['notes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Edit Device Modal -->
<?php if ($editDevice): ?>
<div id="editDeviceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="relative mx-auto w-full max-w-md rounded-xl bg-white shadow-xl">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_device">
      <input type="hidden" name="device_id" value="<?= $editDevice['id'] ?>">

      <div class="flex items-center justify-between border-b px-6 py-4">
        <h2 class="text-lg font-semibold text-gray-900">Edit Device</h2>
        <a href="<?= $redirectUrl ?>" class="rounded-lg p-1 text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </a>
      </div>

      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Fingerprint Hash</label>
          <div class="text-xs font-mono text-gray-500 bg-gray-50 rounded-lg px-3 py-2 break-all"><?= htmlspecialchars($editDevice['fingerprint_hash']) ?></div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1 required">Device Label</label>
          <input type="text" name="label" value="<?= htmlspecialchars($editDevice['label']) ?>" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Device Type</label>
          <select name="device_type" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            <?php foreach (['desktop', 'laptop', 'tablet', 'mobile', 'kiosk'] as $t): ?>
              <option value="<?= $t ?>" <?= ($editDevice['device_type'] ?? 'desktop') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
          <textarea name="notes" rows="2" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Optional notes about this device…"><?= htmlspecialchars($editDevice['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="flex justify-end gap-3 border-t px-6 py-4">
        <a href="<?= $redirectUrl ?>" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Device fingerprint + registration
async function sha256(msg) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(msg));
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function getFingerprint() {
  const parts = [
    navigator.userAgent || '',
    screen.width + 'x' + screen.height,
    Intl.DateTimeFormat().resolvedOptions().timeZone || '',
    navigator.platform || '',
    navigator.language || '',
    screen.colorDepth || '',
    new Date().getTimezoneOffset().toString()
  ];
  return sha256(parts.join('|'));
}

async function registerThisDevice() {
  const fp = await getFingerprint();
  const label = prompt('Give this device a name (e.g., "HR Front Desk PC", "Laptop #3"):');
  if (!label || !label.trim()) return;

  try {
    const res = await fetch('<?= BASE_URL ?>/modules/admin/access-control/api_device', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'register',
        csrf: '<?= csrf_token() ?>',
        fingerprint_hash: fp,
        label: label.trim(),
        user_agent: navigator.userAgent,
        screen_info: screen.width + 'x' + screen.height,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        platform: navigator.platform || '',
        language: navigator.language || ''
      })
    });
    const data = await res.json();
    if (data.ok) {
      document.cookie = '__acl_fp=' + fp + ';path=/;max-age=31536000;SameSite=Lax';
      location.reload();
    } else {
      alert(data.error || 'Failed to register device.');
    }
  } catch (e) {
    alert('Network error while registering device.');
  }
}

// Set current device cookie on load
(async function() {
  try {
    const fp = await getFingerprint();
    document.cookie = '__acl_fp=' + fp + ';path=/;max-age=31536000;SameSite=Lax';
  } catch(e) {}
})();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
