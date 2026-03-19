<?php
/**
 * Print Server API — status checks, stats, job management
 * GET: action=check_status&id=N | action=stats | action=queue | action=history
 * POST: action=create_job | action=update_status | action=process_queue
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();

header('Content-Type: application/json');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);

// Permission checks
$canRead = user_has_access($uid, 'inventory', 'print_server', 'read');
$canWrite = user_has_access($uid, 'inventory', 'print_server', 'write');
$canManage = user_has_access($uid, 'inventory', 'print_server', 'manage');

if (!$canRead) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ──────────────────────────────────────────────
// GET: Check printer status (attempt TCP connection)
// ──────────────────────────────────────────────
if ($action === 'check_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM printers WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $printer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$printer) {
        echo json_encode(['success' => false, 'error' => 'Printer not found']);
        exit;
    }

    $status = 'offline';
    $message = '';
    $responseTime = null;

    if ($printer['connection_type'] === 'network' && $printer['ip_address']) {
        $ip = $printer['ip_address'];
        $port = (int)($printer['port'] ?: 9100);
        $start = microtime(true);

        // Attempt TCP connection with 3-second timeout
        $sock = @fsockopen($ip, $port, $errno, $errstr, 3);
        $elapsed = round((microtime(true) - $start) * 1000);

        if ($sock) {
            fclose($sock);
            $status = 'online';
            $message = 'Connected successfully';
            $responseTime = $elapsed;
        } else {
            $status = 'offline';
            $message = $errstr ?: 'Connection refused or timed out';
        }
    } elseif ($printer['connection_type'] === 'usb') {
        // USB printers — we check if the server can see them (OS-dependent)
        // For now, mark as needs manual check
        $status = $printer['status']; // keep existing
        $message = 'USB printers require manual status verification';
    } elseif ($printer['connection_type'] === 'bluetooth') {
        $status = $printer['status'];
        $message = 'Bluetooth printers require manual status verification';
    }

    // Update the printer status in DB
    $updateStmt = $pdo->prepare("UPDATE printers SET status = :status, last_seen_at = CASE WHEN :status2 = 'online' THEN NOW() ELSE last_seen_at END, last_error = CASE WHEN :status3 != 'online' THEN :msg ELSE NULL END, updated_at = NOW() WHERE id = :id");
    $updateStmt->execute([
        ':status' => $status,
        ':status2' => $status,
        ':status3' => $status,
        ':msg' => ($status !== 'online') ? $message : null,
        ':id' => $id
    ]);

    echo json_encode([
        'success' => true,
        'status' => $status,
        'message' => $message,
        'response_time' => $responseTime,
        'printer_name' => $printer['name']
    ]);
    exit;
}

// ──────────────────────────────────────────────
// GET: Dashboard stats
// ──────────────────────────────────────────────
if ($action === 'stats') {
    $totalPrinters = $pdo->query("SELECT COUNT(*) FROM printers")->fetchColumn();
    $onlinePrinters = $pdo->query("SELECT COUNT(*) FROM printers WHERE status = 'online'")->fetchColumn();
    $queueCount = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','printing')")->fetchColumn();
    $todayCount = $pdo->query("SELECT COUNT(*) FROM print_history WHERE created_at >= CURRENT_DATE")->fetchColumn();

    echo json_encode([
        'success' => true,
        'total_printers' => (int)$totalPrinters,
        'online_printers' => (int)$onlinePrinters,
        'queue_count' => (int)$queueCount,
        'today_prints' => (int)$todayCount
    ]);
    exit;
}

// ──────────────────────────────────────────────
// GET: Queue list (for real-time updates)
// ──────────────────────────────────────────────
if ($action === 'queue') {
    $queue = $pdo->query("SELECT pj.*, p.name as printer_name FROM print_jobs pj LEFT JOIN printers p ON p.id = pj.printer_id WHERE pj.status IN ('queued','printing') ORDER BY pj.priority ASC, pj.created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'jobs' => $queue]);
    exit;
}

// ──────────────────────────────────────────────
// GET: History list with pagination
// ──────────────────────────────────────────────
if ($action === 'history') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    $statusFilter = $_GET['status'] ?? '';
    $search = trim($_GET['q'] ?? '');

    $where = [];
    $params = [];
    if ($statusFilter && in_array($statusFilter, ['completed', 'failed', 'cancelled'])) {
        $where[] = "ph.status = :status";
        $params[':status'] = $statusFilter;
    }
    if ($search) {
        $where[] = "(ph.printer_name ILIKE :q OR ph.document_title ILIKE :q2 OR ph.document_ref ILIKE :q3 OR ph.user_name ILIKE :q4)";
        $params[':q'] = "%$search%";
        $params[':q2'] = "%$search%";
        $params[':q3'] = "%$search%";
        $params[':q4'] = "%$search%";
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM print_history ph $whereStr");
    $total->execute($params);
    $totalCount = $total->fetchColumn();

    $stmt = $pdo->prepare("SELECT ph.* FROM print_history ph $whereStr ORDER BY ph.created_at DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'history' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => (int)$totalCount,
        'page' => $page,
        'pages' => ceil($totalCount / $limit)
    ]);
    exit;
}

// ──────────────────────────────────────────────
// POST: Create print job (from other modules)
// ──────────────────────────────────────────────
if ($action === 'create_job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!csrf_verify($input['csrf'] ?? '')) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit; }

    $printerId = (int)($input['printer_id'] ?? 0);
    $docType = $input['document_type'] ?? 'receipt';
    $docRef = $input['document_ref'] ?? null;
    $docTitle = $input['document_title'] ?? null;
    $content = $input['content'] ?? null;
    $copies = max(1, (int)($input['copies'] ?? 1));
    $priority = max(1, min(10, (int)($input['priority'] ?? 5)));

    // If no printer specified, use smart office allocation (if enabled) or default
    if (!$printerId) {
        $officeAllocEnabled = false;
        try {
            $settingStmt = $pdo->prepare("SELECT setting_value FROM print_server_settings WHERE setting_key = 'office_allocation_enabled'");
            $settingStmt->execute();
            $officeAllocEnabled = ($settingStmt->fetchColumn() === 'true');
        } catch (PDOException $e) {}

        if ($officeAllocEnabled) {
            $userBranchId = (int)($_SESSION['user']['branch_id'] ?? 0);
            if ($userBranchId) {
                // Try to find an enabled printer in the user's branch (prefer default, then online, then any)
                $brStmt = $pdo->prepare("SELECT id FROM printers WHERE branch_id = :bid AND is_enabled = TRUE ORDER BY is_default DESC, CASE WHEN status = 'online' THEN 0 ELSE 1 END, name LIMIT 1");
                $brStmt->execute([':bid' => $userBranchId]);
                $brPrinter = $brStmt->fetchColumn();
                if ($brPrinter) $printerId = (int)$brPrinter;
            }
        }

        // Fallback: global default printer
        if (!$printerId) {
            $def = $pdo->query("SELECT id FROM printers WHERE is_default = TRUE AND is_enabled = TRUE LIMIT 1")->fetchColumn();
            if ($def) $printerId = (int)$def;
        }
    }

    if (!$printerId) {
        echo json_encode(['success' => false, 'error' => 'No printer specified and no default printer configured.']);
        exit;
    }

    // Verify printer exists and is enabled
    $printerCheck = $pdo->prepare("SELECT id, name FROM printers WHERE id = :id AND is_enabled = TRUE");
    $printerCheck->execute([':id' => $printerId]);
    $printer = $printerCheck->fetch(PDO::FETCH_ASSOC);

    if (!$printer) {
        echo json_encode(['success' => false, 'error' => 'Printer not found or disabled.']);
        exit;
    }

    // Generate job number
    $today = date('Ymd');
    $cnt = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE job_number LIKE 'PJ-$today-%'")->fetchColumn();
    $jobNum = 'PJ-' . $today . '-' . str_pad($cnt + 1, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("INSERT INTO print_jobs (printer_id, job_number, document_type, document_ref, document_title, content_data, copies, priority, status, created_by) VALUES (:pid, :jn, :dt, :dr, :dtl, :cd, :cp, :pr, 'queued', :uid) RETURNING id");
    $stmt->execute([
        ':pid' => $printerId,
        ':jn' => $jobNum,
        ':dt' => $docType,
        ':dr' => $docRef,
        ':dtl' => $docTitle,
        ':cd' => $content,
        ':cp' => $copies,
        ':pr' => $priority,
        ':uid' => $uid
    ]);
    $jobId = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'job_number' => $jobNum,
        'printer_name' => $printer['name']
    ]);
    exit;
}

// ──────────────────────────────────────────────
// POST: Process queue — simulate completing queued jobs
// (In production this would talk to actual printer daemon)
// ──────────────────────────────────────────────
if ($action === 'process_queue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!csrf_verify($input['csrf'] ?? '')) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit; }

    // Get next queued job
    $stmt = $pdo->query("SELECT pj.*, p.name as printer_name, p.status as printer_status FROM print_jobs pj LEFT JOIN printers p ON p.id = pj.printer_id WHERE pj.status = 'queued' ORDER BY pj.priority ASC, pj.created_at ASC LIMIT 1");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo json_encode(['success' => true, 'message' => 'No jobs to process']);
        exit;
    }

    $start = microtime(true);

    // Mark as printing
    $pdo->prepare("UPDATE print_jobs SET status = 'printing', started_at = NOW() WHERE id = :id")
        ->execute([':id' => $job['id']]);

    // Simulate print (in production, send to actual printer here)
    $success = ($job['printer_status'] === 'online');
    $duration = round((microtime(true) - $start) * 1000);

    $newStatus = $success ? 'completed' : 'failed';
    $errorMsg = $success ? null : 'Printer is ' . ($job['printer_status'] ?? 'offline');

    // Update job
    $pdo->prepare("UPDATE print_jobs SET status = :s, error_message = :e, completed_at = NOW() WHERE id = :id")
        ->execute([':s' => $newStatus, ':e' => $errorMsg, ':id' => $job['id']]);

    // Write to history
    $pdo->prepare("INSERT INTO print_history (printer_id, print_job_id, printer_name, document_type, document_ref, document_title, copies, status, error_message, duration_ms, created_by, user_name) VALUES (:pid, :jid, :pn, :dt, :dr, :dtl, :cp, :s, :e, :dur, :uid, :un)")
        ->execute([
            ':pid' => $job['printer_id'],
            ':jid' => $job['id'],
            ':pn' => $job['printer_name'] ?? '',
            ':dt' => $job['document_type'],
            ':dr' => $job['document_ref'],
            ':dtl' => $job['document_title'],
            ':cp' => $job['copies'],
            ':s' => $newStatus,
            ':e' => $errorMsg,
            ':dur' => $duration,
            ':uid' => $job['created_by'],
            ':un' => $_SESSION['user']['name'] ?? ''
        ]);

    echo json_encode([
        'success' => true,
        'job_id' => $job['id'],
        'job_number' => $job['job_number'],
        'status' => $newStatus,
        'duration_ms' => $duration
    ]);
    exit;
}

// ──────────────────────────────────────────────
// POST: Manually update printer status
// ──────────────────────────────────────────────
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!csrf_verify($input['csrf'] ?? '')) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit; }

    $id = (int)($input['printer_id'] ?? 0);
    $newStatus = $input['status'] ?? '';

    if (!in_array($newStatus, ['online', 'offline', 'error', 'busy'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE printers SET status = :s, last_seen_at = CASE WHEN :s2 = 'online' THEN NOW() ELSE last_seen_at END, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':s' => $newStatus, ':s2' => $newStatus, ':id' => $id]);

    action_log('inventory', 'update', 'success', ['entity' => 'printer_status', 'id' => $id, 'new_status' => $newStatus]);

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

// ──────────────────────────────────────────────
// GET: List all printers (for dropdowns in other modules)
// ──────────────────────────────────────────────
if ($action === 'list_printers') {
    $branchFilter = (int)($_GET['branch_id'] ?? 0);
    $sql = "SELECT p.id, p.name, p.printer_type, p.connection_type, p.status, p.is_default, p.is_enabled, p.location, p.branch_id, b.name as branch_name FROM printers p LEFT JOIN branches b ON b.id = p.branch_id WHERE p.is_enabled = TRUE";
    $params = [];
    if ($branchFilter) {
        $sql .= " AND p.branch_id = :bid";
        $params[':bid'] = $branchFilter;
    }
    $sql .= " ORDER BY p.is_default DESC, p.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'printers' => $printers]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
