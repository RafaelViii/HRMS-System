<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// Handle inline time edit for HR users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_time'])) {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid form token');
    header('Location: ' . BASE_URL . '/modules/attendance/index');
    exit;
  }

  // Check HR write access
  $currentUser = current_user();
  $userId = (int)($currentUser['id'] ?? 0);
  if (!user_has_access($userId, 'hr_core', 'attendance', 'write')) {
    flash_error('Access denied');
    header('Location: ' . BASE_URL . '/modules/attendance/index');
    exit;
  }

  $attendanceId = (int)($_POST['attendance_id'] ?? 0);
  $timeIn = trim($_POST['time_in'] ?? '');
  $timeOut = trim($_POST['time_out'] ?? '');

  try {
    $pdo->beginTransaction();

    // Validate times
    if (!empty($timeIn) && !DateTime::createFromFormat('H:i', $timeIn)) {
      throw new Exception('Invalid time_in format');
    }
    if (!empty($timeOut) && !DateTime::createFromFormat('H:i', $timeOut)) {
      throw new Exception('Invalid time_out format');
    }

    // Fetch the attendance record to get the date and employee schedule
    $fetchSql = "SELECT a.employee_id, a.date, epp.duty_start, epp.duty_end
                 FROM attendance a
                 LEFT JOIN employee_payroll_profiles epp ON epp.employee_id = a.employee_id
                 WHERE a.id = :id";
    $fetchStmt = $pdo->prepare($fetchSql);
    $fetchStmt->execute([':id' => $attendanceId]);
    $attRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    // Recalculate overtime and hours when times change
    $overtimeMinutes = 0;
    $hoursWorked = null;
    $statusField = 'present';
    if (!empty($timeIn) && !empty($timeOut) && $attRecord) {
      $inDt = DateTime::createFromFormat('H:i', $timeIn);
      $outDt = DateTime::createFromFormat('H:i', $timeOut);
      if ($inDt && $outDt) {
        $diffMinutes = (int)(($outDt->getTimestamp() - $inDt->getTimestamp()) / 60);
        if ($diffMinutes < 0) { $diffMinutes += 1440; } // handle overnight
        $hoursWorked = round($diffMinutes / 60, 2);
        // Calculate OT if duty_end is available
        $dutyEnd = $attRecord['duty_end'] ?? null;
        if ($dutyEnd) {
          $dutyEndDt = DateTime::createFromFormat('H:i:s', $dutyEnd) ?: DateTime::createFromFormat('H:i', $dutyEnd);
          if ($dutyEndDt && $outDt > $dutyEndDt) {
            $overtimeMinutes = (int)(($outDt->getTimestamp() - $dutyEndDt->getTimestamp()) / 60);
          }
        }
      }
    } elseif (empty($timeIn) && empty($timeOut)) {
      $statusField = 'absent';
    }

    $updateSql = "UPDATE attendance SET time_in = :time_in, time_out = :time_out, overtime_minutes = :ot_minutes WHERE id = :id";
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([
      ':time_in' => $timeIn ?: null,
      ':time_out' => $timeOut ?: null,
      ':ot_minutes' => $overtimeMinutes,
      ':id' => $attendanceId
    ]);

    $pdo->commit();

    action_log('attendance', 'edit_time', 'success', [
      'attendance_id' => $attendanceId,
      'time_in' => $timeIn,
      'time_out' => $timeOut
    ]);

    flash_success('Attendance times updated successfully');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    sys_log('ATT-EDIT-001', 'Failed to update attendance: ' . $e->getMessage(), [
      'module' => 'attendance',
      'file' => __FILE__,
      'line' => __LINE__
    ]);
    flash_error('Failed to update attendance times');
  }

  header('Location: ' . BASE_URL . '/modules/attendance/index');
  exit;
}

require_once __DIR__ . '/../../includes/header.php';

if (!function_exists('attendance_format_time12')) {
  function attendance_format_time12($value): string {
    if ($value === null) {
      return '';
    }
    $raw = trim((string)$value);
    if ($raw === '') {
      return '';
    }
    
    // Strip microseconds if present (e.g., "09:09:37.251706" -> "09:09:37")
    $raw = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $raw);
    
    try {
      // Try parsing with seconds first, then without
      $dt = DateTime::createFromFormat('H:i:s', $raw);
      if (!$dt) {
        $dt = DateTime::createFromFormat('H:i', $raw);
      }
      
      if ($dt instanceof DateTime) {
        return $dt->format('h:i A');
      }
    } catch (Throwable $e) {
      return $raw;
    }
    return $raw;
  }
}

if (!function_exists('format_overtime_display')) {
  function format_overtime_display(int $minutes): string {
    if ($minutes <= 0) {
      return '—';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    if ($hours > 0 && $mins > 0) {
      return "{$hours}hr {$mins}min";
    } elseif ($hours > 0) {
      return "{$hours}hr";
    } else {
      return "{$mins}min";
    }
  }
}

if (!function_exists('calculate_attendance_status')) {
  function calculate_attendance_status($timeIn, $timeOut, $dutyStart, $dutyEnd, $currentStatus): string {
    // If already marked as special status, keep it
    if (in_array($currentStatus, ['on-leave', 'holiday', 'absent', 'submitted'])) {
      return $currentStatus;
    }
    
    // No time in = absent
    if (empty($timeIn)) {
      return 'absent';
    }
    
    // No schedule defined = present (can't determine late)
    if (empty($dutyStart)) {
      return 'present';
    }
    
    try {
      $timeInDt = new DateTime($timeIn);
      $dutyStartDt = new DateTime($dutyStart);
      
      // Late threshold: 15 minutes grace period
      $lateThreshold = clone $dutyStartDt;
      $lateThreshold->modify('+15 minutes');
      
      if ($timeInDt > $lateThreshold) {
        return 'late';
      }
      
      return 'present';
    } catch (Throwable $e) {
      return $currentStatus;
    }
  }
}

$q = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$params = [];
$where = ' WHERE a.date BETWEEN :from AND :to ';
$params[':from'] = $from; $params[':to'] = $to;
if ($q !== '') { $where .= ' AND (e.employee_code ILIKE :q OR e.first_name ILIKE :q OR e.last_name ILIKE :q) '; $params[':q'] = "%$q%"; }

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$canEditAttendance = user_has_access($userId, 'hr_core', 'attendance', 'write');

$sql = 'SELECT a.*, 
               e.employee_code, e.first_name, e.last_name,
               epp.duty_start, epp.duty_end,
               wst.name as shift_name, 
               COALESCE(ews.custom_start_time, wst.start_time, epp.duty_start) as shift_start, 
               COALESCE(ews.custom_end_time, wst.end_time, epp.duty_end) as shift_end
        FROM attendance a 
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN employee_payroll_profiles epp ON epp.employee_id = e.id
        LEFT JOIN employee_work_schedules ews ON ews.employee_id = e.id 
            AND (ews.effective_to IS NULL OR ews.effective_to >= a.date)
            AND ews.effective_from <= a.date
        LEFT JOIN work_schedule_templates wst ON wst.id = ews.schedule_template_id
        ' . $where . ' 
        ORDER BY a.date DESC, a.id DESC LIMIT 200';
try { 
  $stmt = $pdo->prepare($sql); 
  $stmt->execute($params); 
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); 
  
  // Calculate correct status for each row
  foreach ($rows as &$row) {
    $row['computed_status'] = calculate_attendance_status(
      $row['time_in'],
      $row['time_out'],
      $row['duty_start'] ?? $row['shift_start'],
      $row['duty_end'] ?? $row['shift_end'],
      $row['status']
    );
  }
} catch (Throwable $e) { 
  sys_log('ATTENDANCE-INDEX', 'Query error: ' . $e->getMessage(), [
    'module' => 'attendance',
    'file' => __FILE__,
    'line' => __LINE__,
    'sql' => $sql,
    'params' => $params
  ]);
  flash_error('Error loading attendance records. Please try again.');
  $rows = []; 
}

// Compute stats from loaded rows
$attStats = ['total' => count($rows), 'present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0];
foreach ($rows as $r) {
  $st = $r['computed_status'];
  if ($st === 'present') $attStats['present']++;
  elseif ($st === 'late') $attStats['late']++;
  elseif ($st === 'absent') $attStats['absent']++;
  elseif ($st === 'on-leave') $attStats['on_leave']++;
}
?>
<div class="space-y-5">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Attendance Management</h1>
      <p class="text-sm text-slate-500 mt-0.5">Track daily attendance, time entries, and overtime across your workforce.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/attendance/schedule">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" class="inline-block mr-1" aria-hidden="true">
          <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
        </svg>
        Schedules
      </a>
      <?php $role = $_SESSION['user']['role'] ?? 'employee'; $canManage = in_array($role, ['admin','hr','hr_supervisor','manager'], true); if ($canManage): ?>
      <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/attendance/create">+ Add Entry</a>
      <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/attendance/import">CSV Import</a>
      <?php endif; ?>
      <div class="dropdown">
        <button class="btn btn-outline" data-dd-toggle>
          <svg class="w-4 h-4 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          Export
        </button>
        <div class="dropdown-menu hidden">
          <a class="dropdown-item" href="<?= BASE_URL ?>/modules/attendance/csv?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($q) ?>" target="_blank" rel="noopener" data-no-loader>CSV</a>
          <a class="dropdown-item" href="<?= BASE_URL ?>/modules/attendance/pdf?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($q) ?>" target="_blank" rel="noopener">PDF</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= $attStats['total'] ?></div>
        <div class="text-xs text-slate-500">Total Records</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-emerald-600"><?= $attStats['present'] ?></div>
        <div class="text-xs text-slate-500">Present</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-amber-600"><?= $attStats['late'] ?></div>
        <div class="text-xs text-slate-500">Late</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-red-600"><?= $attStats['absent'] ?></div>
        <div class="text-xs text-slate-500">Absent</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-blue-600"><?= $attStats['on_leave'] ?></div>
        <div class="text-xs text-slate-500">On Leave</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card">
    <div class="card-body">
      <form method="get" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-0 sm:min-w-[200px]">
          <label class="block text-xs font-medium text-slate-500 mb-1">Search Employee</label>
          <input name="q" value="<?= htmlspecialchars($q) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Name or employee code...">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">To</label>
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_URL ?>/modules/attendance/index" class="btn btn-outline">Clear</a>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="card-header flex items-center justify-between flex-wrap gap-2">
      <span class="font-semibold text-slate-800">Attendance Records <span class="text-sm font-normal text-slate-500">(<?= count($rows) ?> showing)</span></span>
    </div>
    <div class="card-body p-0">
      <div class="overflow-x-auto">
        <table class="table-basic w-full">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th class="hidden lg:table-cell">Shift</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th class="hidden md:table-cell">Overtime</th>
              <th>Status</th>
              <?php if ($canEditAttendance): ?>
              <th>Actions</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="<?= $canEditAttendance ? 8 : 7 ?>" class="text-center py-8 text-sm text-slate-500">No attendance records found for the selected period.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $shiftName = $r['shift_name'] ?? 'Standard';
                  $shiftStart = !empty($r['shift_start']) ? attendance_format_time12($r['shift_start']) : '';
                  $shiftEnd = !empty($r['shift_end']) ? attendance_format_time12($r['shift_end']) : '';
                  $shiftDisplay = ($shiftStart && $shiftEnd) ? $shiftName . ' (' . $shiftStart . ' - ' . $shiftEnd . ')' : $shiftName;
                  
                  $status = $r['computed_status'];
                  $statusBadgeClass = 'bg-slate-100 text-slate-700';
                  if ($status === 'present') $statusBadgeClass = 'bg-emerald-100 text-emerald-700';
                  elseif ($status === 'late') $statusBadgeClass = 'bg-amber-100 text-amber-700';
                  elseif ($status === 'absent') $statusBadgeClass = 'bg-red-100 text-red-700';
                  elseif ($status === 'on-leave') $statusBadgeClass = 'bg-blue-100 text-blue-700';
                  elseif ($status === 'holiday') $statusBadgeClass = 'bg-purple-100 text-purple-700';
                  elseif ($status === 'submitted') $statusBadgeClass = 'bg-indigo-100 text-indigo-700';
                ?>
                <tr class="hover:bg-slate-50">
                  <td class="whitespace-nowrap"><?= htmlspecialchars(date('M d, Y', strtotime($r['date']))) ?></td>
                  <td>
                    <div class="text-sm font-medium text-slate-900"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars($r['employee_code']) ?></div>
                  </td>
                  <td class="text-xs text-slate-600 hidden lg:table-cell"><?= htmlspecialchars($shiftDisplay) ?></td>
                  <td class="whitespace-nowrap"><?= htmlspecialchars(attendance_format_time12($r['time_in'])) ?></td>
                  <td class="whitespace-nowrap"><?= htmlspecialchars(attendance_format_time12($r['time_out'])) ?></td>
                  <td class="font-medium text-indigo-600 hidden md:table-cell"><?= format_overtime_display((int)$r['overtime_minutes']) ?></td>
                  <td>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $statusBadgeClass ?>">
                      <?= htmlspecialchars(ucfirst($status)) ?>
                    </span>
                  </td>
                  <?php if ($canEditAttendance): ?>
                  <td>
                    <button type="button" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium"
                      onclick="openEditTimeModal(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['time_in'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['time_out'], ENT_QUOTES) ?>')">
                      Edit
                    </button>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit Time Modal -->
<?php if ($canEditAttendance): ?>
<div id="editTimeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-gray-900">Edit Attendance Times</h3>
      <button type="button" onclick="closeEditTimeModal()" class="text-gray-400 hover:text-gray-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form method="post" id="editTimeForm">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="edit_time" value="1">
      <input type="hidden" name="attendance_id" id="edit_attendance_id" value="">
      
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Time In (24-hour format)</label>
          <input 
            type="time" 
            name="time_in" 
            id="edit_time_in" 
            class="input-text w-full"
            placeholder="HH:MM"
          >
          <p class="text-xs text-gray-500 mt-1">Leave empty to clear</p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Time Out (24-hour format)</label>
          <input 
            type="time" 
            name="time_out" 
            id="edit_time_out" 
            class="input-text w-full"
            placeholder="HH:MM"
          >
          <p class="text-xs text-gray-500 mt-1">Leave empty to clear</p>
        </div>

        <div class="bg-blue-50 border-l-4 border-blue-400 p-3 rounded">
          <p class="text-xs text-blue-700">
            <strong>Note:</strong> Times will be displayed in 12-hour AM/PM format throughout the system.
          </p>
        </div>
      </div>

      <div class="flex gap-3 mt-6">
        <button type="submit" class="btn btn-primary flex-1">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
          </svg>
          Save Changes
        </button>
        <button type="button" onclick="closeEditTimeModal()" class="btn btn-outline">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditTimeModal(id, timeIn, timeOut) {
  document.getElementById('edit_attendance_id').value = id;
  
  // Convert TIME values (HH:MM:SS or HH:MM) to HH:MM for input[type=time]
  const formatTimeForInput = (time) => {
    if (!time) return '';
    const parts = time.split(':');
    return parts.length >= 2 ? `${parts[0]}:${parts[1]}` : '';
  };
  
  document.getElementById('edit_time_in').value = formatTimeForInput(timeIn);
  document.getElementById('edit_time_out').value = formatTimeForInput(timeOut);
  document.getElementById('editTimeModal').classList.remove('hidden');
}

function closeEditTimeModal() {
  document.getElementById('editTimeModal').classList.add('hidden');
  document.getElementById('editTimeForm').reset();
}

// Close modal on backdrop click
document.getElementById('editTimeModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closeEditTimeModal();
  }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
