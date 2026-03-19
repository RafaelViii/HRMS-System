<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('attendance', 'attendance_records', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// Handle create
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time_in = $_POST['time_in'] ?? null;
    $time_out = $_POST['time_out'] ?? null;
    $status = $_POST['status'] ?? 'present';

    if (!$employee_id) $errors[] = 'Employee is required.';
    if (!$date) $errors[] = 'Date is required.';

    $ot_min = 0;
    if ($time_in && $time_out) {
        // Compute overtime as minutes beyond 8 hours per day
        $start = strtotime($date . ' ' . $time_in);
        $end = strtotime($date . ' ' . $time_out);
        // Handle overnight/graveyard shifts (time_out < time_in means next day)
        if ($end <= $start) {
            $end = strtotime('+1 day', $end);
        }
        $mins = (int)(($end - $start) / 60);
        $ot_min = max(0, $mins - 8*60);
    }

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, time_in, time_out, overtime_minutes, status) VALUES (:eid, :date, :in, :out, :ot, :status)");
      $stmt->execute([':eid'=>$employee_id, ':date'=>$date, ':in'=>$time_in, ':out'=>$time_out, ':ot'=>$ot_min, ':status'=>$status]);
      audit('attendance.create', "Attendance for emp #$employee_id on $date created");
      action_log('attendance', 'create_attendance', 'success', ['employee_id' => $employee_id, 'date' => $date]);
      header('Location: ' . BASE_URL . '/modules/attendance/index?msg=' . urlencode('Attendance saved'));
      exit;
    } catch (Throwable $e) { $msg = $e->getMessage(); if (str_contains($msg, 'duplicate') || str_contains($msg, 'unique')) { $errors[] = 'Duplicate entry for employee/date.'; } else { sys_log('DB2412', 'Execute failed: attendance insert - ' . $e->getMessage(), ['module'=>'attendance','file'=>__FILE__,'line'=>__LINE__]); show_system_error('Could not save attendance.'); } }
  }
}

// Employees for dropdown
$employees = [];
try { $employees = $pdo->query("SELECT id, employee_code, first_name, last_name FROM employees WHERE status='active' ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $employees = []; }

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="bg-white p-4 rounded-lg shadow-sm hover:shadow-md transition">
  <h1 class="text-xl font-semibold mb-3">Add Attendance</h1>
  <?php if ($errors): ?>
    <div class="mb-3 p-3 rounded bg-red-50 text-red-700 border border-red-200">
      <?= htmlspecialchars(implode("\n", $errors)) ?>
    </div>
  <?php endif; ?>
  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label class="block">
      <div class="text-sm text-gray-600 mb-1">Employee</div>
      <select name="employee_id" class="w-full border rounded px-3 py-2" required>
        <option value="">-- Select --</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['employee_code'] . ' - ' . $e['last_name'] . ', ' . $e['first_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block">
      <div class="text-sm text-gray-600 mb-1">Date</div>
      <input type="date" name="date" class="w-full border rounded px-3 py-2" required>
    </label>
    <label class="block">
      <div class="text-sm text-gray-600 mb-1">Time In</div>
      <input type="time" name="time_in" class="w-full border rounded px-3 py-2">
    </label>
    <label class="block">
      <div class="text-sm text-gray-600 mb-1">Time Out</div>
      <input type="time" name="time_out" class="w-full border rounded px-3 py-2">
    </label>
    <label class="block md:col-span-2">
      <div class="text-sm text-gray-600 mb-1">Status</div>
      <select name="status" class="w-full border rounded px-3 py-2">
        <option value="present">Present</option>
        <option value="late">Late</option>
        <option value="absent">Absent</option>
        <option value="on-leave">On Leave</option>
        <option value="holiday">Holiday</option>
      </select>
    </label>
    <div class="md:col-span-2 flex gap-2">
      <button class="px-4 py-2 bg-blue-600 text-white rounded-lg">Save</button>
  <a href="<?= BASE_URL ?>/modules/attendance/index" class="px-4 py-2 bg-gray-200 rounded-lg">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
