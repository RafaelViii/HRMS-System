<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('payroll', 'payroll_runs', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/payroll.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$csrf = csrf_token();
$errors = [];
$templates = payroll_get_approval_templates($pdo);
$branches = payroll_get_branches($pdo);
$allBranchIds = array_keys($branches);

// Fetch available cutoff periods (active and not locked)
$cutoffPeriods = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM cutoff_periods 
        WHERE status = 'active' AND is_locked = FALSE
        ORDER BY start_date DESC
    ");
    $cutoffPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('PAYROLL-CUTOFF-001', 'Failed to load cutoff periods: ' . $e->getMessage());
}

$selectedCutoffId = (int)($_POST['cutoff_period_id'] ?? 0);
$formIntent = $_POST['form_intent'] ?? 'create_run';
$isCreateIntent = $formIntent === 'create_run';
$defaultStart = (new DateTime('first day of this month'))->format('Y-m-d');
$defaultEnd = (new DateTime('last day of this month'))->format('Y-m-d');

// If cutoff period selected, use those dates
if ($selectedCutoffId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($cutoffPeriods as $cp) {
        if ((int)$cp['id'] === $selectedCutoffId) {
            $defaultStart = $cp['start_date'];
            $defaultEnd = $cp['end_date'];
            break;
        }
    }
}

$periodStartInput = trim((string)($_POST['period_start'] ?? $defaultStart));
$periodEndInput = trim((string)($_POST['period_end'] ?? $defaultEnd));

if ($selectedCutoffId && $_SERVER['REQUEST_METHOD'] === 'POST' && !$isCreateIntent) {
  $periodStartInput = $defaultStart;
  $periodEndInput = $defaultEnd;
}

// Defaults aligned with enums
$defaultRunMode = 'automatic';
$defaultCompMode = 'queued';
$selectedBranches = $allBranchIds;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid form token. Please try again.';
  } else {
    $periodStart = $periodStartInput;
    $periodEnd = $periodEndInput;
    $notes = trim($_POST['notes'] ?? '');
    $templateId = (int)($_POST['approval_template_id'] ?? 0) ?: null;
    $runMode = $_POST['run_mode'] ?? $defaultRunMode;
    $compMode = $_POST['computation_mode'] ?? $defaultCompMode;
    $incomingBranches = array_map('intval', (array)($_POST['branches'] ?? []));
    $selectedBranches = array_values(array_intersect($incomingBranches, $allBranchIds));

    if ($isCreateIntent) {
      if (!$selectedBranches) {
        $errors[] = 'Select at least one branch to include in this run.';
      }

      if (!$periodStart || !$periodEnd) {
        $errors[] = 'Please provide both period start and end dates.';
      } else {
        try {
          $startDate = new DateTime($periodStart);
          $endDate = new DateTime($periodEnd);
          if ($startDate > $endDate) {
            $errors[] = 'Period start must be on or before the end date.';
          }
        } catch (Throwable $e) {
          $errors[] = 'Invalid date format provided.';
        }
      }

      if (!$errors) {
        $user = current_user();
        $runId = payroll_create_run($pdo, $periodStart, $periodEnd, (int)($user['id'] ?? 0), $notes ?: null, $templateId, $runMode, $compMode);
        if ($runId) {
          $initOk = payroll_init_batches_for_run($pdo, $runId, $selectedBranches);
          if ($initOk) {
            $branchCount = count($selectedBranches);
            $label = $branchCount === 1 ? 'branch' : 'branches';
            flash_success('Payroll run created and queued for ' . $branchCount . ' ' . $label . '.');
          } else {
            flash_error('Payroll run created but selected branches were not initialized. Open the run to add them manually.');
          }
          action_log('payroll', 'run_created', 'success', [
            'run_id' => $runId,
            'branch_ids' => $selectedBranches,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
          ]);
          header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
          exit;
        }
        $errors[] = 'Unable to create payroll run. Please try again.';
      }
    }
  }
}

$attendanceSnapshot = $allBranchIds ? payroll_get_branch_attendance_snapshot($pdo, $periodStartInput, $periodEndInput, $allBranchIds) : [];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="space-y-6">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">New Payroll Run</h1>
      <p class="text-sm text-slate-500 mt-0.5">Set the payroll period, pick branches, and review attendance coverage before launching.</p>
    </div>
    <a class="btn btn-outline flex-shrink-0" href="<?= BASE_URL ?>/modules/payroll/index">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
      Back to Runs
    </a>
  </div>

  <?php if ($errors): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
    <ul class="list-disc pl-5 space-y-1">
      <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>" />
    <input type="hidden" name="form_intent" id="run_form_intent" value="create_run" />

    <div class="grid gap-6 lg:grid-cols-5">
      <!-- LEFT COLUMN: Configuration -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Cutoff & Period Card -->
        <div class="card">
          <div class="card-header">
            <span class="flex items-center gap-2">
              <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
              Period & Cutoff
            </span>
          </div>
          <div class="card-body space-y-4">
            <?php if ($cutoffPeriods): ?>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1" for="cutoff_period_id">Cutoff Period <span class="text-red-500">*</span></label>
              <select id="cutoff_period_id" name="cutoff_period_id" class="input-text w-full" onchange="document.getElementById('run_form_intent').value='prefill_cutoff'; this.form.submit();" required>
                <option value="">-- Select Cutoff Period --</option>
                <?php foreach ($cutoffPeriods as $cp): ?>
                  <option value="<?= (int)$cp['id'] ?>" 
                          <?= $selectedCutoffId === (int)$cp['id'] ? 'selected' : '' ?>
                          data-start="<?= htmlspecialchars($cp['start_date']) ?>"
                          data-end="<?= htmlspecialchars($cp['end_date']) ?>">
                    <?= htmlspecialchars($cp['period_name']) ?> 
                    (<?= htmlspecialchars(date('M d', strtotime($cp['start_date']))) ?> - <?= htmlspecialchars(date('M d, Y', strtotime($cp['end_date']))) ?>)
                    <?php if ($cp['pay_date']): ?>
                      - Pay: <?= htmlspecialchars(date('M d', strtotime($cp['pay_date']))) ?>
                    <?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="mt-1 text-xs text-slate-500">Dates will auto-fill from the selected cutoff</p>
            </div>
            <?php else: ?>
            <div class="p-3 border border-amber-200 rounded-md bg-amber-50 text-xs text-amber-800 flex items-center gap-2">
              <svg class="w-4 h-4 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
              No active cutoff periods found. <a href="<?= BASE_URL ?>/modules/admin/cutoff-periods" class="underline font-medium">Create cutoff periods</a> to proceed.
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1" for="period_start">Period Start</label>
                <input type="date" id="period_start" name="period_start" class="input-text w-full bg-slate-50" 
                       value="<?= htmlspecialchars($periodStartInput) ?>" readonly required />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1" for="period_end">Period End</label>
                <input type="date" id="period_end" name="period_end" class="input-text w-full bg-slate-50" 
                       value="<?= htmlspecialchars($periodEndInput) ?>" readonly required />
              </div>
            </div>
          </div>
        </div>

        <!-- Run Settings Card -->
        <div class="card">
          <div class="card-header">
            <span class="flex items-center gap-2">
              <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              Run Settings
            </span>
          </div>
          <div class="card-body space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1" for="approval_template_id">Approval Template</label>
              <select id="approval_template_id" name="approval_template_id" class="input-text w-full">
                <option value="">Default Chain</option>
                <?php foreach ($templates as $tpl): ?>
                  <option value="<?= (int)$tpl['id'] ?>" <?= (isset($_POST['approval_template_id']) && (int)$_POST['approval_template_id'] === (int)$tpl['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tpl['label'] . ' (' . $tpl['chain_key'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1" for="run_mode">Run Mode</label>
                <select id="run_mode" name="run_mode" class="input-text w-full">
                  <?php $runModeSel = $_POST['run_mode'] ?? $defaultRunMode; ?>
                  <option value="automatic" <?= $runModeSel === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                  <option value="manual" <?= $runModeSel === 'manual' ? 'selected' : '' ?>>Manual</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1" for="computation_mode">Computation</label>
                <select id="computation_mode" name="computation_mode" class="input-text w-full">
                  <?php $compModeSel = $_POST['computation_mode'] ?? $defaultCompMode; ?>
                  <option value="queued" <?= $compModeSel === 'queued' ? 'selected' : '' ?>>Queued</option>
                  <option value="synchronous" <?= $compModeSel === 'synchronous' ? 'selected' : '' ?>>Synchronous</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1" for="notes">Notes <span class="font-normal text-slate-400">(optional)</span></label>
              <textarea id="notes" name="notes" rows="3" class="input-text w-full" placeholder="e.g., Regular cut-off, includes hazard allowance adjustments."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- Heads-up Info -->
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
          <p class="font-semibold text-slate-700 mb-2 flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            Heads up
          </p>
          <ul class="list-disc pl-5 space-y-1 text-xs">
            <li>Attendance tallies refresh as you adjust the payroll period above.</li>
            <li>Only the branches you select will receive payroll batch placeholders.</li>
            <li>You can still upload biometrics and documentation from the run view after saving.</li>
          </ul>
        </div>

        <!-- Submit Buttons -->
        <div class="flex gap-2">
          <a class="btn btn-outline flex-1 justify-center" href="<?= BASE_URL ?>/modules/payroll/index">Cancel</a>
          <button type="submit" class="btn btn-primary flex-1 justify-center" onclick="document.getElementById('run_form_intent').value='create_run';">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg>
            Create Run
          </button>
        </div>
      </div>

      <!-- RIGHT COLUMN: Branch Selection -->
      <div class="lg:col-span-3 space-y-4">
        <?php if ($branches): ?>
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <span class="flex items-center gap-2">
              <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
              Branch Selection
            </span>
            <span class="text-xs text-slate-500">Period: <?= htmlspecialchars($periodStartInput) ?> → <?= htmlspecialchars($periodEndInput) ?></span>
          </div>
          <div class="card-body">
            <p class="text-xs text-slate-500 mb-3">Select branches to include in this run. Attendance counts reflect records within the selected period.</p>
            <div class="grid sm:grid-cols-2 gap-3">
              <?php foreach ($branches as $branchId => $branch): ?>
                <?php
                  $snapshot = $attendanceSnapshot[$branchId] ?? null;
                  $attendance = $snapshot['attendance'] ?? [];
                  $records = (int)($attendance['records'] ?? 0);
                  $distinctEmployees = (int)($attendance['distinct_employees'] ?? 0);
                  $lastRecordDate = $attendance['last_record_date'] ?? null;
                  $lastCapturedAt = $attendance['last_captured_at'] ?? null;
                  $activeEmployees = (int)($snapshot['employees']['active'] ?? 0);
                  $hasAttendance = $records > 0;
                  $isSelected = in_array($branchId, $selectedBranches, true);
                  $cardClasses = 'border rounded-lg p-3.5 flex flex-col gap-2 transition-all cursor-pointer';
                  if ($isSelected && $hasAttendance) {
                      $cardClasses .= ' border-indigo-300 bg-indigo-50/50 ring-1 ring-indigo-200';
                  } elseif ($isSelected && !$hasAttendance) {
                      $cardClasses .= ' border-amber-300 bg-amber-50 ring-1 ring-amber-200';
                  } elseif (!$hasAttendance) {
                      $cardClasses .= ' border-amber-200 bg-amber-50/50 hover:border-amber-300';
                  } else {
                      $cardClasses .= ' border-slate-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/30';
                  }
                ?>
                <label class="<?= htmlspecialchars($cardClasses) ?>">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($branch['name'] ?? 'Branch') ?></p>
                      <p class="text-xs text-slate-500">Code: <?= htmlspecialchars($branch['code'] ?? '—') ?></p>
                    </div>
                    <input type="checkbox" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" name="branches[]" value="<?= (int)$branchId ?>" <?= $isSelected ? 'checked' : '' ?> />
                  </div>
                  <?php if ($hasAttendance): ?>
                  <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center gap-1 text-emerald-700">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                      <?= htmlspecialchars((string)$records) ?> records
                    </span>
                    <span class="text-slate-400">·</span>
                    <span class="text-slate-600"><?= htmlspecialchars((string)$distinctEmployees) ?> employees</span>
                  </div>
                  <?php else: ?>
                  <div class="flex items-center gap-1.5 text-xs text-amber-700 font-medium">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    No attendance captured
                  </div>
                  <?php endif; ?>
                  <div class="text-xs text-slate-400 flex flex-wrap gap-x-2 gap-y-0.5">
                    <span>Active: <?= htmlspecialchars((string)$activeEmployees) ?></span>
                    <?php if ($lastRecordDate): ?><span>Last: <?= htmlspecialchars($lastRecordDate) ?></span><?php endif; ?>
                    <?php if ($lastCapturedAt): ?><span>Updated: <?= htmlspecialchars($lastCapturedAt) ?></span><?php endif; ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="card card-body">
          <div class="p-4 border border-amber-200 rounded-lg bg-amber-50 text-sm text-amber-800">
            No branches found. Configure branches before creating a payroll run.
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
