<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('payroll', 'approval_workflow', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/payroll.php';

$pageTitle = 'Approval Workflow';
$pdo = get_db_conn();
action_log('payroll', 'view_approval_workflow_admin');

$redirectUrl = BASE_URL . '/modules/admin/approval-workflow';

$authorize = static function (string $actionKey, string $requiredLevel = 'write') {
    $authz = ensure_action_authorized('payroll', $actionKey, $requiredLevel);
    if (!$authz['ok']) {
        $msg = $authz['error'] === 'no_access'
            ? 'You do not have permission to manage the payroll approval workflow.'
            : 'Authorization failed. Provide an authorized credential.';
        flash_error($msg);
        return null;
    }
    return $authz;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token. Please try again.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case 'update_order_bulk':
                $authz = $authorize('update_approver_order', 'write');
                if (!$authz) {
                    break;
                }
                $payload = trim((string)($_POST['order_payload'] ?? ''));
                $rows = json_decode($payload, true);
                if (!is_array($rows)) {
                    flash_error('Invalid order payload.');
                    break;
                }
                $seen = [];
                foreach ($rows as $row) {
                    $id = (int)($row['id'] ?? 0);
                    $step = (int)($row['step_order'] ?? 0);
                    if ($id <= 0 || $step <= 0) {
                        flash_error('Step orders must be positive numbers.');
                        break 2;
                    }
                    if (isset($seen[$step])) {
                        flash_error('Duplicate step order detected.');
                        break 2;
                    }
                    $seen[$step] = true;
                }
                $pdo->beginTransaction();
                try {
                    $update = $pdo->prepare('UPDATE payroll_approvers SET step_order = :step, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    foreach ($rows as $row) {
                        $update->execute([
                            ':step' => (int)$row['step_order'],
                            ':id' => (int)$row['id'],
                        ]);
                    }
                    $pdo->commit();
                    action_log('payroll', 'approver_order_updated', 'success', ['count' => count($rows)]);
                    flash_success('Approval steps reordered.');
                } catch (Throwable $inner) {
                    try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                    throw $inner;
                }
                break;

            case 'add_approver':
                $authz = $authorize('add_payroll_approver', 'write');
                if (!$authz) {
                    break;
                }
                $userId = (int)($_POST['user_id'] ?? 0);
                $stepOrder = (int)($_POST['step_order'] ?? 0);
                $appliesToRaw = trim((string)($_POST['applies_to'] ?? ''));
                $scope = $appliesToRaw !== '' ? $appliesToRaw : null;
                $activeFlag = isset($_POST['active']);

                if ($userId <= 0 || $stepOrder <= 0) {
                    flash_error('Select a user and provide a step order greater than zero.');
                    break;
                }

                $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id LIMIT 1');
                $userStmt->execute([':id' => $userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    flash_error('The selected user no longer exists.');
                    break;
                }

                $lookup = $pdo->prepare("SELECT id FROM payroll_approvers WHERE user_id = :user_id AND COALESCE(applies_to, 'global') = COALESCE(:scope, 'global') LIMIT 1");
                $lookup->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $lookup->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $lookup->execute();
                $existingId = $lookup->fetchColumn();

                if ($existingId) {
                    $update = $pdo->prepare('UPDATE payroll_approvers SET step_order = :step_order, applies_to = :scope, active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $update->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
                    $update->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $update->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
                    $update->bindValue(':id', (int)$existingId, PDO::PARAM_INT);
                    $update->execute();
                    action_log('payroll', 'approver_updated', 'success', [
                        'approver_id' => (int)$existingId,
                        'user_id' => $userId,
                        'scope' => $scope,
                        'step_order' => $stepOrder,
                    ]);
                    flash_success('Approver updated.');
                } else {
                    $insert = $pdo->prepare('INSERT INTO payroll_approvers (user_id, step_order, applies_to, active) VALUES (:user_id, :step_order, :scope, :active) RETURNING id');
                    $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
                    $insert->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
                    $insert->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $insert->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
                    $insert->execute();
                    $approverId = (int)$insert->fetchColumn();
                    action_log('payroll', 'approver_added', 'success', [
                        'approver_id' => $approverId,
                        'user_id' => $userId,
                        'scope' => $scope,
                        'step_order' => $stepOrder,
                    ]);
                    flash_success('Approver added.');
                }
                break;

            case 'update_approver':
                $authz = $authorize('update_payroll_approver', 'write');
                if (!$authz) {
                    break;
                }
                $approverId = (int)($_POST['approver_id'] ?? 0);
                $stepOrder = (int)($_POST['step_order'] ?? 0);
                $scopeRaw = trim((string)($_POST['applies_to'] ?? ''));
                $scope = $scopeRaw !== '' ? $scopeRaw : null;
                $activeFlag = isset($_POST['active']);

                if ($approverId <= 0 || $stepOrder <= 0) {
                    flash_error('Provide a valid step order for the selected approver.');
                    break;
                }

                $current = $pdo->prepare('SELECT user_id FROM payroll_approvers WHERE id = :id LIMIT 1');
                $current->execute([':id' => $approverId]);
                $row = $current->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    flash_error('Approver record not found.');
                    break;
                }

                $update = $pdo->prepare('UPDATE payroll_approvers SET step_order = :step_order, applies_to = :scope, active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $update->bindValue(':step_order', $stepOrder, PDO::PARAM_INT);
                $update->bindValue(':scope', $scope, $scope === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $update->bindValue(':active', $activeFlag, PDO::PARAM_BOOL);
                $update->bindValue(':id', $approverId, PDO::PARAM_INT);
                $update->execute();

                action_log('payroll', 'approver_updated', 'success', [
                    'approver_id' => $approverId,
                    'user_id' => (int)$row['user_id'],
                    'scope' => $scope,
                    'step_order' => $stepOrder,
                    'active' => $activeFlag,
                ]);
                flash_success('Approver saved.');
                break;

            case 'delete_approver':
                $authz = $authorize('delete_payroll_approver', 'admin');
                if (!$authz) {
                    break;
                }
                $approverId = (int)($_POST['approver_id'] ?? 0);
                if ($approverId <= 0) {
                    flash_error('Approver record not found.');
                    break;
                }
                $fetch = $pdo->prepare('SELECT user_id FROM payroll_approvers WHERE id = :id LIMIT 1');
                $fetch->execute([':id' => $approverId]);
                $row = $fetch->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    flash_error('Approver record not found.');
                    break;
                }
                $delete = $pdo->prepare('DELETE FROM payroll_approvers WHERE id = :id');
                $delete->execute([':id' => $approverId]);
                action_log('payroll', 'approver_removed', 'success', [
                    'approver_id' => $approverId,
                    'user_id' => (int)$row['user_id'],
                ]);
                flash_success('Approver removed.');
                break;

            default:
                flash_error('Unsupported action.');
                break;
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-APPROVAL-ADMIN', 'Failed managing approval workflow: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['action' => $action],
        ]);
        flash_error('We could not process the requested change.');
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$approvers = [];
try {
    $stmt = $pdo->query('SELECT pa.*, u.full_name, u.email FROM payroll_approvers pa LEFT JOIN users u ON u.id = pa.user_id ORDER BY pa.step_order, pa.id');
    $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('PAYROLL-APPROVER-LIST', 'Failed loading payroll approvers: ' . $e->getMessage(), [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$activeUsers = [];
try {
    $userStmt = $pdo->query("SELECT id, full_name, email, role FROM users WHERE status = 'active' ORDER BY full_name");
    $activeUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('PAYROLL-APPROVER-USERS', 'Failed loading user list for approvers: ' . $e->getMessage(), [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
    ]);
}

$assignedGlobal = [];
foreach ($approvers as $row) {
    if (($row['applies_to'] ?? null) === null) {
        $assignedGlobal[(int)$row['user_id']] = true;
    }
}

$approverStats = [
    'total' => count($approvers),
    'active' => 0,
    'scoped' => 0,
];
foreach ($approvers as $row) {
    if (!empty($row['active'])) {
        $approverStats['active']++;
    }
    if (($row['applies_to'] ?? '') !== null && trim((string)$row['applies_to']) !== '') {
        $approverStats['scoped']++;
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
          <span class="uppercase tracking-[0.2em] text-slate-500">Approvals</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Approval Workflow</h1>
        <p class="text-sm text-slate-600">Define sequential approvers and scopes for payroll release.</p>
      </div>
      <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-indigo-500">Active approvers</p>
          <p class="mt-1 text-2xl font-semibold text-indigo-900"><?= (int)$approverStats['active'] ?> <span class="text-sm font-medium text-indigo-600">/ <?= (int)$approverStats['total'] ?></span></p>
          <p class="text-xs text-indigo-600">Steps with coverage.</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-emerald-500">Scoped flows</p>
          <p class="mt-1 text-2xl font-semibold text-emerald-900"><?= (int)$approverStats['scoped'] ?></p>
          <p class="text-xs text-emerald-600">Branch/department routing.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-slate-500">Tools</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">3</p>
          <p class="text-xs text-slate-500">Add • reorder • retire.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="card p-6 space-y-6">
    <div class="space-y-3">
      <h2 class="text-xl font-semibold text-gray-900">Add Approver</h2>
      <p class="text-sm text-gray-600">Assign a user to an approval step. Use scopes to differentiate routing per branch or department. Leaving <em>Scope</em> blank treats the approver as global.</p>
    </div>
    <form method="post" class="grid gap-3 md:grid-cols-5 text-sm bg-gray-50 border border-gray-200 rounded-lg p-4"
          data-authz-module="payroll" data-authz-required="write" data-authz-action="Add payroll approver">
      <input type="hidden" name="csrf" value="<?= $csrf ?>" />
      <input type="hidden" name="action" value="add_approver" />
      <label class="md:col-span-2 block">
        <span class="text-xs uppercase tracking-wide text-gray-500">User</span>
        <select name="user_id" class="input-text w-full" required>
          <option value="">Select a user</option>
          <?php foreach ($activeUsers as $user): ?>
            <?php $label = trim(($user['full_name'] ?? '') . ' (' . strtolower($user['email'] ?? '') . ')'); ?>
            <option value="<?= (int)$user['id'] ?>">
              <?= htmlspecialchars($label) ?><?= isset($assignedGlobal[(int)$user['id']]) ? ' (already in global flow)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Step Order</span>
        <input type="number" name="step_order" min="1" class="input-text w-full" placeholder="1" required />
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Scope (optional)</span>
        <input type="text" name="applies_to" class="input-text w-full" placeholder="e.g., branch:cebu" />
        <p class="mt-1 text-[11px] text-gray-500">Use labels like <code>branch:code</code> or <code>department:name</code>.</p>
      </label>
      <div class="flex items-end justify-between gap-2">
        <label class="flex items-center gap-2 text-gray-700">
          <input type="checkbox" name="active" value="1" checked />
          <span>Active</span>
        </label>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </section>

  <section class="card p-6 space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-xl font-semibold text-gray-900">Current Workflow</h2>
        <p class="text-sm text-gray-600">Drag cards to reorder steps. Save the order to persist sequence numbers.</p>
      </div>
      <?php if ($approvers): ?>
        <form method="post" id="approver-order-form" class="flex items-center gap-2"
              data-authz-module="payroll" data-authz-required="write" data-authz-action="Save approval order">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="update_order_bulk" />
          <input type="hidden" name="order_payload" id="order_payload" value="" />
          <button type="submit" class="btn btn-outline">Save Order</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$approvers): ?>
      <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
        No approvers configured yet. Add at least one approver to enforce sequential approvals.
      </div>
    <?php else: ?>
      <div class="space-y-3" id="approver-list">
        <?php foreach ($approvers as $approver): ?>
          <div class="border border-gray-200 rounded-xl p-4 bg-white shadow-sm approver-item" data-approver-id="<?= (int)$approver['id'] ?>">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-gray-900">
                  Step <?= (int)$approver['step_order'] ?> · <?= htmlspecialchars($approver['full_name'] ?? 'Unassigned User') ?>
                </p>
                <p class="text-xs text-gray-500 flex flex-wrap gap-3">
                  <span><?= htmlspecialchars(strtolower((string)($approver['email'] ?? 'unknown email'))) ?></span>
                  <span>Scope: <?= htmlspecialchars(($approver['applies_to'] ?? '') !== '' ? $approver['applies_to'] : 'Global') ?></span>
                </p>
              </div>
              <button type="button" class="drag-handle inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-600" title="Drag to reorder">⇅<span class="sr-only">Drag</span></button>
              <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold <?= !empty($approver['active']) ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' ?>">
                <?= !empty($approver['active']) ? 'Active' : 'Inactive' ?>
              </span>
            </div>

            <form method="post" class="mt-4 grid gap-3 md:grid-cols-4 text-sm"
                  data-authz-module="payroll" data-authz-required="write" data-authz-action="Update payroll approver">
              <input type="hidden" name="csrf" value="<?= $csrf ?>" />
              <input type="hidden" name="action" value="update_approver" />
              <input type="hidden" name="approver_id" value="<?= (int)$approver['id'] ?>" />
              <label class="block">
                <span class="text-xs uppercase tracking-wide text-gray-500">Step Order</span>
                <input type="number" name="step_order" min="1" class="input-text w-full" value="<?= (int)$approver['step_order'] ?>" required />
              </label>
              <label class="block">
                <span class="text-xs uppercase tracking-wide text-gray-500">Scope (optional)</span>
                <input type="text" name="applies_to" class="input-text w-full" value="<?= htmlspecialchars($approver['applies_to'] ?? '') ?>" placeholder="global" />
              </label>
              <div class="flex items-end">
                <label class="flex items-center gap-2 text-gray-700">
                  <input type="checkbox" name="active" value="1" <?= !empty($approver['active']) ? 'checked' : '' ?> />
                  <span>Active</span>
                </label>
              </div>
              <div class="flex items-end justify-end">
                <button type="submit" class="btn btn-primary w-full">Save</button>
              </div>
            </form>

            <form method="post" class="mt-3 flex items-center justify-between text-xs text-gray-500"
                  data-authz-module="payroll" data-authz-required="admin" data-authz-action="Remove payroll approver" data-authz-force>
              <input type="hidden" name="csrf" value="<?= $csrf ?>" />
              <input type="hidden" name="action" value="delete_approver" />
              <input type="hidden" name="approver_id" value="<?= (int)$approver['id'] ?>" />
              <input type="hidden" name="override_force" value="1" />
              <button type="submit" class="text-red-600 hover:underline" onclick="return confirm('Remove this approver? Existing runs tied to this approver will lose the step.');">Remove approver</button>
              <span>Updated <?= htmlspecialchars($approver['updated_at'] ?? $approver['created_at']) ?></span>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(function(){
  const list = document.getElementById('approver-list');
  if (!list) return;
  list.querySelectorAll('.approver-item').forEach((item) => { item.draggable = true; });
  const placeholder = document.createElement('div');
  placeholder.className = 'border border-dashed border-gray-300 rounded p-3 my-1 bg-gray-50';
  let dragEl = null;
  list.addEventListener('dragstart', (e) => {
    const item = e.target.closest('.approver-item');
    if (!item) return;
    dragEl = item;
    item.classList.add('opacity-60');
    e.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragend', () => {
    if (dragEl) { dragEl.classList.remove('opacity-60'); }
    placeholder.remove();
    dragEl = null;
  });
  list.addEventListener('dragover', (e) => {
    e.preventDefault();
    const over = e.target.closest('.approver-item');
    if (!over || over === dragEl) return;
    const rect = over.getBoundingClientRect();
    const before = (e.clientY - rect.top) < rect.height / 2;
    list.insertBefore(placeholder, before ? over : over.nextSibling);
  });
  const orderForm = document.getElementById('approver-order-form');
  orderForm?.addEventListener('submit', () => {
    const items = Array.from(list.querySelectorAll('.approver-item'));
    const payload = items.map((el, idx) => ({ id: parseInt(el.dataset.approverId || '0', 10), step_order: idx + 1 }));
    const input = document.getElementById('order_payload');
    if (input) { input.value = JSON.stringify(payload); }
  });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
