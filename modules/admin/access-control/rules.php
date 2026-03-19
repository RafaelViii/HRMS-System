<?php
/**
 * Access Control — Rules Management
 * CRUD for all access rules: IP, device, user, device-user binding, device-module binding.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_access('system', 'system_settings', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

$pageTitle = 'Access Rules';
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$redirectUrl = BASE_URL . '/modules/admin/access-control/rules';

// ─── Handle POST Actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_rule') {
        $data = [
            'id'                      => !empty($_POST['rule_id']) ? (int)$_POST['rule_id'] : null,
            'rule_type'               => $_POST['rule_type'] ?? 'whitelist',
            'entry_type'              => $_POST['entry_type'] ?? '',
            'scope'                   => $_POST['scope'] ?? 'global',
            'value'                   => $_POST['value'] ?? '',
            'device_fingerprint_hash' => $_POST['device_fingerprint_hash'] ?? null,
            'target_user_id'          => !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : null,
            'target_module'           => $_POST['target_module'] ?? null,
            'label'                   => $_POST['label'] ?? '',
            'reason'                  => $_POST['reason'] ?? '',
            'priority'                => (int)($_POST['priority'] ?? 0),
            'expires_at'              => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
            'is_active'               => isset($_POST['is_active']),
        ];

        // Auto-populate value for binding types
        if (in_array($data['entry_type'], ['device_user_bind', 'device_module_bind'], true)) {
            if (!empty($data['device_fingerprint_hash'])) {
                $data['value'] = $data['device_fingerprint_hash'];
            }
        }

        $result = acl_save_rule($data, $currentUserId);
        if ($result['ok']) {
            action_log('admin', 'access_rule_' . $result['action'], 'success', ['rule_id' => $result['id']]);
            flash_success('Rule ' . $result['action'] . ' successfully.');
        } else {
            flash_error($result['error'] ?? 'Failed to save rule.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId && acl_delete_rule($ruleId, $currentUserId)) {
            action_log('admin', 'access_rule_deleted', 'success', ['rule_id' => $ruleId]);
            flash_success('Rule deleted.');
        } else {
            flash_error('Failed to delete rule.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'toggle_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $active = !empty($_POST['set_active']);
        if ($ruleId && acl_toggle_rule($ruleId, $active, $currentUserId)) {
            flash_success('Rule ' . ($active ? 'enabled' : 'disabled') . '.');
        } else {
            flash_error('Failed to toggle rule.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// ─── Load Data ───────────────────────────────────────────────────────────
$filters = [];
if (!empty($_GET['entry_type']))  $filters['entry_type'] = $_GET['entry_type'];
if (!empty($_GET['rule_type']))   $filters['rule_type'] = $_GET['rule_type'];
if (isset($_GET['is_active']) && $_GET['is_active'] !== '') $filters['is_active'] = (bool)$_GET['is_active'];
if (!empty($_GET['search']))      $filters['search'] = $_GET['search'];

$rules = acl_list_rules($filters);
$devices = acl_list_devices(['active' => true]);
$users = acl_get_users_list();
$modules = acl_get_modules_list();

// Are we editing?
$editRule = null;
if (!empty($_GET['edit'])) {
    $editRule = acl_get_rule((int)$_GET['edit']);
}

// Entry type labels
$entryTypeLabels = [
    'ip'                => 'IP Address',
    'ip_range'          => 'IP Range (CIDR)',
    'device'            => 'Device',
    'user'              => 'User Account',
    'device_user_bind'  => 'Device → Account Binding',
    'device_module_bind' => 'Device → Module Binding',
];

// Colors by entry type
$entryTypeColors = [
    'ip'                => 'bg-gray-100 text-gray-700',
    'ip_range'          => 'bg-gray-100 text-gray-700',
    'device'            => 'bg-blue-100 text-blue-700',
    'user'              => 'bg-purple-100 text-purple-700',
    'device_user_bind'  => 'bg-emerald-100 text-emerald-700',
    'device_module_bind' => 'bg-amber-100 text-amber-700',
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
        <h1 class="text-xl font-bold text-slate-900">Access Rules</h1>
      </div>
      <p class="text-sm text-slate-500">Manage whitelist/blacklist rules, device bindings, and module restrictions.</p>
    </div>
    <button onclick="openRuleModal()" class="btn btn-primary">
      <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Add Rule
    </button>
  </div>

  <!-- Quick Filters -->
  <div class="flex flex-wrap gap-2">
    <a href="<?= $redirectUrl ?>" class="rounded-full px-3 py-1 text-xs font-medium transition <?= empty($filters) ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">All</a>
    <?php foreach ($entryTypeLabels as $key => $label): ?>
      <a href="<?= $redirectUrl ?>?entry_type=<?= $key ?>" class="rounded-full px-3 py-1 text-xs font-medium transition <?= ($filters['entry_type'] ?? '') === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="get" class="flex items-center gap-2">
    <?php if (!empty($filters['entry_type'])): ?><input type="hidden" name="entry_type" value="<?= htmlspecialchars($filters['entry_type']) ?>"><?php endif; ?>
    <div class="relative flex-1 max-w-sm">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" placeholder="Search rules…" class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
    </div>
    <button class="btn btn-secondary text-sm">Search</button>
    <?php if (!empty($filters['search'])): ?>
      <a href="<?= $redirectUrl ?><?= !empty($filters['entry_type']) ? '?entry_type=' . htmlspecialchars($filters['entry_type']) : '' ?>" class="text-xs text-gray-500 hover:text-gray-700">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Rules Table -->
  <div class="card overflow-hidden">
    <?php if (empty($rules)): ?>
      <div class="p-10 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        <p class="mt-3 text-sm text-gray-500">No rules found. <button onclick="openRuleModal()" class="text-indigo-600 font-medium hover:underline">Create your first rule</button></p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-gray-500">
            <tr>
              <th class="px-4 py-3 text-left">Type</th>
              <th class="px-4 py-3 text-left">Label / Value</th>
              <th class="px-4 py-3 text-left">Scope</th>
              <th class="px-4 py-3 text-left">Target</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th class="px-4 py-3 text-left">Priority</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($rules as $rule): 
              $isExpired = $rule['expires_at'] && strtotime($rule['expires_at']) < time();
            ?>
              <tr class="hover:bg-gray-50 transition <?= !$rule['is_active'] || $isExpired ? 'opacity-60' : '' ?>">
                <td class="px-4 py-3">
                  <div class="space-y-1">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase <?= $rule['rule_type'] === 'whitelist' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
                      <?= $rule['rule_type'] ?>
                    </span>
                    <span class="block">
                      <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium <?= $entryTypeColors[$rule['entry_type']] ?? 'bg-gray-100 text-gray-600' ?>">
                        <?= $entryTypeLabels[$rule['entry_type']] ?? $rule['entry_type'] ?>
                      </span>
                    </span>
                  </div>
                </td>
                <td class="px-4 py-3 max-w-xs">
                  <div class="font-medium text-gray-900 truncate"><?= htmlspecialchars($rule['label'] ?: '—') ?></div>
                  <?php if (in_array($rule['entry_type'], ['ip', 'ip_range'])): ?>
                    <div class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($rule['value']) ?></div>
                  <?php elseif ($rule['device_label']): ?>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($rule['device_label']) ?></div>
                  <?php elseif ($rule['value']): ?>
                    <div class="text-xs text-gray-400 font-mono truncate"><?= htmlspecialchars(substr($rule['value'], 0, 20)) ?>…</div>
                  <?php endif; ?>
                  <?php if ($rule['reason']): ?>
                    <div class="text-[11px] text-gray-400 mt-0.5 truncate" title="<?= htmlspecialchars($rule['reason']) ?>"><?= htmlspecialchars($rule['reason']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <span class="text-xs text-gray-600"><?= ucfirst(htmlspecialchars($rule['scope'] ?: 'global')) ?></span>
                </td>
                <td class="px-4 py-3 text-xs">
                  <?php if ($rule['target_user_name']): ?>
                    <div class="flex items-center gap-1">
                      <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                      <span class="text-gray-700"><?= htmlspecialchars($rule['target_user_name']) ?></span>
                    </div>
                  <?php endif; ?>
                  <?php if ($rule['target_module']): ?>
                    <div class="flex items-center gap-1 mt-0.5">
                      <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/></svg>
                      <span class="text-gray-700"><?= htmlspecialchars($modules[$rule['target_module']] ?? $rule['target_module']) ?></span>
                    </div>
                  <?php endif; ?>
                  <?php if (!$rule['target_user_name'] && !$rule['target_module']): ?>
                    <span class="text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <?php if ($isExpired): ?>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500">Expired</span>
                  <?php elseif ($rule['is_active']): ?>
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Active</span>
                  <?php else: ?>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500">Disabled</span>
                  <?php endif; ?>
                  <?php if ($rule['expires_at'] && !$isExpired): ?>
                    <div class="text-[10px] text-gray-400 mt-0.5">Expires <?= date('M d, Y', strtotime($rule['expires_at'])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center">
                  <span class="text-xs text-gray-600"><?= (int)$rule['priority'] ?></span>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <a href="<?= $redirectUrl ?>?edit=<?= $rule['id'] ?>" class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition" title="Edit">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </a>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="toggle_rule">
                      <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                      <input type="hidden" name="set_active" value="<?= $rule['is_active'] ? '0' : '1' ?>">
                      <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-amber-600 hover:bg-amber-50 transition" title="<?= $rule['is_active'] ? 'Disable' : 'Enable' ?>">
                        <?php if ($rule['is_active']): ?>
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        <?php else: ?>
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php endif; ?>
                      </button>
                    </form>
                    <form method="post" class="inline" data-confirm="Delete this rule? This cannot be undone.">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="delete_rule">
                      <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                      <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition" title="Delete">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Rule Modal -->
<div id="ruleModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 overflow-y-auto" style="display:none;">
  <div class="relative mx-auto my-6 w-full max-w-2xl rounded-xl bg-white shadow-xl">
    <form method="post" id="ruleForm" class="flex flex-col max-h-[90vh]">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_rule">
      <input type="hidden" name="rule_id" id="ruleId" value="<?= $editRule['id'] ?? '' ?>">

      <!-- Modal Header -->
      <div class="flex items-center justify-between border-b px-6 py-4">
        <h2 class="text-lg font-semibold text-gray-900" id="ruleModalTitle"><?= $editRule ? 'Edit Rule' : 'New Access Rule' ?></h2>
        <button type="button" onclick="closeRuleModal()" class="rounded-lg p-1 text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="flex-1 overflow-y-auto p-6 space-y-5">
        <!-- Rule Type Templates (Quick Start) -->
        <div id="ruleTemplates" class="<?= $editRule ? 'hidden' : '' ?>">
          <label class="block text-sm font-medium text-gray-700 mb-2">What do you want to do?</label>
          <div class="grid gap-2 sm:grid-cols-2">
            <button type="button" onclick="applyTemplate('device_user_bind')" class="group flex items-center gap-3 rounded-lg border-2 border-gray-200 p-3 transition hover:border-emerald-400 hover:bg-emerald-50 text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100"><svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg></div>
              <div><span class="text-sm font-medium text-gray-900">Bind Device to Account</span><span class="block text-[11px] text-gray-500">Only specified accounts can log in from this device</span></div>
            </button>
            <button type="button" onclick="applyTemplate('device_module_bind')" class="group flex items-center gap-3 rounded-lg border-2 border-gray-200 p-3 transition hover:border-amber-400 hover:bg-amber-50 text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100"><svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/></svg></div>
              <div><span class="text-sm font-medium text-gray-900">Bind Device to Module</span><span class="block text-[11px] text-gray-500">Restrict this device to access only certain modules</span></div>
            </button>
            <button type="button" onclick="applyTemplate('ip_block')" class="group flex items-center gap-3 rounded-lg border-2 border-gray-200 p-3 transition hover:border-red-400 hover:bg-red-50 text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100"><svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></div>
              <div><span class="text-sm font-medium text-gray-900">Block an IP Address</span><span class="block text-[11px] text-gray-500">Blacklist a specific IP or subnet</span></div>
            </button>
            <button type="button" onclick="applyTemplate('ip_allow')" class="group flex items-center gap-3 rounded-lg border-2 border-gray-200 p-3 transition hover:border-blue-400 hover:bg-blue-50 text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-100"><svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
              <div><span class="text-sm font-medium text-gray-900">Allow an IP Address</span><span class="block text-[11px] text-gray-500">Whitelist a specific IP or subnet</span></div>
            </button>
          </div>
          <div class="mt-3 text-center">
            <button type="button" onclick="showAdvancedForm()" class="text-xs text-gray-500 hover:text-indigo-600 font-medium">or create a custom rule →</button>
          </div>
        </div>

        <!-- Rule Form Fields -->
        <div id="ruleFields" class="space-y-4 <?= $editRule ? '' : 'hidden' ?>">
          <div class="grid gap-4 sm:grid-cols-2">
            <!-- Label -->
            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1 required">Label</label>
              <input type="text" name="label" id="ruleLabel" value="<?= htmlspecialchars($editRule['label'] ?? '') ?>" placeholder="e.g., HR Kiosk → John Smith" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent" required>
            </div>

            <!-- Rule Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Rule Type</label>
              <select name="rule_type" id="ruleType" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="whitelist" <?= ($editRule['rule_type'] ?? '') === 'whitelist' ? 'selected' : '' ?>>Whitelist (Allow)</option>
                <option value="blacklist" <?= ($editRule['rule_type'] ?? '') === 'blacklist' ? 'selected' : '' ?>>Blacklist (Block)</option>
              </select>
            </div>

            <!-- Entry Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Entry Type</label>
              <select name="entry_type" id="ruleEntryType" onchange="onEntryTypeChange(this.value)" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?php foreach ($entryTypeLabels as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($editRule['entry_type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Value (IP Address / manual) -->
            <div id="valueGroup" class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1" id="valueLabel">Value</label>
              <input type="text" name="value" id="ruleValue" value="<?= htmlspecialchars($editRule['value'] ?? '') ?>" placeholder="" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
              <p class="text-xs text-gray-500 mt-1" id="valueHint">IP address, CIDR range, or device fingerprint hash</p>
            </div>

            <!-- Device Selector -->
            <div id="deviceGroup" class="sm:col-span-2 hidden">
              <label class="block text-sm font-medium text-gray-700 mb-1">Select Device</label>
              <select name="device_fingerprint_hash" id="ruleDeviceHash" onchange="onDeviceSelect()" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="">— Choose a registered device —</option>
                <?php foreach ($devices as $d): ?>
                  <option value="<?= htmlspecialchars($d['fingerprint_hash']) ?>" <?= ($editRule['device_fingerprint_hash'] ?? '') === $d['fingerprint_hash'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['label'] ?: 'Unknown') ?> (<?= substr($d['fingerprint_hash'], 0, 12) ?>…)
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($devices)): ?>
                <p class="text-xs text-amber-600 mt-1">No devices registered yet. <a href="<?= BASE_URL ?>/modules/admin/access-control/devices" class="font-medium underline">Register a device first →</a></p>
              <?php endif; ?>
            </div>

            <!-- Target User -->
            <div id="userGroup" class="hidden">
              <label class="block text-sm font-medium text-gray-700 mb-1">Target User</label>
              <select name="target_user_id" id="ruleTargetUser" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="">— Select user —</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= ($editRule['target_user_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Target Module -->
            <div id="moduleGroup" class="hidden">
              <label class="block text-sm font-medium text-gray-700 mb-1">Target Module</label>
              <select name="target_module" id="ruleTargetModule" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="">— Select module —</option>
                <?php foreach ($modules as $key => $label): ?>
                  <option value="<?= $key ?>" <?= ($editRule['target_module'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Scope -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Scope</label>
              <select name="scope" id="ruleScope" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <option value="global" <?= ($editRule['scope'] ?? '') === 'global' ? 'selected' : '' ?>>Global</option>
                <option value="login" <?= ($editRule['scope'] ?? '') === 'login' ? 'selected' : '' ?>>Login Only</option>
                <?php foreach ($modules as $key => $label): ?>
                  <option value="<?= $key ?>" <?= ($editRule['scope'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Priority -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
              <input type="number" name="priority" value="<?= (int)($editRule['priority'] ?? 0) ?>" min="0" max="1000" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
              <p class="text-xs text-gray-500 mt-0.5">Higher = evaluated first. 0 = default.</p>
            </div>

            <!-- Reason -->
            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Reason / Notes</label>
              <input type="text" name="reason" value="<?= htmlspecialchars($editRule['reason'] ?? '') ?>" placeholder="Optional reason for this rule" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>

            <!-- Expires At -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Expires At (optional)</label>
              <input type="datetime-local" name="expires_at" value="<?= $editRule['expires_at'] ? date('Y-m-d\TH:i', strtotime($editRule['expires_at'])) : '' ?>" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>

            <!-- Active Toggle -->
            <div class="flex items-center gap-3 self-end pb-1">
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" name="is_active" value="1" class="sr-only peer" <?= ($editRule['is_active'] ?? true) ? 'checked' : '' ?>>
                <div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                <span class="ml-2 text-sm text-gray-700">Active</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div id="ruleFooter" class="flex items-center justify-end gap-3 border-t px-6 py-4 <?= $editRule ? '' : 'hidden' ?>">
        <button type="button" onclick="closeRuleModal()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          <?= $editRule ? 'Update Rule' : 'Create Rule' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openRuleModal() {
  const modal = document.getElementById('ruleModal');
  modal.style.display = 'flex';
  modal.classList.remove('hidden');
}

function closeRuleModal() {
  const modal = document.getElementById('ruleModal');
  modal.style.display = 'none';
  modal.classList.add('hidden');
  // Reset form if adding new
  if (!document.getElementById('ruleId').value) {
    document.getElementById('ruleTemplates').classList.remove('hidden');
    document.getElementById('ruleFields').classList.add('hidden');
    document.getElementById('ruleFooter').classList.add('hidden');
  }
}

function showAdvancedForm() {
  document.getElementById('ruleTemplates').classList.add('hidden');
  document.getElementById('ruleFields').classList.remove('hidden');
  document.getElementById('ruleFooter').classList.remove('hidden');
  onEntryTypeChange(document.getElementById('ruleEntryType').value);
}

function applyTemplate(type) {
  const entryType = document.getElementById('ruleEntryType');
  const ruleType = document.getElementById('ruleType');

  if (type === 'device_user_bind') {
    entryType.value = 'device_user_bind';
    ruleType.value = 'whitelist';
  } else if (type === 'device_module_bind') {
    entryType.value = 'device_module_bind';
    ruleType.value = 'whitelist';
  } else if (type === 'ip_block') {
    entryType.value = 'ip';
    ruleType.value = 'blacklist';
  } else if (type === 'ip_allow') {
    entryType.value = 'ip';
    ruleType.value = 'whitelist';
  }

  showAdvancedForm();
}

function onEntryTypeChange(val) {
  const deviceGroup = document.getElementById('deviceGroup');
  const userGroup = document.getElementById('userGroup');
  const moduleGroup = document.getElementById('moduleGroup');
  const valueGroup = document.getElementById('valueGroup');
  const valueLabel = document.getElementById('valueLabel');
  const valueHint = document.getElementById('valueHint');

  // Reset visibility
  deviceGroup.classList.add('hidden');
  userGroup.classList.add('hidden');
  moduleGroup.classList.add('hidden');
  valueGroup.classList.remove('hidden');

  if (val === 'ip') {
    valueLabel.textContent = 'IP Address';
    valueHint.textContent = 'e.g., 192.168.1.100';
  } else if (val === 'ip_range') {
    valueLabel.textContent = 'IP Range (CIDR)';
    valueHint.textContent = 'e.g., 192.168.1.0/24 or 10.0.0.0/8';
  } else if (val === 'device') {
    valueGroup.classList.add('hidden');
    deviceGroup.classList.remove('hidden');
  } else if (val === 'user') {
    valueLabel.textContent = 'User ID';
    valueHint.textContent = 'Select the user from the dropdown below';
    userGroup.classList.remove('hidden');
  } else if (val === 'device_user_bind') {
    valueGroup.classList.add('hidden');
    deviceGroup.classList.remove('hidden');
    userGroup.classList.remove('hidden');
  } else if (val === 'device_module_bind') {
    valueGroup.classList.add('hidden');
    deviceGroup.classList.remove('hidden');
    moduleGroup.classList.remove('hidden');
  }
}

function onDeviceSelect() {
  const select = document.getElementById('ruleDeviceHash');
  const valueInput = document.getElementById('ruleValue');
  if (select.value) {
    valueInput.value = select.value;
  }
}

// Auto-open modal if editing
<?php if ($editRule): ?>
document.addEventListener('DOMContentLoaded', function() {
  openRuleModal();
  onEntryTypeChange('<?= $editRule['entry_type'] ?>');
});
<?php endif; ?>

// Close modal on backdrop click
document.getElementById('ruleModal').addEventListener('click', function(e) {
  if (e.target === this) closeRuleModal();
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeRuleModal();
});

// Init entry type visibility
document.addEventListener('DOMContentLoaded', function() {
  <?php if ($editRule): ?>
  onEntryTypeChange('<?= $editRule['entry_type'] ?>');
  <?php endif; ?>
});

// SPA re-init
document.addEventListener('spa:loaded', function() {
  <?php if ($editRule): ?>
  openRuleModal();
  onEntryTypeChange('<?= $editRule['entry_type'] ?>');
  <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
