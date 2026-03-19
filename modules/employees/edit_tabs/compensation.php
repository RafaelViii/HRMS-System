<?php
/**
 * Salary & Compensation Tab
 * Employee edit module — base salary, allowances, contributions, tax overrides
 */

// Helper: compute daily/hourly rate for display
$monthlySalary = (float)($emp['salary'] ?? 0);
$dailyRate = $monthlySalary > 0 ? round($monthlySalary / 22, 2) : 0;
$hourlyRate = $dailyRate > 0 ? round($dailyRate / 8, 2) : 0;

// Sum allowances/deductions for summary
$totalDefaultAllowances = 0;
foreach ($defaultAllowances as $a) { $totalDefaultAllowances += (float)($a['amount'] ?? 0); }
$totalDefaultDeductions = 0;
foreach ($defaultDeductions as $d) { $totalDefaultDeductions += (float)($d['amount'] ?? 0); }
$totalOverrideAllowances = 0;
foreach ($compAllowances as $a) { $totalOverrideAllowances += (float)($a['amount'] ?? 0); }
$totalOverrideDeductions = 0;
foreach ($compDeductions as $d) { $totalOverrideDeductions += (float)($d['amount'] ?? 0); }
$effectiveAllowances = $hasCompOverride ? $totalOverrideAllowances : $totalDefaultAllowances;
$effectiveDeductions = $hasCompOverride ? $totalOverrideDeductions : $totalDefaultDeductions;
$estimatedGross = $monthlySalary + $effectiveAllowances;
$estimatedNet = $estimatedGross - $effectiveDeductions;
?>

<div id="tab-compensation" class="tab-content <?= $activeTab === 'compensation' ? 'active' : '' ?>">

  <!-- Salary Summary Cards -->
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100">
          <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Monthly Salary</p>
          <p class="text-lg font-bold text-slate-900">₱<?= number_format($monthlySalary, 2) ?></p>
        </div>
      </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100">
          <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Allowances</p>
          <p class="text-lg font-bold text-emerald-700">₱<?= number_format($effectiveAllowances, 2) ?></p>
        </div>
      </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100">
          <svg class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Deductions</p>
          <p class="text-lg font-bold text-red-700">₱<?= number_format($effectiveDeductions, 2) ?></p>
        </div>
      </div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-100">
          <svg class="h-5 w-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Est. Net Pay</p>
          <p class="text-lg font-bold text-violet-700">₱<?= number_format(max(0, $estimatedNet), 2) ?></p>
        </div>
      </div>
    </div>
  </div>

  <!-- Base Salary Section -->
  <div class="rounded-xl border border-slate-200 bg-white shadow-sm mb-6">
    <div class="border-b border-slate-100 px-5 py-4">
      <div class="flex items-center gap-3">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50">
          <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div>
          <h2 class="text-base font-semibold text-slate-900">Base Salary</h2>
          <p class="text-xs text-slate-500">Monthly base pay — used for daily/hourly rate computations</p>
        </div>
      </div>
    </div>
    <div class="p-5">
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="employee_salary">
        <div class="grid gap-5 md:grid-cols-3">
          <div>
            <label for="salaryInput" class="block text-sm font-medium text-slate-700 mb-1.5">Monthly Rate</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-medium text-sm">₱</span>
              <input
                id="salaryInput"
                type="number"
                step="0.01"
                min="0"
                name="salary"
                class="w-full rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-4 py-2.5 text-lg font-semibold text-slate-900 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
                value="<?= htmlspecialchars($emp['salary'] ?? '0') ?>"
              >
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-500 mb-1.5">Daily Rate <span class="text-xs text-slate-400">(÷ 22 days)</span></label>
            <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-lg font-semibold text-slate-600">
              ₱<span id="dailyRateDisplay"><?= number_format($dailyRate, 2) ?></span>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-500 mb-1.5">Hourly Rate <span class="text-xs text-slate-400">(÷ 8 hrs)</span></label>
            <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-lg font-semibold text-slate-600">
              ₱<span id="hourlyRateDisplay"><?= number_format($hourlyRate, 2) ?></span>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-3 pt-2">
          <button type="submit" class="btn btn-primary">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Update Salary
          </button>
          <p class="text-xs text-slate-400">Changes are saved immediately and affect future payroll computations.</p>
        </div>
      </form>
    </div>
  </div>

  <!-- Two-Column: Defaults vs Overrides -->
  <div class="grid gap-6 lg:grid-cols-2">

    <!-- Company Defaults (Read-only) -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-100 px-5 py-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100">
              <svg class="h-4 w-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <div>
              <h2 class="text-base font-semibold text-slate-900">Company Defaults</h2>
              <p class="text-xs text-slate-500">Standard compensation structure</p>
            </div>
          </div>
          <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Read-only</span>
        </div>
      </div>
      <div class="p-5 space-y-5">
        <!-- Default Allowances -->
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-emerald-100">
              <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
            </span>
            <h3 class="text-sm font-semibold text-slate-700">Allowances</h3>
            <?php if ($defaultAllowances): ?>
              <span class="ml-auto text-xs font-semibold text-emerald-600">₱<?= number_format($totalDefaultAllowances, 2) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($defaultAllowances): ?>
            <div class="space-y-1.5">
              <?php foreach ($defaultAllowances as $row): ?>
                <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                  <span class="text-sm text-slate-700"><?= htmlspecialchars($row['label']) ?></span>
                  <span class="text-sm font-semibold text-slate-900">₱<?= number_format((float)$row['amount'], 2) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-400 italic">No default allowances configured</p>
          <?php endif; ?>
        </div>

        <!-- Default Deductions -->
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-red-100">
              <svg class="h-3 w-3 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
            </span>
            <h3 class="text-sm font-semibold text-slate-700">Contributions & Deductions</h3>
            <?php if ($defaultDeductions): ?>
              <span class="ml-auto text-xs font-semibold text-red-600">₱<?= number_format($totalDefaultDeductions, 2) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($defaultDeductions): ?>
            <div class="space-y-1.5">
              <?php foreach ($defaultDeductions as $row): ?>
                <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                  <span class="text-sm text-slate-700"><?= htmlspecialchars($row['label']) ?></span>
                  <span class="text-sm font-semibold text-slate-900">₱<?= number_format((float)$row['amount'], 2) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-400 italic">No default contributions configured</p>
          <?php endif; ?>
        </div>

        <!-- Tax Info -->
        <div class="grid grid-cols-2 gap-4 pt-4 border-t border-slate-100">
          <div>
            <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Default Tax Rate</div>
            <p class="text-base font-semibold text-slate-900"><?= htmlspecialchars($compDefaultTaxLabel) ?></p>
          </div>
          <div>
            <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Effective Tax Rate</div>
            <p class="text-base font-semibold text-indigo-600"><?= htmlspecialchars($compEffectiveTaxLabel) ?></p>
          </div>
        </div>

        <?php if (trim((string)($compDefaults['notes'] ?? '')) !== ''): ?>
          <div class="pt-4 border-t border-slate-100">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Notes</h3>
            <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars((string)$compDefaults['notes'])) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Employee Overrides (Editable) -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm <?= $hasCompOverride ? 'ring-2 ring-indigo-100' : '' ?>">
      <div class="border-b border-slate-100 px-5 py-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50">
              <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </div>
            <div>
              <h2 class="text-base font-semibold text-slate-900">Employee Overrides</h2>
              <p class="text-xs text-slate-500">Custom compensation for this employee</p>
            </div>
          </div>
          <?php if ($hasCompOverride): ?>
            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700 uppercase tracking-wide">
              <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
              Custom Active
            </span>
          <?php else: ?>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-500 uppercase tracking-wide">Using Defaults</span>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" id="employee-compensation-form" class="p-5 space-y-5">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="employee_compensation">

        <!-- Allowance Overrides -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-emerald-100">
                <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
              </span>
              <h3 class="text-sm font-semibold text-slate-700">Allowance Overrides</h3>
            </div>
            <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 transition" data-comp-add="allowances">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Add Row
            </button>
          </div>
          <div class="space-y-2" data-comp-list="allowances">
            <?php foreach ($compAllowanceRows as $row): ?>
            <div class="grid gap-2 sm:grid-cols-12 items-end rounded-lg border border-slate-100 bg-slate-50 p-3" data-comp-row>
              <div class="sm:col-span-5">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Label</label>
                <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[label][]" value="<?= htmlspecialchars($row['label'] ?? '') ?>" placeholder="e.g., Hazard Pay">
              </div>
              <div class="sm:col-span-3">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Code</label>
                <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[code][]" value="<?= htmlspecialchars($row['code'] ?? '') ?>" placeholder="Optional">
              </div>
              <div class="sm:col-span-3">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Amount</label>
                <div class="relative">
                  <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">₱</span>
                  <input type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-200 bg-white pl-6 pr-2.5 py-2 text-sm font-medium focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[amount][]" value="<?= htmlspecialchars($row['amount'] ?? '') ?>" placeholder="0.00">
                </div>
              </div>
              <div class="sm:col-span-1 flex justify-center">
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition" data-comp-remove title="Remove row">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Contribution Overrides -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-red-100">
                <svg class="h-3 w-3 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
              </span>
              <h3 class="text-sm font-semibold text-slate-700">Contribution Overrides</h3>
            </div>
            <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 transition" data-comp-add="deductions">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Add Row
            </button>
          </div>
          <div class="space-y-2" data-comp-list="deductions">
            <?php foreach ($compDeductionRows as $row): ?>
            <div class="grid gap-2 sm:grid-cols-12 items-end rounded-lg border border-slate-100 bg-slate-50 p-3" data-comp-row>
              <div class="sm:col-span-5">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Label</label>
                <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[label][]" value="<?= htmlspecialchars($row['label'] ?? '') ?>" placeholder="e.g., SSS Override">
              </div>
              <div class="sm:col-span-3">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Code</label>
                <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[code][]" value="<?= htmlspecialchars($row['code'] ?? '') ?>" placeholder="Optional">
              </div>
              <div class="sm:col-span-3">
                <label class="text-[11px] font-medium text-slate-500 block mb-1">Amount</label>
                <div class="relative">
                  <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">₱</span>
                  <input type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-200 bg-white pl-6 pr-2.5 py-2 text-sm font-medium focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[amount][]" value="<?= htmlspecialchars($row['amount'] ?? '') ?>" placeholder="0.00">
                </div>
              </div>
              <div class="sm:col-span-1 flex justify-center">
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition" data-comp-remove title="Remove row">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Tax Override -->
        <div class="rounded-lg border border-indigo-100 bg-indigo-50/50 p-4">
          <div class="flex items-center gap-2 mb-3">
            <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z"/></svg>
            <label class="text-sm font-semibold text-indigo-900">Tax Percentage Override</label>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-32">
              <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                class="w-full rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-medium focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition"
                name="tax_percentage"
                value="<?= htmlspecialchars($compTaxFieldValue) ?>"
                placeholder="e.g., 8.00"
              >
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-medium text-indigo-400">%</span>
            </div>
            <span class="text-xs text-indigo-700/70">
              Leave blank to inherit <?= htmlspecialchars($compTaxPlaceholderHint) ?>
            </span>
          </div>
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Notes</label>
          <textarea
            name="comp_notes"
            class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
            rows="2"
            placeholder="Optional context for the payroll team"
          ><?= htmlspecialchars($compNotes) ?></textarea>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-100">
          <button type="submit" class="btn btn-primary">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Save Overrides
          </button>
          <button type="submit" class="btn btn-outline text-red-600 border-red-200 hover:bg-red-50" name="comp_action" value="clear" data-confirm="Clear all compensation overrides for this employee? Default values will be used.">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Clear Overrides
          </button>
        </div>

        <!-- Templates for dynamic rows -->
        <template data-comp-template="allowances">
          <div class="grid gap-2 sm:grid-cols-12 items-end rounded-lg border border-slate-100 bg-slate-50 p-3" data-comp-row>
            <div class="sm:col-span-5">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Label</label>
              <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[label][]" placeholder="e.g., Hazard Pay">
            </div>
            <div class="sm:col-span-3">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Code</label>
              <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[code][]" placeholder="Optional">
            </div>
            <div class="sm:col-span-3">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Amount</label>
              <div class="relative">
                <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">₱</span>
                <input type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-200 bg-white pl-6 pr-2.5 py-2 text-sm font-medium focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="allowances[amount][]" placeholder="0.00">
              </div>
            </div>
            <div class="sm:col-span-1 flex justify-center">
              <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition" data-comp-remove title="Remove row">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>
        </template>

        <template data-comp-template="deductions">
          <div class="grid gap-2 sm:grid-cols-12 items-end rounded-lg border border-slate-100 bg-slate-50 p-3" data-comp-row>
            <div class="sm:col-span-5">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Label</label>
              <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[label][]" placeholder="e.g., SSS Override">
            </div>
            <div class="sm:col-span-3">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Code</label>
              <input type="text" class="w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[code][]" placeholder="Optional">
            </div>
            <div class="sm:col-span-3">
              <label class="text-[11px] font-medium text-slate-500 block mb-1">Amount</label>
              <div class="relative">
                <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">₱</span>
                <input type="number" step="0.01" min="0" class="w-full rounded-md border border-slate-200 bg-white pl-6 pr-2.5 py-2 text-sm font-medium focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" name="deductions[amount][]" placeholder="0.00">
              </div>
            </div>
            <div class="sm:col-span-1 flex justify-center">
              <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition" data-comp-remove title="Remove row">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>
        </template>
      </form>
    </div>
  </div>

</div>

<script>
// Compensation form: dynamic rows + live salary calculator
(function(){
  // Dynamic rows
  const form = document.getElementById('employee-compensation-form');
  if (form) {
    function addRow(type) {
      if (!type) return;
      const template = form.querySelector('template[data-comp-template="' + type + '"]');
      const list = form.querySelector('[data-comp-list="' + type + '"]');
      if (!template || !list) return;
      const clone = template.content.firstElementChild.cloneNode(true);
      list.appendChild(clone);
      const focusInput = clone.querySelector('input');
      if (focusInput) focusInput.focus();
    }
    form.querySelectorAll('[data-comp-add]').forEach(function(btn){
      btn.addEventListener('click', function(){ addRow(this.getAttribute('data-comp-add')); });
    });
    form.addEventListener('click', function(event){
      const btn = event.target.closest('[data-comp-remove]');
      if (!btn) return;
      const row = btn.closest('[data-comp-row]');
      if (!row) return;
      row.remove();
    });
  }

  // Live rate calculator
  const salaryInput = document.getElementById('salaryInput');
  const dailyDisplay = document.getElementById('dailyRateDisplay');
  const hourlyDisplay = document.getElementById('hourlyRateDisplay');
  if (salaryInput && dailyDisplay && hourlyDisplay) {
    salaryInput.addEventListener('input', function() {
      const monthly = parseFloat(this.value) || 0;
      const daily = monthly / 22;
      const hourly = daily / 8;
      dailyDisplay.textContent = daily.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      hourlyDisplay.textContent = hourly.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });
  }
})();
</script>
