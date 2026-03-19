<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('payroll', 'payroll_runs', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';

$pdo = get_db_conn();
$batchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
if ($batchId <= 0) {
    flash_error('Batch not specified.');
    header('Location: ' . BASE_URL . '/modules/payroll/index');
    exit;
}

// Fetch batch and run info
$stmt = $pdo->prepare('SELECT pb.*, pr.period_start, pr.period_end, pr.id AS run_id, b.name AS branch_name FROM payroll_batches pb JOIN payroll_runs pr ON pr.id = pb.payroll_run_id LEFT JOIN branches b ON b.id = pb.branch_id WHERE pb.id = :id');
$stmt->execute([':id' => $batchId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
    flash_error('Batch not found.');
    header('Location: ' . BASE_URL . '/modules/payroll/index');
    exit;
}
$runId = (int)$batch['payroll_run_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid form token.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    if (empty($_FILES['dtr']['name'])) {
        flash_error('Please choose a CSV file to upload.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    $file = $_FILES['dtr'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_error('Failed to upload DTR file.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    // Support CSV and XLSX
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv','xlsx'], true)) {
        flash_error('Invalid file type. Please upload a .csv or .xlsx file.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    // M-05 fix matching: Actual content type validation
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
  
    $validMime = false;
    if (in_array($ext, ['xls', 'xlsx'], true) && in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) { $validMime = true; }
    elseif ($ext === 'csv' && in_array($mimeType, ['text/csv', 'text/plain'], true)) { $validMime = true; }

    if (!$validMime) {
        flash_error('File contains invalid content for its extension.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    // Destination folder
    $destDir = __DIR__ . '/../../assets/uploads/payroll/dtr/' . $runId . '/' . $batchId;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }

    // Define a minimal required header set for validation
    $requiredHeaders = ['employee_code','date']; // time_in, time_out, overtime_minutes are optional for now
    $headers = [];
    $rowCount = 0;
    $savedPath = '';

    // Helper: read first line headers from CSV (without moving file yet)
    $detectCsvHeaders = function(string $tmpPath) use (&$rowCount): array {
        $h = @fopen($tmpPath, 'r');
        if (!$h) return [];
        $headers = fgetcsv($h);
        while (($r = fgetcsv($h)) !== false) { $rowCount++; }
        fclose($h);
        return is_array($headers) ? array_map(fn($v)=>strtolower(trim((string)$v)), $headers) : [];
    };

    // Helper: very basic XLSX header extraction using ZipArchive (no external libs)
    $xlsxToCsv = function(string $xlsxPath, string $csvPath) use (&$headers, &$rowCount): bool {
        if (!class_exists('ZipArchive')) { return false; }
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) { return false; }
        // Load sharedStrings (if any)
        $shared = [];
        $ssIdx = $zip->locateName('xl/sharedStrings.xml');
        if ($ssIdx !== false) {
            $xml = @simplexml_load_string($zip->getFromIndex($ssIdx));
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $i => $si) {
                    // si may have t or multiple r/t nodes
                    if (isset($si->t)) { $shared[(int)$i] = (string)$si->t; }
                    elseif (isset($si->r)) {
                        $parts = [];
                        foreach ($si->r as $r) { $parts[] = (string)$r->t; }
                        $shared[(int)$i] = implode('', $parts);
                    }
                }
            }
        }
        // Load first worksheet
        $sheetIdx = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIdx === false) { $zip->close(); return false; }
        $sheetXml = @simplexml_load_string($zip->getFromIndex($sheetIdx));
        $zip->close();
        if (!$sheetXml) { return false; }
        // Open CSV for writing
        $out = @fopen($csvPath, 'w'); if (!$out) return false;
        $rowNum = 0; $headers = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $t = (string)($c['t'] ?? '');
                $v = (string)($c->v ?? '');
                if ($t === 's') { // shared string
                    $idx = (int)$v; $cells[] = isset($shared[$idx]) ? $shared[$idx] : '';
                } else {
                    $cells[] = $v;
                }
            }
            if ($rowNum === 0) {
                $headers = array_map(fn($v)=>strtolower(trim((string)$v)), $cells);
            }
            fputcsv($out, $cells);
            $rowNum++;
        }
        fclose($out);
        $rowCount = max(0, $rowNum - 1);
        return true;
    };

    // Validate and save
    $safeBase = sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME));
    $timestamp = time();
    if ($ext === 'csv') {
        // Validate CSV headers from tmp
        $headers = $detectCsvHeaders($file['tmp_name']);
        if (!$headers) {
            flash_error('The CSV appears to be empty or unreadable.');
            header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
            exit;
        }
        $missing = array_values(array_diff($requiredHeaders, $headers));
        if ($missing) {
            flash_error('CSV missing required column(s): ' . implode(', ', $missing));
            header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
            exit;
        }
        $finalName = $safeBase . '_' . $timestamp . '.csv';
        $destPath = $destDir . '/' . $finalName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            flash_error('Could not save uploaded DTR file.');
            header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
            exit;
        }
        $savedPath = $destPath;
    } else {
        // XLSX: convert to CSV if ZipArchive available
        $tmpXlsx = $file['tmp_name'];
        $finalName = $safeBase . '_' . $timestamp . '.csv';
        $destPath = $destDir . '/' . $finalName;
        $ok = false;
        try { $ok = $xlsxToCsv($tmpXlsx, $destPath); } catch (Throwable $e) { $ok = false; }
        if (!$ok) {
            flash_error('XLSX parsing is unavailable on this server. Please upload a CSV export instead.');
            header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
            exit;
        }
        // Validate headers extracted during conversion
        if (!$headers) {
            // Fallback: read headers from generated CSV if not captured
            $headers = $detectCsvHeaders($destPath);
        }
        $missing = array_values(array_diff($requiredHeaders, $headers));
        if ($missing) {
            @unlink($destPath);
            flash_error('Excel file missing required column(s): ' . implode(', ', $missing));
            header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
            exit;
        }
        $savedPath = $destPath;
    }

    $repoRoot = realpath(__DIR__ . '/../../');
    $normalizedDest = str_replace('\\', '/', realpath($savedPath));
    $relative = $repoRoot ? str_replace(str_replace('\\', '/', $repoRoot), '', $normalizedDest) : '';
    if (!$relative || $relative === $normalizedDest) {
        $relative = '/assets/uploads/payroll/dtr/' . $runId . '/' . $batchId . '/' . basename($savedPath);
    }

    $currentUser = current_user();
    $actingUserId = (int)($currentUser['id'] ?? 0);
    if (!payroll_attach_batch_dtr($pdo, $batchId, $relative, $actingUserId)) {
        flash_error('Failed to attach DTR to batch.');
        header('Location: ' . BASE_URL . '/modules/payroll/dtr_upload?batch_id=' . $batchId);
        exit;
    }

    // Optionally enqueue compute if run/batch is queued mode
    if (strtolower((string)($batch['computation_mode'] ?? 'queued')) === 'queued') {
        $jobId = payroll_enqueue_batch_compute($pdo, $batchId, $actingUserId, $relative);
        if ($jobId) {
            flash_success('DTR uploaded (' . ($rowCount ?: 'n/a') . ' rows) and batch enqueued for computation. Job: ' . $jobId);
        } else {
            flash_success('DTR uploaded (' . ($rowCount ?: 'n/a') . ' rows). You can queue computation from the run view.');
        }
    } else {
        flash_success('DTR uploaded successfully (' . ($rowCount ?: 'n/a') . ' rows).');
    }

    header('Location: ' . BASE_URL . '/modules/payroll/run_view?id=' . $runId);
    exit;
}

$csrf = csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-xl">
  <h1 class="text-2xl font-semibold mb-2">Upload DTR for Batch</h1>
  <p class="text-sm text-gray-600 mb-4">
    Run #<?= (int)$runId ?> • Branch <?= htmlspecialchars($batch['branch_name'] ?: ('#' . (int)$batch['branch_id'])) ?>
  </p>
    <form method="post" enctype="multipart/form-data" class="space-y-4 card p-4">
    <input type="hidden" name="csrf" value="<?= $csrf ?>" />
    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>" />
    <div>
            <label class="block text-sm text-gray-700 mb-1">DTR File (CSV or Excel)</label>
            <input type="file" name="dtr" accept=".csv,.xlsx" class="w-full" required />
            <p class="text-xs text-gray-500 mt-1">Upload a CSV or Excel (.xlsx) export of the branch's DTR for this pay period. Required columns: <code>employee_code</code>, <code>date</code>.</p>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary">Upload</button>
      <a href="<?= BASE_URL ?>/modules/payroll/run_view?id=<?= (int)$runId ?>" class="btn btn-outline">Back to Run</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
