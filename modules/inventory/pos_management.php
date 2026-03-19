<?php
/**
 * POS Management – Customize payment types, discount system, and POS settings
 * All operations are audit-logged via action_log()
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'write');

$pdo = get_db_conn();
$pageTitle = 'POS Management';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$tab = $_GET['tab'] ?? 'payments';

// ─── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Payment Types
    if ($action === 'save_payment_type') {
        $id = (int)($_POST['pt_id'] ?? 0);
        $code = strtolower(trim($_POST['pt_code'] ?? ''));
        $label = trim($_POST['pt_label'] ?? '');
        $icon = trim($_POST['pt_icon'] ?? '');
        $requiresRef = isset($_POST['pt_requires_reference']) ? 1 : 0;
        $sortOrder = (int)($_POST['pt_sort_order'] ?? 0);
        $isActive = isset($_POST['pt_is_active']) ? 1 : 0;

        if ($code === '' || $label === '') {
            flash_error('Code and label are required.');
        } else {
            if ($id > 0) {
                $pdo->prepare("UPDATE inv_payment_types SET code=:c, label=:l, icon=:i, requires_reference=:r, sort_order=:s, is_active=:a, updated_at=NOW() WHERE id=:id")
                    ->execute([':c'=>$code,':l'=>$label,':i'=>$icon,':r'=>$requiresRef,':s'=>$sortOrder,':a'=>$isActive,':id'=>$id]);
                action_log('inventory', 'update_payment_type', 'success', ['id'=>$id,'code'=>$code]);
                flash_success("Payment type '$label' updated.");
            } else {
                $pdo->prepare("INSERT INTO inv_payment_types (code,label,icon,requires_reference,sort_order,is_active) VALUES (:c,:l,:i,:r,:s,:a)")
                    ->execute([':c'=>$code,':l'=>$label,':i'=>$icon,':r'=>$requiresRef,':s'=>$sortOrder,':a'=>$isActive]);
                action_log('inventory', 'create_payment_type', 'success', ['code'=>$code]);
                flash_success("Payment type '$label' created.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/inventory/pos_management?tab=payments');
        exit;
    }

    if ($action === 'delete_payment_type') {
        $id = (int)($_POST['del_pt_id'] ?? 0);
        $pdo->prepare("UPDATE inv_payment_types SET is_active = FALSE, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]);
        action_log('inventory', 'deactivate_payment_type', 'success', ['id' => $id]);
        flash_success('Payment type deactivated.');
        header('Location: ' . BASE_URL . '/modules/inventory/pos_management?tab=payments');
        exit;
    }

    // Discount Types
    if ($action === 'save_discount_type') {
        $id = (int)($_POST['dt_id'] ?? 0);
        $code = strtolower(trim($_POST['dt_code'] ?? ''));
        $label = trim($_POST['dt_label'] ?? '');
        $mode = $_POST['dt_mode'] ?? 'percentage';
        $value = (float)($_POST['dt_value'] ?? 0);
        $appliesTo = $_POST['dt_applies_to'] ?? 'transaction';
        $minAmount = (float)($_POST['dt_min_amount'] ?? 0);
        $maxDiscount = ($_POST['dt_max_discount'] ?? '') === '' ? null : (float)$_POST['dt_max_discount'];
        $requiresApproval = isset($_POST['dt_requires_approval']) ? 1 : 0;
        $sortOrder = (int)($_POST['dt_sort_order'] ?? 0);
        $isActive = isset($_POST['dt_is_active']) ? 1 : 0;

        if ($code === '' || $label === '') {
            flash_error('Code and label are required.');
        } else {
            if ($id > 0) {
                $pdo->prepare("UPDATE inv_discount_types SET code=:c, label=:l, discount_mode=:m, value=:v, applies_to=:at, min_amount=:mn, max_discount=:mx, requires_approval=:ra, sort_order=:s, is_active=:a, updated_at=NOW() WHERE id=:id")
                    ->execute([':c'=>$code,':l'=>$label,':m'=>$mode,':v'=>$value,':at'=>$appliesTo,':mn'=>$minAmount,':mx'=>$maxDiscount,':ra'=>$requiresApproval,':s'=>$sortOrder,':a'=>$isActive,':id'=>$id]);
                action_log('inventory', 'update_discount_type', 'success', ['id'=>$id,'code'=>$code]);
                flash_success("Discount type '$label' updated.");
            } else {
                $pdo->prepare("INSERT INTO inv_discount_types (code,label,discount_mode,value,applies_to,min_amount,max_discount,requires_approval,sort_order,is_active) VALUES (:c,:l,:m,:v,:at,:mn,:mx,:ra,:s,:a)")
                    ->execute([':c'=>$code,':l'=>$label,':m'=>$mode,':v'=>$value,':at'=>$appliesTo,':mn'=>$minAmount,':mx'=>$maxDiscount,':ra'=>$requiresApproval,':s'=>$sortOrder,':a'=>$isActive]);
                action_log('inventory', 'create_discount_type', 'success', ['code'=>$code]);
                flash_success("Discount type '$label' created.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/inventory/pos_management?tab=discounts');
        exit;
    }

    if ($action === 'delete_discount_type') {
        $id = (int)($_POST['del_dt_id'] ?? 0);
        $pdo->prepare("UPDATE inv_discount_types SET is_active = FALSE, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]);
        action_log('inventory', 'deactivate_discount_type', 'success', ['id' => $id]);
        flash_success('Discount type deactivated.');
        header('Location: ' . BASE_URL . '/modules/inventory/pos_management?tab=discounts');
        exit;
    }

    // POS Config
    if ($action === 'save_pos_config') {
        $keys = $_POST['config_key'] ?? [];
        $vals = $_POST['config_value'] ?? [];
        $changed = [];
        foreach ($keys as $i => $key) {
            $val = trim($vals[$i] ?? '');
            $pdo->prepare("UPDATE inv_pos_config SET config_value = :v, updated_at = NOW() WHERE config_key = :k")
                ->execute([':v' => $val, ':k' => $key]);
            $changed[$key] = $val;
        }
        action_log('inventory', 'update_pos_config', 'success', $changed);
        flash_success('POS settings saved.');
        header('Location: ' . BASE_URL . '/modules/inventory/pos_management?tab=settings');
        exit;
    }
}

// ─── Fetch data (defensive: tables may not exist until migration runs) ────────
$paymentTypes = [];
$discountTypes = [];
$posConfigs = [];
$activities = [];
$tablesExist = true;

try {
    $paymentTypes = $pdo->query("SELECT * FROM inv_payment_types ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
    $discountTypes = $pdo->query("SELECT * FROM inv_discount_types ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
    $posConfigs = $pdo->query("SELECT * FROM inv_pos_config ORDER BY config_key")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tablesExist = false;
    sys_log('DB_POS', 'POS management tables not found — run migration 2026-02-09_inventory_pos_management.sql', ['error' => $e->getMessage()]);
}

// Recent activity for this module
try {
    $recentActivity = $pdo->prepare("SELECT al.*, u.full_name AS user_name FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
        WHERE al.details LIKE '%\"module\":\"inventory\"%' AND al.action IN ('create_payment_type','update_payment_type','deactivate_payment_type','create_discount_type','update_discount_type','deactivate_discount_type','update_pos_config')
        ORDER BY al.created_at DESC LIMIT 20");
    $recentActivity->execute();
    $activities = $recentActivity->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // audit_logs query failed — non-critical, keep empty array
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">POS Management</h1>
      <p class="text-sm text-gray-500">Customize payment types, discounts, and POS settings</p>
    </div>
  </div>

  <?php if (!$tablesExist): ?>
  <div class="card card-body border-amber-200 bg-amber-50">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
      <div>
        <h3 class="text-sm font-semibold text-amber-800">Setup Required</h3>
        <p class="text-sm text-amber-700 mt-1">POS management tables haven't been created yet. Please run the database migration <code class="text-xs bg-amber-100 px-1 py-0.5 rounded">2026-02-09_inventory_pos_management.sql</code> via <strong>Tools → Migrate</strong> to enable this feature.</p>
      </div>
    </div>
  </div>
  <?php else: ?>

  <!-- Tabs -->
  <div class="flex border-b">
    <a href="?tab=payments" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab === 'payments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      Payment Types
    </a>
    <a href="?tab=discounts" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab === 'discounts' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      Discounts
    </a>
    <a href="?tab=settings" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab === 'settings' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      General Settings
    </a>
    <a href="?tab=activity" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab === 'activity' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      Activity Log
    </a>
  </div>

  <!-- ======================================================================= -->
  <!-- PAYMENT TYPES TAB -->
  <!-- ======================================================================= -->
  <?php if ($tab === 'payments'): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($paymentTypes as $pt): ?>
    <div class="bg-white rounded-xl border p-4 <?= $pt['is_active'] ? '' : 'opacity-50' ?>">
      <div class="flex items-start justify-between">
        <div>
          <div class="flex items-center gap-2">
            <?php if ($pt['icon']): ?>
              <span class="text-lg"><?= htmlspecialchars($pt['icon']) ?></span>
            <?php endif; ?>
            <h3 class="font-medium text-gray-900"><?= htmlspecialchars($pt['label']) ?></h3>
          </div>
          <p class="text-xs text-gray-400 font-mono mt-1"><?= htmlspecialchars($pt['code']) ?></p>
        </div>
        <div class="flex items-center gap-1.5">
          <?php if ($pt['is_active']): ?>
            <span class="px-2 py-0.5 rounded-full text-xs bg-emerald-50 text-emerald-700">Active</span>
          <?php else: ?>
            <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Inactive</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
        <?php if ($pt['requires_reference']): ?>
          <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-600">Requires Reference</span>
        <?php endif; ?>
        <span>Sort: <?= $pt['sort_order'] ?></span>
      </div>
      <div class="mt-3 flex gap-2">
        <button type="button" onclick='openPaymentModal(<?= json_encode($pt) ?>)' class="btn btn-outline text-xs flex-1">Edit</button>
        <form method="POST" class="inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="delete_payment_type" />
          <input type="hidden" name="del_pt_id" value="<?= $pt['id'] ?>" />
          <button type="submit" class="btn btn-outline text-xs text-red-500 hover:text-red-700" onclick="return confirm('Deactivate this payment type?')">Deactivate</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Add new card -->
    <button type="button" onclick='openPaymentModal(null)' class="bg-white rounded-xl border-2 border-dashed border-gray-300 p-4 flex flex-col items-center justify-center min-h-[140px] hover:border-blue-400 hover:bg-blue-50 transition group">
      <svg class="w-8 h-8 text-gray-300 group-hover:text-blue-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      <span class="text-sm text-gray-400 group-hover:text-blue-600 mt-2">Add Payment Type</span>
    </button>
  </div>

  <!-- Payment Type Modal -->
  <div id="paymentModal" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
      <form method="POST" class="p-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
        <input type="hidden" name="action" value="save_payment_type" />
        <input type="hidden" name="pt_id" id="pt_id" value="0" />
        <h2 class="text-lg font-semibold text-gray-900" id="paymentModalTitle">Add Payment Type</h2>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Code *</label>
            <input type="text" name="pt_code" id="pt_code" class="input-text text-sm" required />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Label *</label>
            <input type="text" name="pt_label" id="pt_label" class="input-text text-sm" required />
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Icon (emoji)</label>
            <input type="text" name="pt_icon" id="pt_icon" class="input-text text-sm" placeholder="💵" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Sort Order</label>
            <input type="number" name="pt_sort_order" id="pt_sort_order" class="input-text text-sm" value="0" />
          </div>
        </div>
        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="pt_requires_reference" id="pt_requires_reference" class="rounded" />
            Requires Reference #
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="pt_is_active" id="pt_is_active" class="rounded" checked />
            Active
          </label>
        </div>
        <div class="flex gap-2 pt-2">
          <button type="submit" class="btn btn-primary text-sm flex-1">Save</button>
          <button type="button" onclick="closePaymentModal()" class="btn btn-outline text-sm flex-1">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ======================================================================= -->
  <!-- DISCOUNTS TAB -->
  <!-- ======================================================================= -->
  <?php if ($tab === 'discounts'): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($discountTypes as $dt): ?>
    <div class="bg-white rounded-xl border p-4 <?= $dt['is_active'] ? '' : 'opacity-50' ?>">
      <div class="flex items-start justify-between">
        <div>
          <h3 class="font-medium text-gray-900"><?= htmlspecialchars($dt['label']) ?></h3>
          <p class="text-xs text-gray-400 font-mono mt-1"><?= htmlspecialchars($dt['code']) ?></p>
        </div>
        <span class="px-2 py-0.5 rounded-full text-xs font-bold <?= $dt['discount_mode'] === 'percentage' ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700' ?>">
          <?= $dt['discount_mode'] === 'percentage' ? $dt['value'] . '%' : 'P' . number_format((float)$dt['value'], 2) ?>
        </span>
      </div>
      <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
        <span class="px-2 py-0.5 rounded bg-gray-50 text-gray-600"><?= ucfirst($dt['applies_to']) ?>-level</span>
        <?php if ($dt['min_amount'] > 0): ?>
          <span class="px-2 py-0.5 rounded bg-gray-50 text-gray-600">Min: P<?= number_format((float)$dt['min_amount'], 2) ?></span>
        <?php endif; ?>
        <?php if ($dt['max_discount']): ?>
          <span class="px-2 py-0.5 rounded bg-gray-50 text-gray-600">Max: P<?= number_format((float)$dt['max_discount'], 2) ?></span>
        <?php endif; ?>
        <?php if ($dt['requires_approval']): ?>
          <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700">Requires Approval</span>
        <?php endif; ?>
        <?php if (!$dt['is_active']): ?>
          <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-500">Inactive</span>
        <?php endif; ?>
      </div>
      <div class="mt-3 flex gap-2">
        <button type="button" onclick='openDiscountModal(<?= json_encode($dt) ?>)' class="btn btn-outline text-xs flex-1">Edit</button>
        <form method="POST" class="inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="delete_discount_type" />
          <input type="hidden" name="del_dt_id" value="<?= $dt['id'] ?>" />
          <button type="submit" class="btn btn-outline text-xs text-red-500 hover:text-red-700" onclick="return confirm('Deactivate this discount type?')">Deactivate</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Add new -->
    <button type="button" onclick='openDiscountModal(null)' class="bg-white rounded-xl border-2 border-dashed border-gray-300 p-4 flex flex-col items-center justify-center min-h-[140px] hover:border-blue-400 hover:bg-blue-50 transition group">
      <svg class="w-8 h-8 text-gray-300 group-hover:text-blue-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      <span class="text-sm text-gray-400 group-hover:text-blue-600 mt-2">Add Discount Type</span>
    </button>
  </div>

  <!-- Discount Modal -->
  <div id="discountModal" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
      <form method="POST" class="p-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
        <input type="hidden" name="action" value="save_discount_type" />
        <input type="hidden" name="dt_id" id="dt_id" value="0" />
        <h2 class="text-lg font-semibold text-gray-900" id="discountModalTitle">Add Discount Type</h2>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Code *</label>
            <input type="text" name="dt_code" id="dt_code" class="input-text text-sm" required />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Label *</label>
            <input type="text" name="dt_label" id="dt_label" class="input-text text-sm" required />
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Discount Mode</label>
            <select name="dt_mode" id="dt_mode" class="input-text text-sm">
              <option value="percentage">Percentage (%)</option>
              <option value="fixed">Fixed Amount (P)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Value</label>
            <input type="number" name="dt_value" id="dt_value" class="input-text text-sm" step="0.01" min="0" value="0" />
          </div>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Applies To</label>
            <select name="dt_applies_to" id="dt_applies_to" class="input-text text-sm">
              <option value="transaction">Transaction</option>
              <option value="item">Item</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Min Amount</label>
            <input type="number" name="dt_min_amount" id="dt_min_amount" class="input-text text-sm" step="0.01" min="0" value="0" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Max Discount</label>
            <input type="number" name="dt_max_discount" id="dt_max_discount" class="input-text text-sm" step="0.01" min="0" placeholder="No limit" />
          </div>
        </div>
        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="dt_requires_approval" id="dt_requires_approval" class="rounded" />
            Requires Approval
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="dt_is_active" id="dt_is_active" class="rounded" checked />
            Active
          </label>
          <div>
            <label class="block text-xs font-medium text-gray-700">Sort</label>
            <input type="number" name="dt_sort_order" id="dt_sort_order" class="input-text text-sm w-16" value="0" />
          </div>
        </div>
        <div class="flex gap-2 pt-2">
          <button type="submit" class="btn btn-primary text-sm flex-1">Save</button>
          <button type="button" onclick="closeDiscountModal()" class="btn btn-outline text-sm flex-1">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ======================================================================= -->
  <!-- GENERAL SETTINGS TAB -->
  <!-- ======================================================================= -->
  <?php if ($tab === 'settings'): ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <form method="POST" class="divide-y">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
      <input type="hidden" name="action" value="save_pos_config" />
      <div class="px-6 py-4 bg-gray-50">
        <h2 class="text-sm font-semibold text-gray-700">POS Configuration</h2>
        <p class="text-xs text-gray-500">Global settings that affect the POS terminal behavior</p>
      </div>
      <?php foreach ($posConfigs as $cfg): ?>
      <div class="px-6 py-4 flex flex-col md:flex-row md:items-center gap-3">
        <div class="flex-1">
          <label class="text-sm font-medium text-gray-900"><?= ucwords(str_replace('_', ' ', $cfg['config_key'])) ?></label>
          <?php if ($cfg['description']): ?>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($cfg['description']) ?></p>
          <?php endif; ?>
        </div>
        <div class="w-full md:w-48">
          <input type="hidden" name="config_key[]" value="<?= htmlspecialchars($cfg['config_key']) ?>" />
          <?php
            $val = $cfg['config_value'];
            if (in_array($val, ['true','false'])):
          ?>
            <select name="config_value[]" class="input-text text-sm">
              <option value="true" <?= $val === 'true' ? 'selected' : '' ?>>Enabled</option>
              <option value="false" <?= $val === 'false' ? 'selected' : '' ?>>Disabled</option>
            </select>
          <?php else: ?>
            <input type="text" name="config_value[]" value="<?= htmlspecialchars($val) ?>" class="input-text text-sm" />
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="px-6 py-4 bg-gray-50 flex justify-end">
        <button type="submit" class="btn btn-primary text-sm">Save Settings</button>
      </div>
    </form>
  </div>

  <!-- Receipt Settings Quick Link -->
  <div class="bg-white rounded-xl border p-4 flex items-center justify-between">
    <div>
      <h3 class="text-sm font-medium text-gray-900">Receipt Settings</h3>
      <p class="text-xs text-gray-500">Customize receipt header, footer, and layout</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/inventory/receipt_settings" class="btn btn-outline text-sm">
      Configure Receipt
      <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>
  </div>
  <?php endif; ?>

  <!-- ======================================================================= -->
  <!-- ACTIVITY LOG TAB -->
  <!-- ======================================================================= -->
  <?php if ($tab === 'activity'): ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b">
      <h2 class="text-sm font-semibold text-gray-700">POS Management Activity</h2>
      <p class="text-xs text-gray-500">Recent changes to payment types, discounts, and settings</p>
    </div>
    <?php if (empty($activities)): ?>
      <div class="px-4 py-8 text-center text-gray-400 text-sm">No activity recorded yet.</div>
    <?php else: ?>
      <div class="divide-y">
        <?php foreach ($activities as $act): ?>
        <div class="px-4 py-3 flex items-start gap-3 hover:bg-gray-50">
          <div class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0 mt-0.5">
            <?php
              $actionIcon = match(true) {
                str_contains($act['action'], 'create') => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>',
                str_contains($act['action'], 'update') || str_contains($act['action'], 'config') => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
                str_contains($act['action'], 'deactivate') => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>',
                default => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
              };
              echo $actionIcon;
            ?>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-900">
              <span class="font-medium"><?= htmlspecialchars($act['user_name'] ?? 'System') ?></span>
              <?= htmlspecialchars(str_replace('_', ' ', $act['action'])) ?>
            </p>
            <?php if ($act['details']): ?>
              <?php $parsed = json_decode($act['details'], true); $meta = $parsed['meta'] ?? []; ?>
              <?php if ($meta): ?>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(implode(', ', array_map(fn($k,$v) => "$k: $v", array_keys($meta), $meta))) ?></p>
              <?php endif; ?>
            <?php endif; ?>
            <p class="text-xs text-gray-400 mt-1"><?= date('M d, Y H:i:s', strtotime($act['created_at'])) ?></p>
          </div>
          <span class="px-2 py-0.5 rounded-full text-xs <?= $act['status'] === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>"><?= $act['status'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<script>
// Payment modal
function openPaymentModal(data) {
  const m = document.getElementById('paymentModal');
  document.getElementById('paymentModalTitle').textContent = data ? 'Edit Payment Type' : 'Add Payment Type';
  document.getElementById('pt_id').value = data ? data.id : 0;
  document.getElementById('pt_code').value = data ? data.code : '';
  document.getElementById('pt_label').value = data ? data.label : '';
  document.getElementById('pt_icon').value = data ? (data.icon || '') : '';
  document.getElementById('pt_sort_order').value = data ? data.sort_order : 0;
  document.getElementById('pt_requires_reference').checked = data ? !!parseInt(data.requires_reference) : false;
  document.getElementById('pt_is_active').checked = data ? !!parseInt(data.is_active) : true;
  m.classList.remove('hidden');
}
function closePaymentModal() { document.getElementById('paymentModal').classList.add('hidden'); }

// Discount modal
function openDiscountModal(data) {
  const m = document.getElementById('discountModal');
  document.getElementById('discountModalTitle').textContent = data ? 'Edit Discount Type' : 'Add Discount Type';
  document.getElementById('dt_id').value = data ? data.id : 0;
  document.getElementById('dt_code').value = data ? data.code : '';
  document.getElementById('dt_label').value = data ? data.label : '';
  document.getElementById('dt_mode').value = data ? data.discount_mode : 'percentage';
  document.getElementById('dt_value').value = data ? data.value : 0;
  document.getElementById('dt_applies_to').value = data ? data.applies_to : 'transaction';
  document.getElementById('dt_min_amount').value = data ? data.min_amount : 0;
  document.getElementById('dt_max_discount').value = data ? (data.max_discount || '') : '';
  document.getElementById('dt_sort_order').value = data ? data.sort_order : 0;
  document.getElementById('dt_requires_approval').checked = data ? !!parseInt(data.requires_approval) : false;
  document.getElementById('dt_is_active').checked = data ? !!parseInt(data.is_active) : true;
  m.classList.remove('hidden');
}
function closeDiscountModal() { document.getElementById('discountModal').classList.add('hidden'); }

// Close modals on backdrop click
document.querySelectorAll('#paymentModal, #discountModal').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.add('hidden'); });
});
</script>

<?php endif; // tablesExist ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
