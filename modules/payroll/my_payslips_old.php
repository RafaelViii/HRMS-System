<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// Resolve employee id
$empId = null;
try {
  $st = $pdo->prepare('SELECT id FROM employees WHERE user_id = :uid LIMIT 1');
  $st->execute([':uid' => $uid]);
  $empId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) { $empId = 0; }

require_once __DIR__ . '/../../includes/header.php';
if (!$empId) {
  echo '<div class="card p-4 max-w-xl">';
  show_human_error('Your account is not linked to an employee profile.');
  echo '</div>';
  require_once __DIR__ . '/../../includes/footer.php';
  exit;
}

// Handle complaint submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token.');
    header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
    exit;
  }
  $action = $_POST['action'] ?? '';
  if ($action === 'complaint_create') {
    $payslipId = (int)($_POST['payslip_id'] ?? 0);
    $categoryCode = $_POST['category_code'] ?? '';
    $subcategoryCode = $_POST['subcategory_code'] ?? '';
    $priority = $_POST['priority'] ?? 'normal';
    $description = trim((string)($_POST['description'] ?? ''));
    if ($payslipId <= 0 || $description === '') {
      flash_error('Select a payslip and provide a description.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    // Validate ownership against the payslips table
    $ownStmt = $pdo->prepare('SELECT ps.employee_id, ps.payroll_run_id FROM payslips ps WHERE ps.id = :pid LIMIT 1');
    try { $ownStmt->execute([':pid' => $payslipId]); $own = $ownStmt->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $own = null; }
    if (!$own) {
      flash_error('Payslip not found.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    // Resolve employee matches current user
    $empCheck = $pdo->prepare('SELECT id FROM employees WHERE id = :eid AND user_id = :uid');
    $empCheck->execute([':eid' => (int)$own['employee_id'], ':uid' => $uid]);
    if (!(int)($empCheck->fetchColumn() ?: 0)) {
      flash_error('You cannot file a complaint for this payslip.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    $runId = (int)($own['payroll_run_id'] ?? 0);
    $resolvedCategory = payroll_resolve_complaint_category($categoryCode, $subcategoryCode);
    if (!$resolvedCategory['valid']) {
      flash_error('Select a category and topic for the complaint.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    $priorities = payroll_get_complaint_priorities();
    $priority = array_key_exists($priority, $priorities) ? $priority : 'normal';
    $issueLabel = $resolvedCategory['label'];
    $complaintId = payroll_log_complaint($pdo, $runId, (int)$own['employee_id'], $issueLabel, $description, $uid, null, $resolvedCategory['category_code'], $resolvedCategory['subcategory_code'], $priority);
    if ($complaintId) { flash_success('Complaint submitted.'); } else { flash_error('Unable to log complaint.'); }
    header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
    exit;
  }
}

$complaintCategories = payroll_get_complaint_categories();
$complaintPriorities = payroll_get_complaint_priorities();
$rows = [];
try {
  $q = $pdo->prepare("SELECT ps.id, ps.net_pay, ps.released_at, ps.period_start, ps.period_end, ps.status,
                             COALESCE(ps.released_at, ps.updated_at, ps.created_at) AS sort_value
                        FROM payslips ps
                       WHERE ps.employee_id = :eid
                         AND (ps.released_at IS NOT NULL OR ps.status = 'released')
                       ORDER BY sort_value DESC, ps.id DESC
                       LIMIT 100");
  $q->execute([':eid' => $empId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }
?>
<div class="card p-4">
  <h1 class="text-xl font-semibold mb-3">My Payslips</h1>
  <details class="mb-4">
    <summary class="cursor-pointer text-blue-700">File a Payroll Complaint</summary>
    <form method="post" class="grid gap-3 md:grid-cols-2 text-sm mt-2">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
      <input type="hidden" name="action" value="complaint_create" />
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Payslip</span>
        <select name="payslip_id" class="input-text w-full" required>
          <option value="">Select a payslip…</option>
          <?php foreach ($rows as $r): ?>
            <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars(($r['period_start'] ?? '') . ' to ' . ($r['period_end'] ?? '')) ?> — Net: <?= number_format((float)$r['net_pay'], 2) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Category</span>
        <select name="category_code" id="complaintCategory" class="input-text w-full" required>
          <option value="">Select a category…</option>
          <?php foreach ($complaintCategories as $catCode => $catData): ?>
            <option value="<?= htmlspecialchars($catCode) ?>"><?= htmlspecialchars($catData['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Topic</span>
        <select name="subcategory_code" id="complaintSubcategory" class="input-text w-full" required>
          <option value="">Select a topic…</option>
          <?php foreach ($complaintCategories as $catCode => $catData): ?>
            <?php foreach ($catData['items'] as $subCode => $subLabel): ?>
              <option value="<?= htmlspecialchars($subCode) ?>" data-category="<?= htmlspecialchars($catCode) ?>"><?= htmlspecialchars($subLabel) ?></option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Priority</span>
        <select name="priority" class="input-text w-full">
          <?php foreach ($complaintPriorities as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $code === 'normal' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md:col-span-2 block">
        <span class="text-xs uppercase tracking-wide text-gray-500">Description</span>
        <textarea name="description" class="input-text w-full" rows="3" placeholder="Describe the issue" required></textarea>
      </label>
      <div class="md:col-span-2 flex justify-end">
        <button type="submit" class="btn btn-secondary">Submit Complaint</button>
      </div>
    </form>
  </details>
  <div class="overflow-x-auto">
    <table class="table-basic min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left">Period</th>
          <th class="p-2 text-left">Net Pay</th>
          <th class="p-2 text-left">Released</th>
          <th class="p-2 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="border-t">
          <td class="p-2"><?= htmlspecialchars(($r['period_start'] ?? '') . ' to ' . ($r['period_end'] ?? '')) ?></td>
          <td class="p-2 font-medium"><?= number_format((float)$r['net_pay'], 2) ?></td>
          <?php $releasedLabel = format_datetime_display($r['released_at'] ?? null); ?>
          <td class="p-2 text-gray-600"><?= htmlspecialchars($releasedLabel ?: '—') ?></td>
          <td class="p-2">
            <div class="action-links">
              <a href="<?= BASE_URL ?>/modules/payroll/view?id=<?= (int)$r['id'] ?>" class="text-blue-700">View</a>
              <span class="text-gray-400">|</span>
              <a href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" class="text-red-700" data-no-loader>PDF</a>
            </div>
          </td>
        </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td class="p-3 text-gray-500" colspan="4">No payslips yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const categorySelect = document.getElementById('complaintCategory');
  const subSelect = document.getElementById('complaintSubcategory');
  if (!categorySelect || !subSelect) return;
  const subOptions = Array.from(subSelect.querySelectorAll('option[data-category]'));
  const syncSubcategories = () => {
    const selected = categorySelect.value;
    subSelect.value = '';
    subOptions.forEach((opt) => {
      const matches = !selected || opt.dataset.category === selected;
      opt.hidden = !matches;
      opt.disabled = !matches;
    });
  };
  syncSubcategories();
  categorySelect.addEventListener('change', syncSubcategories);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php';
