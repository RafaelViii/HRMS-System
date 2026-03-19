<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('leave', 'leave_defaults', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pageTitle = 'Leave Defaults';
$pdo = get_db_conn();
action_log('leave', 'view_leave_defaults_admin');

$redirectUrl = BASE_URL . '/modules/admin/leave-defaults';

if (!function_exists('format_leave_days_input')) {
    function format_leave_days_input($value): string {
        if ($value === null || $value === '') {
            return '';
        }
        $formatted = number_format((float)$value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token. Please try again.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case 'save_leave_defaults':
                $authz = ensure_action_authorized('leave', 'save_global_entitlements', 'write');
                if (!$authz['ok']) {
                    $msg = $authz['error'] === 'no_access'
                        ? 'You do not have permission to update global leave defaults.'
                        : 'Authorization failed. Provide a valid credential.';
                    flash_error($msg);
                    break;
                }
                $payload = $_POST['leave_days'] ?? [];
                if (!is_array($payload)) {
                    flash_error('Invalid payload for leave defaults.');
                    break;
                }
                $knownTypes = leave_get_known_types($pdo);
                $entries = [];
                foreach ($knownTypes as $type) {
                    $raw = trim((string)($payload[$type] ?? ''));
                    if ($raw === '') {
                        $entries[$type] = null;
                        continue;
                    }
                    if (!is_numeric($raw)) {
                        flash_error('Leave allowance values must be numeric.');
                        $entries = null;
                        break;
                    }
                    $entries[$type] = max(0, (float)$raw);
                }
                if ($entries === null) {
                    break;
                }
                try {
                    $pdo->beginTransaction();
                    $deleteStmt = $pdo->prepare('DELETE FROM leave_entitlements WHERE scope_type = :scope AND scope_id IS NULL AND leave_type = :type');
                    $upsertStmt = $pdo->prepare('INSERT INTO leave_entitlements (scope_type, scope_id, leave_type, days) VALUES (:scope, NULL, :type, :days) ON CONFLICT (scope_type, scope_id, leave_type) DO UPDATE SET days = EXCLUDED.days, updated_at = CURRENT_TIMESTAMP');
                    $updatedCount = 0;
                    foreach ($entries as $type => $value) {
                        $params = [':scope' => 'global', ':type' => $type];
                        if ($value === null) {
                            $deleteStmt->execute($params);
                            continue;
                        }
                        $upsertStmt->execute($params + [':days' => $value]);
                        $updatedCount++;
                    }
                    $pdo->commit();
                    action_log('leave', 'global_entitlements_saved', 'success', ['count' => $updatedCount]);
                    flash_success('Default leave allowances updated.');
                } catch (Throwable $inner) {
                    if ($pdo->inTransaction()) {
                        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                    }
                    throw $inner;
                }
                break;

            case 'save_department_entitlements':
                $authz = ensure_action_authorized('leave', 'save_department_entitlements', 'admin');
                if (!$authz['ok']) {
                    $msg = $authz['error'] === 'no_access'
                        ? 'You do not have permission to manage department overrides.'
                        : 'Authorization failed. Provide an admin credential.';
                    flash_error($msg);
                    break;
                }
                $deptId = (int)($_POST['department_id'] ?? 0);
                if ($deptId <= 0) {
                    flash_error('Select a department to update.');
                    break;
                }
                $deptStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
                $deptStmt->execute([':id' => $deptId]);
                $deptName = $deptStmt->fetchColumn();
                if (!$deptName) {
                    flash_error('Department not found.');
                    break;
                }
                $payload = $_POST['leave_days'] ?? [];
                if (!is_array($payload)) {
                    flash_error('Invalid payload for department overrides.');
                    break;
                }
                $knownTypes = leave_get_known_types($pdo);
                $entries = [];
                foreach ($knownTypes as $type) {
                    $raw = trim((string)($payload[$type] ?? ''));
                    if ($raw === '') {
                        $entries[$type] = null;
                        continue;
                    }
                    if (!is_numeric($raw)) {
                        flash_error('Leave allowance values must be numeric.');
                        $entries = null;
                        break;
                    }
                    $entries[$type] = max(0, (float)$raw);
                }
                if ($entries === null) {
                    break;
                }
                try {
                    $pdo->beginTransaction();
                    $deleteStmt = $pdo->prepare('DELETE FROM leave_entitlements WHERE scope_type = :scope AND scope_id = :scope_id AND leave_type = :type');
                    $upsertStmt = $pdo->prepare('INSERT INTO leave_entitlements (scope_type, scope_id, leave_type, days) VALUES (:scope, :scope_id, :type, :days) ON CONFLICT (scope_type, scope_id, leave_type) DO UPDATE SET days = EXCLUDED.days, updated_at = CURRENT_TIMESTAMP');
                    $updatedCount = 0;
                    foreach ($entries as $type => $value) {
                        $baseParams = [':scope' => 'department', ':scope_id' => $deptId, ':type' => $type];
                        if ($value === null) {
                            $deleteStmt->execute($baseParams);
                            continue;
                        }
                        $upsertStmt->execute($baseParams + [':days' => $value]);
                        $updatedCount++;
                    }
                    $pdo->commit();
                    $context = [
                        'department_id' => $deptId,
                        'department_name' => $deptName,
                        'count' => $updatedCount,
                        'authorized_by' => (int)($authz['as_user'] ?? 0) ?: null,
                    ];
                    action_log('leave', 'department_entitlements_saved', 'success', $context);
                    $message = $updatedCount > 0 ? 'Department leave overrides saved.' : 'Department leave overrides cleared.';
                    flash_success($message);
                } catch (Throwable $inner) {
                    if ($pdo->inTransaction()) {
                        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                    }
                    throw $inner;
                }
                break;

            default:
                flash_error('Unsupported action.');
                break;
        }
    } catch (Throwable $e) {
        sys_log('LEAVE-DEFAULTS-ADMIN', 'Failed saving leave defaults: ' . $e->getMessage(), [
            'module' => 'leave',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['action' => $action],
        ]);
        flash_error('We could not process the requested change.');
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$leaveTypes = leave_get_known_types($pdo);
$defaultEntitlements = leave_get_default_entitlements();
$globalEntitlements = leave_fetch_entitlements($pdo, 'global', null);

$departments = [];
try {
    $departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('LEAVE-DEPARTMENT-LIST', 'Unable to load departments for leave overrides: ' . $e->getMessage(), [
        'module' => 'leave',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$departmentOverrides = [];
try {
    $deptOverridesStmt = $pdo->query("SELECT scope_id, LOWER(leave_type) AS leave_type, days FROM leave_entitlements WHERE scope_type = 'department'");
    foreach ($deptOverridesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deptId = (int)($row['scope_id'] ?? 0);
        if ($deptId <= 0) {
            continue;
        }
        $type = strtolower((string)($row['leave_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $departmentOverrides[$deptId][$type] = max(0, (float)($row['days'] ?? 0));
    }
} catch (Throwable $e) {
    sys_log('LEAVE-DEPARTMENT-READ', 'Unable to load department leave overrides: ' . $e->getMessage(), [
        'module' => 'leave',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$departmentSummaries = [];
$departmentOverrideCounts = [];
$departmentEffective = [];
foreach ($departments as $deptRow) {
    $deptId = (int)$deptRow['id'];
    $overrides = $departmentOverrides[$deptId] ?? [];
    $overrideCount = 0;
    foreach ($leaveTypes as $type) {
        $overrideValue = array_key_exists($type, $overrides) ? $overrides[$type] : null;
        if ($overrideValue !== null) {
            $overrideCount++;
        }
        $globalValue = $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0);
        $effectiveValue = $overrideValue ?? $globalValue;
        $departmentEffective[$deptId][$type] = [
            'override' => $overrideValue,
            'global' => $globalValue,
            'effective' => $effectiveValue,
        ];
    }
    $departmentOverrideCounts[$deptId] = $overrideCount;
    if ($overrideCount === 0) {
        $departmentSummaries[$deptId] = 'Using global defaults';
        continue;
    }
    $parts = [];
    foreach ($overrides as $type => $value) {
        if ($value === null) {
            continue;
        }
        $parts[] = leave_label_for_type($type) . ': ' . format_leave_days_input($value) . 'd';
    }
    $departmentSummaries[$deptId] = $parts ? implode(', ', $parts) : 'Using global defaults';
}

$leaveStats = [
    'global' => count(array_filter($globalEntitlements, static fn($value) => $value !== null)),
    'departments' => 0,
];
foreach ($departmentOverrides as $overrides) {
    if (!empty($overrides)) {
        $leaveStats['departments']++;
    }
}

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
          <span class="uppercase tracking-[0.2em] text-slate-500">Leave</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Leave Defaults</h1>
        <p class="text-sm text-slate-600">Set global quotas per leave type and manage department overrides.</p>
      </div>
      <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-indigo-500">Leave types</p>
          <p class="mt-1 text-2xl font-semibold text-indigo-900"><?= count($leaveTypes) ?></p>
          <p class="text-xs text-indigo-600">Available for configuration.</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">Dept overrides</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= (int)$leaveStats['departments'] ?></p>
          <p class="text-xs text-emerald-600">Custom allowances in use.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-slate-500">Global overrides</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int)$leaveStats['global'] ?></p>
          <p class="text-xs text-slate-500">Leave types with explicit values.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="card p-6 space-y-6" id="global-defaults">
    <div>
      <h2 class="text-xl font-semibold text-gray-900">Default Leave Allowances</h2>
      <p class="text-sm text-gray-600">Set the organization-wide quota for each leave type. Leave a field blank to fall back to the system default.</p>
    </div>
    <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
          data-authz-module="leave" data-authz-required="write" data-authz-action="Save global leave defaults">
      <input type="hidden" name="csrf" value="<?= $csrf ?>" />
      <input type="hidden" name="action" value="save_leave_defaults" />
      <?php foreach ($leaveTypes as $type): ?>
        <?php $value = $globalEntitlements[$type] ?? null; ?>
        <label class="block text-sm">
          <span class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(leave_label_for_type($type)) ?></span>
          <input type="number" step="0.5" min="0" class="input-text w-full mt-1" name="leave_days[<?= htmlspecialchars($type) ?>]" value="<?= htmlspecialchars(format_leave_days_input($value)) ?>" placeholder="Inherit" />
        </label>
      <?php endforeach; ?>
      <div class="sm:col-span-2 lg:col-span-3 flex items-center justify-between text-xs text-gray-500">
        <span>Blank values inherit defaults from <code>LEAVE_DEFAULT_ENTITLEMENTS</code>.</span>
        <button type="submit" class="btn btn-primary">Save Defaults</button>
      </div>
    </form>
  </section>

  <section class="card p-6 space-y-6" id="department-overrides">
    <div>
      <h2 class="text-xl font-semibold text-gray-900">Department Overrides</h2>
      <p class="text-sm text-gray-600">Apply department-specific allowances. Clear values to restore the organization defaults.</p>
    </div>
    <?php if (!$departments): ?>
      <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">No departments found. Add departments first to configure overrides.</div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($departments as $deptRow): ?>
          <?php
            $deptId = (int)$deptRow['id'];
            $summaryText = $departmentSummaries[$deptId] ?? 'Using global defaults';
            $overrideCount = $departmentOverrideCounts[$deptId] ?? 0;
            $effectiveSet = $departmentEffective[$deptId] ?? [];
          ?>
          <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-4 md:flex-row md:items-center md:justify-between">
              <div>
                <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($deptRow['name']) ?></p>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($summaryText) ?></p>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full border <?= $overrideCount > 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-50 text-gray-500' ?> px-3 py-1 text-[11px] font-semibold uppercase tracking-wide">
                  <?= $overrideCount > 0 ? ($overrideCount === 1 ? '1 override active' : $overrideCount . ' overrides active') : 'No overrides' ?>
                </span>
                <button type="button" class="btn btn-outline px-3 py-2 text-sm" data-dept-modal="dept-modal-<?= $deptId ?>" data-dept-id="<?= $deptId ?>">Edit</button>
              </div>
            </div>
            <dl class="grid gap-3 px-4 py-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
              <?php foreach ($leaveTypes as $type): ?>
                <?php
                  $metrics = $effectiveSet[$type] ?? [
                    'override' => null,
                    'global' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                    'effective' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                  ];
                  $overrideValue = $metrics['override'];
                  $globalValue = $metrics['global'];
                  $effectiveValue = $metrics['effective'];
                ?>
                <div class="rounded-lg bg-gray-50 p-3">
                  <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(leave_label_for_type($type)) ?></dt>
                  <dd class="mt-1 flex items-center justify-between font-semibold text-gray-900">
                    <span><?= htmlspecialchars(format_leave_days_input($effectiveValue)) ?>d</span>
                    <?php if ($overrideValue !== null): ?>
                      <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Override</span>
                    <?php else: ?>
                      <span class="ml-2 text-[11px] uppercase tracking-wide text-gray-400">Default</span>
                    <?php endif; ?>
                  </dd>
                  <?php if ($overrideValue !== null): ?>
                    <p class="mt-1 text-xs text-gray-500">Global/default: <?= htmlspecialchars(format_leave_days_input($globalValue)) ?>d</p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </dl>
          </div>
        <?php endforeach; ?>
      </div>

      <?php foreach ($departments as $deptRow): ?>
        <?php
          $deptId = (int)$deptRow['id'];
          $effectiveSet = $departmentEffective[$deptId] ?? [];
        ?>
        <div id="dept-modal-<?= $deptId ?>" class="dept-modal fixed inset-0 z-50 hidden" data-dept-id="<?= $deptId ?>" data-dept-name="<?= htmlspecialchars($deptRow['name'], ENT_QUOTES) ?>">
          <div class="absolute inset-0 bg-black/40" data-close></div>
          <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
              <div class="flex items-start justify-between border-b border-gray-100 px-5 py-4">
                <div>
                  <h3 class="text-lg font-semibold text-gray-900" data-dept-modal-title>Adjust <?= htmlspecialchars($deptRow['name']) ?> overrides</h3>
                  <p class="text-xs text-gray-500">Leave a field blank to inherit the global allowance.</p>
                </div>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-close aria-label="Close">✕</button>
              </div>
              <form method="post" id="department-form-<?= $deptId ?>" class="space-y-5 px-5 py-5" data-dept-form
                    data-authz-module="leave" data-authz-required="admin" data-authz-action="Save department leave overrides" data-authz-force>
                <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                <input type="hidden" name="action" value="save_department_entitlements" />
                <input type="hidden" name="department_id" value="<?= $deptId ?>" data-dept-input="department_id" />
                <input type="hidden" name="override_force" value="1" />
                <div class="grid gap-4 sm:grid-cols-2">
                  <?php foreach ($leaveTypes as $type): ?>
                    <?php
                      $metrics = $effectiveSet[$type] ?? [
                        'override' => null,
                        'global' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                        'effective' => $globalEntitlements[$type] ?? ($defaultEntitlements[$type] ?? 0),
                      ];
                      $currentOverride = $metrics['override'];
                      $currentDefault = $metrics['effective'];
                      $inheritValue = $metrics['global'];
                    ?>
                    <label class="block text-sm" data-leave-row data-leave-type="<?= htmlspecialchars($type) ?>">
                      <span class="text-xs uppercase tracking-wide text-gray-500" data-leave-label><?= htmlspecialchars(leave_label_for_type($type)) ?></span>
                      <input type="number" step="0.5" min="0" class="input-text mt-1 w-full" name="leave_days[<?= htmlspecialchars($type) ?>]" value="<?= htmlspecialchars(format_leave_days_input($currentOverride)) ?>" placeholder="Inherit" data-leave-input />
                      <p class="mt-1 text-xs text-gray-500" data-leave-meta>
                        Current: <?= htmlspecialchars(format_leave_days_input($currentDefault)) ?>d <?= $currentOverride !== null ? '(override)' : '(default)' ?>
                        <?php if ($currentOverride !== null): ?> · Global <?= htmlspecialchars(format_leave_days_input($inheritValue)) ?>d<?php endif; ?>
                      </p>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
                  Sensitive change. Provide an admin credential to confirm this override update.
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                  <button type="button" class="btn btn-outline text-sm" data-clear-target="#department-form-<?= $deptId ?>">Clear Overrides</button>
                  <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-outline text-sm" data-close>Cancel</button>
                    <button type="submit" class="btn btn-primary text-sm">Save Overrides</button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<script>
(function(){
  document.querySelectorAll('[data-clear-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const selector = btn.getAttribute('data-clear-target');
      if (!selector) return;
      const form = document.querySelector(selector);
      if (!form) return;
      const inputs = form.querySelectorAll('input[type="number"]');
      inputs.forEach((input) => { input.value = ''; });
    });
  });
})();
</script>
<script>
(function(){
  function bindDepartmentModals(scope = document) {
    scope.querySelectorAll('[data-dept-modal]').forEach((btn) => {
      if (btn.dataset.deptModalBound) return;
      btn.dataset.deptModalBound = '1';
      const target = btn.getAttribute('data-dept-modal');
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        if (target) {
          openModal(target);
        }
      });
    });
    scope.querySelectorAll('.dept-modal').forEach((modal) => {
      if (modal.dataset.modalBound) return;
      modal.dataset.modalBound = '1';
      modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
          closeModal(modal.id);
        }
      });
    });
  }
  bindDepartmentModals(document);
  document.addEventListener('spa:loaded', () => bindDepartmentModals(document));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.dept-modal').forEach((modal) => {
        if (!modal.classList.contains('hidden')) {
          closeModal(modal.id);
        }
      });
    }
  });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
