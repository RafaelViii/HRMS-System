<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('payroll', 'compensation_defaults', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/payroll.php';

$pageTitle = 'Benefits & Deductions Configuration';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
action_log('payroll', 'view_compensation_templates');

$redirectBase = BASE_URL . '/modules/admin/compensation';
$activeTab = trim((string)($_GET['tab'] ?? 'allowances'));
if (!in_array($activeTab, ['allowances', 'contributions', 'taxes', 'deductions', 'shift_benefits'])) {
    $activeTab = 'allowances';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectUrl = $redirectBase . '?tab=' . $activeTab;

    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token. Please try again.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case 'save_template':
                $authz = ensure_action_authorized('payroll', 'compensation_defaults', 'admin');
                if (!$authz['ok']) {
                    $msg = $authz['error'] === 'no_access'
                        ? 'You do not have permission to save compensation templates.'
                        : 'Authorization failed. Provide a valid admin credential.';
                    flash_error($msg);
                    break;
                }

                $templateData = [
                    'id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
                    'category' => trim((string)($_POST['category'] ?? '')),
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'code' => trim((string)($_POST['code'] ?? '')),
                    'amount_type' => trim((string)($_POST['amount_type'] ?? 'static')),
                    'static_amount' => !empty($_POST['static_amount']) ? (float)$_POST['static_amount'] : 0,
                    'percentage' => !empty($_POST['percentage']) ? (float)$_POST['percentage'] : 0,
                    'is_modifiable' => !empty($_POST['is_modifiable']),
                    'effectivity_until' => !empty($_POST['effectivity_until']) ? $_POST['effectivity_until'] : null,
                    'notes' => trim((string)($_POST['notes'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? !empty($_POST['is_active']) : true,
                ];

                $actorId = (int)($authz['as_user'] ?? $currentUserId ?: null) ?: $currentUserId;
                $result = payroll_save_compensation_template($pdo, $templateData, $actorId);
                
                if ($result['ok']) {
                    flash_success($templateData['id'] ? 'Template updated successfully.' : 'Template created successfully.');
                    $redirectUrl = $redirectBase . '?tab=' . $templateData['category'];
                } else {
                    flash_error($result['error'] ?? 'Failed to save template.');
                }
                break;

            case 'delete_template':
                $authz = ensure_action_authorized('payroll', 'compensation_defaults', 'admin');
                if (!$authz['ok']) {
                    flash_error('You do not have permission to delete compensation templates.');
                    break;
                }

                $templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
                if ($templateId > 0) {
                    $deleted = payroll_delete_compensation_template($pdo, $templateId);
                    if ($deleted) {
                        flash_success('Template deleted successfully.');
                    } else {
                        flash_error('Failed to delete template.');
                    }
                }
                break;

            case 'save_shift_benefit':
                $authz = ensure_action_authorized('payroll', 'compensation_defaults', 'admin');
                if (!$authz['ok']) {
                    $msg = $authz['error'] === 'no_access'
                        ? 'You do not have permission to save shift benefits.'
                        : 'Authorization failed. Provide a valid admin credential.';
                    flash_error($msg);
                    break;
                }

                $benefitData = [
                    'id' => !empty($_POST['benefit_id']) ? (int)$_POST['benefit_id'] : null,
                    'shift_name' => trim((string)($_POST['shift_name'] ?? '')),
                    'shift_code' => trim((string)($_POST['shift_code'] ?? '')),
                    'benefit_type' => trim((string)($_POST['benefit_type'] ?? 'night_differential')),
                    'amount_type' => trim((string)($_POST['amount_type'] ?? 'percentage')),
                    'static_amount' => !empty($_POST['static_amount']) ? (float)$_POST['static_amount'] : 0,
                    'percentage' => !empty($_POST['percentage']) ? (float)$_POST['percentage'] : 0,
                    'effectivity_until' => !empty($_POST['effectivity_until']) ? $_POST['effectivity_until'] : null,
                    'notes' => trim((string)($_POST['notes'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? !empty($_POST['is_active']) : true,
                ];

                $actorId = (int)($authz['as_user'] ?? $currentUserId ?: null) ?: $currentUserId;
                $result = payroll_save_shift_benefit($pdo, $benefitData, $actorId);
                
                if ($result['ok']) {
                    flash_success($benefitData['id'] ? 'Shift benefit updated successfully.' : 'Shift benefit created successfully.');
                    $redirectUrl = $redirectBase . '?tab=shift_benefits';
                } else {
                    flash_error($result['error'] ?? 'Failed to save shift benefit.');
                }
                break;

            case 'delete_shift_benefit':
                $authz = ensure_action_authorized('payroll', 'compensation_defaults', 'admin');
                if (!$authz['ok']) {
                    flash_error('You do not have permission to delete shift benefits.');
                    break;
                }

                $benefitId = !empty($_POST['benefit_id']) ? (int)$_POST['benefit_id'] : 0;
                if ($benefitId > 0) {
                    $deleted = payroll_delete_shift_benefit($pdo, $benefitId);
                    if ($deleted) {
                        flash_success('Shift benefit deleted successfully.');
                    } else {
                        flash_error('Failed to delete shift benefit.');
                    }
                }
                break;

            default:
                flash_error('Unsupported action.');
                break;
        }
    } catch (Throwable $e) {
        sys_log('ADMIN-COMPENSATION-POST', 'Failed processing compensation template action: ' . $e->getMessage(), [
            'module' => 'admin',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['action' => $action],
        ]);
        flash_error('We could not process the requested change.');
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// Load templates and shift benefits
$allowanceTemplates = payroll_get_compensation_templates($pdo, 'allowance', true, false);
$contributionTemplates = payroll_get_compensation_templates($pdo, 'contribution', true, false);
$taxTemplates = payroll_get_compensation_templates($pdo, 'tax', true, false);
$deductionTemplates = payroll_get_compensation_templates($pdo, 'deduction', true, false);
$shiftBenefits = payroll_get_shift_benefits($pdo, true, false);

// Helper function to format amounts
$formatAmount = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
};

// Helper function to format benefit type labels
$formatBenefitType = static function(string $type): string {
    return ucwords(str_replace('_', ' ', $type));
};

$csrf = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <section class="card p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="space-y-2">
        <div class="flex items-center gap-3 text-sm">
          <a href="<?= BASE_URL ?>/modules/admin/index" class="inline-flex items-center gap-2 font-semibold text-indigo-600 transition hover:text-indigo-700" data-no-loader>
            <span class="text-base">←</span>
            <span>HR Admin</span>
          </a>
          <span class="text-slate-400">/</span>
          <span class="uppercase tracking-[0.2em] text-slate-500">Compensation</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Benefits & Deductions Configuration</h1>
        <p class="text-sm text-slate-600">Manage compensation templates for allowances, contributions, taxes, deductions, and shift-based benefits.</p>
      </div>
      <div class="grid gap-3 text-sm sm:grid-cols-4">
        <div class="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-blue-500">Allowances</p>
          <p class="mt-1 text-2xl font-semibold text-blue-900"><?= count($allowanceTemplates) ?></p>
        </div>
        <div class="rounded-2xl border border-green-100 bg-green-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-green-500">Contributions</p>
          <p class="mt-1 text-2xl font-semibold text-green-900"><?= count($contributionTemplates) ?></p>
        </div>
        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-amber-500">Taxes</p>
          <p class="mt-1 text-2xl font-semibold text-amber-900"><?= count($taxTemplates) ?></p>
        </div>
        <div class="rounded-2xl border border-purple-100 bg-purple-50 px-4 py-3">
          <p class="text-[11px] uppercase tracking-wide text-purple-500">Shift Benefits</p>
          <p class="mt-1 text-2xl font-semibold text-purple-900"><?= count($shiftBenefits) ?></p>
        </div>
      </div>
    </div>
  </section>

  <!-- Category Tabs -->
  <div class="card overflow-hidden">
    <div class="border-b border-slate-200 bg-slate-50">
      <nav class="flex -mb-px overflow-x-auto">
        <a href="?tab=allowances" class="px-6 py-4 text-sm font-medium border-b-2 whitespace-nowrap <?= $activeTab === 'allowances' ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300' ?>">
          Allowances
        </a>
        <a href="?tab=contributions" class="px-6 py-4 text-sm font-medium border-b-2 whitespace-nowrap <?= $activeTab === 'contributions' ? 'border-green-500 text-green-600' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300' ?>">
          Contributions
        </a>
        <a href="?tab=taxes" class="px-6 py-4 text-sm font-medium border-b-2 whitespace-nowrap <?= $activeTab === 'taxes' ? 'border-amber-500 text-amber-600' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300' ?>">
          Taxes
        </a>
        <a href="?tab=deductions" class="px-6 py-4 text-sm font-medium border-b-2 whitespace-nowrap <?= $activeTab === 'deductions' ? 'border-red-500 text-red-600' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300' ?>">
          Deductions
        </a>
        <a href="?tab=shift_benefits" class="px-6 py-4 text-sm font-medium border-b-2 whitespace-nowrap <?= $activeTab === 'shift_benefits' ? 'border-purple-500 text-purple-600' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300' ?>">
          Shift Benefits
        </a>
      </nav>
    </div>

    <div class="p-6">
      <?php if ($activeTab === 'allowances'): ?>
        <!-- Allowances Tab -->
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Allowance Templates</h2>
            <button type="button" onclick="openTemplateModal('allowance')" class="btn btn-primary">
              <span>+ Add Allowance</span>
            </button>
          </div>
          
          <?php if (empty($allowanceTemplates)): ?>
            <div class="text-center py-12 text-slate-500">
              <p>No allowance templates configured yet.</p>
              <p class="text-sm mt-2">Click "Add Allowance" to create your first template.</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Code</th>
                    <th class="px-4 py-3 text-left font-medium">Amount</th>
                    <th class="px-4 py-3 text-left font-medium">Bi-Monthly</th>
                    <th class="px-4 py-3 text-center font-medium">Modifiable</th>
                    <th class="px-4 py-3 text-left font-medium">Effectivity</th>
                    <th class="px-4 py-3 text-center font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($allowanceTemplates as $template): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($template['name']) ?></td>
                      <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($template['code'] ?: '—') ?></td>
                      <td class="px-4 py-3 text-slate-900">
                        <?php if ($template['amount_type'] === 'percentage'): ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php else: ?>
                          ₱<?= $formatAmount($template['static_amount']) ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?php if ($template['amount_type'] === 'static'): ?>
                          ₱<?= $formatAmount(payroll_calculate_bi_monthly_amount($template['static_amount'])) ?>
                        <?php else: ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <?php if ($template['is_modifiable']): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Yes</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">No</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?= $template['effectivity_until'] ? htmlspecialchars(format_datetime_display($template['effectivity_until'])) : 'Ongoing' ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <button type="button" onclick='editTemplate(<?= json_encode($template, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        <button type="button" onclick="deleteTemplate(<?= $template['id'] ?>, '<?= htmlspecialchars($template['name']) ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php elseif ($activeTab === 'contributions'): ?>
        <!-- Contributions Tab -->
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Contribution Templates</h2>
            <button type="button" onclick="openTemplateModal('contribution')" class="btn btn-primary">
              <span>+ Add Contribution</span>
            </button>
          </div>
          
          <?php if (empty($contributionTemplates)): ?>
            <div class="text-center py-12 text-slate-500">
              <p>No contribution templates configured yet.</p>
              <p class="text-sm mt-2">Click "Add Contribution" to create your first template.</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Code</th>
                    <th class="px-4 py-3 text-left font-medium">Amount</th>
                    <th class="px-4 py-3 text-left font-medium">Bi-Monthly</th>
                    <th class="px-4 py-3 text-center font-medium">Modifiable</th>
                    <th class="px-4 py-3 text-left font-medium">Effectivity</th>
                    <th class="px-4 py-3 text-center font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($contributionTemplates as $template): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($template['name']) ?></td>
                      <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($template['code'] ?: '—') ?></td>
                      <td class="px-4 py-3 text-slate-900">
                        <?php if ($template['amount_type'] === 'percentage'): ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php else: ?>
                          ₱<?= $formatAmount($template['static_amount']) ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?php if ($template['amount_type'] === 'static'): ?>
                          ₱<?= $formatAmount(payroll_calculate_bi_monthly_amount($template['static_amount'])) ?>
                        <?php else: ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <?php if ($template['is_modifiable']): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Yes</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">No</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?= $template['effectivity_until'] ? htmlspecialchars(format_datetime_display($template['effectivity_until'])) : 'Ongoing' ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <button type="button" onclick='editTemplate(<?= json_encode($template, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        <button type="button" onclick="deleteTemplate(<?= $template['id'] ?>, '<?= htmlspecialchars($template['name']) ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php elseif ($activeTab === 'taxes'): ?>
        <!-- Taxes Tab -->
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Tax Templates</h2>
            <button type="button" onclick="openTemplateModal('tax')" class="btn btn-primary">
              <span>+ Add Tax</span>
            </button>
          </div>
          
          <?php if (empty($taxTemplates)): ?>
            <div class="text-center py-12 text-slate-500">
              <p>No tax templates configured yet.</p>
              <p class="text-sm mt-2">Click "Add Tax" to create your first template.</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Code</th>
                    <th class="px-4 py-3 text-left font-medium">Amount</th>
                    <th class="px-4 py-3 text-left font-medium">Bi-Monthly</th>
                    <th class="px-4 py-3 text-center font-medium">Modifiable</th>
                    <th class="px-4 py-3 text-left font-medium">Effectivity</th>
                    <th class="px-4 py-3 text-center font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($taxTemplates as $template): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($template['name']) ?></td>
                      <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($template['code'] ?: '—') ?></td>
                      <td class="px-4 py-3 text-slate-900">
                        <?php if ($template['amount_type'] === 'percentage'): ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php else: ?>
                          ₱<?= $formatAmount($template['static_amount']) ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?php if ($template['amount_type'] === 'static'): ?>
                          ₱<?= $formatAmount(payroll_calculate_bi_monthly_amount($template['static_amount'])) ?>
                        <?php else: ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <?php if ($template['is_modifiable']): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Yes</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">No</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?= $template['effectivity_until'] ? htmlspecialchars(format_datetime_display($template['effectivity_until'])) : 'Ongoing' ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <button type="button" onclick='editTemplate(<?= json_encode($template, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        <button type="button" onclick="deleteTemplate(<?= $template['id'] ?>, '<?= htmlspecialchars($template['name']) ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php elseif ($activeTab === 'deductions'): ?>
        <!-- Deductions Tab -->
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Deduction Templates</h2>
            <button type="button" onclick="openTemplateModal('deduction')" class="btn btn-primary">
              <span>+ Add Deduction</span>
            </button>
          </div>
          
          <?php if (empty($deductionTemplates)): ?>
            <div class="text-center py-12 text-slate-500">
              <p>No deduction templates configured yet.</p>
              <p class="text-sm mt-2">Click "Add Deduction" to create your first template.</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Code</th>
                    <th class="px-4 py-3 text-left font-medium">Amount</th>
                    <th class="px-4 py-3 text-left font-medium">Bi-Monthly</th>
                    <th class="px-4 py-3 text-center font-medium">Modifiable</th>
                    <th class="px-4 py-3 text-left font-medium">Effectivity</th>
                    <th class="px-4 py-3 text-center font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($deductionTemplates as $template): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($template['name']) ?></td>
                      <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($template['code'] ?: '—') ?></td>
                      <td class="px-4 py-3 text-slate-900">
                        <?php if ($template['amount_type'] === 'percentage'): ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php else: ?>
                          ₱<?= $formatAmount($template['static_amount']) ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?php if ($template['amount_type'] === 'static'): ?>
                          ₱<?= $formatAmount(payroll_calculate_bi_monthly_amount($template['static_amount'])) ?>
                        <?php else: ?>
                          <?= $formatAmount($template['percentage']) ?>%
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <?php if ($template['is_modifiable']): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Yes</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">No</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?= $template['effectivity_until'] ? htmlspecialchars(format_datetime_display($template['effectivity_until'])) : 'Ongoing' ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <button type="button" onclick='editTemplate(<?= json_encode($template, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        <button type="button" onclick="deleteTemplate(<?= $template['id'] ?>, '<?= htmlspecialchars($template['name']) ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php elseif ($activeTab === 'shift_benefits'): ?>
        <!-- Shift Benefits Tab -->
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Shift-Based Benefits</h2>
            <button type="button" onclick="openShiftBenefitModal()" class="btn btn-primary">
              <span>+ Add Shift Benefit</span>
            </button>
          </div>
          
          <?php if (empty($shiftBenefits)): ?>
            <div class="text-center py-12 text-slate-500">
              <p>No shift benefits configured yet.</p>
              <p class="text-sm mt-2">Click "Add Shift Benefit" to create your first benefit.</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">Shift Name</th>
                    <th class="px-4 py-3 text-left font-medium">Code</th>
                    <th class="px-4 py-3 text-left font-medium">Benefit Type</th>
                    <th class="px-4 py-3 text-left font-medium">Amount</th>
                    <th class="px-4 py-3 text-left font-medium">Bi-Monthly</th>
                    <th class="px-4 py-3 text-left font-medium">Effectivity</th>
                    <th class="px-4 py-3 text-center font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($shiftBenefits as $benefit): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($benefit['shift_name']) ?></td>
                      <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($benefit['shift_code'] ?: '—') ?></td>
                      <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars($formatBenefitType($benefit['benefit_type'])) ?></td>
                      <td class="px-4 py-3 text-slate-900">
                        <?php if ($benefit['amount_type'] === 'percentage'): ?>
                          <?= $formatAmount($benefit['percentage']) ?>%
                        <?php else: ?>
                          ₱<?= $formatAmount($benefit['static_amount']) ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?php if ($benefit['amount_type'] === 'static'): ?>
                          ₱<?= $formatAmount(payroll_calculate_bi_monthly_amount($benefit['static_amount'])) ?>
                        <?php else: ?>
                          <?= $formatAmount($benefit['percentage']) ?>%
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-600">
                        <?= $benefit['effectivity_until'] ? htmlspecialchars(format_datetime_display($benefit['effectivity_until'])) : 'Ongoing' ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <button type="button" onclick='editShiftBenefit(<?= json_encode($benefit, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        <button type="button" onclick="deleteShiftBenefit(<?= $benefit['id'] ?>, '<?= htmlspecialchars($benefit['shift_name']) ?>')" class="text-red-600 hover:text-red-900">Delete</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Template Modal -->
<div id="templateModal" class="modal hidden">
  <div class="modal-content max-w-2xl">
    <div class="modal-header">
      <h3 id="templateModalTitle" class="text-xl font-semibold">Add Template</h3>
      <button type="button" onclick="closeTemplateModal()" class="text-slate-400 hover:text-slate-600">&times;</button>
    </div>
    <form method="POST" id="templateForm">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="template_id" id="template_id">
      <input type="hidden" name="category" id="template_category">
      
      <div class="modal-body space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="template_name" class="block text-sm font-medium text-slate-700 mb-1">Template Name *</label>
            <input type="text" name="name" id="template_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div>
            <label for="template_code" class="block text-sm font-medium text-slate-700 mb-1">Code</label>
            <input type="text" name="code" id="template_code" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">Amount Type *</label>
          <div class="flex gap-4">
            <label class="inline-flex items-center">
              <input type="radio" name="amount_type" value="static" checked onchange="toggleAmountFields()" class="mr-2">
              <span>Static Amount</span>
            </label>
            <label class="inline-flex items-center">
              <input type="radio" name="amount_type" value="percentage" onchange="toggleAmountFields()" class="mr-2">
              <span>Percentage</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div id="static_amount_field">
            <label for="template_static_amount" class="block text-sm font-medium text-slate-700 mb-1">Static Amount (₱) *</label>
            <input type="number" name="static_amount" id="template_static_amount" step="0.01" min="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div id="percentage_field" class="hidden">
            <label for="template_percentage" class="block text-sm font-medium text-slate-700 mb-1">Percentage (%) *</label>
            <input type="number" name="percentage" id="template_percentage" step="0.01" min="0" max="100" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div>
            <label for="template_effectivity" class="block text-sm font-medium text-slate-700 mb-1">Effectivity Until</label>
            <input type="date" name="effectivity_until" id="template_effectivity" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
            <div class="mt-2">
              <label class="inline-flex items-center cursor-pointer">
                <input type="checkbox" id="template_no_end_date" class="mr-2" onchange="toggleTemplateEffectivityDate()">
                <span class="text-xs text-slate-600">No End Date (Ongoing)</span>
              </label>
            </div>
          </div>
        </div>

        <div>
          <label class="inline-flex items-center">
            <input type="checkbox" name="is_modifiable" id="template_modifiable" value="1" class="mr-2">
            <span class="text-sm text-slate-700">Allow HR to modify per employee</span>
          </label>
        </div>

        <div>
          <label for="template_notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
          <textarea name="notes" id="template_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" onclick="closeTemplateModal()" class="btn btn-secondary">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Template</button>
      </div>
    </form>
  </div>
</div>

<!-- Shift Benefit Modal -->
<div id="shiftBenefitModal" class="modal hidden">
  <div class="modal-content max-w-2xl">
    <div class="modal-header">
      <h3 id="shiftBenefitModalTitle" class="text-xl font-semibold">Add Shift Benefit</h3>
      <button type="button" onclick="closeShiftBenefitModal()" class="text-slate-400 hover:text-slate-600">&times;</button>
    </div>
    <form method="POST" id="shiftBenefitForm">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save_shift_benefit">
      <input type="hidden" name="benefit_id" id="benefit_id">
      
      <div class="modal-body space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="shift_name" class="block text-sm font-medium text-slate-700 mb-1">Shift Name *</label>
            <input type="text" name="shift_name" id="shift_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div>
            <label for="shift_code" class="block text-sm font-medium text-slate-700 mb-1">Shift Code</label>
            <input type="text" name="shift_code" id="shift_code" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
        </div>

        <div>
          <label for="benefit_type" class="block text-sm font-medium text-slate-700 mb-1">Benefit Type *</label>
          <select name="benefit_type" id="benefit_type" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
            <option value="night_differential">Night Differential</option>
            <option value="shift_allowance">Shift Allowance</option>
            <option value="hazard_pay">Hazard Pay</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">Amount Type *</label>
          <div class="flex gap-4">
            <label class="inline-flex items-center">
              <input type="radio" name="amount_type" value="static" onchange="toggleShiftAmountFields()" class="mr-2">
              <span>Static Amount</span>
            </label>
            <label class="inline-flex items-center">
              <input type="radio" name="amount_type" value="percentage" checked onchange="toggleShiftAmountFields()" class="mr-2">
              <span>Percentage</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div id="shift_static_amount_field" class="hidden">
            <label for="shift_static_amount" class="block text-sm font-medium text-slate-700 mb-1">Static Amount (₱) *</label>
            <input type="number" name="static_amount" id="shift_static_amount" step="0.01" min="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div id="shift_percentage_field">
            <label for="shift_percentage" class="block text-sm font-medium text-slate-700 mb-1">Percentage (%) *</label>
            <input type="number" name="percentage" id="shift_percentage" step="0.01" min="0" max="100" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
          </div>
          <div>
            <label for="shift_effectivity" class="block text-sm font-medium text-slate-700 mb-1">Effectivity Until</label>
            <input type="date" name="effectivity_until" id="shift_effectivity" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
            <div class="mt-2">
              <label class="inline-flex items-center cursor-pointer">
                <input type="checkbox" id="shift_no_end_date" class="mr-2" onchange="toggleShiftEffectivityDate()">
                <span class="text-xs text-slate-600">No End Date (Ongoing)</span>
              </label>
            </div>
          </div>
        </div>

        <div>
          <label for="shift_notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
          <textarea name="notes" id="shift_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" onclick="closeShiftBenefitModal()" class="btn btn-secondary">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Shift Benefit</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirmation Forms -->
<form method="POST" id="deleteTemplateForm" style="display: none;">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="delete_template">
  <input type="hidden" name="template_id" id="delete_template_id">
</form>

<form method="POST" id="deleteShiftBenefitForm" style="display: none;">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="delete_shift_benefit">
  <input type="hidden" name="benefit_id" id="delete_benefit_id">
</form>

<script>
// Template Modal Functions
function openTemplateModal(category) {
  document.getElementById('template_id').value = '';
  document.getElementById('template_category').value = category;
  document.getElementById('templateModalTitle').textContent = 'Add ' + category.charAt(0).toUpperCase() + category.slice(1);
  document.getElementById('templateForm').reset();
  document.querySelector('input[name="amount_type"][value="static"]').checked = true;
  toggleAmountFields();
  toggleTemplateEffectivityDate();
  document.getElementById('templateModal').classList.remove('hidden');
}

function closeTemplateModal() {
  document.getElementById('templateModal').classList.add('hidden');
}

function editTemplate(template) {
  document.getElementById('template_id').value = template.id;
  document.getElementById('template_category').value = template.category;
  document.getElementById('templateModalTitle').textContent = 'Edit ' + template.category.charAt(0).toUpperCase() + template.category.slice(1);
  document.getElementById('template_name').value = template.name;
  document.getElementById('template_code').value = template.code || '';
  document.querySelector('input[name="amount_type"][value="' + template.amount_type + '"]').checked = true;
  document.getElementById('template_static_amount').value = template.static_amount || '';
  document.getElementById('template_percentage').value = template.percentage || '';
  document.getElementById('template_modifiable').checked = template.is_modifiable;
  document.getElementById('template_effectivity').value = template.effectivity_until || '';
  document.getElementById('template_no_end_date').checked = !template.effectivity_until;
  document.getElementById('template_notes').value = template.notes || '';
  toggleAmountFields();
  toggleTemplateEffectivityDate();
  document.getElementById('templateModal').classList.remove('hidden');
}

function toggleAmountFields() {
  const amountType = document.querySelector('input[name="amount_type"]:checked').value;
  const staticField = document.getElementById('static_amount_field');
  const percentageField = document.getElementById('percentage_field');
  
  if (amountType === 'static') {
    staticField.classList.remove('hidden');
    percentageField.classList.add('hidden');
    document.getElementById('template_static_amount').required = true;
    document.getElementById('template_percentage').required = false;
  } else {
    staticField.classList.add('hidden');
    percentageField.classList.remove('hidden');
    document.getElementById('template_static_amount').required = false;
    document.getElementById('template_percentage').required = true;
  }
}

function toggleTemplateEffectivityDate() {
  const checkbox = document.getElementById('template_no_end_date');
  const dateField = document.getElementById('template_effectivity');
  
  if (checkbox.checked) {
    dateField.value = '';
    dateField.disabled = true;
    dateField.classList.add('bg-slate-100', 'cursor-not-allowed');
  } else {
    dateField.disabled = false;
    dateField.classList.remove('bg-slate-100', 'cursor-not-allowed');
  }
}

function deleteTemplate(id, name) {
  if (confirm('Are you sure you want to delete the template "' + name + '"? This will mark it as inactive.')) {
    document.getElementById('delete_template_id').value = id;
    document.getElementById('deleteTemplateForm').submit();
  }
}

// Shift Benefit Modal Functions
function openShiftBenefitModal() {
  document.getElementById('benefit_id').value = '';
  document.getElementById('shiftBenefitModalTitle').textContent = 'Add Shift Benefit';
  document.getElementById('shiftBenefitForm').reset();
  document.querySelector('#shiftBenefitForm input[name="amount_type"][value="percentage"]').checked = true;
  toggleShiftAmountFields();
  toggleShiftEffectivityDate();
  document.getElementById('shiftBenefitModal').classList.remove('hidden');
}

function closeShiftBenefitModal() {
  document.getElementById('shiftBenefitModal').classList.add('hidden');
}

function editShiftBenefit(benefit) {
  document.getElementById('benefit_id').value = benefit.id;
  document.getElementById('shiftBenefitModalTitle').textContent = 'Edit Shift Benefit';
  document.getElementById('shift_name').value = benefit.shift_name;
  document.getElementById('shift_code').value = benefit.shift_code || '';
  document.getElementById('benefit_type').value = benefit.benefit_type;
  document.querySelector('#shiftBenefitForm input[name="amount_type"][value="' + benefit.amount_type + '"]').checked = true;
  document.getElementById('shift_static_amount').value = benefit.static_amount || '';
  document.getElementById('shift_percentage').value = benefit.percentage || '';
  document.getElementById('shift_effectivity').value = benefit.effectivity_until || '';
  document.getElementById('shift_no_end_date').checked = !benefit.effectivity_until;
  document.getElementById('shift_notes').value = benefit.notes || '';
  toggleShiftAmountFields();
  toggleShiftEffectivityDate();
  document.getElementById('shiftBenefitModal').classList.remove('hidden');
}

function toggleShiftAmountFields() {
  const amountType = document.querySelector('#shiftBenefitForm input[name="amount_type"]:checked').value;
  const staticField = document.getElementById('shift_static_amount_field');
  const percentageField = document.getElementById('shift_percentage_field');
  
  if (amountType === 'static') {
    staticField.classList.remove('hidden');
    percentageField.classList.add('hidden');
    document.getElementById('shift_static_amount').required = true;
    document.getElementById('shift_percentage').required = false;
  } else {
    staticField.classList.add('hidden');
    percentageField.classList.remove('hidden');
    document.getElementById('shift_static_amount').required = false;
    document.getElementById('shift_percentage').required = true;
  }
}

function toggleShiftEffectivityDate() {
  const checkbox = document.getElementById('shift_no_end_date');
  const dateField = document.getElementById('shift_effectivity');
  
  if (checkbox.checked) {
    dateField.value = '';
    dateField.disabled = true;
    dateField.classList.add('bg-slate-100', 'cursor-not-allowed');
  } else {
    dateField.disabled = false;
    dateField.classList.remove('bg-slate-100', 'cursor-not-allowed');
  }
}

function deleteShiftBenefit(id, name) {
  if (confirm('Are you sure you want to delete the shift benefit "' + name + '"? This will mark it as inactive.')) {
    document.getElementById('delete_benefit_id').value = id;
    document.getElementById('deleteShiftBenefitForm').submit();
  }
}

// Close modals on background click
document.getElementById('templateModal').addEventListener('click', function(e) {
  if (e.target === this) closeTemplateModal();
});

document.getElementById('shiftBenefitModal').addEventListener('click', function(e) {
  if (e.target === this) closeShiftBenefitModal();
});
</script>

<style>
.modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
}

.modal.hidden {
  display: none;
}

.modal-content {
  background: white;
  border-radius: 12px;
  width: 90%;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem;
  border-bottom: 1px solid #e2e8f0;
}

.modal-header button {
  font-size: 1.5rem;
  font-weight: bold;
  border: none;
  background: none;
  cursor: pointer;
}

.modal-body {
  padding: 1.5rem;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1.5rem;
  border-top: 1px solid #e2e8f0;
}
</style>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
