<?php
/**
 * Access Control — Device API
 * JSON endpoint for AJAX device registration and queries.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/access_control.php';

header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// Read input
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

// CSRF check
if (empty($input['csrf']) || !csrf_verify($input['csrf'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$action = $input['action'] ?? '';

// ─── Register Device ─────────────────────────────────────────────────────
if ($action === 'register') {
    // Must have manage access to system settings
    if (!user_has_access($currentUserId, 'system', 'system_settings', 'manage')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to register devices.']);
        exit;
    }

    $meta = [
        'fingerprint_hash' => $input['fingerprint_hash'] ?? '',
        'label'            => $input['label'] ?? 'Unknown Device',
        'device_type'      => $input['device_type'] ?? 'desktop',
        'user_agent'       => $input['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'screen_info'      => $input['screen_info'] ?? null,
        'timezone'         => $input['timezone'] ?? null,
        'platform'         => $input['platform'] ?? null,
        'language'         => $input['language'] ?? null,
        'notes'            => $input['notes'] ?? null,
        'ip'               => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
    ];

    if ($meta['ip']) {
        $meta['ip'] = trim(explode(',', $meta['ip'])[0]);
    }

    $result = acl_register_device($meta, $currentUserId);
    echo json_encode($result);
    exit;
}

// ─── Get Current Device Info ─────────────────────────────────────────────
if ($action === 'get_device') {
    $hash = $input['fingerprint_hash'] ?? ($_COOKIE['__acl_fp'] ?? '');
    if (!$hash) {
        echo json_encode(['ok' => false, 'error' => 'No fingerprint provided.']);
        exit;
    }

    $device = acl_get_device_by_hash($hash);
    if ($device) {
        echo json_encode(['ok' => true, 'device' => [
            'id' => $device['id'],
            'label' => $device['label'],
            'device_type' => $device['device_type'],
            'is_active' => $device['is_active'],
            'last_seen_at' => $device['last_seen_at'],
        ]]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Device not registered.']);
    }
    exit;
}

// ─── Touch Device (update last_seen) ─────────────────────────────────────
if ($action === 'touch') {
    $hash = $input['fingerprint_hash'] ?? ($_COOKIE['__acl_fp'] ?? '');
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip) $ip = trim(explode(',', $ip)[0]);
    
    if ($hash) {
        acl_touch_device($hash, $ip);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'No fingerprint.']);
    }
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
