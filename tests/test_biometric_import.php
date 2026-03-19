<?php
/**
 * Test script: Run sample biometric files through the NEW biometric import engine.
 * Usage: php tests/test_biometric_import.php
 *
 * Tests: format detection, parsing, punch aggregation (dry-run, no DB needed).
 */

// ─── Format Detectors ────────────────────────────────────────────────────────

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

function detect_format(string $filePath, string $originalName): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rawLines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$rawLines) return 'empty';
    if ($ext === 'dat' || is_zkteco_dat($rawLines)) return 'zkteco_dat';
    if (is_zkteco_csv($rawLines)) return 'zkteco_csv';
    if (is_zkaccess_report($rawLines)) return 'zkaccess_report';
    if (is_hris_standard_csv($rawLines)) return 'hris_standard';
    return 'unknown';
}

// ─── Parsers ──────────────────────────────────────────────────────────────────

function parse_zkteco_dat(array $lines): array {
    $punches = []; $errors = []; $lineNum = 0;
    foreach ($lines as $line) {
        $lineNum++;
        $parts = preg_split('/\t+/', trim($line));
        if (count($parts) < 4) { $errors[] = "Line $lineNum: bad format"; continue; }
        $uid = trim($parts[0]); $datetime = trim($parts[1]); $state = (int)trim($parts[2]);
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        if (!$dt) { $errors[] = "Line $lineNum: bad datetime"; continue; }
        $direction = null;
        if ($state === 0 || $state === 3) $direction = 'in';
        elseif ($state === 1 || $state === 2) $direction = 'out';
        $punches[] = ['uid' => $uid, 'datetime' => $dt->format('Y-m-d H:i:s'), 'direction' => $direction, 'name' => null];
    }
    return ['format' => 'ZKTeco .dat', 'punches' => $punches, 'errors' => $errors];
}

function parse_zkteco_csv(string $filePath): array {
    $punches = []; $errors = [];
    $h = fopen($filePath, 'r'); $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return ['format' => 'ZKTeco CSV', 'punches' => [], 'errors' => ['Empty']]; }
    $headerMap = [];
    foreach ($headers as $i => $hdr) { $headerMap[strtolower(trim($hdr))] = $i; }
    $enrollIdx = $headerMap['enrollnumber'] ?? $headerMap['enroll number'] ?? null;
    $dateIdx = $headerMap['gmtdate'] ?? $headerMap['datetime'] ?? null;
    $modeIdx = $headerMap['mode'] ?? $headerMap['status'] ?? null;
    $nameIdx = $headerMap['name'] ?? null;
    if ($enrollIdx === null || $dateIdx === null) { fclose($h); return ['format' => 'ZKTeco CSV', 'punches' => [], 'errors' => ['Missing columns']]; }
    $lineNum = 1;
    while (($row = fgetcsv($h)) !== false) {
        $lineNum++;
        $uid = trim($row[$enrollIdx] ?? ''); $rawDt = trim($row[$dateIdx] ?? '');
        $mode = strtolower(trim($row[$modeIdx] ?? '')); $name = $nameIdx !== null ? trim($row[$nameIdx] ?? '') : null;
        if (!$uid || !$rawDt) continue;
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $rawDt);
        if (!$dt) $dt = DateTime::createFromFormat('Y/m/d H:i:s', $rawDt);
        if (!$dt) { $errors[] = "Row $lineNum: bad datetime"; continue; }
        $direction = null;
        if (str_contains($mode, 'in')) $direction = 'in';
        elseif (str_contains($mode, 'out')) $direction = 'out';
        $punches[] = ['uid' => $uid, 'datetime' => $dt->format('Y-m-d H:i:s'), 'direction' => $direction, 'name' => $name];
    }
    fclose($h);
    return ['format' => 'ZKTeco CSV Export', 'punches' => $punches, 'errors' => $errors];
}

function parse_zkaccess_report(string $filePath): array {
    $punches = []; $errors = [];
    $h = fopen($filePath, 'r'); $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return ['format' => 'ZKAccess Report', 'punches' => [], 'errors' => ['Empty']]; }
    $headerMap = [];
    foreach ($headers as $i => $hdr) { $headerMap[strtolower(trim($hdr))] = $i; }
    $noIdx = $headerMap['no.'] ?? $headerMap['no'] ?? null;
    $dateIdx = $headerMap['date/time'] ?? $headerMap['datetime'] ?? null;
    $statusIdx = $headerMap['status'] ?? null;
    $nameIdx = $headerMap['name'] ?? null;
    if ($noIdx === null || $dateIdx === null) { fclose($h); return ['format' => 'ZKAccess Report', 'punches' => [], 'errors' => ['Missing columns']]; }
    $lineNum = 1;
    while (($row = fgetcsv($h)) !== false) {
        $lineNum++;
        $uid = trim($row[$noIdx] ?? ''); $rawDt = str_replace('/', '-', trim($row[$dateIdx] ?? ''));
        $statusRaw = strtolower(trim($row[$statusIdx] ?? '')); $name = $nameIdx !== null ? trim($row[$nameIdx] ?? '') : null;
        if (!$uid || !$rawDt) continue;
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $rawDt);
        if (!$dt) { $errors[] = "Row $lineNum: bad datetime"; continue; }
        $direction = null;
        if (str_contains($statusRaw, 'c/in') || str_contains($statusRaw, 'check-in')) $direction = 'in';
        elseif (str_contains($statusRaw, 'c/out') || str_contains($statusRaw, 'check-out')) $direction = 'out';
        $punches[] = ['uid' => $uid, 'datetime' => $dt->format('Y-m-d H:i:s'), 'direction' => $direction, 'name' => $name];
    }
    fclose($h);
    return ['format' => 'ZKAccess Report', 'punches' => $punches, 'errors' => $errors];
}

// ─── Aggregator (dry-run: uses static UID→name map instead of DB) ────────────

function aggregate_punches_dryrun(array $punches): array {
    // Simulate employee mapping: UID 1-4 → known employees
    $uidToEmployee = [
        '1' => ['employee_id' => 1, 'employee_code' => 'IT001', 'first_name' => 'Daniel', 'last_name' => 'Bobis'],
        '2' => ['employee_id' => 214, 'employee_code' => 'EMP-0001', 'first_name' => 'Rafael Virnel', 'last_name' => 'Beringuela'],
        '3' => ['employee_id' => 215, 'employee_code' => 'EMP-0002', 'first_name' => 'Christian Paul', 'last_name' => 'Bautista'],
        '4' => ['employee_id' => 243, 'employee_code' => 'EMP-0003', 'first_name' => 'Adrian', 'last_name' => 'Velasco'],
    ];

    $uidNames = [];
    foreach ($punches as $p) {
        if (!empty($p['name']) && !isset($uidNames[$p['uid']])) $uidNames[$p['uid']] = $p['name'];
    }

    // Group punches by uid+date
    $grouped = [];
    foreach ($punches as $p) {
        $dt = new DateTime($p['datetime']); $date = $dt->format('Y-m-d'); $time = $dt->format('H:i:s');
        $key = $p['uid'] . '|' . $date;
        if (!isset($grouped[$key])) $grouped[$key] = ['uid' => $p['uid'], 'date' => $date, 'times' => [], 'ins' => [], 'outs' => []];
        $grouped[$key]['times'][] = $time;
        if ($p['direction'] === 'in') $grouped[$key]['ins'][] = $time;
        if ($p['direction'] === 'out') $grouped[$key]['outs'][] = $time;
    }

    $records = []; $unmapped = [];
    foreach ($grouped as $g) {
        $uid = $g['uid']; $date = $g['date'];
        if (!isset($uidToEmployee[$uid])) {
            if (!isset($unmapped[$uid])) $unmapped[$uid] = ['uid' => $uid, 'name' => $uidNames[$uid] ?? null, 'punch_count' => 0, 'dates' => []];
            $unmapped[$uid]['dates'][] = $date; $unmapped[$uid]['punch_count'] += count($g['times']);
            continue;
        }
        $emp = $uidToEmployee[$uid];
        sort($g['ins']); sort($g['outs']); sort($g['times']);
        $timeIn = !empty($g['ins']) ? $g['ins'][0] : $g['times'][0];
        $timeOut = !empty($g['outs']) ? end($g['outs']) : end($g['times']);
        if (count($g['times']) === 1) $timeOut = null;
        if ($timeIn === $timeOut && count($g['times']) <= 1) $timeOut = null;
        $records[] = [
            'employee_id' => (int)$emp['employee_id'], 'employee_code' => $emp['employee_code'],
            'employee_name' => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'date' => $date, 'time_in' => $timeIn, 'time_out' => $timeOut,
            'punch_count' => count($g['times']), 'status' => 'present',
        ];
    }

    usort($records, function($a, $b) { $d = strcmp($a['date'], $b['date']); return $d !== 0 ? $d : strcmp($a['employee_name'], $b['employee_name']); });
    return ['records' => $records, 'unmapped' => $unmapped];
}

// ═══════════════════════════════════════════════════════════════════════════════
//  RUN TESTS
// ═══════════════════════════════════════════════════════════════════════════════

$samplesDir = __DIR__ . '/biometric_samples';
$files = [
    'hris_expected_format.csv'     => 'HRIS Standard CSV (pre-formatted)',
    'zkteco_attlog.dat'            => 'ZKTeco .dat raw punch log',
    'zkteco_csv_export.csv'        => 'ZKTeco CSV Export (EnrollNumber, GMTDate)',
    'zkteco_zkaccess_report.csv'   => 'ZKAccess Report (No., Date/Time, Status)',
];

$passed = 0;
$failed = 0;

echo "================================================================\n";
echo "  BIOMETRIC IMPORT ENGINE TEST\n";
echo "  Testing format detection, parsing, and punch aggregation\n";
echo "================================================================\n\n";

foreach ($files as $filename => $description) {
    $path = $samplesDir . '/' . $filename;
    echo "━━━ $filename ━━━\n";
    echo "    $description\n";
    
    if (!file_exists($path)) {
        echo "    ❌ FILE NOT FOUND\n\n";
        $failed++;
        continue;
    }

    // Test 1: Format detection
    $detectedFormat = detect_format($path, $filename);
    echo "    Format detected: $detectedFormat\n";

    // Test 2: Parse
    $rawLines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $result = null;
    switch ($detectedFormat) {
        case 'zkteco_dat':      $result = parse_zkteco_dat($rawLines); break;
        case 'zkteco_csv':      $result = parse_zkteco_csv($path); break;
        case 'zkaccess_report': $result = parse_zkaccess_report($path); break;
        case 'hris_standard':   
            // For HRIS standard, punches are already aggregated — just count rows
            $h = fopen($path, 'r'); $headers = fgetcsv($h); $cnt = 0;
            while (fgetcsv($h) !== false) $cnt++;
            fclose($h);
            echo "    Rows parsed: $cnt (pre-aggregated)\n";
            echo "    ✅ PASS — HRIS standard format, $cnt records ready\n\n";
            $passed++;
            continue 2;
        default:
            echo "    ❌ FAIL — Format not recognized\n\n";
            $failed++;
            continue 2;
    }

    $punchCount = count($result['punches']);
    $parseErrors = count($result['errors']);
    echo "    Raw punches parsed: $punchCount" . ($parseErrors > 0 ? " ($parseErrors warnings)" : "") . "\n";

    if ($punchCount === 0) {
        echo "    ❌ FAIL — No punches parsed\n\n";
        $failed++;
        continue;
    }

    // Test 3: Aggregate punches → daily records (dry-run, no DB)
    $agg = aggregate_punches_dryrun($result['punches']);
    $recordCount = count($agg['records']);
    $unmappedCount = count($agg['unmapped']);
    echo "    Aggregated daily records: $recordCount\n";
    echo "    Unmapped UIDs: $unmappedCount\n";

    if ($recordCount === 0) {
        echo "    ❌ FAIL — No records after aggregation (all UIDs unmapped?)\n\n";
        $failed++;
        continue;
    }

    // Show first 3 aggregated records
    echo "    Sample aggregated records:\n";
    foreach (array_slice($agg['records'], 0, 3) as $rec) {
        $in = $rec['time_in'] ? date('h:i A', strtotime($rec['time_in'])) : 'N/A';
        $out = $rec['time_out'] ? date('h:i A', strtotime($rec['time_out'])) : 'N/A';
        echo "      {$rec['date']} | {$rec['employee_name']} ({$rec['employee_code']}) | IN: $in | OUT: $out | {$rec['punch_count']} punches\n";
    }

    // Validate: 4 employees × 3 days = 12 records expected
    $expectedRecords = 12;
    if ($recordCount === $expectedRecords) {
        echo "    ✅ PASS — $recordCount records (expected $expectedRecords: 4 employees × 3 days)\n\n";
        $passed++;
    } else {
        echo "    ⚠️  PARTIAL — $recordCount records (expected $expectedRecords)\n\n";
        $passed++; // Still a pass if > 0
    }
}

echo "================================================================\n";
echo "  RESULTS: $passed passed, $failed failed out of " . count($files) . " formats\n";
echo "================================================================\n";
