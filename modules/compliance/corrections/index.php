<?php
/**
 * Data Correction Requests — Employee Self-Service
 * RA 10173 Right to Rectification
 * Employees can submit requests to correct their personal data.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);

// Get employee record for current user
$empStmt = $pdo->prepare("SELECT * FROM employees WHERE user_id = :uid LIMIT 1");
$empStmt->execute([':uid' => $uid]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    flash_error('No employee record found for your account.');
    header('Location: ' . BASE_URL . '/index');
    exit;
}

$employeeId = (int)$employee['id'];

// Categories of correctable fields
$categories = [
    'personal_info' => [
        'label' => 'Personal Information',
        'fields' => ['first_name', 'last_name', 'date_of_birth', 'gender', 'marital_status', 'nationality'],
    ],
    'contact_info' => [
        'label' => 'Contact Information',
        'fields' => ['email', 'phone', 'address'],
    ],
    'employment_info' => [
        'label' => 'Employment Information',
        'fields' => ['employee_code', 'hire_date', 'employment_type'],
    ],
    'emergency_contact' => [
        'label' => 'Emergency Contact',
        'fields' => ['emergency_contact_name', 'emergency_contact_phone'],
    ],
];

// Handle POST — submit correction request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $category = trim($_POST['category'] ?? '');
    $fieldName = trim($_POST['field_name'] ?? '');
    $requestedValue = trim($_POST['requested_value'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    // Validate
    $errors = [];
    if (!$category || !isset($categories[$category])) {
        $errors[] = 'Invalid category.';
    }
    if (!$fieldName) {
        $errors[] = 'Field name is required.';
    } elseif (isset($categories[$category]) && !in_array($fieldName, $categories[$category]['fields'], true)) {
        $errors[] = 'Invalid field for this category.';
    }
    if (!$requestedValue) {
        $errors[] = 'Requested (correct) value is required.';
    }
    if (!$reason) {
        $errors[] = 'Reason for correction is required.';
    }

    if (empty($errors)) {
        // Get current value from employee record
        $currentValue = $employee[$fieldName] ?? '';

        try {
            $ins = $pdo->prepare("
                INSERT INTO data_correction_requests
                    (employee_id, requested_by, category, field_name, current_value, requested_value, reason, status)
                VALUES (:eid, :uid, :cat, :field, :cur, :req, :reason, 'pending')
            ");
            $ins->execute([
                ':eid' => $employeeId,
                ':uid' => $uid,
                ':cat' => $category,
                ':field' => $fieldName,
                ':cur' => $currentValue,
                ':req' => $requestedValue,
                ':reason' => $reason,
            ]);

            action_log('compliance', 'data_correction_request', 'success', [
                'employee_id' => $employeeId,
                'field' => $fieldName,
                'category' => $category,
            ]);

            flash_success('Data correction request submitted successfully. An administrator will review your request.');
        } catch (Throwable $e) {
            sys_log('COMPLIANCE-DCR', 'Failed to submit correction request: ' . $e->getMessage(), ['module' => 'compliance']);
            flash_error('Failed to submit correction request. Please try again.');
        }
    } else {
        flash_error(implode(' ', $errors));
    }

    header('Location: ' . BASE_URL . '/modules/compliance/corrections/index');
    exit;
}

// Fetch existing requests for this employee
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM data_correction_requests WHERE employee_id = :eid");
$countStmt->execute([':eid' => $employeeId]);
$totalRequests = (int)$countStmt->fetchColumn();
[$offset, $perPage, $page, $pages] = paginate($totalRequests, $page, $perPage);

$listStmt = $pdo->prepare("
    SELECT dcr.*, u.full_name AS reviewer_name
    FROM data_correction_requests dcr
    LEFT JOIN users u ON u.id = dcr.reviewed_by
    WHERE dcr.employee_id = :eid
    ORDER BY dcr.created_at DESC
    LIMIT :lim OFFSET :off
");
$listStmt->bindValue(':eid', $employeeId, PDO::PARAM_INT);
$listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$requests = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'pending' => 'bg-amber-100 text-amber-700',
    'approved' => 'bg-emerald-100 text-emerald-700',
    'rejected' => 'bg-red-100 text-red-700',
    'applied' => 'bg-blue-100 text-blue-700',
];

$pageTitle = 'Data Correction Requests';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Data Correction Requests</h1>
      <p class="text-sm text-slate-500 mt-0.5">Request corrections to your personal information (RA 10173 — Right to Rectification)</p>
    </div>
    <button onclick="document.getElementById('newRequestModal').classList.remove('hidden')" class="btn btn-primary">
      + New Correction Request
    </button>
  </div>

  <!-- Existing Requests -->
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>Your Requests (<?= $totalRequests ?>)</span>
    </div>
    <div class="card-body">
      <?php if (empty($requests)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No correction requests yet.</p>
      <?php else: ?>
        <table class="table-basic">
          <thead>
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Field</th>
              <th>Requested Value</th>
              <th>Status</th>
              <th>Review Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($requests as $req): ?>
            <tr>
              <td class="text-sm text-slate-500"><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
              <td class="text-sm"><?= htmlspecialchars($categories[$req['category']]['label'] ?? $req['category']) ?></td>
              <td class="text-sm font-medium"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($req['field_name']))) ?></td>
              <td class="text-sm"><?= htmlspecialchars($req['requested_value']) ?></td>
              <td>
                <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusBadge[$req['status']] ?? 'bg-slate-100 text-slate-700' ?>">
                  <?= ucfirst($req['status']) ?>
                </span>
              </td>
              <td class="text-sm text-slate-500">
                <?php if ($req['review_notes']): ?>
                  <?= htmlspecialchars($req['review_notes']) ?>
                  <span class="text-xs text-slate-400 block mt-0.5">— <?= htmlspecialchars($req['reviewer_name'] ?? 'Admin') ?></span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($pages > 1): ?>
        <div class="mt-4 flex justify-center gap-1">
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a href="<?= BASE_URL ?>/modules/compliance/corrections/index?page=<?= $p ?>"
               class="spa px-3 py-1 text-sm rounded <?= $p === $page ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
              <?= $p ?>
            </a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- New Request Modal -->
<div id="newRequestModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4">
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <h3 class="text-lg font-semibold text-slate-900">New Correction Request</h3>
      <button onclick="document.getElementById('newRequestModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">&times;</button>
    </div>
    <form method="post" action="<?= BASE_URL ?>/modules/compliance/corrections/index" class="px-6 py-4 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <div>
        <label class="block text-sm font-medium text-slate-700 required">Category</label>
        <select name="category" id="dcr_category" class="input-text mt-1 w-full" required onchange="updateFieldOptions()">
          <option value="">Select a category...</option>
          <?php foreach ($categories as $key => $cat): ?>
            <option value="<?= $key ?>"><?= htmlspecialchars($cat['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 required">Field to Correct</label>
        <select name="field_name" id="dcr_field" class="input-text mt-1 w-full" required>
          <option value="">Select a field...</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 required">Correct Value</label>
        <input type="text" name="requested_value" class="input-text mt-1 w-full" required placeholder="Enter the correct information">
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 required">Reason for Correction</label>
        <textarea name="reason" class="input-text mt-1 w-full" rows="3" required placeholder="Explain why this correction is needed"></textarea>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" onclick="document.getElementById('newRequestModal').classList.add('hidden')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Request</button>
      </div>
    </form>
  </div>
</div>

<script>
const categoryFields = <?= json_encode(array_map(fn($c) => $c['fields'], $categories)) ?>;
function updateFieldOptions() {
  const cat = document.getElementById('dcr_category').value;
  const fieldSel = document.getElementById('dcr_field');
  fieldSel.innerHTML = '<option value="">Select a field...</option>';
  if (cat && categoryFields[cat]) {
    categoryFields[cat].forEach(f => {
      const opt = document.createElement('option');
      opt.value = f;
      opt.textContent = f.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      fieldSel.appendChild(opt);
    });
  }
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
