<?php
/**
 * Personal Data Export — Employee Self-Service
 * RA 10173 Right to Access
 * Employees can download their personal data as CSV.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/encryption.php';

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);

// Get employee record
$empStmt = $pdo->prepare("
    SELECT e.*, d.name AS department_name, p.name AS position_title, b.name AS branch_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id AND d.deleted_at IS NULL
    LEFT JOIN positions p ON p.id = e.position_id AND p.deleted_at IS NULL
    LEFT JOIN branches b ON b.id = e.branch_id
    WHERE e.user_id = :uid LIMIT 1
");
$empStmt->execute([':uid' => $uid]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    flash_error('No employee record found for your account.');
    header('Location: ' . BASE_URL . '/index');
    exit;
}

$employeeId = (int)$employee['id'];

// Handle POST — download data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $format = $_POST['format'] ?? 'csv';
    $sections = $_POST['sections'] ?? [];

    if (empty($sections)) {
        flash_error('Please select at least one data section to export.');
        header('Location: ' . BASE_URL . '/modules/compliance/data-export');
        exit;
    }

    action_log('compliance', 'data_export', 'success', [
        'employee_id' => $employeeId,
        'format' => $format,
        'sections' => $sections,
    ]);

    $exportData = [];

    // Personal Info
    if (in_array('personal', $sections, true)) {
        $exportData['Personal Information'] = [
            ['Field', 'Value'],
            ['Employee Code', $employee['employee_code'] ?? ''],
            ['First Name', $employee['first_name'] ?? ''],
            ['Last Name', $employee['last_name'] ?? ''],
            ['Email', $employee['email'] ?? ''],
            ['Phone', $employee['phone'] ?? ''],
            ['Date of Birth', $employee['date_of_birth'] ?? ''],
            ['Gender', $employee['gender'] ?? ''],
            ['Marital Status', $employee['marital_status'] ?? ''],
            ['Nationality', $employee['nationality'] ?? ''],
            ['Address', $employee['address'] ?? ''],
        ];
    }

    // Employment Info
    if (in_array('employment', $sections, true)) {
        $exportData['Employment Information'] = [
            ['Field', 'Value'],
            ['Department', $employee['department_name'] ?? ''],
            ['Position', $employee['position_title'] ?? ''],
            ['Branch', $employee['branch_name'] ?? ''],
            ['Hire Date', $employee['hire_date'] ?? ''],
            ['Employment Type', $employee['employment_type'] ?? ''],
            ['Status', $employee['status'] ?? ''],
        ];
    }

    // Emergency Contact
    if (in_array('emergency_contact', $sections, true)) {
        $exportData['Emergency Contact'] = [
            ['Field', 'Value'],
            ['Contact Name', $employee['emergency_contact_name'] ?? 'Not set'],
            ['Contact Phone', $employee['emergency_contact_phone'] ?? 'Not set'],
        ];
    }

    // Leave History
    if (in_array('leaves', $sections, true)) {
        try {
            $leaveStmt = $pdo->prepare("
                SELECT lr.leave_type, lr.start_date, lr.end_date, lr.status, lr.reason, lr.created_at
                FROM leave_requests lr
                WHERE lr.employee_id = :eid
                ORDER BY lr.created_at DESC LIMIT 100
            ");
            $leaveStmt->execute([':eid' => $employeeId]);
            $leaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
            $leaveRows = [['Leave Type', 'Start Date', 'End Date', 'Status', 'Reason', 'Filed Date']];
            foreach ($leaves as $l) {
                $leaveRows[] = [
                    $l['leave_type'],
                    $l['start_date'],
                    $l['end_date'],
                    $l['status'],
                    $l['reason'] ?? '',
                    date('M d, Y', strtotime($l['created_at'])),
                ];
            }
            $exportData['Leave History'] = $leaveRows;
        } catch (Throwable $e) {
            $exportData['Leave History'] = [['Error loading leave data']];
        }
    }

    // Payslip Summary
    if (in_array('payslips', $sections, true)) {
        try {
            $payStmt = $pdo->prepare("
                SELECT period_start, period_end, basic_pay, total_earnings, total_deductions, net_pay, status
                FROM payslips
                WHERE employee_id = :eid AND status IN ('locked','released')
                ORDER BY period_start DESC LIMIT 50
            ");
            $payStmt->execute([':eid' => $employeeId]);
            $payslips = $payStmt->fetchAll(PDO::FETCH_ASSOC);
            $payRows = [['Period Start', 'Period End', 'Basic Pay', 'Total Earnings', 'Total Deductions', 'Net Pay', 'Status']];
            foreach ($payslips as $p) {
                $payRows[] = [
                    $p['period_start'],
                    $p['period_end'],
                    number_format((float)$p['basic_pay'], 2, '.', ''),
                    number_format((float)$p['total_earnings'], 2, '.', ''),
                    number_format((float)$p['total_deductions'], 2, '.', ''),
                    number_format((float)$p['net_pay'], 2, '.', ''),
                    $p['status'],
                ];
            }
            $exportData['Payslip Summary'] = $payRows;
        } catch (Throwable $e) {
            $exportData['Payslip Summary'] = [['Error loading payslip data']];
        }
    }

    // Attendance
    if (in_array('attendance', $sections, true)) {
        try {
            $attStmt = $pdo->prepare("
                SELECT date, time_in, time_out, status
                FROM attendance
                WHERE employee_id = :eid
                ORDER BY date DESC LIMIT 200
            ");
            $attStmt->execute([':eid' => $employeeId]);
            $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);
            $attRows = [['Date', 'Time In', 'Time Out', 'Status']];
            foreach ($attendance as $a) {
                $attRows[] = [
                    $a['date'],
                    $a['time_in'] ?? '',
                    $a['time_out'] ?? '',
                    $a['status'] ?? '',
                ];
            }
            $exportData['Attendance Records'] = $attRows;
        } catch (Throwable $e) {
            $exportData['Attendance Records'] = [['Error loading attendance data']];
        }
    }

    // Output as CSV
    $filename = "MyData_" . ($employee['employee_code'] ?? 'export') . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['Personal Data Export — ' . ($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')]);
    fputcsv($out, ['Generated: ' . date('M d, Y h:i A')]);
    fputcsv($out, ['Under RA 10173 Data Privacy Act — Right to Access']);
    fputcsv($out, []);

    foreach ($exportData as $section => $rows) {
        fputcsv($out, ['=== ' . $section . ' ===']);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fputcsv($out, []);
    }

    fclose($out);
    exit;
}

// ---- Display page ----
$pageTitle = 'Download My Data';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Download My Personal Data</h1>
    <p class="text-sm text-slate-500 mt-0.5">Exercise your Right to Access under RA 10173 — download a copy of your personal data stored in the system.</p>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="flex items-center gap-2">
        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Select Data to Export
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="<?= BASE_URL ?>/modules/compliance/data-export">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="format" value="csv">

        <p class="text-sm text-slate-600 mb-4">Select which sections of your data you want to download:</p>

        <div class="space-y-3">
          <?php
          $exportSections = [
              'personal' => ['label' => 'Personal Information', 'desc' => 'Name, contact details, date of birth, address', 'icon' => 'indigo'],
              'employment' => ['label' => 'Employment Information', 'desc' => 'Department, position, branch, hire date, status', 'icon' => 'emerald'],
              'emergency_contact' => ['label' => 'Emergency Contact', 'desc' => 'Emergency contact name and phone number', 'icon' => 'amber'],
              'leaves' => ['label' => 'Leave History', 'desc' => 'All leave requests and their statuses', 'icon' => 'blue'],
              'payslips' => ['label' => 'Payslip Summary', 'desc' => 'Pay period summaries (basic, earnings, deductions, net)', 'icon' => 'purple'],
              'attendance' => ['label' => 'Attendance Records', 'desc' => 'Time in/out logs and attendance status', 'icon' => 'rose'],
          ];
          foreach ($exportSections as $key => $sec):
          ?>
          <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer transition-colors">
            <input type="checkbox" name="sections[]" value="<?= $key ?>" checked
              class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <div>
              <span class="font-medium text-sm text-slate-900"><?= $sec['label'] ?></span>
              <p class="text-xs text-slate-500 mt-0.5"><?= $sec['desc'] ?></p>
            </div>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="mt-6 flex items-center justify-between">
          <a href="<?= BASE_URL ?>/modules/compliance/privacy/consent" class="spa text-sm text-indigo-600 hover:text-indigo-500">
            &larr; Back to Privacy & Consent
          </a>
          <button type="submit" class="btn btn-primary" data-no-loader>
            <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Download CSV
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <div>
        <h4 class="text-sm font-semibold text-blue-900">About Your Data Export</h4>
        <p class="text-xs text-blue-700 mt-1">This export contains your personal data as stored in the HRIS system. Government ID numbers are decrypted for your copy. The exported file is in CSV format and can be opened in Excel or any spreadsheet application. This export is logged for security purposes.</p>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
