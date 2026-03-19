<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/permissions.php';
session_bootstrap();
session_validate();

// Superadmin constants — defined in config.php via env vars.
// Fallback here in case config.php was not loaded first.
if (!defined('SUPERADMIN_USER_ID')) {
    define('SUPERADMIN_USER_ID', (int)(getenv('SUPERADMIN_USER_ID') ?: 0));
}
if (!defined('SUPERADMIN_EMAIL')) {
    define('SUPERADMIN_EMAIL', getenv('SUPERADMIN_EMAIL') ?: 'bobis.daniel.bscs2023@gmail.com');
}

if (!defined('HRMS_REMEMBER_COOKIE')) {
    define('HRMS_REMEMBER_COOKIE', 'HRMSREMEMBER');
    define('HRMS_REMEMBER_COOKIE_LIFETIME', 60 * 60 * 24 * 30); // 30 days
}

/** Backwards-compatible cookie setter for PHP < 7.3 */
function hrms_setcookie(string $name, string $value, array $options): void {
    $expires = (int)($options['expires'] ?? 0);
    $path = (string)($options['path'] ?? '/');
    $domain = (string)($options['domain'] ?? '');
    $secure = (bool)($options['secure'] ?? false);
    $httponly = (bool)($options['httponly'] ?? true);
    $samesite = (string)($options['samesite'] ?? 'Lax');
    // If PHP supports options array (>=7.3), use it directly
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, $options);
        return;
    }
    // For older versions, append SameSite to path attribute
    if ($samesite) {
        $path .= '; samesite=' . $samesite;
    }
    setcookie($name, $value, $expires, $path, $domain, $secure, $httponly);
}

/** Build cookie options for the remember-me token. */
function remember_cookie_options(int $expires): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Persist a remember-me token for the given user and set the browser cookie. */
function remember_issue_token(int $userId): void {
    try {
        $pdo = get_db_conn();
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :uid OR expires_at < NOW()')->execute([':uid' => $userId]);
        $selector = bin2hex(random_bytes(9));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (:uid, :selector, :hash, :expires)');
        $stmt->execute([
            ':uid' => $userId,
            ':selector' => $selector,
            ':hash' => $hash,
            ':expires' => $expiresAt,
        ]);
        $cookieValue = $selector . ':' . $token;
        $options = remember_cookie_options(time() + HRMS_REMEMBER_COOKIE_LIFETIME);
        hrms_setcookie(HRMS_REMEMBER_COOKIE, $cookieValue, $options);
        $_COOKIE[HRMS_REMEMBER_COOKIE] = $cookieValue;
    } catch (Throwable $e) {
        sys_log('AUTH-REMEMBER-ISSUE', 'Failed issuing remember token: ' . $e->getMessage(), ['module' => 'auth', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['user_id' => $userId]]);
    }
}

/** Clear remember-me cookie locally. */
function remember_clear_cookie(): void {
    $options = remember_cookie_options(time() - 3600);
    hrms_setcookie(HRMS_REMEMBER_COOKIE, '', $options);
    unset($_COOKIE[HRMS_REMEMBER_COOKIE]);
}

/** Remove persisted remember-me tokens for a user or selector. */
function remember_clear_tokens(?int $userId = null, ?string $selector = null): void {
    if ($userId === null && ($selector === null || $selector === '')) {
        return;
    }
    try {
        $pdo = get_db_conn();
        if ($userId !== null) {
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = :uid')->execute([':uid' => $userId]);
        } elseif ($selector !== null) {
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = :selector')->execute([':selector' => $selector]);
        }
    } catch (Throwable $e) {
        sys_log('AUTH-REMEMBER-CLEAR', 'Failed clearing remember token(s): ' . $e->getMessage(), ['module' => 'auth', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['user_id' => $userId, 'selector' => $selector]]);
    }
}

/** Complete login lifecycle: rotate session, stamp metadata, and audit. */
function auth_complete_login(array $user, string $method = 'password'): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['__meta'] = $_SESSION['__meta'] ?? [];
        $_SESSION['__meta']['created'] = time();
        $_SESSION['__meta']['last_active'] = time();
        $_SESSION['__meta']['rotate_at'] = time() + 300;
    }

    $_SESSION['user'] = [
        'id' => (int)($user['id'] ?? 0),
        'email' => $user['email'] ?? '',
        'name' => $user['full_name'] ?? ($user['name'] ?? ''),
        'role' => $user['role'] ?? 'employee',
        'branch_id' => isset($user['branch_id']) ? (int)$user['branch_id'] : null,
        'branch_name' => $user['branch_name'] ?? null,
        'branch_code' => $user['branch_code'] ?? null,
    ];

    try {
        $pdo = get_db_conn();
        $upd = $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id');
        $upd->execute([':id' => (int)($user['id'] ?? 0)]);
    } catch (Throwable $e) {
        sys_log('AUTH-LOGIN-LAST', 'Failed updating last_login: ' . $e->getMessage(), ['module' => 'auth', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['user_id' => $user['id'] ?? null]]);
    }

    $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', (string)$rawIp)[0]);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $payload = [
        'event' => 'login',
        'method' => $method,
        'ip' => $ip,
        'ua' => $ua,
    ];
    audit('login', json_encode($payload, JSON_UNESCAPED_SLASHES));
}

/** Attempt automatic login using a persisted remember-me token. */
function auth_attempt_remember_login(): bool {
    if (!empty($_SESSION['user'])) {
        return true;
    }
    $cookie = $_COOKIE[HRMS_REMEMBER_COOKIE] ?? '';
    if (!$cookie || strpos($cookie, ':') === false) {
        return false;
    }
    [$selector, $token] = explode(':', $cookie, 2);
    $selector = trim($selector ?? '');
    $token = trim($token ?? '');
    if ($selector === '' || $token === '') {
        remember_clear_cookie();
        return false;
    }
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('SELECT user_id, token_hash, expires_at FROM user_remember_tokens WHERE selector = :selector LIMIT 1');
        $stmt->execute([':selector' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            remember_clear_cookie();
            return false;
        }
        $expiresTs = strtotime((string)($row['expires_at'] ?? '')) ?: 0;
        if ($expiresTs < time()) {
            remember_clear_tokens(null, $selector);
            remember_clear_cookie();
            return false;
        }
        $expected = (string)($row['token_hash'] ?? '');
        $actual = hash('sha256', $token);
        if (!hash_equals($expected, $actual)) {
            remember_clear_tokens((int)$row['user_id']);
            remember_clear_cookie();
            return false;
        }

                $userStmt = $pdo->prepare('SELECT u.id, u.email, u.full_name, u.role, u.status, u.branch_id, b.name AS branch_name, b.code AS branch_code
                    FROM users u
                    LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE u.id = :id
                 LIMIT 1');
                $userStmt->execute([':id' => (int)$row['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            remember_clear_tokens((int)$row['user_id']);
            remember_clear_cookie();
            return false;
        }

        auth_complete_login($user, 'remember_token');
        remember_clear_tokens(null, $selector);
        remember_issue_token((int)$user['id']);
        return true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // If table doesn't exist or migration not yet applied, degrade gracefully without erroring the page
        if (stripos($msg, 'user_remember_tokens') !== false && (stripos($msg, 'does not exist') !== false || stripos($msg, 'undefined') !== false)) {
            // no-op
        } else {
            sys_log('AUTH-REMEMBER-LOGIN', 'Auto-login via remember token failed: ' . $msg, ['module' => 'auth', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['selector' => $selector]]);
        }
        remember_clear_cookie();
        return false;
    }
}

/**
 * Check if a user is the superadmin (configured in config.php).
 * This account has unlimited access and CANNOT be edited or deleted.
 * 
 * @param int|array|null $user User ID, user array, or null to check current user
 * @return bool True if the user is the superadmin
 */
function is_superadmin($user = null): bool {
    if ($user === null) {
        $user = $_SESSION['user'] ?? null;
    }
    
    if (is_array($user)) {
        $userId = (int)($user['id'] ?? 0);
    } else {
        $userId = (int)$user;
    }
    
    return $userId === SUPERADMIN_USER_ID;
}

/**
 * Check if the current user can edit a specific user account.
 * Superadmin cannot be edited by anyone, including themselves.
 * 
 * @param int $targetUserId The user ID being edited
 * @return bool True if editing is allowed
 */
function can_edit_user(int $targetUserId): bool {
    // Superadmin account is LOCKED - cannot be edited by anyone
    if ($targetUserId === SUPERADMIN_USER_ID) {
        return false;
    }
    
    // Current user must have write access to user_accounts
    return user_can('user_management', 'user_accounts', 'write');
}

function auth_login(string $email, string $password, bool $remember = false): bool {
    $pdo = get_db_conn();
        $stmt = $pdo->prepare('SELECT u.id, u.email, u.password_hash, u.full_name, u.role, u.status, u.branch_id, b.name AS branch_name, b.code AS branch_code
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.email = :email
         LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && ($user['status'] ?? '') === 'active' && password_verify($password, $user['password_hash'])) {
        // Rehash password if algorithm has been upgraded
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                    ->execute([':hash' => $newHash, ':id' => (int)$user['id']]);
            } catch (Throwable $e) { /* non-fatal */ }
        }
        auth_complete_login($user, 'password');
        if ($remember) {
            remember_issue_token((int)$user['id']);
        } else {
            remember_clear_tokens((int)$user['id']);
            remember_clear_cookie();
        }
        return true;
    }
    return false;
}

function auth_logout(): void {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    $selector = null;
    if (!empty($_COOKIE[HRMS_REMEMBER_COOKIE]) && strpos($_COOKIE[HRMS_REMEMBER_COOKIE], ':') !== false) {
        [$sel] = explode(':', $_COOKIE[HRMS_REMEMBER_COOKIE], 2);
        $selector = $sel;
    }
    audit('logout', 'User logged out');
    if ($uid > 0) {
        remember_clear_tokens($uid);
    }
    if ($selector) {
        remember_clear_tokens(null, $selector);
    }
    remember_clear_cookie();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function require_login(): void {
    if (empty($_SESSION['user'])) {
    header('Location: ' . BASE_URL . '/login');
        exit;
    }
    // Re-validate user is still active every 5 minutes
    $lastCheck = $_SESSION['__meta']['status_check'] ?? 0;
    if (time() - $lastCheck > 300) {
        try {
            $pdo = get_db_conn();
            $st = $pdo->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => (int)$_SESSION['user']['id']]);
            $status = $st->fetchColumn();
            if ($status !== 'active') {
                auth_logout();
                header('Location: ' . BASE_URL . '/login');
                exit;
            }
            $_SESSION['__meta']['status_check'] = time();
        } catch (Throwable $e) {
            // If DB is unreachable, allow session to continue briefly
        }
    }
}

/**
 * DEPRECATED: Use require_access($domain, $resource, $level) instead.
 * 
 * Legacy role-based guard. Still functional during migration but will be removed.
 * Maps old roles to new position-based permissions where possible.
 */
function require_role(array $roles): void {
    require_login();
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        header('Location: ' . BASE_URL . '/unauthorized');
        exit;
    }
    
    // Check if user is system admin (bypass all role checks)
    if (!empty($user['is_system_admin'])) {
        return; // System admins always pass
    }
    
    // Try new position-based system first
    // Map roles to common permission patterns
    $rolePermissionMap = [
        'admin' => ['system', 'system_settings', 'manage'],
        'hr' => ['hr_core', 'employees', 'write'],
        'hr_supervisor' => ['hr_core', 'employees', 'manage'],
        'hr_payroll' => ['payroll', 'payroll_runs', 'write'],
        'accountant' => ['payroll', 'payroll_runs', 'manage'],
        'manager' => ['hr_core', 'departments', 'write'],
    ];
    
    // Check if any required role maps to a permission the user has
    foreach ($roles as $role) {
        if (isset($rolePermissionMap[$role])) {
            [$domain, $resource, $level] = $rolePermissionMap[$role];
            if (user_can($domain, $resource, $level)) {
                return; // User has equivalent permission
            }
        }
    }
    
    // Fallback: check legacy role field
    if (!in_array($user['role'], $roles, true)) {
        // Log deprecation warning
        sys_log('AUTH-DEPRECATED', 'require_role() called - please migrate to require_access()', [
            'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? 'unknown',
            'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0,
            'required_roles' => implode(',', $roles),
            'user_role' => $user['role'],
        ]);
        
        header('Location: ' . BASE_URL . '/unauthorized');
        exit;
    }
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function audit(string $action, ?string $details = null, array $context = []): void {
    $pdo = get_db_conn();
    // Single-use authorization override: attribute this audit event to the authorizer if set
    $uid = $GLOBALS['__override_as_user_id'] ?? ($_SESSION['user']['id'] ?? null);
    
    // Extract structured data from context
    $module = $context['module'] ?? null;
    $actionType = $context['action_type'] ?? null;
    $targetType = $context['target_type'] ?? null;
    $targetId = $context['target_id'] ?? null;
    $oldValues = isset($context['old_values']) ? json_encode($context['old_values']) : null;
    $newValues = isset($context['new_values']) ? json_encode($context['new_values']) : null;
    $status = $context['status'] ?? 'success';
    $severity = $context['severity'] ?? 'normal';
    $employeeId = $context['employee_id'] ?? null;
    
    // Capture IP address and user agent
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Auto-detect employee_id if not provided
    if (!$employeeId && $uid) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':uid' => $uid]);
            $employeeId = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            // Ignore
        }
    }
    
    try {
        $stmt = $pdo->prepare('
            INSERT INTO audit_logs (
                user_id, action, details, module, action_type, 
                target_type, target_id, old_values, new_values, 
                status, severity, employee_id, ip_address, user_agent
            ) VALUES (
                :uid, :action, :details, :module, :action_type,
                :target_type, :target_id, :old_values, :new_values,
                :status, :severity, :employee_id, :ip_address, :user_agent
            )
        ');
        $stmt->execute([
            ':uid' => $uid,
            ':action' => $action,
            ':details' => $details,
            ':module' => $module,
            ':action_type' => $actionType,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':old_values' => $oldValues,
            ':new_values' => $newValues,
            ':status' => $status,
            ':severity' => $severity,
            ':employee_id' => $employeeId,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    } catch (Throwable $e) {
        sys_log('DB2901', 'Audit insert failed: ' . $e->getMessage(), [
            'module'=>'auth',
            'file'=>__FILE__,
            'line'=>__LINE__,
            'context'=>['action'=>$action]
        ]);
    }
}

/** Structured user action logger (CRUD, approvals, etc.) */
function action_log(string $module, string $actionType, string $status = 'success', array $meta = []): void {
    $module = strtolower($module);
    $payload = [
        'module' => $module,
        'status' => $status,
        'meta' => (object)$meta,
    ];
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

    // Build human-readable English sentence from action + meta
    $readableDetails = _build_action_sentence($module, $actionType, $status, $meta);

    // Pass structured context so audit() populates module/action_type columns
    audit($actionType, $readableDetails, [
        'module' => $module,
        'action_type' => $actionType,
        'status' => $status,
    ]);

    $userOverride = $GLOBALS['__override_as_user_id'] ?? null;
    $sessionUser = $_SESSION['user']['id'] ?? null;
    $actorId = $userOverride ?: $sessionUser;
    $code = $module !== '' ? ('ACTION-' . strtoupper(substr(preg_replace('/[^a-z0-9]+/i', '-', $module), 0, 32))) : 'ACTION';
    $message = sprintf('Action %s:%s recorded with status %s', $module ?: 'system', $actionType, $status);
    $context = [
        'action' => $actionType,
        'status' => $status,
        'meta' => $meta,
        'actor_id' => $actorId,
        'override_user_id' => $userOverride,
        'payload' => $jsonPayload,
    ];

    sys_log($code, $message, [
        'module' => $module,
        'file' => __FILE__,
        'line' => __LINE__,
        'func' => __FUNCTION__,
        'context' => $context,
    ]);
}

/**
 * Build a human-readable English sentence describing an action_log event.
 * Covers all known action patterns across 26+ modules (~140 call sites).
 */
function _build_action_sentence(string $module, string $action, string $status, array $m): string {
    // Helper: get a meta value with fallback
    $g = function(string ...$keys) use ($m): string {
        foreach ($keys as $k) {
            if (isset($m[$k]) && !is_array($m[$k]) && !is_object($m[$k]) && (string)$m[$k] !== '') {
                return (string)$m[$k];
            }
        }
        return '';
    };

    // Format the module name nicely
    $mod = ucfirst(str_replace('_', ' ', $module));

    // Attempt pattern-based sentence construction
    $sentence = '';

    // === CRUD verbs: create, update, delete, deactivate ===
    if (preg_match('/^create[_\s]?(.+)$/i', $action, $am)) {
        $thing = ucfirst(str_replace('_', ' ', $am[1]));
        $name = $g('name', 'label', 'code', 'po_number', 'leave_type', 'period_name');
        $sentence = $name ? "Created {$thing} \"{$name}\"" : "Created a new {$thing}";
        $id = $g('id', 'item_id', 'employee_id', 'record_id', 'template_id', 'po_id', 'branch_id', 'rule_id', 'benefit_id', 'memo_id');
        if ($id) $sentence .= " (#{$id})";
    }
    elseif (preg_match('/^update[_\s]?(.+)$/i', $action, $am)) {
        $thing = ucfirst(str_replace('_', ' ', $am[1]));
        $name = $g('name', 'label', 'code', 'po_number');
        $id = $g('id', 'item_id', 'employee_id', 'template_id', 'po_id', 'branch_id', 'user_id', 'device_id', 'rule_id', 'benefit_id');
        if ($thing === 'Po status' || $action === 'update_po_status') {
            $from = $g('from'); $to = $g('to');
            $po = $g('po_number');
            $sentence = "Updated PO" . ($po ? " #{$po}" : '') . " status" . ($from && $to ? " from \"{$from}\" to \"{$to}\"" : '');
        } elseif ($action === 'update_cost') {
            $itemName = $g('item_name'); $old = $g('old_cost'); $new = $g('new_cost');
            $sentence = "Updated cost" . ($itemName ? " for \"{$itemName}\"" : '') . ($old && $new ? " from {$old} to {$new}" : '');
        } elseif ($action === 'update_permissions') {
            $posId = $g('position_id');
            $count = $g('permissions_saved');
            $sentence = "Updated permissions" . ($posId ? " for position #{$posId}" : '') . ($count ? " ({$count} saved)" : '');
        } else {
            $sentence = "Updated {$thing}";
            if ($name) $sentence .= " \"{$name}\"";
            elseif ($id) $sentence .= " #{$id}";
        }
    }
    elseif (preg_match('/^delete[_\s]?(.+)$/i', $action, $am)) {
        $thing = ucfirst(str_replace('_', ' ', $am[1]));
        $name = $g('name', 'label', 'code', 'po_number', 'patient_name', 'leave_type');
        $id = $g('id', 'item_id', 'employee_id', 'record_id', 'template_id', 'po_id', 'branch_id', 'user_id', 'position_id', 'department_id', 'rule_id', 'benefit_id');
        $sentence = $name ? "Deleted {$thing} \"{$name}\"" : "Deleted {$thing}";
        if ($id) $sentence .= " (#{$id})";
    }
    elseif (preg_match('/^deactivate[_\s]?(.+)$/i', $action, $am)) {
        $thing = ucfirst(str_replace('_', ' ', $am[1]));
        $id = $g('id', 'item_id');
        $sentence = "Deactivated {$thing}" . ($id ? " #{$id}" : '');
    }
    // === Approval / rejection ===
    elseif (preg_match('/^(approve|reject|batch_approve|batch_reject)[_\s]?(.*)$/i', $action, $am)) {
        $verb = stripos($am[1], 'reject') !== false ? 'Rejected' : 'Approved';
        $thing = !empty($am[2]) ? ucfirst(str_replace('_', ' ', $am[2])) : '';
        $id = $g('overtime_id', 'adjustment_id', 'request_id', 'batch_id', 'complaint_id', 'leave_request_id');
        $empName = $g('employee_name', 'patient_name');
        $reason = $g('reason');
        if (!$thing) $thing = ucfirst(str_replace('_', ' ', $module)) . ' request';
        $sentence = "{$verb} {$thing}" . ($id ? " #{$id}" : '');
        if ($empName) $sentence .= " for {$empName}";
        if ($verb === 'Rejected' && $reason) $sentence .= " — reason: \"{$reason}\"";
    }
    // === View pages ===
    elseif (preg_match('/^view[_\s]?(.+)$/i', $action, $am)) {
        $page = ucfirst(str_replace('_', ' ', $am[1]));
        $sentence = "Viewed {$page}";
    }
    // === Specific known actions ===
    elseif ($action === 'file_request') {
        $emp = $g('employee_name', 'employee_id');
        $hrs = $g('hours');
        $date = $g('date');
        $sentence = "Filed overtime request" . ($emp ? " for {$emp}" : '') . ($hrs ? " ({$hrs} hrs)" : '') . ($date ? " on {$date}" : '');
    }
    elseif ($action === 'generate_from_attendance') {
        $gen = $g('generated'); $skip = $g('skipped');
        $from = $g('from'); $to = $g('to');
        $sentence = "Generated overtime requests from attendance" . ($from && $to ? " ({$from} to {$to})" : '') . ($gen ? " — {$gen} generated" : '') . ($skip ? ", {$skip} skipped" : '');
    }
    elseif ($action === 'leave_decision_recorded') {
        $id = $g('leave_request_id'); $dec = $g('decision');
        $sentence = "Recorded leave decision" . ($dec ? ": " . ucfirst($dec) : '') . ($id ? " for request #{$id}" : '');
    }
    elseif ($action === 'leave_request_filed') {
        $id = $g('leave_request_id'); $att = $g('attachments');
        $sentence = "Filed a leave request" . ($id ? " (#{$id})" : '') . ($att ? " with {$att} attachment(s)" : '');
    }
    elseif (preg_match('/^(run_created|run_deleted|run_released|run_generate_payslips)$/', $action)) {
        $runId = $g('run_id');
        $verbs = ['run_created' => 'Created', 'run_deleted' => 'Deleted', 'run_released' => 'Released', 'run_generate_payslips' => 'Generated payslips for'];
        $verb = $verbs[$action] ?? ucfirst(str_replace('_', ' ', $action));
        $sentence = "{$verb} payroll run" . ($runId ? " #{$runId}" : '');
        $period = $g('period_start');
        $periodEnd = $g('period_end');
        if ($period && $periodEnd) $sentence .= " ({$period} to {$periodEnd})";
    }
    elseif ($action === 'batch_approval_decision' || $action === 'approval_decision' || $action === 'batch_approval') {
        $decision = $g('decision'); $batchId = $g('batch_id'); $step = $g('step');
        $sentence = "Made approval decision" . ($decision ? ": " . ucfirst($decision) : '') . ($batchId ? " on batch #{$batchId}" : '') . ($step ? " (step {$step})" : '');
    }
    elseif ($action === 'batch_updated' || $action === 'batch_status_update') {
        $batchId = $g('batch_id'); $st = $g('status');
        $sentence = "Updated batch" . ($batchId ? " #{$batchId}" : '') . ($st ? " status to \"{$st}\"" : '');
    }
    elseif ($action === 'batch_compute') {
        $batchId = $g('batch_id');
        $sentence = "Computed payroll batch" . ($batchId ? " #{$batchId}" : '');
    }
    elseif ($action === 'submit_dtr') {
        $batchId = $g('batch_id');
        $sentence = "Submitted DTR" . ($batchId ? " for batch #{$batchId}" : '');
    }
    elseif ($action === 'complaint_update' || $action === 'complaint_updated') {
        $cid = $g('complaint_id'); $old = $g('old_status'); $new = $g('new_status', 'status');
        $sentence = "Updated payroll complaint" . ($cid ? " #{$cid}" : '') . ($old && $new ? " from \"{$old}\" to \"{$new}\"" : ($new ? " to \"{$new}\"" : ''));
    }
    elseif ($action === 'submission_update') {
        $sid = $g('submission_id'); $st = $g('status');
        $sentence = "Updated submission" . ($sid ? " #{$sid}" : '') . ($st ? " to \"{$st}\"" : '');
    }
    elseif ($action === 'payslip_adjusted') {
        $type = $g('type'); $label = $g('label'); $amount = $g('amount');
        $sentence = "Adjusted payslip" . ($label ? " — {$type} \"{$label}\"" : '') . ($amount ? ": ₱{$amount}" : '');
    }
    elseif ($action === 'stock_adjustment') {
        $itemId = $g('item_id'); $qty = $g('qty_change'); $newQty = $g('new_qty');
        $sentence = "Adjusted stock for item #{$itemId}" . ($qty ? " by {$qty}" : '') . ($newQty ? " (new qty: {$newQty})" : '');
    }
    elseif ($action === 'void_transaction' || $action === 'refund_transaction') {
        $txn = $g('txn_id'); $reason = $g('reason');
        $verb = $action === 'void_transaction' ? 'Voided' : 'Refunded';
        $sentence = "{$verb} transaction" . ($txn ? " #{$txn}" : '') . ($reason ? " — reason: \"{$reason}\"" : '');
    }
    elseif ($action === 'pos_sale') {
        $txn = $g('txn_number'); $total = $g('total'); $pay = $g('payment_method');
        $sentence = "Completed POS sale" . ($txn ? " #{$txn}" : '') . ($total ? " for ₱{$total}" : '') . ($pay ? " via {$pay}" : '');
    }
    elseif ($action === 'bulk_import_csv' || $action === 'bulk_import_manual') {
        $imported = $g('imported'); $skipped = $g('skipped');
        $method = $action === 'bulk_import_csv' ? 'CSV' : 'manual entry';
        $sentence = "Bulk imported inventory via {$method}" . ($imported ? " — {$imported} items imported" : '') . ($skipped ? ", {$skipped} skipped" : '');
    }
    elseif ($action === 'receive_po_items') {
        $po = $g('po_number'); $qty = $g('qty_received');
        $sentence = "Received items for PO" . ($po ? " #{$po}" : '') . ($qty ? " ({$qty} items)" : '');
    }
    elseif (preg_match('/^memo_/', $action)) {
        $memoId = $g('memo_id'); $code = $g('code');
        $verbs = [
            'memo_created' => 'Created memo',
            'memo_updated' => 'Updated memo',
            'memo_acknowledge' => 'Acknowledged memo',
            'memo_attachment_download' => 'Downloaded memo attachment',
            'memo_attachment_preview' => 'Previewed memo attachment',
            'memo_attachment_removed' => 'Removed memo attachment',
            'memo_toggle_downloads' => 'Toggled memo downloads',
        ];
        $sentence = ($verbs[$action] ?? ucfirst(str_replace('_', ' ', $action)));
        if ($code) $sentence .= " \"{$code}\"";
        elseif ($memoId) $sentence .= " #{$memoId}";
    }
    elseif ($action === 'profile_update') {
        $sentence = "Updated user profile" . ($g('user_id') ? " for user #{$g('user_id')}" : '');
    }
    elseif ($action === 'password_change') {
        $sentence = "Changed password" . ($g('user_id') ? " for user #{$g('user_id')}" : '');
    }
    elseif ($action === 'profile_photo_update') {
        $sentence = "Updated profile photo" . ($g('employee_id') ? " for employee #{$g('employee_id')}" : '');
    }
    elseif ($action === 'reset_password') {
        $sentence = "Reset password" . ($g('user_id') ? " for user #{$g('user_id')}" : '');
    }
    elseif ($action === 'unbind_account') {
        $sentence = "Unlinked account #{$g('user_id')} from employee #{$g('employee_id')}";
    }
    elseif ($action === 'transition_to_employee') {
        $sentence = "Transitioned applicant #{$g('recruitment_id')} to employee #{$g('employee_id')}";
    }
    elseif ($action === 'update_status') {
        $id = $g('id'); $st = $g('status');
        $sentence = "Updated status" . ($id ? " for #{$id}" : '') . ($st ? " to \"{$st}\"" : '');
    }
    elseif ($action === 'consent_update') {
        $sentence = "Updated privacy consent" . ($g('user_id') ? " for user #{$g('user_id')}" : '');
    }
    elseif ($action === 'erasure_request') {
        $scope = $g('scope');
        $sentence = "Requested data erasure" . ($g('employee_id') ? " for employee #{$g('employee_id')}" : '') . ($scope ? " (scope: {$scope})" : '');
    }
    elseif ($action === 'data_export') {
        $format = $g('format');
        $sentence = "Exported employee data" . ($g('employee_id') ? " for employee #{$g('employee_id')}" : '') . ($format ? " as {$format}" : '');
    }
    elseif ($action === 'edit_time') {
        $timeIn = $g('time_in'); $timeOut = $g('time_out');
        $sentence = "Edited attendance time" . ($g('attendance_id') ? " #{$g('attendance_id')}" : '') . ($timeIn ? " — in: {$timeIn}" : '') . ($timeOut ? ", out: {$timeOut}" : '');
    }
    elseif ($action === 'create_period') {
        $sentence = "Created cutoff period" . ($g('period_name') ? " \"{$g('period_name')}\"" : '') . ($g('date_range') ? " ({$g('date_range')})" : '');
    }
    elseif ($action === 'login') {
        $sentence = "Logged in" . ($g('method') ? " via {$g('method')}" : '');
    }
    elseif ($action === 'logout') {
        $sentence = "Logged out";
    }
    elseif (preg_match('/^(add|remove)[_\s]?(.+)$/i', $action, $am)) {
        $verb = strtolower($am[1]) === 'add' ? 'Added' : 'Removed';
        $thing = ucfirst(str_replace('_', ' ', $am[2]));
        $id = $g('supervisor_id', 'record_id', 'approver_id', 'id');
        $sentence = "{$verb} {$thing}" . ($id ? " #{$id}" : '');
        $parentId = $g('department_id');
        if ($parentId) $sentence .= " in department #{$parentId}";
    }
    elseif (preg_match('/^(assign|set)[_\s]?(.+)$/i', $action, $am)) {
        $thing = ucfirst(str_replace('_', ' ', $am[2]));
        $id = $g('template_id', 'id');
        $empId = $g('employee_id');
        $sentence = "Assigned {$thing}" . ($id ? " #{$id}" : '') . ($empId ? " to employee #{$empId}" : '');
    }
    elseif ($action === 'export') {
        $report = $g('report'); $year = $g('year'); $rows = $g('rows');
        $sentence = "Exported report" . ($report ? " \"{$report}\"" : '') . ($year ? " for {$year}" : '') . ($rows ? " ({$rows} rows)" : '');
    }

    // Fallback: generate a reasonable sentence from action name + meta
    if (empty($sentence)) {
        $readable = ucfirst(str_replace('_', ' ', $action));
        $name = $g('name', 'label', 'code', 'patient_name', 'po_number');
        $id = $g('id', 'item_id', 'employee_id', 'record_id', 'template_id', 'branch_id', 'user_id', 'run_id', 'batch_id');
        if ($name) {
            $sentence = "{$readable}: \"{$name}\"";
        } elseif ($id) {
            $sentence = "{$readable} (#{$id})";
        } else {
            $sentence = $readable;
        }
    }

    // Append failure note if status is not success
    if ($status === 'error' || $status === 'failed') {
        $errMsg = $g('reason', 'message', 'error');
        $sentence .= ' [FAILED' . ($errMsg ? ": {$errMsg}" : '') . ']';
    }

    return $sentence;
}

// ===== Quick Authorization Override & Module Access Helpers =====

/** Map access levels to comparable integers */
function access_level_rank(string $level): int {
    switch (strtolower($level)) {
        case 'admin':  // Legacy compatibility
        case 'manage': return 3; // Highest level - full control
        case 'write': return 2;
        case 'read':  return 1;
        default:      return 0; // none
    }
}

/** 
 * Determine a user's access level for a given module.
 * NEW: Uses position-based permissions with backward compatibility
 * 
 * @deprecated Use get_user_effective_access() with domain.resource pattern instead
 */
function user_access_level(int $userId, string $module): string {
    $module = strtolower(trim($module));
    
    // Map old module names to new domain.resource pattern
    $moduleMap = [
        'leave' => ['leave', 'leave_requests'],
        'payroll' => ['payroll', 'payroll_runs'],
        'employees' => ['hr_core', 'employees'],
        'departments' => ['hr_core', 'departments'],
        'positions' => ['hr_core', 'positions'],
        'attendance' => ['attendance', 'attendance_records'],
        'audit' => ['system', 'audit_logs'],
        'documents' => ['documents', 'documents'],
        'memos' => ['documents', 'memos'],
        'recruitment' => ['hr_core', 'recruitment'],
        'notifications' => ['notifications', 'view_notifications'],
        'account' => ['user_management', 'user_accounts'],
    ];
    
    // Try new permission system first
    if (isset($moduleMap[$module])) {
        [$domain, $resource] = $moduleMap[$module];
        $level = get_user_effective_access($userId, $domain, $resource);
        // Map 'manage' to 'admin' for backward compatibility
        return $level === 'manage' ? 'admin' : $level;
    }
    
    // Fallback to old system
    $pdo = get_db_conn();
    try {
        $stmt = $pdo->prepare('SELECT level FROM user_access_permissions WHERE user_id = :uid AND module = :module LIMIT 1');
        $stmt->execute([':uid' => $userId, ':module' => $module]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lvl = strtolower($row['level'] ?? '');
            if (in_array($lvl, ['none','read','write','admin'], true)) return $lvl;
        }
    } catch (Throwable $e) {
        // Table may not exist yet; ignore
    }
    
    // Last resort: check if system admin
    try {
        $stmt = $pdo->prepare('SELECT is_system_admin FROM users WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return 'admin';
        }
    } catch (Throwable $e) {
        // Column may not exist yet; ignore
    }
    
    // Legacy role-based fallback
    $role = null;
    if (!empty($_SESSION['user']) && (int)($_SESSION['user']['id'] ?? 0) === $userId) {
        $role = strtolower($_SESSION['user']['role'] ?? '');
    } else {
        try {
            $st = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $st->execute([':id' => $userId]);
            $role = strtolower((string)($st->fetch(PDO::FETCH_COLUMN) ?? ''));
        } catch (Throwable $e) { /* ignore */ }
    }
    if ($role === 'admin') return 'admin';
    if ($module === 'leave') return 'write'; // Everyone can file leave
    return 'none';
}

/** 
 * Check if user meets required access
 * NEW: Uses position-based permissions with backward compatibility
 * 
 * @deprecated Use user_has_access() with domain.resource pattern instead
 */
function has_module_access(int $userId, string $module, string $required): bool {
    // Map 'admin' to 'manage' for new system compatibility
    $mappedRequired = $required === 'admin' ? 'manage' : $required;
    
    // Get access level (already uses new system via user_access_level)
    $level = user_access_level($userId, $module);
    
    // Compare using hierarchy (manage = admin = 3)
    return access_level_rank($level) >= access_level_rank($mappedRequired);
}

/** Guard an entire page by module access. On failure, redirect to unauthorized. */
function require_module_access(string $module, string $required = 'read'): void {
    $u = $_SESSION['user'] ?? null;
    if (!$u) { require_login(); return; }
    if (!has_module_access((int)$u['id'], $module, $required)) {
        http_response_code(403);
    header('Location: ' . BASE_URL . '/unauthorized');
        exit;
    }
}

/** 
 * Validate override credentials for a single action.
 * NEW: Supports both legacy module-based and new domain.resource pattern
 * 
 * @param string $module Module name (legacy) or "domain.resource" pattern (new)
 * @param string $requiredLevel Access level required (none/read/write/manage/admin)
 */
function validate_override_credentials(string $module, string $requiredLevel, string $email, string $password, ?string $actionLabel = null): array {
    $module = strtolower($module);
    $email = trim($email);
    $nowUser = $_SESSION['user'] ?? null;
    $attemptBy = $nowUser['id'] ?? null;
    $attemptDetails = [
        'attempted_by' => $attemptBy,
        'module' => $module,
        'required' => $requiredLevel,
        'authorizer_email' => $email,
        'action' => $actionLabel,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];
    audit('auth_override_attempt', json_encode($attemptDetails));
    sys_log('AUTH-OVR-ATTEMPT', 'Authorization override attempt', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$attemptDetails]);

    if ($email === '' || $password === '') {
        audit('auth_override_denied', json_encode($attemptDetails + ['reason' => 'missing_credentials']));
        sys_log('AUTH-OVR-DENY', 'Authorization override denied (missing credentials)', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$attemptDetails]);
        return ['ok'=>false, 'user'=>null, 'error'=>'Missing credentials'];
    }
    
    $pdo = get_db_conn();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, status, is_system_admin FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
        audit('auth_override_denied', json_encode($attemptDetails + ['reason' => 'invalid_credentials']));
        sys_log('AUTH-OVR-DENY', 'Authorization override denied (invalid credentials)', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$attemptDetails]);
        return ['ok'=>false, 'user'=>null, 'error'=>'Invalid credentials'];
    }
    
    // Check if authorizer has override permission
    $canOverride = false;
    
    // System admins can always override
    if (!empty($u['is_system_admin'])) {
        $canOverride = true;
    } else {
        // Check if module follows new domain.resource pattern
        if (strpos($module, '.') !== false) {
            [$domain, $resource] = explode('.', $module, 2);
            // Check if user has override permission for this resource
            $canOverride = user_can_override((int)$u['id'], $domain, $resource);
            // Also verify they have the required access level
            if ($canOverride) {
                $lvl = get_user_effective_access((int)$u['id'], $domain, $resource);
                $canOverride = access_level_rank($lvl) >= access_level_rank($requiredLevel);
            }
        } else {
            // Legacy module-based check
            $lvl = user_access_level((int)$u['id'], $module);
            $canOverride = access_level_rank($lvl) >= access_level_rank($requiredLevel);
        }
    }
    
    if (!$canOverride) {
        audit('auth_override_denied', json_encode($attemptDetails + ['reason' => 'insufficient_authorizer']));
        sys_log('AUTH-OVR-DENY', 'Authorization override denied (insufficient access or no override permission)', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$attemptDetails]);
        return ['ok'=>false, 'user'=>null, 'error'=>'Insufficient authorizer access'];
    }
    
    audit('auth_override_granted', json_encode($attemptDetails + ['authorized_by' => $u['id'], 'authorized_role' => $u['role'] ?? null]));
    sys_log('AUTH-OVR-GRANTED', 'Authorization override granted', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$attemptDetails + ['authorized_by'=>$u['id']]]);
    return ['ok'=>true, 'user'=>$u, 'error'=>null];
}

/** Check access or allow single-use override present in POST. */
function ensure_action_authorized(string $module, string $action, string $requiredLevel): array {
    $curr = $_SESSION['user'] ?? null;
    $uid = (int)($curr['id'] ?? 0);
    
    // Handle both legacy module format and new domain.resource format
    if (strpos($module, '.') !== false) {
        // New domain.resource format
        [$domain, $resource] = explode('.', $module, 2);
        $currentLevel = get_user_effective_access($uid, $domain, $resource);
    } else {
        // Legacy module format
        $currentLevel = user_access_level($uid, $module);
    }
    
    $forceOverride = isset($_POST['override_force']) && $_POST['override_force'] === '1';
    $currentRank = access_level_rank($currentLevel);
    $requiredRank = access_level_rank($requiredLevel);
    if (!$forceOverride && $currentRank >= $requiredRank) {
        // No override needed
        $GLOBALS['__override_as_user_id'] = null;
        return ['ok'=>true, 'override'=>false, 'as_user'=>$uid];
    }
    if ($currentRank === 0 && !$forceOverride) {
        // No access at all; do not allow override
        return ['ok'=>false, 'error'=>'no_access'];
    }
    $ovEmail = $_POST['override_email'] ?? '';
    $ovPass  = $_POST['override_password'] ?? '';
    $actionLabel = trim((string)($_POST['override_action'] ?? '')) ?: null;
    $res = validate_override_credentials($module, $requiredLevel, $ovEmail, $ovPass, $actionLabel);
    if (!$res['ok']) {
        return ['ok'=>false, 'error'=>'override_failed'];
    }
    // Attribute subsequent audit events to the authorizer for this request only
    $GLOBALS['__override_as_user_id'] = (int)$res['user']['id'];
    $execCtx = [
        'action' => $action,
        'module' => $module,
        'requested_by' => $uid,
        'authorized_by' => $GLOBALS['__override_as_user_id'],
        'action_label' => $actionLabel,
        'forced' => $forceOverride,
    ];
    audit('auth_override_execute_as', json_encode($execCtx));
    sys_log('AUTH-OVR-EXEC-AS', 'Authorization override execute-as', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$execCtx]);
    return ['ok'=>true, 'override'=>true, 'as_user'=>$GLOBALS['__override_as_user_id']];
}

/** Create a single-use, short-lived override token for cross-request actions (e.g., navigating to edit page). */
function issue_override_token(string $module, string $action, $resourceId = null, int $ttlSeconds = 300): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $tok = bin2hex(random_bytes(16));
    $authorizedBy = $GLOBALS['__override_as_user_id'] ?? ($_SESSION['user']['id'] ?? null);
    $_SESSION['__authz_tokens'] = $_SESSION['__authz_tokens'] ?? [];
    $_SESSION['__authz_tokens'][$tok] = [
        'module' => strtolower($module),
        'action' => $action,
        'resource' => $resourceId,
        'authorized_by' => $authorizedBy,
        'exp' => time() + $ttlSeconds,
    ];
    $tokCtx = ['token'=>$tok,'module'=>$module,'action'=>$action,'resource'=>$resourceId,'authorized_by'=>$authorizedBy];
    audit('auth_override_token_issued', json_encode($tokCtx));
    sys_log('AUTH-OVR-TOKEN-ISSUED', 'Authorization override token issued', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$tokCtx]);
    return $tok;
}

/** Consume a token; returns authorizer user ID on success, or null on failure. */
function consume_override_token(string $token, string $module, string $action, $resourceId = null): ?int {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $list = $_SESSION['__authz_tokens'] ?? [];
    if (!isset($list[$token])) return null;
    $data = $list[$token];
    // Remove token (single-use)
    unset($_SESSION['__authz_tokens'][$token]);
    // Validate
    if (($data['exp'] ?? 0) < time()) return null;
    if (strtolower($data['module'] ?? '') !== strtolower($module)) return null;
    if (($data['action'] ?? '') !== $action) return null;
    if (isset($data['resource']) && (string)$data['resource'] !== (string)$resourceId) return null;
    $authId = (int)($data['authorized_by'] ?? 0) ?: null;
    if ($authId) {
        $GLOBALS['__override_as_user_id'] = $authId; // attribute audits on this request
        $usedCtx = ['token'=>$token,'module'=>$module,'action'=>$action,'resource'=>$resourceId,'authorized_by'=>$authId];
        audit('auth_override_token_used', json_encode($usedCtx));
        sys_log('AUTH-OVR-TOKEN-USED', 'Authorization override token used', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>$usedCtx]);
    } else {
        sys_log('AUTH-OVR-TOKEN-FAIL', 'Authorization override token failed/invalid', ['module'=>$module,'file'=>__FILE__,'line'=>__LINE__,'context'=>['token'=>$token,'module'=>$module,'action'=>$action,'resource'=>$resourceId]]);
    }
    return $authId;
}
