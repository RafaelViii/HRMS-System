<?php
/**
 * Clinic Records — Create new record
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$userName = $user['name'] ?? 'Unknown';

// Get current user's employee ID
$myEmployeeId = null;
$myEmpName = '';
$empStmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
$empStmt->execute([':uid' => $uid]);
$myEmp = $empStmt->fetch(PDO::FETCH_ASSOC);
if ($myEmp) {
    $myEmployeeId = (int)$myEmp['id'];
    $myEmpName = $myEmp['first_name'] . ' ' . $myEmp['last_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $patientType = $_POST['patient_type'] ?? 'employee';
    $employeeId = ($patientType === 'employee') ? ((int)($_POST['employee_id'] ?? 0) ?: null) : null;
    $patientName = trim($_POST['patient_name'] ?? '');
    $recordDate = $_POST['record_date'] ?? date('Y-m-d');

    // If employee, fetch name
    if ($employeeId) {
        $empSt = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = :id AND deleted_at IS NULL');
        $empSt->execute([':id' => $employeeId]);
        $empRow = $empSt->fetch(PDO::FETCH_ASSOC);
        if ($empRow) {
            $patientName = $empRow['first_name'] . ' ' . $empRow['last_name'];
        }
    }

    // Nurse fields
    $hasNurse = !empty($_POST['has_nurse']);
    $nurseEmployeeId = $hasNurse ? ((int)($_POST['nurse_employee_id'] ?? 0) ?: null) : null;
    $nurseDatetime = $hasNurse ? ($_POST['nurse_service_datetime'] ?? null) : null;
    $nurseNotes = $hasNurse ? trim($_POST['nurse_notes'] ?? '') : null;

    // MedTech fields
    $hasMedtech = !empty($_POST['has_medtech']);
    $medtechEmployeeId = $hasMedtech ? ((int)($_POST['medtech_employee_id'] ?? 0) ?: null) : null;
    $medtechDatetime = $hasMedtech ? ($_POST['medtech_pickup_datetime'] ?? null) : null;
    $medtechNotes = $hasMedtech ? trim($_POST['medtech_notes'] ?? '') : null;

    // Validation
    if (empty($patientName)) {
        flash_error('Patient name is required.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/create');
        exit;
    }
    if (!$hasNurse && !$hasMedtech) {
        flash_error('At least one service entry (Nurse or MedTech) is required.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/create');
        exit;
    }

    $status = ($hasNurse && $hasMedtech) ? 'completed' : 'open';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clinic_records
            (employee_id, patient_name, record_date, status,
             nurse_employee_id, nurse_service_datetime, nurse_notes,
             medtech_employee_id, medtech_pickup_datetime, medtech_notes,
             created_by, created_at, updated_at)
            VALUES (:emp_id, :name, :rdate, :status,
             :nurse_id, :nurse_dt, :nurse_notes,
             :mt_id, :mt_dt, :mt_notes,
             :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':name' => $patientName,
            ':rdate' => $recordDate,
            ':status' => $status,
            ':nurse_id' => $nurseEmployeeId,
            ':nurse_dt' => $nurseDatetime ?: null,
            ':nurse_notes' => $nurseNotes ?: null,
            ':mt_id' => $medtechEmployeeId,
            ':mt_dt' => $medtechDatetime ?: null,
            ':mt_notes' => $medtechNotes ?: null,
            ':created_by' => $uid,
        ]);
        $newId = $stmt->fetchColumn();

        // Insert history
        $histStmt = $pdo->prepare("
            INSERT INTO clinic_record_history (clinic_record_id, action, changed_by, changed_by_name, new_values, created_at)
            VALUES (:rid, 'created', :uid, :uname, :vals, CURRENT_TIMESTAMP)
        ");
        $histStmt->execute([
            ':rid' => $newId,
            ':uid' => $uid,
            ':uname' => $userName,
            ':vals' => json_encode([
                'patient_name' => $patientName,
                'nurse_employee_id' => $nurseEmployeeId,
                'medtech_employee_id' => $medtechEmployeeId,
                'status' => $status,
            ]),
        ]);

        action_log('clinic_records', 'create_record', 'success', [
            'record_id' => $newId,
            'patient_name' => $patientName,
        ]);

        flash_success('Clinic record created successfully.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/index');
        exit;
    } catch (Throwable $e) {
        sys_log('MED1002', 'Failed to create clinic record: ' . $e->getMessage(), [
            'module' => 'clinic_records', 'file' => __FILE__, 'line' => __LINE__,
        ]);
        flash_error('Failed to create record. Please try again.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/create');
        exit;
    }
}

$pageTitle = 'New Clinic Record';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">New Clinic Record</h1>
    <p class="text-sm text-slate-500 mt-0.5">Log a nurse or medtech service record</p>
  </div>
  <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="btn btn-outline text-sm spa">Back to Records</a>
</div>

<form method="post" class="max-w-3xl">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

  <!-- Patient Information -->
  <div class="card mb-5">
    <div class="card-header"><span class="font-semibold">Patient Information</span></div>
    <div class="card-body space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Patient Type</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" name="patient_type" value="employee" checked onchange="togglePatientType(this.value)" class="text-indigo-600 focus:ring-indigo-500">
            <span class="text-sm text-slate-700">Employee</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" name="patient_type" value="external" onchange="togglePatientType(this.value)" class="text-indigo-600 focus:ring-indigo-500">
            <span class="text-sm text-slate-700">External Patient</span>
          </label>
        </div>
      </div>

      <div id="patientEmployeeField">
        <label class="required block text-sm font-medium text-slate-700 mb-1">Search Employee</label>
        <input type="text" id="patientSearch" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Type name or employee code..." autocomplete="off">
        <div id="patientResults" class="mt-1 max-h-40 overflow-y-auto border border-slate-200 rounded-lg bg-white" style="display:none;"></div>
        <input type="hidden" name="employee_id" id="patientEmployeeId" value="">
        <input type="hidden" name="patient_name" id="patientNameHidden" value="">
        <div id="patientSelected" class="mt-1 text-sm text-emerald-600" style="display:none;"></div>
      </div>

      <div id="patientExternalField" style="display:none;">
        <label class="required block text-sm font-medium text-slate-700 mb-1">Patient Name</label>
        <input type="text" name="patient_name_ext" id="patientNameExt" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Full name of external patient">
      </div>

      <div>
        <label class="required block text-sm font-medium text-slate-700 mb-1">Record Date</label>
        <input type="date" name="record_date" value="<?= date('Y-m-d') ?>" class="border border-slate-200 rounded-lg px-3 py-2 text-sm" required>
      </div>
    </div>
  </div>

  <!-- Nurse Service -->
  <div class="card mb-5">
    <div class="card-header flex items-center gap-3">
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="has_nurse" value="1" id="chkNurse" checked onchange="toggleSection('nurse', this.checked)" class="text-indigo-600 focus:ring-indigo-500 rounded">
        <span class="font-semibold">Nurse Service</span>
      </label>
    </div>
    <div class="card-body space-y-4" id="nurseSection">
      <div>
        <label class="required block text-sm font-medium text-slate-700 mb-1">Nurse</label>
        <input type="text" id="nurseSearch" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Search nurse by name..." autocomplete="off">
        <div id="nurseResults" class="mt-1 max-h-40 overflow-y-auto border border-slate-200 rounded-lg bg-white" style="display:none;"></div>
        <input type="hidden" name="nurse_employee_id" id="nurseEmployeeId" value="<?= $myEmployeeId ?>">
        <div id="nurseSelected" class="mt-1 text-sm text-emerald-600"><?= $myEmpName ? 'Selected: ' . htmlspecialchars($myEmpName) . ' (you)' : '' ?></div>
      </div>
      <div>
        <label class="required block text-sm font-medium text-slate-700 mb-1">Service Date & Time</label>
        <input type="datetime-local" name="nurse_service_datetime" value="<?= date('Y-m-d\TH:i') ?>" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Nurse Notes</label>
        <textarea name="nurse_notes" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Details about the service provided..."></textarea>
      </div>
    </div>
  </div>

  <!-- MedTech Service -->
  <div class="card mb-5">
    <div class="card-header flex items-center gap-3">
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="has_medtech" value="1" id="chkMedtech" onchange="toggleSection('medtech', this.checked)" class="text-indigo-600 focus:ring-indigo-500 rounded">
        <span class="font-semibold">MedTech Service</span>
      </label>
    </div>
    <div class="card-body space-y-4" id="medtechSection" style="display:none;">
      <div>
        <label class="required block text-sm font-medium text-slate-700 mb-1">MedTech</label>
        <input type="text" id="medtechSearch" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Search medtech by name..." autocomplete="off">
        <div id="medtechResults" class="mt-1 max-h-40 overflow-y-auto border border-slate-200 rounded-lg bg-white" style="display:none;"></div>
        <input type="hidden" name="medtech_employee_id" id="medtechEmployeeId" value="">
        <div id="medtechSelected" class="mt-1 text-sm text-emerald-600"></div>
      </div>
      <div>
        <label class="required block text-sm font-medium text-slate-700 mb-1">Pickup Date & Time</label>
        <input type="datetime-local" name="medtech_pickup_datetime" value="<?= date('Y-m-d\TH:i') ?>" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">MedTech Notes</label>
        <textarea name="medtech_notes" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="Details about the medtech service..."></textarea>
      </div>
    </div>
  </div>

  <div class="flex items-center gap-3">
    <button type="submit" class="btn btn-primary">Save Record</button>
    <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="btn btn-outline spa">Cancel</a>
  </div>
</form>

<script>
const _baseUrl = '<?= BASE_URL ?>';

function togglePatientType(type) {
    document.getElementById('patientEmployeeField').style.display = type === 'employee' ? '' : 'none';
    document.getElementById('patientExternalField').style.display = type === 'external' ? '' : 'none';
}

function toggleSection(section, show) {
    document.getElementById(section + 'Section').style.display = show ? '' : 'none';
}

// Before form submit, ensure patient_name is set properly
document.querySelector('form').addEventListener('submit', function(e) {
    const type = document.querySelector('input[name="patient_type"]:checked').value;
    if (type === 'external') {
        document.getElementById('patientNameHidden').value = document.getElementById('patientNameExt').value;
        document.getElementById('patientEmployeeId').value = '';
    }
    // For employee type, patientNameHidden is set by autocomplete
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Employee autocomplete setup
function setupAutocomplete(searchId, resultsId, hiddenId, selectedId, extraHiddenId) {
    const search = document.getElementById(searchId);
    const results = document.getElementById(resultsId);
    if (!search) return;

    let timer;
    search.addEventListener('input', function() {
        clearTimeout(timer);
        const val = this.value.trim();
        if (val.length < 2) { results.style.display = 'none'; return; }

        timer = setTimeout(() => {
            fetch(_baseUrl + '/modules/clinic_records/api_employee_search?q=' + encodeURIComponent(val))
                .then(r => r.json())
                .then(emps => {
                    let h = '';
                    emps.forEach(e => {
                        const name = e.first_name + ' ' + e.last_name;
                        h += '<div class="px-3 py-2 hover:bg-indigo-50 cursor-pointer text-sm" data-id="' + e.id + '" data-name="' + name.replace(/"/g, '&quot;') + '">';
                        h += '<span class="font-medium">' + escapeHtml(name) + '</span>';
                        h += ' <span class="text-xs text-slate-400">' + escapeHtml(e.employee_code || '') + '</span>';
                        if (e.position_name) h += ' <span class="text-xs text-slate-400">· ' + escapeHtml(e.position_name) + '</span>';
                        h += '</div>';
                    });
                    if (!emps.length) h = '<div class="px-3 py-2 text-sm text-slate-400">No results</div>';
                    results.innerHTML = h;
                    results.style.display = '';

                    results.querySelectorAll('[data-id]').forEach(el => {
                        el.addEventListener('click', function() {
                            document.getElementById(hiddenId).value = this.dataset.id;
                            search.value = this.dataset.name;
                            results.style.display = 'none';
                            document.getElementById(selectedId).textContent = 'Selected: ' + this.dataset.name;
                            document.getElementById(selectedId).style.display = '';
                            if (extraHiddenId) {
                                document.getElementById(extraHiddenId).value = this.dataset.name;
                            }
                        });
                    });
                });
        }, 300);
    });

    search.addEventListener('blur', function() {
        setTimeout(() => { results.style.display = 'none'; }, 200);
    });
}

setupAutocomplete('patientSearch', 'patientResults', 'patientEmployeeId', 'patientSelected', 'patientNameHidden');
setupAutocomplete('nurseSearch', 'nurseResults', 'nurseEmployeeId', 'nurseSelected', null);
setupAutocomplete('medtechSearch', 'medtechResults', 'medtechEmployeeId', 'medtechSelected', null);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
