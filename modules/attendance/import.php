<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('attendance', 'attendance_records', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

// ─── Helper: Parse biometric file into normalized punch records ────────────────

function detect_and_parse_biometric_file(string $filePath, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rawLines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$rawLines) return ['format' => 'unknown', 'punches' => [], 'errors' => ['File is empty or unreadable.']];

    if ($ext === 'dat' || is_zkteco_dat($rawLines)) {
        return parse_zkteco_dat($rawLines);
    }
    if (is_zkteco_csv($rawLines)) {
        return parse_zkteco_csv($filePath);
    }
    if (is_zkaccess_report($rawLines)) {
        return parse_zkaccess_report($filePath);
    }
    if (is_hris_standard_csv($rawLines)) {
        return parse_hris_standard($filePath);
    }

    return [
        'format' => 'unknown',
        'punches' => [],
        'errors' => ['Unrecognized file format. Supported: ZKTeco .dat, ZKTeco CSV, ZKAccess Report, HRIS Standard CSV.']
    ];
}

// ─── Format Detectors ─────────────────────────────────────────────────────────

function is_zkteco_dat(array $lines): bool {
    $firstLine = $lines[0] ?? '';
    $parts = preg_split('/\t+/', trim($firstLine));
    return count($parts) >= 4 && preg_match('/^\d+$/', $parts[0]) && preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $parts[1]);
}

function is_zkteco_csv(array $lines): bool {
    $header = strtolower($lines[0] ?? '');
    return (str_contains($header, 'enrollnumber') && str_contains($header, 'gmtdate'));
}

function is_zkaccess_report(array $lines): bool {
    $header = strtolower($lines[0] ?? '');
    return (str_contains($header, 'date/time') || str_contains($header, 'date\\time'))
        && (str_contains($header, 'no.') || str_contains($header, 'no,'));
}

function is_hris_standard_csv(array $lines): bool {
    $header = strtolower($lines[0] ?? '');
    return str_contains($header, 'employee_code') && str_contains($header, 'date')
        && str_contains($header, 'time_in') && str_contains($header, 'time_out');
}

// ─── Parsers ──────────────────────────────────────────────────────────────────

function parse_zkteco_dat(array $lines): array {
    $punches = [];
    $errors = [];
    $lineNum = 0;
    foreach ($lines as $line) {
        $lineNum++;
        $parts = preg_split('/\t+/', trim($line));
        if (count($parts) < 4) {
            $errors[] = "Line $lineNum: Could not parse (expected tab-separated fields).";
            continue;
        }
        $uid = trim($parts[0]);
        $datetime = trim($parts[1]);
        $state = (int)trim($parts[2]); // 0=IN, 1=OUT, 2=break-out, 3=break-in

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        if (!$dt) {
            $errors[] = "Line $lineNum: Invalid datetime '$datetime'.";
            continue;
        }

        $direction = null;
        if ($state === 0 || $state === 3) $direction = 'in';
        elseif ($state === 1 || $state === 2) $direction = 'out';

        $punches[] = [
            'uid' => $uid,
            'datetime' => $dt->format('Y-m-d H:i:s'),
            'direction' => $direction,
            'name' => null,
        ];
    }
    return ['format' => 'ZKTeco .dat (raw punch log)', 'punches' => $punches, 'errors' => $errors];
}

function parse_zkteco_csv(string $filePath): array {
    $punches = [];
    $errors = [];
    $h = fopen($filePath, 'r');
    $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return ['format' => 'ZKTeco CSV', 'punches' => [], 'errors' => ['Empty CSV.']]; }

    $headerMap = [];
    foreach ($headers as $i => $hdr) {
        $headerMap[strtolower(trim($hdr))] = $i;
    }

    $enrollIdx = $headerMap['enrollnumber'] ?? $headerMap['enroll number'] ?? $headerMap['userid'] ?? null;
    $dateIdx   = $headerMap['gmtdate'] ?? $headerMap['datetime'] ?? $headerMap['date/time'] ?? null;
    $modeIdx   = $headerMap['mode'] ?? $headerMap['status'] ?? null;
    $nameIdx   = $headerMap['name'] ?? null;

    if ($enrollIdx === null || $dateIdx === null) {
        fclose($h);
        return ['format' => 'ZKTeco CSV', 'punches' => [], 'errors' => ['Missing required columns (EnrollNumber, GMTDate).']];
    }

    $lineNum = 1;
    while (($row = fgetcsv($h)) !== false) {
        $lineNum++;
        $uid = trim($row[$enrollIdx] ?? '');
        $rawDt = trim($row[$dateIdx] ?? '');
        $mode = strtolower(trim($row[$modeIdx] ?? ''));
        $name = $nameIdx !== null ? trim($row[$nameIdx] ?? '') : null;

        if (!$uid || !$rawDt) {
            $errors[] = "Row $lineNum: Missing EnrollNumber or DateTime.";
            continue;
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $rawDt);
        if (!$dt) $dt = DateTime::createFromFormat('Y/m/d H:i:s', $rawDt);
        if (!$dt) {
            $errors[] = "Row $lineNum: Invalid datetime '$rawDt'.";
            continue;
        }

        $direction = null;
        if (str_contains($mode, 'in')) $direction = 'in';
        elseif (str_contains($mode, 'out')) $direction = 'out';

        $punches[] = [
            'uid' => $uid,
            'datetime' => $dt->format('Y-m-d H:i:s'),
            'direction' => $direction,
            'name' => $name,
        ];
    }
    fclose($h);
    return ['format' => 'ZKTeco CSV Export', 'punches' => $punches, 'errors' => $errors];
}

function parse_zkaccess_report(string $filePath): array {
    $punches = [];
    $errors = [];
    $h = fopen($filePath, 'r');
    $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return ['format' => 'ZKAccess Report', 'punches' => [], 'errors' => ['Empty CSV.']]; }

    $headerMap = [];
    foreach ($headers as $i => $hdr) {
        $headerMap[strtolower(trim($hdr))] = $i;
    }

    $noIdx     = $headerMap['no.'] ?? $headerMap['no'] ?? $headerMap['id'] ?? null;
    $dateIdx   = $headerMap['date/time'] ?? $headerMap['datetime'] ?? null;
    $statusIdx = $headerMap['status'] ?? null;
    $nameIdx   = $headerMap['name'] ?? null;

    if ($noIdx === null || $dateIdx === null) {
        fclose($h);
        return ['format' => 'ZKAccess Report', 'punches' => [], 'errors' => ['Missing required columns (No., Date/Time).']];
    }

    $lineNum = 1;
    while (($row = fgetcsv($h)) !== false) {
        $lineNum++;
        $uid = trim($row[$noIdx] ?? '');
        $rawDt = trim($row[$dateIdx] ?? '');
        $statusRaw = strtolower(trim($row[$statusIdx] ?? ''));
        $name = $nameIdx !== null ? trim($row[$nameIdx] ?? '') : null;

        if (!$uid || !$rawDt) {
            $errors[] = "Row $lineNum: Missing No. or Date/Time.";
            continue;
        }

        $rawDt = str_replace('/', '-', $rawDt);
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $rawDt);
        if (!$dt) {
            $errors[] = "Row $lineNum: Invalid datetime '$rawDt'.";
            continue;
        }

        $direction = null;
        if (str_contains($statusRaw, 'c/in') || str_contains($statusRaw, 'check-in') || $statusRaw === 'in') $direction = 'in';
        elseif (str_contains($statusRaw, 'c/out') || str_contains($statusRaw, 'check-out') || $statusRaw === 'out') $direction = 'out';

        $punches[] = [
            'uid' => $uid,
            'datetime' => $dt->format('Y-m-d H:i:s'),
            'direction' => $direction,
            'name' => $name,
        ];
    }
    fclose($h);
    return ['format' => 'ZKAccess Report', 'punches' => $punches, 'errors' => $errors];
}

function parse_hris_standard(string $filePath): array {
    $rows = [];
    $h = fopen($filePath, 'r');
    $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return ['format' => 'HRIS Standard CSV', 'punches' => [], 'errors' => ['Empty CSV.']]; }

    $lineNum = 1;
    while (($row = fgetcsv($h)) !== false) {
        $lineNum++;
        if (count($row) < count($headers)) continue;
        $data = array_combine(array_map('trim', $headers), $row);

        $code = trim($data['employee_code'] ?? '');
        $date = trim($data['date'] ?? '');
        $timeIn = trim($data['time_in'] ?? '');
        $timeOut = trim($data['time_out'] ?? '');
        $status = trim($data['status'] ?? 'present');

        if (!$code || !$date) continue;

        $rows[] = [
            'employee_code' => $code,
            'date' => $date,
            'time_in' => $timeIn ?: null,
            'time_out' => $timeOut ?: null,
            'status' => $status,
        ];
    }
    fclose($h);
    return ['format' => 'HRIS Standard CSV', 'punches' => $rows, 'errors' => [], 'is_aggregated' => true];
}

// ─── Aggregator: Collapse raw punches into first-in / last-out per employee per day ──

function aggregate_punches(array $punches, PDO $pdo): array {
    $uidToEmployee = [];
    $allUids = array_unique(array_column($punches, 'uid'));
    if (empty($allUids)) return ['records' => [], 'unmapped' => [], 'uid_names' => []];

    // Check biometric_id_mapping table
    $placeholders = implode(',', array_fill(0, count($allUids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT bm.biometric_uid, bm.employee_id, e.employee_code, e.first_name, e.last_name
                               FROM biometric_id_mapping bm 
                               JOIN employees e ON e.id = bm.employee_id 
                               WHERE bm.biometric_uid IN ($placeholders)");
        $stmt->execute(array_values($allUids));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uidToEmployee[$row['biometric_uid']] = $row;
        }
    } catch (Throwable $e) {
        // Table may not exist yet, continue with fallback
    }

    // For UIDs not found in mapping, try direct employee_code match
    foreach ($allUids as $uid) {
        if (!isset($uidToEmployee[$uid])) {
            $stmt2 = $pdo->prepare("SELECT id AS employee_id, employee_code, first_name, last_name FROM employees WHERE employee_code = :code LIMIT 1");
            $stmt2->execute([':code' => $uid]);
            $emp = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $uidToEmployee[$uid] = $emp;
            }
        }
    }

    // Collect names from punches for display
    $uidNames = [];
    foreach ($punches as $p) {
        if (!empty($p['name']) && !isset($uidNames[$p['uid']])) {
            $uidNames[$p['uid']] = $p['name'];
        }
    }

    // Group punches by uid+date
    $grouped = [];
    foreach ($punches as $p) {
        $uid = $p['uid'];
        $dt = new DateTime($p['datetime']);
        $date = $dt->format('Y-m-d');
        $time = $dt->format('H:i:s');
        $direction = $p['direction'];

        $key = $uid . '|' . $date;
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['uid' => $uid, 'date' => $date, 'times' => [], 'ins' => [], 'outs' => []];
        }
        $grouped[$key]['times'][] = $time;
        if ($direction === 'in') $grouped[$key]['ins'][] = $time;
        if ($direction === 'out') $grouped[$key]['outs'][] = $time;
    }

    // Aggregate to first-in / last-out
    $records = [];
    $unmapped = [];
    foreach ($grouped as $key => $g) {
        $uid = $g['uid'];
        $date = $g['date'];

        if (!isset($uidToEmployee[$uid])) {
            if (!isset($unmapped[$uid])) {
                $unmapped[$uid] = [
                    'uid' => $uid,
                    'name' => $uidNames[$uid] ?? null,
                    'punch_count' => count($g['times']),
                    'dates' => [$date],
                ];
            } else {
                $unmapped[$uid]['dates'][] = $date;
                $unmapped[$uid]['punch_count'] += count($g['times']);
            }
            continue;
        }

        $emp = $uidToEmployee[$uid];

        sort($g['ins']);
        sort($g['outs']);
        sort($g['times']);

        $timeIn  = !empty($g['ins'])  ? $g['ins'][0]   : $g['times'][0];
        $timeOut = !empty($g['outs']) ? end($g['outs']) : end($g['times']);

        // If only one punch, it's time_in with no time_out
        if (count($g['times']) === 1) {
            $timeOut = null;
        }
        if ($timeIn === $timeOut && count($g['times']) <= 1) {
            $timeOut = null;
        }

        $records[] = [
            'employee_id'   => (int)$emp['employee_id'],
            'employee_code' => $emp['employee_code'],
            'employee_name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
            'date'          => $date,
            'time_in'       => $timeIn,
            'time_out'      => $timeOut,
            'punch_count'   => count($g['times']),
            'status'        => 'present',
        ];
    }

    usort($records, function($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        return $d !== 0 ? $d : strcmp($a['employee_name'], $b['employee_name']);
    });

    return ['records' => $records, 'unmapped' => $unmapped, 'uid_names' => $uidNames];
}

// ─── POST Handlers ────────────────────────────────────────────────────────────

$errors = [];
$parseResult = null;
$previewRecords = null;
$unmapped = [];
$imported = 0;
$step = 'upload';

// Step 2: Confirm import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    csrf_verify($_POST['csrf_token'] ?? '');

    $recordsJson = $_POST['records_json'] ?? '';
    $records = json_decode($recordsJson, true);

    if (!$records || !is_array($records)) {
        flash_error('No records to import. Please try again.');
        header('Location: ' . BASE_URL . '/modules/attendance/import');
        exit;
    }

    // Save biometric mappings for any new UID → employee links
    $mappingsJson = $_POST['mappings_json'] ?? '';
    $newMappings = json_decode($mappingsJson, true) ?: [];
    foreach ($newMappings as $mapping) {
        try {
            $mStmt = $pdo->prepare("INSERT INTO biometric_id_mapping (biometric_uid, employee_id, device_name) 
                                     VALUES (:uid, :eid, :device) 
                                     ON CONFLICT (biometric_uid, device_name) DO NOTHING");
            $mStmt->execute([
                ':uid' => $mapping['uid'],
                ':eid' => (int)$mapping['employee_id'],
                ':device' => $mapping['device_name'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Non-critical, continue
        }
    }

    $stmtIns = $pdo->prepare("INSERT INTO attendance (employee_id, date, time_in, time_out, overtime_minutes, status)
        VALUES (:eid, :date, :in, :out, :ot, :status)
        ON CONFLICT (employee_id, date) DO UPDATE 
        SET time_in = EXCLUDED.time_in, time_out = EXCLUDED.time_out, 
            overtime_minutes = EXCLUDED.overtime_minutes, status = EXCLUDED.status");

    $failed = 0;
    foreach ($records as $rec) {
        $eid = (int)($rec['employee_id'] ?? 0);
        $date = $rec['date'] ?? '';
        $timeIn = $rec['time_in'] ?? null;
        $timeOut = $rec['time_out'] ?? null;
        $status = $rec['status'] ?? 'present';

        if (!$eid || !$date) { $failed++; continue; }

        $ot = 0;
        if ($timeIn && $timeOut) {
            $start = strtotime($date . ' ' . $timeIn);
            $end = strtotime($date . ' ' . $timeOut);
            if ($end <= $start) $end = strtotime('+1 day', $end);
            $mins = (int)(($end - $start) / 60);
            $ot = max(0, $mins - 8 * 60);
        }

        try {
            $stmtIns->execute([
                ':eid' => $eid,
                ':date' => $date,
                ':in' => $timeIn,
                ':out' => $timeOut,
                ':ot' => $ot,
                ':status' => $status,
            ]);
            $imported++;
        } catch (Throwable $e) {
            $failed++;
        }
    }

    audit('attendance.biometric_import', "Imported $imported attendance records from biometric data" . ($failed ? ", $failed failed" : ""));
    action_log('attendance', 'biometric_import', 'success', ['imported' => $imported, 'failed' => $failed]);

    $msg = "Successfully imported $imported attendance records.";
    if ($failed > 0) $msg .= " ($failed records failed.)";
    flash_success($msg);
    header('Location: ' . BASE_URL . '/modules/attendance/index');
    exit;
}

// Step 1: Upload & parse file → show preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['biometric_file'])) {
    csrf_verify($_POST['csrf_token'] ?? '');

    if (empty($_FILES['biometric_file']['name'])) {
        $errors[] = 'Please select a file to import.';
    } else {
        $tmp = $_FILES['biometric_file']['tmp_name'];
        $originalName = $_FILES['biometric_file']['name'];
        $parseResult = detect_and_parse_biometric_file($tmp, $originalName);

        if (!empty($parseResult['errors']) && empty($parseResult['punches'])) {
            $errors = array_merge($errors, $parseResult['errors']);
        } elseif (empty($parseResult['punches'])) {
            $errors[] = 'No records could be parsed from the file.';
        } else {
            if (!empty($parseResult['is_aggregated'])) {
                // HRIS standard format — already aggregated, look up employee IDs
                $records = [];
                foreach ($parseResult['punches'] as $row) {
                    $stmt = $pdo->prepare("SELECT id, employee_code, first_name, last_name FROM employees WHERE employee_code = :code LIMIT 1");
                    $stmt->execute([':code' => $row['employee_code']]);
                    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($emp) {
                        $records[] = [
                            'employee_id' => (int)$emp['id'],
                            'employee_code' => $emp['employee_code'],
                            'employee_name' => $emp['first_name'] . ' ' . $emp['last_name'],
                            'date' => $row['date'],
                            'time_in' => $row['time_in'],
                            'time_out' => $row['time_out'],
                            'punch_count' => '-',
                            'status' => $row['status'] ?? 'present',
                        ];
                    } else {
                        $unmapped[$row['employee_code']] = [
                            'uid' => $row['employee_code'],
                            'name' => null,
                            'punch_count' => 1,
                            'dates' => [$row['date']],
                        ];
                    }
                }
                $previewRecords = $records;
            } else {
                // Biometric format — aggregate raw punches
                $agg = aggregate_punches($parseResult['punches'], $pdo);
                $previewRecords = $agg['records'];
                $unmapped = $agg['unmapped'];
            }
            $step = 'preview';
        }
    }
}

$pageTitle = 'Import Attendance';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-5">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Import Attendance</h1>
      <p class="text-sm text-slate-500 mt-0.5">Upload biometric device reports or formatted CSV files to import attendance data.</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/attendance/index" class="btn btn-outline">
      <svg class="w-4 h-4 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
      Back to Attendance
    </a>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="rounded-lg bg-red-50 border border-red-200 p-4">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <div>
        <h3 class="text-sm font-semibold text-red-800">Import Errors</h3>
        <ul class="mt-1 text-sm text-red-700 list-disc list-inside space-y-0.5">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($step === 'upload'): ?>
  <!-- Upload Form -->
  <div class="card">
    <div class="card-header">
      <span class="font-semibold text-slate-800">Upload Biometric File</span>
    </div>
    <div class="card-body space-y-5">
      <form method="post" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1 required">Select File</label>
          <input type="file" name="biometric_file" accept=".csv,.dat,.txt,.xls" required
                 class="block w-full text-sm text-slate-700 border border-slate-300 rounded-lg cursor-pointer bg-white file:mr-4 file:py-2 file:px-4 file:rounded-l-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
          <p class="text-xs text-slate-500 mt-1.5">Accepted: .csv, .dat, .txt files up to 10MB</p>
        </div>

        <button type="submit" class="btn btn-primary">
          <svg class="w-4 h-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
          Upload &amp; Parse
        </button>
      </form>

      <!-- Supported Formats Guide -->
      <div class="border-t border-slate-200 pt-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-3">Supported Formats</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-700">AUTO-DETECT</span>
              <span class="text-sm font-medium text-slate-800">ZKTeco .dat</span>
            </div>
            <p class="text-xs text-slate-600">Raw punch log from ZKTeco devices (tab-separated).<br>Fields: UID, DateTime, State, Verify, WorkCode, Reserved</p>
          </div>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-700">AUTO-DETECT</span>
              <span class="text-sm font-medium text-slate-800">ZKTeco CSV Export</span>
            </div>
            <p class="text-xs text-slate-600">ZKBioSecurity / ZKTime export with columns:<br>No, EnrollNumber, Name, GMTDate, Mode</p>
          </div>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-700">AUTO-DETECT</span>
              <span class="text-sm font-medium text-slate-800">ZKAccess Report</span>
            </div>
            <p class="text-xs text-slate-600">ZKAccess software attendance report:<br>Department, Name, No., Date/Time, Status</p>
          </div>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700">STANDARD</span>
              <span class="text-sm font-medium text-slate-800">HRIS CSV</span>
            </div>
            <p class="text-xs text-slate-600">Pre-formatted CSV with columns:<br>employee_code, date, time_in, time_out, status</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($step === 'preview'): ?>
  <!-- Preview + Confirm -->
  <div class="card">
    <div class="card-header flex items-center justify-between flex-wrap gap-2">
      <div>
        <span class="font-semibold text-slate-800">Import Preview</span>
        <span class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700">
          <?= htmlspecialchars($parseResult['format'] ?? 'Unknown') ?>
        </span>
      </div>
      <div class="flex items-center gap-3 text-sm">
        <span class="text-emerald-700 font-medium"><?= count($previewRecords ?? []) ?> records to import</span>
        <?php if (!empty($unmapped)): ?>
        <span class="text-amber-700 font-medium"><?= count($unmapped) ?> unmapped IDs</span>
        <?php endif; ?>
        <?php if (!empty($parseResult['errors'])): ?>
        <span class="text-red-600 font-medium"><?= count($parseResult['errors']) ?> parse warnings</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($previewRecords)): ?>
      <div class="overflow-x-auto">
        <table class="table-basic w-full">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th>Code</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Punches</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($previewRecords as $rec): ?>
            <tr>
              <td class="whitespace-nowrap"><?= htmlspecialchars(date('M d, Y', strtotime($rec['date']))) ?></td>
              <td class="font-medium text-slate-900"><?= htmlspecialchars($rec['employee_name']) ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($rec['employee_code']) ?></td>
              <td class="whitespace-nowrap"><?= $rec['time_in'] ? htmlspecialchars(date('h:i A', strtotime($rec['time_in']))) : '<span class="text-slate-400">—</span>' ?></td>
              <td class="whitespace-nowrap"><?= $rec['time_out'] ? htmlspecialchars(date('h:i A', strtotime($rec['time_out']))) : '<span class="text-slate-400">—</span>' ?></td>
              <td class="text-center"><?= htmlspecialchars($rec['punch_count']) ?></td>
              <td>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">
                  <?= htmlspecialchars(ucfirst($rec['status'])) ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="p-6 text-center text-sm text-slate-500">No importable records found.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($unmapped)): ?>
  <!-- Unmapped Biometric IDs Warning -->
  <div class="rounded-lg bg-amber-50 border border-amber-200 p-4">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      <div class="flex-1">
        <h3 class="text-sm font-semibold text-amber-800">Unmapped Biometric IDs</h3>
        <p class="text-xs text-amber-700 mt-0.5 mb-3">The following biometric device user IDs could not be linked to any employee. Their records will be skipped.</p>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="border-b border-amber-200">
                <th class="text-left py-1.5 text-amber-800">Biometric UID</th>
                <th class="text-left py-1.5 text-amber-800">Name (from device)</th>
                <th class="text-left py-1.5 text-amber-800">Punches</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($unmapped as $uid => $info): ?>
              <tr class="border-b border-amber-100">
                <td class="py-1.5 font-mono"><?= htmlspecialchars($uid) ?></td>
                <td class="py-1.5"><?= htmlspecialchars($info['name'] ?? '—') ?></td>
                <td class="py-1.5"><?= (int)$info['punch_count'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-amber-600 mt-2">
          <strong>Tip:</strong> Set up biometric ID mappings in Attendance &rarr; Biometric Mappings to link device IDs to employees.
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($parseResult['errors'])): ?>
  <!-- Parse Warnings -->
  <div class="card">
    <div class="card-header">
      <span class="font-semibold text-slate-800">Parse Warnings</span>
      <span class="ml-2 text-xs text-slate-500">(<?= count($parseResult['errors']) ?> warnings)</span>
    </div>
    <div class="card-body max-h-40 overflow-y-auto">
      <ul class="text-xs text-slate-600 space-y-0.5 font-mono">
        <?php foreach (array_slice($parseResult['errors'], 0, 50) as $warn): ?>
        <li><?= htmlspecialchars($warn) ?></li>
        <?php endforeach; ?>
        <?php if (count($parseResult['errors']) > 50): ?>
        <li class="text-slate-400">... and <?= count($parseResult['errors']) - 50 ?> more</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- Confirm / Cancel -->
  <div class="flex items-center gap-3">
    <?php if (!empty($previewRecords)): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="confirm_import" value="1">
      <input type="hidden" name="records_json" value="<?= htmlspecialchars(json_encode($previewRecords)) ?>">
      <input type="hidden" name="mappings_json" value="[]">
      <button type="submit" class="btn btn-primary" data-confirm="This will import <?= count($previewRecords) ?> attendance records. Existing records for the same employee+date will be updated. Continue?">
        <svg class="w-4 h-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Confirm Import (<?= count($previewRecords) ?> records)
      </button>
    </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/modules/attendance/import" class="btn btn-outline">
      <svg class="w-4 h-4 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
      Upload Different File
    </a>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
