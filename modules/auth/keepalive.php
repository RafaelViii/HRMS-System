<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

// Touch the session meta so the server-side idle timer stays fresh.
if (!empty($_SESSION['__meta']) && is_array($_SESSION['__meta'])) {
    $now = time();
    $_SESSION['__meta']['last_active'] = $now;
    // Do NOT reset 'created' — it anchors the absolute session timeout
    $idleTimeout = (int)($_SESSION['__meta']['idle_timeout'] ?? HRMS_SESSION_IDLE_TIMEOUT);
    $absoluteTimeout = (int)($_SESSION['__meta']['absolute_timeout'] ?? HRMS_SESSION_ABSOLUTE_TIMEOUT);
    $_SESSION['__meta']['idle_expires_at'] = $now + $idleTimeout;
    // Recompute absolute expiry from original creation time
    $created = (int)($_SESSION['__meta']['created'] ?? $now);
    $_SESSION['__meta']['absolute_expires_at'] = $created + $absoluteTimeout;
    $_SESSION['__meta']['server_now'] = $now;
}

$meta = $_SESSION['__meta'] ?? [];
$sessionInfo = [
    'serverNow' => (int)($meta['server_now'] ?? time()),
    'idleExpiresAt' => (int)($meta['idle_expires_at'] ?? 0),
    'absoluteExpiresAt' => (int)($meta['absolute_expires_at'] ?? 0),
    'idleTimeout' => (int)($meta['idle_timeout'] ?? HRMS_SESSION_IDLE_TIMEOUT),
    'absoluteTimeout' => (int)($meta['absolute_timeout'] ?? HRMS_SESSION_ABSOLUTE_TIMEOUT),
];

echo json_encode([
    'ok' => true,
    'session' => $sessionInfo,
]);
