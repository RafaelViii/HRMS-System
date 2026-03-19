<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('payroll', 'payroll_runs', 'manage');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// OT-related formula codes
$otCodes = [
    'overtime_multiplier',
    'rest_day_ot_multiplier',
    'regular_holiday_multiplier',
    'regular_holiday_ot_multiplier',
    'special_holiday_multiplier',
    'special_holiday_ot_multiplier',
];

// Handle POST — update formula values
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    ensure_action_authorized('payroll.payroll_runs', 'update_ot_rates', 'manage');

    $updates = $_POST['rates'] ?? [];
    $errors = [];
    $updated = 0;

    foreach ($updates as $code => $newValue) {
        if (!in_array($code, $otCodes, true)) {
            continue;
        }
        $val = trim($newValue);
        if ($val === '' || !is_numeric($val)) {
            $errors[] = "Invalid value for " . htmlspecialchars($code);
            continue;
        }
        $numericVal = (float)$val;
        if ($numericVal < 0 || $numericVal > 99) {
            $errors[] = "Value for " . htmlspecialchars($code) . " must be between 0 and 99.";
            continue;
        }

        try {
            // Get current value for audit
            $oldStmt = $pdo->prepare('SELECT id, default_value FROM payroll_formula_settings WHERE code = :code AND is_active = TRUE ORDER BY effective_start DESC LIMIT 1');
            $oldStmt->execute([':code' => $code]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldRow) {
                $errors[] = "Formula setting not found: " . htmlspecialchars($code);
                continue;
            }

            $oldValue = (float)($oldRow['default_value'] ?? 0);
            if (abs($oldValue - $numericVal) < 0.000001) {
                continue; // No change
            }

            $stmt = $pdo->prepare('UPDATE payroll_formula_settings SET default_value = :val, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':val' => $numericVal, ':id' => (int)$oldRow['id']]);
            $updated++;

            audit('update_ot_rate', "Updated OT rate '{$code}' from {$oldValue} to {$numericVal}", [
                'target_type' => 'payroll_formula_settings',
                'target_id' => (int)$oldRow['id'],
                'old_values' => ['default_value' => $oldValue],
                'new_values' => ['default_value' => $numericVal],
            ]);
        } catch (Throwable $e) {
            sys_log('OT-RATE-UPDATE', 'Failed to update OT rate: ' . $e->getMessage(), ['module' => 'admin', 'code' => $code]);
            $errors[] = "Database error updating " . htmlspecialchars($code);
        }
    }

    if ($errors) {
        flash_error(implode('<br>', $errors));
    } elseif ($updated > 0) {
        flash_success("Updated {$updated} overtime rate(s) successfully.");
        action_log('admin', 'update_ot_rates', 'success', ['updated_count' => $updated]);
    } else {
        flash_success('No changes detected.');
    }

    header('Location: ' . BASE_URL . '/modules/admin/overtime-rates');
    exit;
}

// Load current OT formula settings
$allSettings = payroll_get_formula_settings($pdo);
$otSettings = [];
foreach ($otCodes as $code) {
    if (isset($allSettings[$code])) {
        $otSettings[$code] = $allSettings[$code];
    }
}

// Friendly labels + descriptions for display
$otDisplay = [
    'overtime_multiplier' => [
        'label' => 'Standard Overtime',
        'desc'  => 'Regular overtime rate applied on normal working days.',
        'icon'  => 'clock',
        'color' => 'indigo',
    ],
    'rest_day_ot_multiplier' => [
        'label' => 'Rest Day Overtime',
        'desc'  => 'Premium rate for overtime work performed on rest days.',
        'icon'  => 'calendar-off',
        'color' => 'blue',
    ],
    'regular_holiday_multiplier' => [
        'label' => 'Regular Holiday',
        'desc'  => 'Base rate multiplier for work on regular holidays.',
        'icon'  => 'flag',
        'color' => 'emerald',
    ],
    'regular_holiday_ot_multiplier' => [
        'label' => 'Regular Holiday Overtime',
        'desc'  => 'Enhanced rate for overtime work on regular holidays.',
        'icon'  => 'flag-ot',
        'color' => 'teal',
    ],
    'special_holiday_multiplier' => [
        'label' => 'Special Non-Working Day',
        'desc'  => 'Base rate multiplier for work on special non-working days.',
        'icon'  => 'star',
        'color' => 'amber',
    ],
    'special_holiday_ot_multiplier' => [
        'label' => 'Special Non-Working Day OT',
        'desc'  => 'Overtime rate for work on special non-working days.',
        'icon'  => 'star-ot',
        'color' => 'orange',
    ],
];

action_log('admin', 'view_ot_rates', 'success');

$pageTitle = 'Overtime Rate Configuration';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Hero Header -->
  <div class="rounded-xl bg-gradient-to-br from-slate-900 via-indigo-900 to-violet-900 p-6 text-white shadow-lg">
    <div class="flex items-start justify-between">
      <div>
        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white/75">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Payroll Settings
        </div>
        <h1 class="text-2xl font-semibold">Overtime & Holiday Rates</h1>
        <p class="mt-1 text-sm text-white/70">Configure system-wide default multiplier rates for overtime and holiday pay computations. These rates serve as the base defaults across all branches unless overridden per-employee.</p>
      </div>
      <a href="<?= BASE_URL ?>/modules/admin/management" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/20 transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Back
      </a>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3">
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Rate Types</div>
        <div class="mt-1 text-sm font-semibold"><?= count($otSettings) ?> configured</div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Scope</div>
        <div class="mt-1 text-sm font-semibold">System-wide Defaults</div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Override</div>
        <div class="mt-1 text-sm font-semibold">Per-employee via Payroll Profile</div>
      </div>
    </div>
  </div>

  <?php if (empty($otSettings)): ?>
    <div class="card card-body text-center py-12">
      <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
      </svg>
      <h3 class="mt-3 text-base font-semibold text-slate-900">No OT Formula Settings Found</h3>
      <p class="mt-1 text-sm text-slate-500">The payroll formula settings table has not been seeded yet. Run the migration <code>2025-10-22_payroll_data_foundations.sql</code> first.</p>
    </div>
  <?php else: ?>
    <form method="POST" action="" data-confirm="Save overtime rate changes?">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="update_rates">

      <!-- Info banner -->
      <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 mb-5">
        <div class="flex gap-3">
          <div class="shrink-0">
            <svg class="h-5 w-5 text-indigo-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div class="text-sm text-indigo-800">
            <strong>How multipliers work:</strong> A multiplier of <strong>1.25</strong> means the employee earns <strong>125%</strong> of their regular hourly rate. For example, if the daily rate is ₱500 (8 hrs), the hourly rate is ₱62.50, and OT at 1.25× = ₱78.13/hr.
          </div>
        </div>
      </div>

      <!-- Rates Grid -->
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($otCodes as $code):
            $setting = $otSettings[$code] ?? null;
            if (!$setting) continue;
            $display = $otDisplay[$code] ?? ['label' => $code, 'desc' => '', 'icon' => 'default', 'color' => 'slate'];
            $currentValue = (float)($setting['default_value'] ?? 0);
            $color = $display['color'];
        ?>
        <div class="card group relative overflow-hidden transition hover:shadow-md hover:border-<?= $color ?>-200">
          <div class="card-body space-y-4">
            <!-- Header -->
            <div class="flex items-start justify-between gap-3">
              <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-<?= $color ?>-100 text-<?= $color ?>-600 transition group-hover:scale-105">
                <?php if (str_contains($code, 'regular_holiday')): ?>
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                <?php elseif (str_contains($code, 'special_holiday')): ?>
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                <?php elseif (str_contains($code, 'rest_day')): ?>
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path stroke-linecap="round" d="M8 15l2 2 4-4"/></svg>
                <?php else: ?>
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3"/></svg>
                <?php endif; ?>
              </div>
              <span class="inline-flex items-center rounded-full bg-<?= $color ?>-50 border border-<?= $color ?>-200 px-2.5 py-0.5 text-xs font-bold text-<?= $color ?>-700">
                <?= number_format($currentValue, 2) ?>×
              </span>
            </div>

            <!-- Label & Description -->
            <div>
              <h3 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($display['label']) ?></h3>
              <p class="mt-0.5 text-xs text-slate-500 leading-relaxed"><?= htmlspecialchars($display['desc']) ?></p>
            </div>

            <!-- Input -->
            <div>
              <label for="rate_<?= $code ?>" class="block text-xs font-medium text-slate-600 mb-1">Multiplier Value</label>
              <div class="relative">
                <input
                  type="number"
                  id="rate_<?= $code ?>"
                  name="rates[<?= $code ?>]"
                  value="<?= number_format($currentValue, 6) ?>"
                  step="0.01"
                  min="0"
                  max="99"
                  class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-900 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
                  required
                />
                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-400">×</div>
              </div>
              <p class="mt-1.5 text-[11px] text-slate-400">
                Effective since: <?= date('M d, Y', strtotime($setting['effective_start'])) ?>
                <?php if ($setting['effective_end']): ?>
                  — Expires: <?= date('M d, Y', strtotime($setting['effective_end'])) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Action bar -->
      <div class="mt-6 flex items-center justify-between rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
        <p class="text-sm text-slate-500">Changes apply system-wide to all future payroll computations.</p>
        <div class="flex items-center gap-3">
          <a href="<?= BASE_URL ?>/modules/admin/management" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">
            <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Save Rates
          </button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
