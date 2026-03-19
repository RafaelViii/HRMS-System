<?php
/**
 * QZ Tray Settings API — per-user QZ Tray preferences (printer, paper, auto-print)
 * GET:  action=get          → fetch current user's QZ Tray settings
 * POST: action=save         → save current user's QZ Tray settings
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();

header('Content-Type: application/json');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);

// QZ settings are per-user preferences needed by both POS and Print Server users
if (!user_has_access($uid, 'inventory', 'pos_transactions', 'read') &&
    !user_has_access($uid, 'inventory', 'print_server', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized — requires POS or Print Server access']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ──────────────────────────────────────────────
// GET: Fetch current user's QZ Tray settings
// ──────────────────────────────────────────────
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT default_printer, paper_width, auto_print FROM qz_tray_settings WHERE user_id = :uid");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode([
                'success'  => true,
                'settings' => [
                    'default_printer' => $row['default_printer'] ?? '',
                    'paper_width'     => (int)$row['paper_width'],
                    'auto_print'      => $row['auto_print'] === true || $row['auto_print'] === 't' || $row['auto_print'] === '1'
                ]
            ]);
        } else {
            // No settings yet — return defaults
            echo json_encode([
                'success'  => true,
                'settings' => [
                    'default_printer' => '',
                    'paper_width'     => 48,
                    'auto_print'      => false
                ]
            ]);
        }
    } catch (PDOException $e) {
        // Table may not exist yet
        echo json_encode([
            'success'  => true,
            'settings' => [
                'default_printer' => '',
                'paper_width'     => 48,
                'auto_print'      => false
            ]
        ]);
    }
    exit;
}

// ──────────────────────────────────────────────
// POST: Save current user's QZ Tray settings
// ──────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid request body']);
        exit;
    }

    if (!csrf_verify($input['csrf'] ?? '')) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit; }

    $defaultPrinter = trim($input['default_printer'] ?? '');
    $paperWidth     = max(32, min(80, (int)($input['paper_width'] ?? 48)));
    $autoPrint      = ($input['auto_print'] === true || $input['auto_print'] === 'true' || $input['auto_print'] === '1');

    try {
        // Upsert: insert or update on conflict
        $stmt = $pdo->prepare("
            INSERT INTO qz_tray_settings (user_id, default_printer, paper_width, auto_print, updated_at)
            VALUES (:uid, :printer, :pw, :ap, NOW())
            ON CONFLICT (user_id) DO UPDATE
            SET default_printer = EXCLUDED.default_printer,
                paper_width     = EXCLUDED.paper_width,
                auto_print      = EXCLUDED.auto_print,
                updated_at      = NOW()
        ");
        $stmt->execute([
            ':uid'     => $uid,
            ':printer' => $defaultPrinter ?: null,
            ':pw'      => $paperWidth,
            ':ap'      => $autoPrint ? 't' : 'f'
        ]);

        action_log('inventory', 'update', 'success', [
            'entity'          => 'qz_tray_settings',
            'default_printer' => $defaultPrinter,
            'paper_width'     => $paperWidth,
            'auto_print'      => $autoPrint
        ]);

        echo json_encode(['success' => true, 'message' => 'QZ Tray settings saved']);
    } catch (PDOException $e) {
        sys_log('DB001', 'Failed to save QZ Tray settings', ['error' => $e->getMessage(), 'user_id' => $uid]);
        echo json_encode(['success' => false, 'error' => 'Could not save settings. Please run migrations.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
