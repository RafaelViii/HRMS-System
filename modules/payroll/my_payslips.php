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

if (!$empId) {
  require_once __DIR__ . '/../../includes/header.php';
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
    // Validate ownership
    $ownStmt = $pdo->prepare('SELECT ps.employee_id, ps.payroll_run_id FROM payslips ps WHERE ps.id = :pid LIMIT 1');
    try { $ownStmt->execute([':pid' => $payslipId]); $own = $ownStmt->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $own = null; }
    if (!$own) {
      flash_error('Payslip not found.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    $empCheck = $pdo->prepare('SELECT id FROM employees WHERE id = :eid AND user_id = :uid');
    $empCheck->execute([':eid' => (int)$own['employee_id'], ':uid' => $uid]);
    if (!(int)($empCheck->fetchColumn() ?: 0)) {
      flash_error('You cannot file a complaint for this payslip.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    $runId = (int)($own['payroll_run_id'] ?? 0);
    
    // Check for existing open complaint on this payslip
    $existingCheck = $pdo->prepare('SELECT id FROM payroll_complaints WHERE payslip_id = :pid AND status NOT IN (:resolved, :rejected) LIMIT 1');
    $existingCheck->execute([':pid' => $payslipId, ':resolved' => 'resolved', ':rejected' => 'rejected']);
    if ($existingCheck->fetchColumn()) {
      flash_error('You already have an open complaint for this payslip. Please wait for it to be resolved before filing another.');
      header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
      exit;
    }
    
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
    if ($complaintId) { 
      flash_success('Complaint submitted successfully. You can track its progress in the Complaints tab.'); 
    } else { 
      flash_error('Unable to log complaint.'); 
    }
    header('Location: ' . BASE_URL . '/modules/payroll/my_payslips');
    exit;
  }
}

$activeTab = $_GET['tab'] ?? 'payslips';
$complaintCategories = payroll_get_complaint_categories();
$complaintPriorities = payroll_get_complaint_priorities();

// Fetch payslips
$payslips = [];
try {
  $q = $pdo->prepare("SELECT ps.id, ps.net_pay, ps.released_at, ps.period_start, ps.period_end, ps.status,
                             ps.basic_pay, ps.total_earnings, ps.total_deductions,
                             COALESCE(ps.released_at, ps.updated_at, ps.created_at) AS sort_value
                        FROM payslips ps
                       WHERE ps.employee_id = :eid
                         AND (ps.released_at IS NOT NULL OR ps.status = 'released')
                       ORDER BY sort_value DESC, ps.id DESC
                       LIMIT 100");
  $q->execute([':eid' => $empId]);
  $payslips = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $payslips = []; }

// Fetch complaints
$complaints = [];
try {
  $cq = $pdo->prepare("SELECT pc.id, pc.ticket_code, pc.subject, pc.description, pc.status, 
                              pc.priority, pc.submitted_at, pc.resolved_at, pc.resolution_notes,
                              pc.adjustment_amount, pc.adjustment_type, pc.adjustment_label,
                              pr.period_start, pr.period_end,
                              ps.id as payslip_id, ps.net_pay,
                              u.full_name as assigned_to_name
                         FROM payroll_complaints pc
                         JOIN payroll_runs pr ON pr.id = pc.payroll_run_id
                         LEFT JOIN payslips ps ON ps.id = pc.payslip_id
                         LEFT JOIN users u ON u.id = pc.assigned_to
                        WHERE pc.employee_id = :eid
                        ORDER BY pc.submitted_at DESC
                        LIMIT 50");
  $cq->execute([':eid' => $empId]);
  $complaints = $cq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $complaints = []; }

$pageTitle = 'My Payslips';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
  <!-- Header -->
  <div>
    <h1 class="text-2xl font-bold text-gray-900">My Payroll</h1>
    <p class="text-sm text-gray-500 mt-1">View your payslips and track complaint status</p>
  </div>

  <!-- Tabs -->
  <div class="border-b border-gray-200">
    <nav class="-mb-px flex space-x-8">
      <a href="?tab=payslips" class="<?= $activeTab === 'payslips' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
        Payslips
      </a>
      <a href="?tab=complaints" class="<?= $activeTab === 'complaints' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
        My Complaints
        <?php if (count(array_filter($complaints, fn($c) => in_array($c['status'], ['pending', 'in_review'])))): ?>
          <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
            <?= count(array_filter($complaints, fn($c) => in_array($c['status'], ['pending', 'in_review']))) ?>
          </span>
        <?php endif; ?>
      </a>
    </nav>
  </div>

  <?php if ($activeTab === 'payslips'): ?>
    <!-- Payslips Tab -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="p-6 border-b border-gray-200">
        <details class="group">
          <summary class="cursor-pointer text-blue-600 font-medium hover:text-blue-700 flex items-center">
            <svg class="w-5 h-5 mr-2 transform group-open:rotate-90 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            File a Payroll Complaint
          </summary>
          <form method="post" class="mt-4 grid gap-4 md:grid-cols-2 p-4 bg-gray-50 rounded-lg">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
            <input type="hidden" name="action" value="complaint_create" />
            
            <label class="block md:col-span-2">
              <span class="block text-sm font-medium text-gray-700 mb-1">Select Payslip *</span>
              <select name="payslip_id" class="input-text w-full" required>
                <option value="">Choose the payslip with the issue...</option>
                <?php foreach ($payslips as $r): ?>
                  <option value="<?= (int)$r['id'] ?>">
                    <?= htmlspecialchars(date('M d', strtotime($r['period_start'])) . ' - ' . date('M d, Y', strtotime($r['period_end']))) ?> — 
                    Net: ₱<?= number_format((float)$r['net_pay'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block">
              <span class="block text-sm font-medium text-gray-700 mb-1">Category *</span>
              <select name="category_code" id="complaintCategory" class="input-text w-full" required>
                <option value="">Select category...</option>
                <?php foreach ($complaintCategories as $catCode => $catData): ?>
                  <option value="<?= htmlspecialchars($catCode) ?>"><?= htmlspecialchars($catData['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block">
              <span class="block text-sm font-medium text-gray-700 mb-1">Topic *</span>
              <select name="subcategory_code" id="complaintSubcategory" class="input-text w-full" required>
                <option value="">Select topic...</option>
                <?php foreach ($complaintCategories as $catCode => $catData): ?>
                  <?php foreach ($catData['items'] as $subCode => $subLabel): ?>
                    <option value="<?= htmlspecialchars($subCode) ?>" data-category="<?= htmlspecialchars($catCode) ?>"><?= htmlspecialchars($subLabel) ?></option>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block md:col-span-2">
              <span class="block text-sm font-medium text-gray-700 mb-1">Priority</span>
              <select name="priority" class="input-text w-full">
                <?php foreach ($complaintPriorities as $code => $label): ?>
                  <option value="<?= htmlspecialchars($code) ?>" <?= $code === 'normal' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="md:col-span-2 block">
              <span class="block text-sm font-medium text-gray-700 mb-1">Description *</span>
              <textarea name="description" class="input-text w-full" rows="4" placeholder="Please describe the issue in detail..." required></textarea>
            </label>

            <div class="md:col-span-2 flex justify-end gap-3">
              <button type="button" onclick="this.closest('details').removeAttribute('open')" class="btn btn-outline">Cancel</button>
              <button type="submit" class="btn btn-primary">Submit Complaint</button>
            </div>
          </form>
        </details>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Basic Pay</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Earnings</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Deductions</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net Pay</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Released</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($payslips as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                <?= htmlspecialchars(date('M d', strtotime($r['period_start'])) . ' - ' . date('M d, Y', strtotime($r['period_end']))) ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 text-right">₱<?= number_format((float)$r['basic_pay'], 2) ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 text-right">₱<?= number_format((float)$r['total_earnings'], 2) ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 text-right">₱<?= number_format((float)$r['total_deductions'], 2) ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 text-right">₱<?= number_format((float)$r['net_pay'], 2) ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                <?= htmlspecialchars($r['released_at'] ? date('M d, Y g:i A', strtotime($r['released_at'])) : '—') ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                <div class="flex gap-3">
                  <a href="<?= BASE_URL ?>/modules/payroll/view?id=<?= (int)$r['id'] ?>" class="text-blue-600 hover:text-blue-800 font-medium">View</a>
                  <a href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" class="text-red-600 hover:text-red-800 font-medium" data-no-loader>PDF</a>
                </div>
              </td>
            </tr>
            <?php endforeach; if (!$payslips): ?>
              <tr><td class="px-6 py-4 text-center text-gray-500" colspan="7">No payslips available yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php else: ?>
    <!-- Complaints Tab -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Complaint History</h2>
        <?php if ($complaints): ?>
          <div class="space-y-4">
            <?php foreach ($complaints as $c): ?>
              <?php
                $statusClass = [
                  'pending' => 'bg-amber-100 text-amber-800',
                  'in_review' => 'bg-blue-100 text-blue-800',
                  'resolved' => 'bg-green-100 text-green-800',
                  'rejected' => 'bg-red-100 text-red-800',
                ][$c['status']] ?? 'bg-gray-100 text-gray-800';
                
                $priorityClass = [
                  'low' => 'text-gray-600',
                  'normal' => 'text-blue-600',
                  'high' => 'text-orange-600',
                  'urgent' => 'text-red-600',
                ][$c['priority']] ?? 'text-gray-600';
              ?>
              <div class="border border-gray-200 rounded-lg p-5 hover:border-gray-300 transition-colors">
                <div class="flex items-start justify-between mb-3">
                  <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                      <h3 class="text-sm font-semibold text-gray-900">
                        <?= htmlspecialchars($c['ticket_code'] ?: 'Ticket #' . $c['id']) ?>
                      </h3>
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusClass ?>">
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $c['status']))) ?>
                      </span>
                      <span class="text-xs font-medium <?= $priorityClass ?>">
                        <?= htmlspecialchars(ucfirst($c['priority'])) ?> Priority
                      </span>
                    </div>
                    <p class="text-sm text-gray-700 mb-2"><?= htmlspecialchars($c['subject'] ?: $c['description']) ?></p>
                    <div class="text-xs text-gray-500 space-y-1">
                      <p>Period: <?= htmlspecialchars(date('M d', strtotime($c['period_start'])) . ' - ' . date('M d, Y', strtotime($c['period_end']))) ?></p>
                      <p>Submitted: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($c['submitted_at']))) ?></p>
                      <?php if ($c['assigned_to_name']): ?>
                        <p>Assigned to: <?= htmlspecialchars($c['assigned_to_name']) ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <?php if ($c['status'] === 'resolved' || $c['status'] === 'rejected'): ?>
                  <div class="mt-4 pt-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Resolution</h4>
                    <?php if ($c['resolution_notes']): ?>
                      <p class="text-sm text-gray-700 mb-2"><?= nl2br(htmlspecialchars($c['resolution_notes'])) ?></p>
                    <?php endif; ?>
                    
                    <?php if ($c['adjustment_amount'] && $c['adjustment_amount'] > 0): ?>
                      <div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex items-center gap-2 mb-1">
                          <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                          </svg>
                          <span class="text-sm font-medium text-green-900">Adjustment Approved</span>
                        </div>
                        <p class="text-sm text-green-800">
                          <?= htmlspecialchars(ucfirst($c['adjustment_type'])) ?>: 
                          <span class="font-semibold">₱<?= number_format((float)$c['adjustment_amount'], 2) ?></span>
                          <?php if ($c['adjustment_label']): ?>
                            <br><span class="text-xs"><?= htmlspecialchars($c['adjustment_label']) ?></span>
                          <?php endif; ?>
                        </p>
                        <p class="text-xs text-green-700 mt-1">This adjustment will be applied to your next payroll.</p>
                      </div>
                    <?php endif; ?>
                    
                    <?php if ($c['resolved_at']): ?>
                      <p class="text-xs text-gray-500 mt-2">Resolved on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($c['resolved_at']))) ?></p>
                    <?php endif; ?>
                  </div>
                <?php elseif ($c['status'] === 'in_review'): ?>
                  <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center gap-2">
                      <svg class="w-4 h-4 text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                      </svg>
                      <span class="text-sm font-medium text-blue-900">Under Review</span>
                    </div>
                    <p class="text-sm text-blue-800 mt-1">Our team is currently reviewing your complaint. You'll be notified once resolved.</p>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No complaints filed</h3>
            <p class="mt-1 text-sm text-gray-500">You haven't filed any payroll complaints yet.</p>
            <div class="mt-6">
              <a href="?tab=payslips" class="btn btn-primary">Go to Payslips</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
