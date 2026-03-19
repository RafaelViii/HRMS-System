<?php
/**
 * Clinic Records — Edit record (only if medtech not yet assigned, or admin)
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

$canManage = user_has_access($uid, 'healthcare', 'clinic_records', 'manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    flash_error('Record not found.');
    header('Location: ' . BASE_URL . '/modules/clinic_records/index');
    exit;
}

// Fetch record
$stmt = $pdo->prepare('SELECT * FROM clinic_records WHERE id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    flash_error('Record not found.');
    header('Location: ' . BASE_URL . '/modules/clinic_records/index');
    exit;
}

// Check: only nurse can edit if no medtech, or admin can always edit
$myEmployeeId = null;
$empStmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
$empStmt->execute([':uid' => $uid]);
$myEmp = $empStmt->fetch(PDO::FETCH_ASSOC);
if ($myEmp) $myEmployeeId = (int)$myEmp['id'];

$isNurse = $myEmployeeId && (int)$record['nurse_employee_id'] === $myEmployeeId;
$hasMedtech = !empty($record['medtech_employee_id']);

if (!$canManage && ($hasMedtech || !$isNurse)) {
    flash_error('Cannot edit: MedTech is already assigned or you are not the assigned nurse.');
    header('Location: ' . BASE_URL . '/modules/clinic_records/index');
    exit;
}

// Fetch nurse/medtech names for display
$nurseName = '';
if ($record['nurse_employee_id']) {
    $ns = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = :id');
    $ns->execute([':id' => $record['nurse_employee_id']]);
    $nr = $ns->fetch(PDO::FETCH_ASSOC);
    if ($nr) $nurseName = $nr['first_name'] . ' ' . $nr['last_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $patientName = trim($_POST['patient_name'] ?? '');
    $recordDate = $_POST['record_date'] ?? $record['record_date'];

    $nurseEmployeeId = (int)($_POST['nurse_employee_id'] ?? 0) ?: null;
    $nurseDatetime = $_POST['nurse_service_datetime'] ?? null;
    $nurseNotes = trim($_POST['nurse_notes'] ?? '');

    if (empty($patientName)) {
        flash_error('Patient name is required.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/edit?id=' . $id);
        exit;
    }

    try {
        // Track changes for history
        $oldValues = [
            'patient_name' => $record['patient_name'],
            'record_date' => $record['record_date'],
            'nurse_employee_id' => $record['nurse_employee_id'],
            'nurse_service_datetime' => $record['nurse_service_datetime'],
            'nurse_notes' => $record['nurse_notes'],
        ];

        $upd = $pdo->prepare("
            UPDATE clinic_records
            SET patient_name = :name, record_date = :rdate,
                nurse_employee_id = :nurse_id, nurse_service_datetime = :nurse_dt, nurse_notes = :nurse_notes,
                updated_by = :uid, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $upd->execute([
            ':name' => $patientName,
            ':rdate' => $recordDate,
            ':nurse_id' => $nurseEmployeeId,
            ':nurse_dt' => $nurseDatetime ?: null,
            ':nurse_notes' => $nurseNotes ?: null,
            ':uid' => $uid,
            ':id' => $id,
        ]);

        $newValues = [
            'patient_name' => $patientName,
            'record_date' => $recordDate,
            'nurse_employee_id' => $nurseEmployeeId,
            'nurse_service_datetime' => $nurseDatetime,
            'nurse_notes' => $nurseNotes,
        ];

        // History entry
        $histStmt = $pdo->prepare("
            INSERT INTO clinic_record_history (clinic_record_id, action, changed_by, changed_by_name, old_values, new_values, created_at)
            VALUES (:rid, 'edited', :uid, :uname, :old, :new, CURRENT_TIMESTAMP)
        ");
        $histStmt->execute([
            ':rid' => $id,
            ':uid' => $uid,
            ':uname' => $userName,
            ':old' => json_encode($oldValues),
            ':new' => json_encode($newValues),
        ]);

        action_log('clinic_records', 'edit_record', 'success', [
            'record_id' => $id,
            'patient_name' => $patientName,
        ]);

        flash_success('Clinic record updated successfully.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/index');
        exit;
    } catch (Throwable $e) {
        sys_log('MED1003', 'Failed to update clinic record: ' . $e->getMessage(), [
            'module' => 'clinic_records', 'file' => __FILE__, 'line' => __LINE__,
        ]);
        flash_error('Failed to update record.');
        header('Location: ' . BASE_URL . '/modules/clinic_records/edit?id=' . $id);
        exit;
    }
}

$pageTitle = 'Edit Clinic Record';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h1 class="text-lg sm:text-xl font-bold text-slate-900">Edit Clinic Record #<?= $id ?></h1>
    <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Update patient and nurse service details</p>
  </div>
  <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="btn btn-outline text-sm spa">Back to Records</a>
</div>

<form method="post" class="max-w-3xl mx-auto">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
  <input type="hidden" name="id" value="<?= $id ?>">

  <!-- Patient Information -->
  <div class="card mb-4">
    <div class="card-header"><span class="font-semibold text-sm">Patient Information</span></div>
    <div class="card-body space-y-4 p-3 sm:p-5">
      <div>
        <label class="required block text-xs font-medium text-slate-600 mb-1">Patient Name</label>
        <input type="text" name="patient_name" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm" value="<?= htmlspecialchars($record['patient_name']) ?>" placeholder="Enter patient full name..." required>
      </div>

      <div>
        <label class="required block text-xs font-medium text-slate-600 mb-1">Record Date</label>
        <input type="date" name="record_date" value="<?= htmlspecialchars($record['record_date']) ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" required>
      </div>
    </div>
  </div>

  <!-- Nurse Service -->
  <div class="card mb-4">
    <div class="card-header bg-indigo-50"><span class="font-semibold text-sm text-indigo-800">Nurse Service</span></div>
    <div class="card-body space-y-4 p-3 sm:p-5">
      <div>
        <label class="required block text-xs font-medium text-slate-600 mb-1">Nurse</label>
        <div class="relative">
          <input type="text" id="nurseSearch" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm pl-9" placeholder="Search nurse..." autocomplete="off" value="<?= htmlspecialchars($nurseName) ?>">
          <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="m21 21-4.35-4.35"/></svg>
        </div>
        <div id="nurseResults" class="mt-1 max-h-40 overflow-y-auto border border-slate-200 rounded-lg bg-white" style="display:none;"></div>
        <input type="hidden" name="nurse_employee_id" id="nurseEmployeeId" value="<?= $record['nurse_employee_id'] ?? '' ?>">
        <div id="nurseSelected" class="mt-1 text-xs text-emerald-600"><?= $nurseName ? 'Selected: ' . htmlspecialchars($nurseName) : '' ?></div>
      </div>
      <div>
        <label class="required block text-xs font-medium text-slate-600 mb-1">Service Date & Time</label>
        <input type="datetime-local" name="nurse_service_datetime" value="<?= $record['nurse_service_datetime'] ? date('Y-m-d\TH:i', strtotime($record['nurse_service_datetime'])) : '' ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Nurse Notes</label>
        <textarea name="nurse_notes" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars($record['nurse_notes'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <div class="flex items-center gap-2">
    <button type="submit" class="btn btn-primary text-sm flex-1 sm:flex-none">Update Record</button>
    <a href="<?= BASE_URL ?>/modules/clinic_records/index" class="btn btn-outline text-sm flex-1 sm:flex-none text-center spa">Cancel</a>
  </div>
</form>

<script>
const _baseUrl = '<?= BASE_URL ?>';

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setupAutocomplete(searchId, resultsId, hiddenId, selectedId) {
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
                        });
                    });
                });
        }, 300);
    });

    search.addEventListener('blur', function() {
        setTimeout(() => { results.style.display = 'none'; }, 200);
    });
}

setupAutocomplete('nurseSearch', 'nurseResults', 'nurseEmployeeId', 'nurseSelected');
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
