<?php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function flash_set(string $key, string $message): void {
    $_SESSION['__flash'][$key] = $message;
}

function flash_get(string $key): ?string {
    if (!empty($_SESSION['__flash'][$key])) {
        $msg = $_SESSION['__flash'][$key];
        unset($_SESSION['__flash'][$key]);
        return $msg;
    }
    return null;
}

/** Convenience: mark a success flash with a standard message unless overridden */
function flash_success(string $message = 'Changes have been saved'): void {
    flash_set('success', $message);
}

/** Convenience: mark an error flash with a standard message unless overridden */
function flash_error(string $message = 'Changes could not be saved'): void {
    flash_set('error', $message);
}

/** Pop a flash value (alias of flash_get) */
function flash_pop(string $key): ?string {
    return flash_get($key);
}

function csrf_verify($token): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
}

function app_settings_ensure_table(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(150) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by)
                  REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
            )'
        );
        $ready = true;
    } catch (Throwable $e) {
        // best effort
    }
}

function app_settings_get(string $key, $default = null) {
    try {
        $pdo = get_db_conn();
        app_settings_ensure_table($pdo);
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return $default;
        }
        return $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_settings_set(string $key, $value, ?int $userId = null): void {
    try {
        $pdo = get_db_conn();
        app_settings_ensure_table($pdo);
        $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by)
            VALUES (:key, :value, :uid)
            ON CONFLICT (setting_key) DO UPDATE
              SET setting_value = EXCLUDED.setting_value,
                  updated_by = EXCLUDED.updated_by,
                  updated_at = CURRENT_TIMESTAMP');
        if ($userId === null || $userId <= 0) {
            $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':value', (string)$value, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable $e) {
        // ignore
    }
}

function app_time_offset_seconds(bool $refresh = false): int {
    static $offset = null;
    if ($offset !== null && !$refresh) {
        return $offset;
    }
    $raw = app_settings_get('system.time_offset', '0');
    $offset = is_numeric($raw) ? (int)$raw : 0;
    return $offset;
}

function sanitize_file_name(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $name);
    return trim($name, '_');
}

function handle_upload(array $file, string $destDir): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Whitelist allowed file extensions
    $allowedExts = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt','zip'];
    if (!in_array($ext, $allowedExts, true)) return null;

    // MIME type validation — verify file content matches expected type
    $allowedMimes = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($detectedMime && isset($allowedMimes[$ext]) && !in_array($detectedMime, $allowedMimes[$ext], true)) {
            return null; // MIME mismatch — reject
        }
    }

    $safe = sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME));
    $dest = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $safe . '_' . time() . '.' . $ext;
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) return $dest;
    return null;
}

function ensure_system_logs_table(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_logs (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                module VARCHAR(50) NULL,
                file VARCHAR(255) NULL,
                line INT NULL,
                func VARCHAR(100) NULL,
                context TEXT NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON system_logs (created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_code ON system_logs (code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_module ON system_logs (module)');
        $ready = true;
    } catch (Throwable $e) {
        // best effort; fallback to no-op if permissions or schema issues occur
    }
}

function branches_fetch_all(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT id, code, name, COALESCE(address, \'\') AS address, created_at, updated_at FROM branches ORDER BY name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('BRANCH-LIST', 'Failed loading branches: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
}

function branches_indexed(PDO $pdo): array {
    $list = branches_fetch_all($pdo);
    $map = [];
    foreach ($list as $row) {
        $map[(int)$row['id']] = $row;
    }
    return $map;
}

function branches_get_default_id(PDO $pdo): ?int {
    $branches = branches_fetch_all($pdo);
    foreach ($branches as $branch) {
        if (strcasecmp((string)$branch['code'], 'QC') === 0 || strcasecmp((string)$branch['name'], 'Quezon City') === 0) {
            return (int)$branch['id'];
        }
    }
    if ($branches) {
        return (int)($branches[0]['id'] ?? 0) ?: null;
    }
    return null;
}

function paginate(int $total, int $page, int $perPage = 10): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return [$offset, $perPage, $page, $pages];
}

function csv_to_array(string $path): array {
    $rows = [];
    if (!file_exists($path)) return $rows;
    if (($h = fopen($path, 'r')) !== false) {
        $headers = fgetcsv($h);
        if (!$headers) { fclose($h); return $rows; }
        while (($data = fgetcsv($h)) !== false) {
            $rows[] = array_combine($headers, $data);
        }
        fclose($h);
    }
    return $rows;
}

function notify(?int $userId, $titleOrPayload, ?string $body = null): void {
    $pdo = get_db_conn();
    $title = 'Notification';
    $description = '';
    $payload = null;

    if (is_array($titleOrPayload)) {
        $title = trim((string)($titleOrPayload['title'] ?? 'Notification')) ?: 'Notification';
        $description = trim((string)($titleOrPayload['body'] ?? ($titleOrPayload['message'] ?? '')));
        $payloadRaw = $titleOrPayload['payload'] ?? ($titleOrPayload['meta'] ?? null);
        if (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        } elseif (is_string($payloadRaw)) {
            try {
                $decoded = defined('JSON_THROW_ON_ERROR')
                    ? json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR)
                    : json_decode($payloadRaw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (Throwable $e) {
                // Ignore invalid JSON payloads and fall back to text-only notifications.
            }
        }
    } else {
        $title = trim((string)$titleOrPayload);
        if ($body === null) {
            $description = $title;
            $title = 'Notification';
        } else {
            $description = trim($body);
        }
    }

    if ($description === '') {
        $description = $title;
        $title = 'Notification';
    }

    if ($title === '') {
        $title = 'Notification';
    }

    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 150);
    } else {
        $title = substr($title, 0, 150);
    }

    $message = $description;
    $payloadJson = null;
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $payloadJson = $encoded;
        }
    }

    $uidParam = $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
    try {
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, message, payload) VALUES (:uid, :title, :body, :msg, :payload)');
        $stmt->bindValue(':uid', $userId, $uidParam);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':body', $description, PDO::PARAM_STR);
        $stmt->bindValue(':msg', $message, PDO::PARAM_STR);
        if ($payloadJson === null) {
            $stmt->bindValue(':payload', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':payload', $payloadJson, PDO::PARAM_STR);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        $fallbackSql = 'INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)';
        $needsFallback = stripos($e->getMessage(), 'column') !== false || stripos($e->getMessage(), 'notifications') !== false;
        if ($needsFallback) {
            try {
                $stmt = $pdo->prepare($fallbackSql);
                $stmt->bindValue(':uid', $userId, $uidParam);
                $stmt->bindValue(':msg', $message, PDO::PARAM_STR);
                $stmt->execute();
                return;
            } catch (Throwable $nested) {
                sys_log('NOTIFY-FALLBACK', 'Fallback notification insert failed: ' . $nested->getMessage(), ['module' => 'notifications', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['message' => $message]]);
            }
        }
        sys_log('NOTIFY-FAIL', 'Notification insert failed: ' . $e->getMessage(), ['module' => 'notifications', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['message' => $message]]);
    }
}

function app_timezone(bool $refresh = false): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone && !$refresh) {
        return $tz;
    }
    $default = defined('APP_TIMEZONE') ? (string)APP_TIMEZONE : date_default_timezone_get();
    $configured = app_settings_get('system.timezone', $default);
    try {
        $tz = new DateTimeZone($configured);
    } catch (Throwable $e) {
        $tz = new DateTimeZone($default ?: 'UTC');
    }
    date_default_timezone_set($tz->getName());
    return $tz;
}

/** Render datetimes consistently in the configured Philippines 12-hour format. */
function format_datetime_display($value, bool $includeSeconds = false, string $fallback = '—'): string {
    if ($value === null || $value === '') {
        return $fallback;
    }
    try {
        if ($value instanceof DateTimeInterface) {
            $dt = (new DateTimeImmutable('@' . $value->getTimestamp()))->setTimezone($value->getTimezone());
        } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $dt = new DateTimeImmutable('@' . (int)$value);
        } else {
            $dt = new DateTimeImmutable((string)$value);
        }
        $dt = $dt->setTimezone(app_timezone());
        $offset = app_time_offset_seconds();
        if ($offset !== 0) {
            $dt = $dt->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' seconds');
        }
        $format = $includeSeconds
            ? (defined('APP_DISPLAY_TIME_FORMAT_WITH_SECONDS') ? APP_DISPLAY_TIME_FORMAT_WITH_SECONDS : 'M d, Y g:i:s A')
            : (defined('APP_DISPLAY_TIME_FORMAT') ? APP_DISPLAY_TIME_FORMAT : 'M d, Y g:i A');
        return $dt->format($format);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/** Ensure bridge table exists for per-user read tracking of global notifications. */
function ensure_notification_reads(PDO $pdo): void {
    try {
        // Create table if not exists (PostgreSQL). Composite PK enables ON CONFLICT upsert semantics.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notification_reads (
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (notification_id, user_id)
            )'
        );
    } catch (Throwable $e) {
        // best-effort; do not throw
    }
}

/**
 * System error logging (admin-only logs)
 * Do NOT use for human input errors.
 * @param string $code  Standardized code e.g., DB1001, AUTH2001, PAY3001, GEN4001
 * @param string $message Technical message/details
 * @param array $meta Optional meta: module, file, line, func, context (string or array)
 */
function sys_log(string $code, string $message, array $meta = []): void {
    try {
        $pdo = get_db_conn();
    } catch (Throwable $e) {
        try {
            $pdo = get_db_conn(true);
        } catch (Throwable $ignored) {
            return;
        }
    }

    if ($pdo->inTransaction()) {
        try {
            $pdo = get_db_conn(true);
        } catch (Throwable $ignored) {
            // If we cannot grab a fresh connection, fall back to the current one even if it is transactional.
        }
    }

    try {
        ensure_system_logs_table($pdo);
        $module = (string)($meta['module'] ?? null);
        $file   = (string)($meta['file'] ?? null);
        $line   = isset($meta['line']) ? (int)$meta['line'] : null;
        $func   = (string)($meta['func'] ?? null);
        $ctx    = $meta['context'] ?? null;
        if (is_array($ctx)) { $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE); }
        $stmt = $pdo->prepare('INSERT INTO system_logs (code, message, module, file, line, func, context) VALUES (:code,:message,:module,:file,:line,:func,:ctx)');
        $stmt->execute([
            ':code' => $code,
            ':message' => $message,
            ':module' => $module,
            ':file' => $file,
            ':line' => $line,
            ':func' => $func,
            ':ctx' => $ctx,
        ]);
    } catch (Throwable $e) {
        // Last resort: avoid throwing; silent fail
    }
}

/** Show a generic system error message to non-admin end-users */
function show_system_error(string $userMsg = 'A system error occurred. Please try again later.') {
    echo '<div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm">' . htmlspecialchars($userMsg) . '</div>';
}

/** Show a friendly human error prompt (validation) */
function show_human_error(string $msg) {
    echo '<div class="bg-yellow-50 text-yellow-700 p-2 rounded mb-3 text-sm">' . htmlspecialchars($msg) . '</div>';
}

/**
 * backup_then_delete
 * Copies a row from a main table to its backup table (table_backup) and deletes the original.
 * Runs inside a transaction for safety.
 * @param mysqli $db
 * @param string $table Main table name
 * @param string $pkCol Primary key column name (e.g., 'id')
 * @param int $id Primary key value
 * @return bool true on success, false on failure
 */
function backup_then_delete(PDO $pdo, string $table, string $pkCol, int $id): bool {
    $backup = pg_ident($table . '_backup');
    $tableSafe = pg_ident($table);
    $pkSafe = pg_ident($pkCol);
    $ownsTransaction = false;
    if (!$pdo->inTransaction()) {
        try {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        } catch (Throwable $e) {
            sys_log('DB-BACKUP-DEL', 'backup_then_delete failed to begin transaction: ' . $e->getMessage(), ['module'=>'utils','file'=>__FILE__,'line'=>__LINE__,'context'=>['table'=>$table,'id'=>$id]]);
            return false;
        }
    }
    try {
        // Check if backup table schema matches the main table
        $checkSql = "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :backup";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([':backup' => $table . '_backup']);
        $backupColCount = (int)$stmt->fetchColumn();
        
        $checkSql2 = "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :table";
        $stmt2 = $pdo->prepare($checkSql2);
        $stmt2->execute([':table' => $table]);
        $mainColCount = (int)$stmt2->fetchColumn();
        
        // If column counts don't match, drop and recreate backup table
        if ($backupColCount > 0 && $backupColCount !== $mainColCount) {
            $pdo->exec('DROP TABLE IF EXISTS "' . $backup . '"');
        }
        
        // Ensure backup table exists (LIKE including all)
        $pdo->exec('CREATE TABLE IF NOT EXISTS "' . $backup . '" (LIKE "' . $tableSafe . '" INCLUDING ALL)');

    // Insert into backup from main; ignore if already exists (by primary key)
    // Important: backup tables mirror identity columns (GENERATED ALWAYS). Use OVERRIDING SYSTEM VALUE
    // to allow copying the explicit id and other identity columns safely.
    $sqlCopy = 'INSERT INTO "' . $backup . '" OVERRIDING SYSTEM VALUE SELECT * FROM "' . $tableSafe . '" WHERE "' . $pkSafe . '" = :id';
        try {
            $stmt = $pdo->prepare($sqlCopy);
            $stmt->execute([':id' => $id]);
        } catch (Throwable $e) {
            // If copy fails due to duplicate, continue; otherwise rethrow
            if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'unique') === false) {
                throw $e;
            }
        }

        // Delete original row
        $sqlDel = 'DELETE FROM "' . $tableSafe . '" WHERE "' . $pkSafe . '" = :id';
        $stmt = $pdo->prepare($sqlDel);
        if (!$stmt->execute([':id' => $id])) { throw new Exception('Delete failed'); }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
        }
        sys_log('DB-BACKUP-DEL', 'backup_then_delete failed: ' . $e->getMessage(), ['module'=>'utils','file'=>__FILE__,'line'=>__LINE__,'context'=>['table'=>$table,'id'=>$id]]);
        return false;
    }
}

function ensure_roles_meta_seed(PDO $pdo): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }
    try {
        $pdo->exec(
            "INSERT INTO roles_meta (role_name, label, is_active)
             SELECT role_name, INITCAP(REPLACE(role_name, '_', ' ')), 1
             FROM (
               SELECT unnest(enum_range(NULL::user_role))::text AS role_name
             ) AS r
             WHERE NOT EXISTS (
               SELECT 1 FROM roles_meta m WHERE m.role_name = r.role_name
             )"
        );
        $seeded = true;
        return;
    } catch (Throwable $e) {
        // fallback below
    }
    $roles = ['admin','hr','employee','accountant','manager','hr_supervisor','hr_recruit','hr_payroll','admin_assistant'];
    try {
        $stmt = $pdo->prepare('INSERT INTO roles_meta (role_name, label, is_active) VALUES (:role, :label, 1)
            ON CONFLICT (role_name) DO NOTHING');
        foreach ($roles as $role) {
            $stmt->execute([
                ':role' => $role,
                ':label' => ucwords(str_replace('_', ' ', $role)),
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }
    $seeded = true;
}

/** Stream a CSV file for download from an array of associative rows. */
function output_csv(string $filename, array $headers, array $rows): void {
    $filename = sanitize_file_name($filename);
    if (stripos($filename, '.csv') === false) { $filename .= '.csv'; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    // Open output stream
    $out = fopen('php://output', 'w');
    // Optional UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    // Write header row
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        // Coerce to ordered list by $headers keys if associative
        if (!empty($r) && array_keys($r) !== range(0, count($r)-1)) {
            $ordered = [];
            foreach ($headers as $h) { $ordered[] = (string)($r[$h] ?? ''); }
            fputcsv($out, $ordered);
        } else {
            fputcsv($out, $r);
        }
    }
    fclose($out);
    exit;
}

function leave_get_default_entitlements(): array {
    if (defined('LEAVE_DEFAULT_ENTITLEMENTS') && is_array(LEAVE_DEFAULT_ENTITLEMENTS)) {
        $defaults = [];
        foreach (LEAVE_DEFAULT_ENTITLEMENTS as $type => $days) {
            $key = strtolower((string)$type);
            $defaults[$key] = max(0, (float)$days);
        }
        if ($defaults) {
            return $defaults;
        }
    }
    return [
        'sick' => 0,
        'vacation' => 0,
        'emergency' => 0,
        'unpaid' => 0,
        'other' => 0,
    ];
}

function leave_get_known_types(?PDO $pdo = null): array {
    $types = array_keys(leave_get_default_entitlements());
    if ($pdo) {
        try {
            // Get types from leave_entitlements table
            $stmt = $pdo->query('SELECT DISTINCT LOWER(leave_type) AS leave_type FROM leave_entitlements');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = strtolower((string)($row['leave_type'] ?? ''));
                if ($code !== '') {
                    $types[] = $code;
                }
            }
            
            // Also get types from leave_filing_policies to include custom types
            $stmt = $pdo->query('SELECT DISTINCT LOWER(leave_type) AS leave_type FROM leave_filing_policies');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $code = strtolower((string)($row['leave_type'] ?? ''));
                    if ($code !== '') {
                        $types[] = $code;
                    }
                }
            }
            
            // Also get from custom labels table if it exists
            $stmt = $pdo->query('SELECT DISTINCT LOWER(leave_type) AS leave_type FROM leave_type_labels');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $code = strtolower((string)($row['leave_type'] ?? ''));
                    if ($code !== '') {
                        $types[] = $code;
                    }
                }
            }
        } catch (Throwable $e) {
            sys_log('LEAVE-TYPE-LIST', 'Unable to read leave entitlement types: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__]);
        }
    }
    $types = array_values(array_unique(array_map('strtolower', $types)));
    sort($types);
    return $types;
}

function leave_label_for_type(string $type): string {
    static $map = [
        'sick' => 'Sick Leave',
        'vacation' => 'Vacation Leave',
        'emergency' => 'Emergency Leave',
        'unpaid' => 'Unpaid Leave',
        'other' => 'Other Leave',
        'maternity' => 'Maternity Leave',
        'paternity' => 'Paternity Leave',
    ];
    
    // Try to load from database if available
    static $dbLabelsLoaded = false;
    static $dbLabels = [];
    if (!$dbLabelsLoaded) {
        try {
            $pdo = get_db_conn();
            $stmt = $pdo->query("SELECT leave_type, label FROM leave_type_labels");
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $dbLabels[strtolower($row['leave_type'])] = $row['label'];
                }
            }
        } catch (Throwable $e) {
            // Table doesn't exist yet or query failed, use default map
        }
        $dbLabelsLoaded = true;
    }
    
    $key = strtolower($type);
    
    // Check database labels first
    if (isset($dbLabels[$key])) {
        return $dbLabels[$key];
    }
    
    // Fall back to hardcoded map
    if (isset($map[$key])) {
        return $map[$key];
    }
    
    // Generate from code
    $label = preg_replace('/[_-]+/', ' ', $key);
    return ucwords($label ?: $key);
}

function leave_fetch_entitlements(PDO $pdo, string $scopeType, ?int $scopeId = null): array {
    $scopeType = strtolower(trim($scopeType));
    if (!in_array($scopeType, ['global', 'department', 'employee'], true)) {
        return [];
    }
    $sql = 'SELECT LOWER(leave_type) AS leave_type, days FROM leave_entitlements WHERE scope_type = :scope';
    $params = [':scope' => $scopeType];
    if ($scopeId === null) {
        $sql .= ' AND scope_id IS NULL';
    } else {
        $sql .= ' AND scope_id = :scope_id';
        $params[':scope_id'] = (int)$scopeId;
    }
    static $entitlementsTableMissing = false;
    if ($entitlementsTableMissing) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'leave_entitlements') !== false && stripos($message, 'does not exist') !== false) {
            $entitlementsTableMissing = true;
        } else {
            sys_log('LEAVE-ENTITLEMENTS-READ', 'Failed fetching leave entitlements: ' . $message, ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['scope_type' => $scopeType, 'scope_id' => $scopeId]]);
        }
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $code = strtolower((string)($row['leave_type'] ?? ''));
        if ($code === '') {
            continue;
        }
        $map[$code] = max(0, (float)($row['days'] ?? 0));
    }
    return $map;
}

function leave_collect_entitlement_layers(PDO $pdo, int $employeeId): array {
    $layers = [
        'defaults' => leave_get_default_entitlements(),
        'global' => [],
        'department' => [],
        'employee' => [],
    ];
    $departmentId = null;
    try {
        $stmt = $pdo->prepare('SELECT department_id FROM employees WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $employeeId]);
        $departmentId = $stmt->fetchColumn();
        if ($departmentId !== false) {
            $departmentId = (int)$departmentId ?: null;
        } else {
            $departmentId = null;
        }
    } catch (Throwable $e) {
        sys_log('LEAVE-EMPLOYEE-LAYER', 'Unable to resolve employee department: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
    }

    $layers['global'] = leave_fetch_entitlements($pdo, 'global', null);
    if ($departmentId) {
        $layers['department'] = leave_fetch_entitlements($pdo, 'department', $departmentId);
    }
    $layers['employee'] = leave_fetch_entitlements($pdo, 'employee', $employeeId);

    $allTypes = [];
    foreach ($layers as $bucket) {
        $allTypes = array_merge($allTypes, array_keys($bucket));
    }
    $allTypes = array_unique(array_merge(array_keys($layers['defaults']), $allTypes));

    $effective = [];
    $sources = [];
    foreach ($allTypes as $type) {
        $key = strtolower($type);
        $value = (float)($layers['defaults'][$key] ?? 0);
        $source = 'defaults';
        if (array_key_exists($key, $layers['global'])) {
            $value = (float)$layers['global'][$key];
            $source = 'global';
        }
        if (array_key_exists($key, $layers['department'])) {
            $value = (float)$layers['department'][$key];
            $source = 'department';
        }
        if (array_key_exists($key, $layers['employee'])) {
            $value = (float)$layers['employee'][$key];
            $source = 'employee';
        }
        $effective[$key] = $value;
        $sources[$key] = $source;
    }

    return [
        'defaults' => $layers['defaults'],
        'global' => $layers['global'],
        'department' => $layers['department'],
        'employee' => $layers['employee'],
        'effective' => $effective,
        'sources' => $sources,
        'meta' => ['department_id' => $departmentId],
    ];
}

function leave_get_effective_entitlements(PDO $pdo, int $employeeId): array {
    $layers = leave_collect_entitlement_layers($pdo, $employeeId);
    return $layers['effective'];
}

function leave_calculate_balances(PDO $pdo, int $employeeId, ?array $entitlements = null): array {
    $balances = $entitlements ?? leave_get_effective_entitlements($pdo, $employeeId);
    $yearStart = date('Y-01-01');
    $yearEnd = date('Y-12-31');
    $sql = "SELECT leave_type, COALESCE(SUM(total_days),0) AS used
        FROM leave_requests
        WHERE employee_id = :eid
          AND status = 'approved'
          AND start_date BETWEEN :start AND :end
        GROUP BY leave_type";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':eid' => $employeeId, ':start' => $yearStart, ':end' => $yearEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('LEAVE-BALANCE', 'Failed computing leave balances: ' . $e->getMessage(), ['module' => 'leave', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return $balances;
    }
    foreach ($rows as $row) {
        $type = strtolower((string)($row['leave_type'] ?? ''));
        $used = (float)($row['used'] ?? 0);
        if (!array_key_exists($type, $balances)) {
            continue;
        }
        $balances[$type] = max(0, (float)$balances[$type] - $used);
    }
    return $balances;
}

/**
 * Check if a user is a supervisor for a specific department
 * @param PDO $pdo Database connection
 * @param int $userId User ID to check
 * @param int $departmentId Department ID to check supervision for
 * @return bool True if user is a supervisor for the department
 */
function is_department_supervisor(PDO $pdo, int $userId, int $departmentId): bool {
    try {
        $stmt = $pdo->prepare('SELECT id FROM department_supervisors WHERE supervisor_user_id = :uid AND department_id = :dept LIMIT 1');
        $stmt->execute([':uid' => $userId, ':dept' => $departmentId]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        sys_log('DEPT-SUPERVISOR-CHECK', 'Failed checking supervisor status: ' . $e->getMessage(), [
            'module' => 'departments',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $userId, 'department_id' => $departmentId]
        ]);
        return false;
    }
}

/**
 * Get all departments supervised by a user
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array Array of department IDs
 */
function get_supervised_departments(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare('SELECT department_id FROM department_supervisors WHERE supervisor_user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        sys_log('DEPT-SUPERVISOR-LIST', 'Failed fetching supervised departments: ' . $e->getMessage(), [
            'module' => 'departments',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $userId]
        ]);
        return [];
    }
}

/**
 * Format bytes to human readable format
 */
function format_bytes(int $bytes, int $precision = 2): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Soft delete a record (archive it instead of permanent deletion)
 * 
 * @param PDO $pdo Database connection
 * @param string $table Table name (must be in allowed list)
 * @param int $id Record ID to archive
 * @param int $deletedBy User ID performing the deletion
 * @return bool True on success, false on failure
 */
function soft_delete(PDO $pdo, string $table, int $id, int $deletedBy): bool {
    $allowedTables = ['employees', 'departments', 'positions', 'memos', 'documents'];
    
    if (!in_array($table, $allowedTables)) {
        sys_log('SOFT-DELETE', "Attempted soft delete on invalid table: $table", [
            'table' => $table,
            'id' => $id,
            'deleted_by' => $deletedBy
        ]);
        return false;
    }
    
    try {
        // Check if archive system is enabled
        $checkStmt = $pdo->query("
            SELECT setting_value FROM system_settings 
            WHERE setting_key = 'archive_enabled'
        ");
        $enabled = $checkStmt->fetchColumn();
        
        if ($enabled !== '1') {
            sys_log('SOFT-DELETE', 'Archive system is disabled', [
                'table' => $table,
                'id' => $id
            ]);
            return false;
        }
        
        // Get old record data for audit
        $selectStmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND deleted_at IS NULL");
        $selectStmt->execute([$id]);
        $oldRecord = $selectStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldRecord) {
            return false; // Record not found or already deleted
        }
        
        // Soft delete the record
        $updateStmt = $pdo->prepare("
            UPDATE $table 
            SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $success = $updateStmt->execute([$deletedBy, $id]);
        
        if ($success && $updateStmt->rowCount() > 0) {
            // Log the soft delete
            action_log('system_management', 'soft_delete', 'success', [
                'target_type' => $table,
                'target_id' => $id,
                'old_values' => $oldRecord,
                'new_values' => [
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'deleted_by' => $deletedBy
                ],
                'message' => "Archived $table record #$id"
            ]);
            
            return true;
        }
        
        return false;
        
    } catch (Throwable $e) {
        sys_log('SOFT-DELETE', "Failed to soft delete: " . $e->getMessage(), [
            'table' => $table,
            'id' => $id,
            'deleted_by' => $deletedBy,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Check if a record is archived (soft deleted)
 * 
 * @param PDO $pdo Database connection
 * @param string $table Table name
 * @param int $id Record ID
 * @return bool True if archived, false otherwise
 */
function is_archived(PDO $pdo, string $table, int $id): bool {
    $allowedTables = ['employees', 'departments', 'positions', 'memos', 'documents'];
    
    if (!in_array($table, $allowedTables)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT deleted_at FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $deleted_at = $stmt->fetchColumn();
        return $deleted_at !== null && $deleted_at !== false;
    } catch (Throwable $e) {
        return false;
    }
}

