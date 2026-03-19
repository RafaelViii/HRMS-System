<?php
// Developer helper to (re)seed the default admin user. Remove or protect in production.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Security: require CLI, local access, or HRMS_TOOL_SECRET via header/POST (never GET to avoid log leaks)
if (php_sapi_name() !== 'cli') {
    $token = $_SERVER['HTTP_X_TOOL_TOKEN'] ?? $_POST['token'] ?? '';
    $expectedToken = getenv('HRMS_TOOL_SECRET') ?: '';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$isLocal && ($expectedToken === '' || !hash_equals($expectedToken, $token))) {
        http_response_code(403);
        echo 'Forbidden - CLI, local access, or HRMS_TOOL_SECRET (via X-Tool-Token header) required';
        exit;
    }
}

$pdo = get_db_conn();
$defaultBranchId = branches_get_default_id($pdo);
$email = SUPERADMIN_EMAIL;
$hash = password_hash(SUPERADMIN_DEFAULT_PASSWORD, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute([':email'=>$email]);
$id = (int)($stmt->fetchColumn() ?: 0);

if ($id) {
    $u = $pdo->prepare("UPDATE users SET password_hash = :hash, role = 'admin', status = 'active', branch_id = COALESCE(branch_id, :branch) WHERE id = :id");
    $u->execute([':hash'=>$hash, ':id'=>$id, ':branch'=>$defaultBranchId]);
    echo 'Admin user updated. Login with ' . SUPERADMIN_EMAIL . ' using the configured SUPERADMIN_DEFAULT_PASSWORD.';
} else {
    $name = 'System Admin';
    $role = 'admin';
    $i = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, branch_id) VALUES (:email,:hash,:name,:role,\'active\',:branch) RETURNING id');
    $i->execute([':email'=>$email, ':hash'=>$hash, ':name'=>$name, ':role'=>$role, ':branch'=>$defaultBranchId]);
    $newId = (int)($i->fetchColumn() ?: 0);
    echo 'Admin user created. Login with ' . SUPERADMIN_EMAIL . ' using the configured SUPERADMIN_DEFAULT_PASSWORD.';
}
