<?php
/**
 * Leave Entitlements Tab
 * Employee edit module - leave balances and overrides
 */

$leaveSummaryColumns = max(2, min(4, count($leaveTypes) ?: 2));
$leaveFormColumns = max(2, min(3, count($leaveTypes) ?: 2));
?>

<div id="tab-leave" class="tab-content <?= $activeTab === 'leave' ? 'active' : '' ?>">
  <div class="info-card">
    <div class="info-card-header">
      <div>
        <h2 class="info-card-title">Leave Entitlements</h2>
        <p class="info-card-subtitle">Review effective balances and override specific leave types</p>
      </div>
      <span class="text-xs text-gray-500">
        Blank overrides inherit upstream allowances
      </span>
    </div>

    <!-- Current Effective Balances -->
    <div class="mb-6">
      <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
        <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Current Effective Balances
      </h3>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-<?= $leaveSummaryColumns ?>">
        <?php foreach ($leaveTypes as $type): ?>
          <?php
            $sourceKey = $leaveSourceMap[$type] ?? 'defaults';
            $boxClass = 'border border-gray-200 bg-gray-50';
            $badgeClass = 'text-gray-600';
            if ($sourceKey === 'employee') { 
              $boxClass = 'border-blue-300 bg-blue-50'; 
              $badgeClass = 'text-blue-700';
            } elseif ($sourceKey === 'department') { 
              $boxClass = 'border-amber-300 bg-amber-50'; 
              $badgeClass = 'text-amber-700';
            } elseif ($sourceKey === 'global') { 
              $boxClass = 'border-emerald-300 bg-emerald-50'; 
              $badgeClass = 'text-emerald-700';
            }
            $effectiveValue = $effectiveLeaveEntitlements[$type] ?? 0;
            $label = $leaveSourceLabels[$sourceKey] ?? 'System Default';
          ?>
          <div class="rounded-lg <?= $boxClass ?> p-4 transition-all hover:shadow-md">
            <div class="flex items-start justify-between mb-2">
              <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                <?= htmlspecialchars(leave_label_for_type($type)) ?>
              </p>
              <?php if ($sourceKey === 'employee'): ?>
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase bg-blue-200 text-blue-800 rounded-full">
                  Custom
                </span>
              <?php endif; ?>
            </div>
            <p class="text-2xl font-bold <?= $badgeClass ?> mb-2">
              <?= htmlspecialchars(leave_format_days_compact($effectiveValue)) ?>
              <span class="text-sm font-normal text-gray-600">day(s)</span>
            </p>
            <div class="flex items-center text-xs text-gray-600">
              <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Source: <?= htmlspecialchars($label) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Override Form -->
    <form method="post" class="p-6 bg-gradient-to-br from-gray-50 to-white border-2 border-gray-200 rounded-xl">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="employee_leave">

      <div class="mb-4">
        <h3 class="text-sm font-semibold text-gray-700 flex items-center">
          <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          Employee-Specific Overrides
        </h3>
        <p class="text-xs text-gray-600 mt-1">
          Set custom leave balances for this employee. Leave fields blank to use inherited values.
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-<?= $leaveFormColumns ?>">
        <?php foreach ($leaveTypes as $type): ?>
          <?php $overrideValue = $employeeLeaveOverrides[$type] ?? null; ?>
          <div class="relative">
            <label class="block mb-2">
              <span class="text-xs font-medium text-gray-700 uppercase tracking-wide">
                <?= htmlspecialchars(leave_label_for_type($type)) ?>
              </span>
              <div class="relative mt-1">
                <input 
                  type="number" 
                  step="0.5" 
                  min="0" 
                  class="input-text w-full pr-16" 
                  name="leave_days[<?= htmlspecialchars($type) ?>]" 
                  value="<?= htmlspecialchars(leave_format_days_compact($overrideValue)) ?>" 
                  placeholder="Inherit"
                />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500">
                  day(s)
                </span>
              </div>
            </label>
            <div class="flex items-center text-[11px] text-gray-500 mt-1">
              <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Current: <span class="font-medium ml-1"><?= htmlspecialchars(leave_format_days_compact($effectiveLeaveEntitlements[$type] ?? 0)) ?> day(s)</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="flex items-center justify-between gap-3 mt-6 pt-6 border-t border-gray-200">
        <button 
          type="button" 
          class="btn btn-outline text-sm" 
          id="clear-employee-leave"
        >
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          Clear All Overrides
        </button>
        <button type="submit" class="btn btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          Save Leave Overrides
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('clear-employee-leave')?.addEventListener('click', function(){
  if (!confirm('Clear all employee leave overrides? Default values will be restored.')) {
    return;
  }
  const form = this.closest('form');
  if (!form) return;
  form.querySelectorAll('input[type="number"]').forEach(function(input){ 
    input.value = ''; 
  });
});
</script>
