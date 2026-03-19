<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$psId = (int)($_GET['id'] ?? 0);

if ($psId <= 0) {
  flash_error('Payslip not found.');
  header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
  exit;
}

$row = payroll_get_payslip($pdo, $psId);
if (!$row) {
  flash_error('Payslip not found.');
  header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
  exit;
}

$ownerUserId = (int)($row['owner_user_id'] ?? 0);
$canReadPayroll = user_has_access($uid, 'payroll', 'payslips', 'read');
$canAdmin = in_array(($user['role'] ?? ''), ['admin','hr','accountant','manager'], true) || $canReadPayroll;
if (!$canAdmin && $ownerUserId !== $uid) {
  require_once __DIR__ . '/../../includes/header.php';
  http_response_code(403);
  show_human_error('You do not have access to this payslip.');
  require_once __DIR__ . '/../../includes/footer.php';
  exit;
}

// Handle POST (adjustment or complaint)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    sys_log('PAYROLL-COMPLAINT-CSRF', 'Invalid CSRF token during complaint submission', [
      'user_id' => $uid,
      'payslip_id' => $psId,
      'post_data' => $_POST
    ]);
    audit('payroll_complaint_error', 'Invalid CSRF token', ['user_id' => $uid, 'payslip_id' => $psId]);
    flash_error('Invalid form token.');
    header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
    exit;
  }
  $action = $_POST['action'] ?? '';
  switch ($action) {
    case 'adjust_payslip':
      $auth = ensure_action_authorized('payroll', 'payslip_adjust', 'admin');
      if (!$auth['ok']) {
        flash_error('Payslip adjustment requires an authorized override.');
        header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
        exit;
      }
      $type = in_array(($_POST['type'] ?? ''), ['earning','deduction'], true) ? $_POST['type'] : 'earning';
      $code = trim($_POST['code'] ?? 'adjust');
      $label = trim($_POST['label'] ?? 'Adjustment');
      $amount = (float)($_POST['amount'] ?? 0);
      $reason = trim($_POST['reason'] ?? '');
      if ($amount <= 0 || $reason === '') {
        flash_error('Amount and reason are required.');
        header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
        exit;
      }
      $actingUserId = (int)($auth['as_user'] ?? $uid);
      $newId = payroll_clone_payslip_with_adjustment($pdo, $psId, $type, $code, $label, $amount, $reason, $actingUserId);
      if (!$newId) {
        flash_error('Unable to adjust payslip.');
        header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
        exit;
      }
      flash_success('Payslip adjusted and re-released.');
      header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $newId);
      exit;

    case 'file_complaint':
      // Only owner can file complaint from this screen
      if ($ownerUserId !== $uid) {
        sys_log('PAYROLL-COMPLAINT-OWNER', 'Non-owner tried to file complaint', [
          'user_id' => $uid,
          'owner_user_id' => $ownerUserId,
          'payslip_id' => $psId,
          'post_data' => $_POST
        ]);
        audit('payroll_complaint_error', 'Non-owner tried to file complaint', ['user_id' => $uid, 'owner_user_id' => $ownerUserId, 'payslip_id' => $psId]);
        flash_error('Only the payslip owner may file a complaint here.');
        header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
        exit;
      }
      $desc = trim($_POST['description'] ?? '');
      if ($desc === '') {
        sys_log('PAYROLL-COMPLAINT-NODESC', 'Complaint submission missing description', [
          'user_id' => $uid,
          'payslip_id' => $psId,
          'post_data' => $_POST
        ]);
        audit('payroll_complaint_error', 'Complaint submission missing description', ['user_id' => $uid, 'payslip_id' => $psId]);
        flash_error('Please describe your concern.');
        header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
        exit;
      }
      $employeeId = (int)$row['employee_id'];
      $runId = (int)$row['payroll_run_id'];
      $complaintId = payroll_log_complaint($pdo, $runId, $employeeId, 'Payslip Issue', $desc, $uid);
      if (!$complaintId) {
        sys_log('PAYROLL-COMPLAINT-FAIL', 'Failed to log complaint', [
          'user_id' => $uid,
          'payslip_id' => $psId,
          'employee_id' => $employeeId,
          'run_id' => $runId,
          'description' => $desc,
          'post_data' => $_POST
        ]);
        audit('payroll_complaint_error', 'Failed to log complaint', ['user_id' => $uid, 'payslip_id' => $psId, 'employee_id' => $employeeId, 'run_id' => $runId, 'description' => $desc]);
        flash_error('Could not submit complaint.');
      } else {
        sys_log('PAYROLL-COMPLAINT-SUCCESS', 'Complaint logged successfully', [
          'user_id' => $uid,
          'complaint_id' => $complaintId,
          'payslip_id' => $psId,
          'employee_id' => $employeeId,
          'run_id' => $runId,
          'description' => $desc
        ]);
        audit('payroll_complaint_logged', 'Complaint logged successfully', ['user_id' => $uid, 'complaint_id' => $complaintId, 'payslip_id' => $psId, 'employee_id' => $employeeId, 'run_id' => $runId, 'description' => $desc]);
        flash_success('Your complaint was submitted to payroll.');
      }
      header('Location: ' . BASE_URL . '/modules/payroll/view?id=' . $psId);
      exit;
  }
}

require_once __DIR__ . '/../../includes/header.php';
$items = payroll_get_payslip_items($pdo, [$psId]);
$lines = $items[$psId] ?? [];
$grouped = ['earning' => [], 'deduction' => []];
foreach ($lines as $it) {
  $t = $it['type'] ?? '';
  if ($t === 'earning' || $t === 'deduction') { $grouped[$t][] = $it; }
}
$csrf = csrf_token();
?>
<div class="card p-4 max-w-3xl">
  <h1 class="text-xl font-semibold mb-2">Payslip</h1>
  <div class="text-sm text-gray-600 mb-4">
    Employee: <span class="font-medium"><?= htmlspecialchars(($row['employee_code'] ?? '') . ' — ' . ($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')) ?></span><br>
    Department: <span class="font-medium"><?= htmlspecialchars($row['department_name'] ?? 'Unassigned') ?></span><br>
    Period: <span class="font-medium"><?= htmlspecialchars(($row['period_start'] ?? '') . ' to ' . ($row['period_end'] ?? '')) ?></span><br>
    Version: <span class="font-medium">#<?= (int)($row['version'] ?? 1) ?></span><br>
    Released: <span class="font-medium"><?= htmlspecialchars($row['released_at'] ?? '—') ?></span>
  </div>
  <div class="grid md:grid-cols-2 gap-3 text-sm">
    <div class="p-3 bg-gray-50 rounded">
      <div class="font-semibold mb-1">Earnings</div>
      <div class="flex justify-between"><span>Basic Pay</span><span><?= number_format((float)$row['basic_pay'], 2) ?></span></div>
      <?php foreach ($grouped['earning'] as $it): ?>
        <div class="flex justify-between"><span><?= htmlspecialchars($it['label'] ?? $it['code'] ?? 'Earning') ?></span><span><?= number_format((float)$it['amount'], 2) ?></span></div>
      <?php endforeach; ?>
      <div class="flex justify-between border-t mt-2 pt-1"><span>Total Earnings</span><span><?= number_format((float)$row['total_earnings'], 2) ?></span></div>
    </div>
    <div class="p-3 bg-gray-50 rounded">
      <div class="font-semibold mb-1">Deductions</div>
      <?php foreach ($grouped['deduction'] as $it): ?>
        <div class="flex justify-between"><span><?= htmlspecialchars($it['label'] ?? $it['code'] ?? 'Deduction') ?></span><span><?= number_format((float)$it['amount'], 2) ?></span></div>
      <?php endforeach; ?>
      <div class="flex justify-between border-t mt-2 pt-1"><span>Total Deductions</span><span><?= number_format((float)$row['total_deductions'], 2) ?></span></div>
    </div>
  </div>
  <div class="mt-3 text-lg font-semibold flex justify-between border-t pt-3">
    <span>Net Pay</span>
    <span><?= number_format((float)$row['net_pay'], 2) ?></span>
  </div>
  <div class="mt-4 flex flex-wrap gap-2">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= (int)$psId ?>" target="_blank" rel="noopener">Download PDF</a>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/payroll/my_payslips">Back</a>
    <?php if ($canAdmin && false): // Adjust Payslip feature disabled ?>
      <details class="ml-auto">
        <summary class="btn btn-secondary">Adjust Payslip</summary>
        <form method="post" class="mt-2 p-3 bg-gray-50 rounded" data-authz-module="payroll" data-authz-required="admin" data-authz-action="Adjust payslip and re-release">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="adjust_payslip" />
          <div class="grid md:grid-cols-2 gap-2">
            <label class="block text-xs">Type
              <select name="type" class="input-text w-full">
                <option value="earning">Earning (+)</option>
                <option value="deduction">Deduction (+)</option>
              </select>
            </label>
            <label class="block text-xs">Amount
              <input type="number" step="0.01" min="0.01" name="amount" class="input-text w-full" required />
            </label>
            <label class="block text-xs">Code
              <input type="text" name="code" class="input-text w-full" placeholder="ADJ" />
            </label>
            <label class="block text-xs">Label
              <input type="text" name="label" class="input-text w-full" placeholder="Manual Adjustment" />
            </label>
            <label class="block text-xs md:col-span-2">Reason
              <input type="text" name="reason" class="input-text w-full" required placeholder="Explain why this adjustment is needed" />
            </label>
          </div>
          <div class="mt-2 text-right">
            <button type="submit" class="btn btn-primary">Apply Adjustment</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php if (!$canAdmin && $ownerUserId === $uid): ?>
<div class="card p-4 max-w-3xl mt-4">
  <h2 class="font-semibold mb-2">Report an Issue</h2>
  <form method="post" class="space-y-2">
    <input type="hidden" name="csrf" value="<?= $csrf ?>" />
    <input type="hidden" name="action" value="file_complaint" />
    <label class="block text-sm">Describe your concern
      <textarea name="description" rows="3" class="input-text w-full" required placeholder="What seems incorrect in this payslip?"></textarea>
    </label>
    <div class="text-right">
      <button type="submit" class="btn btn-outline">Submit Complaint</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php';
