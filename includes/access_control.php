<?php
/**
 * Access Control Engine
 * 
 * Whitelist/blacklist system with device-account binding and module restrictions.
 * OFF by default — controlled via access_control_settings table.
 * 
 * Key concepts:
 * - Device fingerprint: SHA-256 hash of browser attributes (UA, screen, timezone, platform, language)
 * - Device-user binding: a device can be bound to specific user accounts (only those accounts can log in from that device)
 * - Device-module binding: a device can be restricted to specific modules
 * - IP rules: traditional IP/CIDR whitelist or blacklist
 * - Overrides: temporary admin-granted bypasses for blocked access
 */

if (!defined('BASE_URL')) {
    die('Direct access not permitted');
}

// ─── Settings ────────────────────────────────────────────────────────────

/**
 * Get an access control setting value.
 * Results are cached in session for performance.
 */
function acl_get_setting(string $key, $default = null) {
    // Check session cache first
    $cache = $_SESSION['__acl_settings'] ?? [];
    $cacheTime = $_SESSION['__acl_settings_ts'] ?? 0;
    $ttl = 300; // 5 minutes

    if (!empty($cache) && (time() - $cacheTime) < $ttl && array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    // Refresh cache from DB
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM access_control_settings');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $_SESSION['__acl_settings'] = $rows;
        $_SESSION['__acl_settings_ts'] = time();
        return $rows[$key] ?? $default;
    } catch (Throwable $e) {
        // Table might not exist yet — degrade gracefully
        return $default;
    }
}

/**
 * Update an access control setting.
 */
function acl_set_setting(string $key, string $value, int $updatedBy): bool {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('UPDATE access_control_settings SET setting_value = :val, updated_by = :by, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :key');
        $stmt->execute([':val' => $value, ':by' => $updatedBy, ':key' => $key]);
        // Clear cache
        unset($_SESSION['__acl_settings'], $_SESSION['__acl_settings_ts']);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        sys_log('ACL-SETTING', 'Failed updating ACL setting: ' . $e->getMessage(), [
            'module' => 'access_control', 'file' => __FILE__, 'line' => __LINE__,
            'context' => ['key' => $key, 'value' => $value]
        ]);
        return false;
    }
}

/**
 * Check if the access control system is enabled.
 */
function acl_is_enabled(): bool {
    return strtolower((string)acl_get_setting('enabled', 'false')) === 'true';
}

/**
 * Check if device binding enforcement is enabled.
 */
function acl_device_binding_enabled(): bool {
    return acl_is_enabled() && strtolower((string)acl_get_setting('device_binding_enabled', 'false')) === 'true';
}

/**
 * Check if module restriction enforcement is enabled.
 */
function acl_module_restriction_enabled(): bool {
    return acl_is_enabled() && strtolower((string)acl_get_setting('module_restriction_enabled', 'false')) === 'true';
}

// ─── Device Management ───────────────────────────────────────────────────

/**
 * Register a new device or update an existing one.
 */
function acl_register_device(array $meta, int $registeredBy): array {
    $hash = $meta['fingerprint_hash'] ?? '';
    if ($hash === '' || strlen($hash) !== 64) {
        return ['ok' => false, 'error' => 'Invalid device fingerprint hash.'];
    }

    try {
        $pdo = get_db_conn();
        
        // Check if device already exists
        $existing = $pdo->prepare('SELECT id FROM device_fingerprints WHERE fingerprint_hash = :hash');
        $existing->execute([':hash' => $hash]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            // Update existing device
            $stmt = $pdo->prepare('UPDATE device_fingerprints SET 
                label = COALESCE(:label, label),
                device_type = COALESCE(:device_type, device_type),
                user_agent = COALESCE(:ua, user_agent),
                screen_info = COALESCE(:screen, screen_info),
                timezone = COALESCE(:tz, timezone),
                platform = COALESCE(:platform, platform),
                language = COALESCE(:lang, language),
                notes = COALESCE(:notes, notes),
                last_seen_ip = :ip,
                last_seen_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE fingerprint_hash = :hash');
            $stmt->execute([
                ':label' => $meta['label'] ?? null,
                ':device_type' => $meta['device_type'] ?? null,
                ':ua' => $meta['user_agent'] ?? null,
                ':screen' => $meta['screen_info'] ?? null,
                ':tz' => $meta['timezone'] ?? null,
                ':platform' => $meta['platform'] ?? null,
                ':lang' => $meta['language'] ?? null,
                ':notes' => $meta['notes'] ?? null,
                ':ip' => $meta['ip'] ?? null,
                ':hash' => $hash,
            ]);
            return ['ok' => true, 'id' => (int)$existingId, 'action' => 'updated'];
        }

        // Insert new device
        $stmt = $pdo->prepare('INSERT INTO device_fingerprints (fingerprint_hash, label, device_type, user_agent, screen_info, timezone, platform, language, notes, last_seen_ip, last_seen_at, registered_by)
            VALUES (:hash, :label, :device_type, :ua, :screen, :tz, :platform, :lang, :notes, :ip, CURRENT_TIMESTAMP, :by) RETURNING id');
        $stmt->execute([
            ':hash' => $hash,
            ':label' => $meta['label'] ?? 'Unknown Device',
            ':device_type' => $meta['device_type'] ?? 'desktop',
            ':ua' => $meta['user_agent'] ?? null,
            ':screen' => $meta['screen_info'] ?? null,
            ':tz' => $meta['timezone'] ?? null,
            ':platform' => $meta['platform'] ?? null,
            ':lang' => $meta['language'] ?? null,
            ':notes' => $meta['notes'] ?? null,
            ':ip' => $meta['ip'] ?? null,
            ':by' => $registeredBy,
        ]);
        $newId = (int)$stmt->fetchColumn();
        
        acl_log('device_registered', [
            'device_id' => $newId,
            'fingerprint' => $hash,
            'label' => $meta['label'] ?? 'Unknown Device',
            'registered_by' => $registeredBy,
        ]);

        return ['ok' => true, 'id' => $newId, 'action' => 'created'];
    } catch (Throwable $e) {
        sys_log('ACL-DEVICE-REG', 'Failed registering device: ' . $e->getMessage(), [
            'module' => 'access_control', 'file' => __FILE__, 'line' => __LINE__,
        ]);
        return ['ok' => false, 'error' => 'Database error while registering device.'];
    }
}

/**
 * Get a device by fingerprint hash.
 */
function acl_get_device_by_hash(string $hash): ?array {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('SELECT * FROM device_fingerprints WHERE fingerprint_hash = :hash');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * List all registered devices.
 */
function acl_list_devices(array $filters = []): array {
    try {
        $pdo = get_db_conn();
        $where = [];
        $params = [];

        if (isset($filters['active'])) {
            $where[] = 'df.is_active = :active';
            $params[':active'] = $filters['active'] ? 'true' : 'false';
        }
        if (!empty($filters['search'])) {
            $where[] = '(df.label ILIKE :search OR df.fingerprint_hash ILIKE :search OR df.platform ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT df.*, u.full_name AS registered_by_name
            FROM device_fingerprints df
            LEFT JOIN users u ON u.id = df.registered_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY df.updated_at DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Update device touch (last_seen).
 */
function acl_touch_device(string $hash, string $ip): void {
    try {
        $pdo = get_db_conn();
        $pdo->prepare('UPDATE device_fingerprints SET last_seen_ip = :ip, last_seen_at = CURRENT_TIMESTAMP WHERE fingerprint_hash = :hash')
            ->execute([':ip' => $ip, ':hash' => $hash]);
    } catch (Throwable $e) {
        // silent
    }
}

// ─── Rule Management ─────────────────────────────────────────────────────

/**
 * Create or update an access rule.
 */
function acl_save_rule(array $data, int $createdBy): array {
    $ruleType = $data['rule_type'] ?? '';
    $entryType = $data['entry_type'] ?? '';
    $scope = $data['scope'] ?? 'global';
    $value = trim($data['value'] ?? '');
    
    $validRuleTypes = ['whitelist', 'blacklist'];
    $validEntryTypes = ['ip', 'ip_range', 'device', 'user', 'device_user_bind', 'device_module_bind'];
    
    if (!in_array($ruleType, $validRuleTypes, true)) {
        return ['ok' => false, 'error' => 'Invalid rule type.'];
    }
    if (!in_array($entryType, $validEntryTypes, true)) {
        return ['ok' => false, 'error' => 'Invalid entry type.'];
    }
    if ($value === '') {
        return ['ok' => false, 'error' => 'Value is required.'];
    }

    try {
        $pdo = get_db_conn();
        $id = !empty($data['id']) ? (int)$data['id'] : null;

        $fields = [
            'rule_type' => $ruleType,
            'entry_type' => $entryType,
            'scope' => $scope,
            'value' => $value,
            'device_fingerprint_hash' => $data['device_fingerprint_hash'] ?? null,
            'target_user_id' => !empty($data['target_user_id']) ? (int)$data['target_user_id'] : null,
            'target_module' => $data['target_module'] ?? null,
            'label' => trim($data['label'] ?? ''),
            'reason' => trim($data['reason'] ?? ''),
            'is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
            'priority' => (int)($data['priority'] ?? 0),
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
        ];

        if ($id) {
            // Update
            $sets = [];
            $params = [':id' => $id];
            foreach ($fields as $col => $val) {
                $sets[] = "$col = :$col";
                $params[":$col"] = is_bool($val) ? ($val ? 'true' : 'false') : $val;
            }
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
            $sql = 'UPDATE access_rules SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            
            acl_log('rule_updated', ['rule_id' => $id, 'changes' => $fields, 'by' => $createdBy]);
            acl_invalidate_cache();
            return ['ok' => true, 'id' => $id, 'action' => 'updated'];
        } else {
            // Insert
            $fields['created_by'] = $createdBy;
            $cols = array_keys($fields);
            $placeholders = array_map(fn($c) => ':' . $c, $cols);
            $params = [];
            foreach ($fields as $col => $val) {
                $params[':' . $col] = is_bool($val) ? ($val ? 'true' : 'false') : $val;
            }
            $sql = 'INSERT INTO access_rules (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ') RETURNING id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $newId = (int)$stmt->fetchColumn();

            acl_log('rule_created', ['rule_id' => $newId, 'data' => $fields, 'by' => $createdBy]);
            acl_invalidate_cache();
            return ['ok' => true, 'id' => $newId, 'action' => 'created'];
        }
    } catch (Throwable $e) {
        sys_log('ACL-RULE-SAVE', 'Failed saving access rule: ' . $e->getMessage(), [
            'module' => 'access_control', 'file' => __FILE__, 'line' => __LINE__,
        ]);
        return ['ok' => false, 'error' => 'Database error saving rule.'];
    }
}

/**
 * Delete an access rule.
 */
function acl_delete_rule(int $id, int $deletedBy): bool {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('DELETE FROM access_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            acl_log('rule_deleted', ['rule_id' => $id, 'by' => $deletedBy]);
            acl_invalidate_cache();
            return true;
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Toggle a rule's active state.
 */
function acl_toggle_rule(int $id, bool $active, int $updatedBy): bool {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('UPDATE access_rules SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':active' => $active ? 'true' : 'false', ':id' => $id]);
        acl_log($active ? 'rule_enabled' : 'rule_disabled', ['rule_id' => $id, 'by' => $updatedBy]);
        acl_invalidate_cache();
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * List all access rules with optional filters.
 */
function acl_list_rules(array $filters = []): array {
    try {
        $pdo = get_db_conn();
        $where = [];
        $params = [];

        if (isset($filters['rule_type'])) {
            $where[] = 'ar.rule_type = :rule_type';
            $params[':rule_type'] = $filters['rule_type'];
        }
        if (isset($filters['entry_type'])) {
            $where[] = 'ar.entry_type = :entry_type';
            $params[':entry_type'] = $filters['entry_type'];
        }
        if (isset($filters['scope'])) {
            $where[] = 'ar.scope = :scope';
            $params[':scope'] = $filters['scope'];
        }
        if (isset($filters['is_active'])) {
            $where[] = 'ar.is_active = :is_active';
            $params[':is_active'] = $filters['is_active'] ? 'true' : 'false';
        }
        if (!empty($filters['search'])) {
            $where[] = '(ar.label ILIKE :search OR ar.value ILIKE :search OR ar.reason ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT ar.*, 
                u.full_name AS created_by_name,
                tu.full_name AS target_user_name,
                df.label AS device_label
            FROM access_rules ar
            LEFT JOIN users u ON u.id = ar.created_by
            LEFT JOIN users tu ON tu.id = ar.target_user_id
            LEFT JOIN device_fingerprints df ON df.fingerprint_hash = ar.device_fingerprint_hash'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ar.priority DESC, ar.created_at DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a single rule by ID.
 */
function acl_get_rule(int $id): ?array {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('SELECT ar.*, u.full_name AS created_by_name, tu.full_name AS target_user_name, df.label AS device_label
            FROM access_rules ar
            LEFT JOIN users u ON u.id = ar.created_by
            LEFT JOIN users tu ON tu.id = ar.target_user_id
            LEFT JOIN device_fingerprints df ON df.fingerprint_hash = ar.device_fingerprint_hash
            WHERE ar.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ─── Enforcement (Checking) ─────────────────────────────────────────────

/**
 * Check if a device is bound to a specific user.
 * Returns: null (no binding exists — allow), true (binding exists and matches), false (binding exists but doesn't match).
 */
function acl_check_device_user_binding(string $fingerprint, int $userId): ?bool {
    if (!acl_device_binding_enabled()) {
        return null;
    }

    try {
        $pdo = get_db_conn();
        // Check if any binding exists for this device
        $stmt = $pdo->prepare("SELECT target_user_id FROM access_rules 
            WHERE entry_type = 'device_user_bind' 
            AND device_fingerprint_hash = :hash 
            AND is_active = true 
            AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)");
        $stmt->execute([':hash' => $fingerprint]);
        $bindings = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($bindings)) {
            return null; // No bindings for this device — allow anyone
        }

        // Check if the user is in the binding list
        return in_array($userId, array_map('intval', $bindings), true);
    } catch (Throwable $e) {
        return null; // Degrade gracefully
    }
}

/**
 * Check if a device is allowed to access a specific module.
 * Returns: null (no restriction), true (allowed), false (restricted).
 */
function acl_check_device_module(string $fingerprint, string $module): ?bool {
    if (!acl_module_restriction_enabled()) {
        return null;
    }

    try {
        $pdo = get_db_conn();
        // Check if any module binding exists for this device
        $stmt = $pdo->prepare("SELECT target_module FROM access_rules 
            WHERE entry_type = 'device_module_bind' 
            AND device_fingerprint_hash = :hash 
            AND is_active = true 
            AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)");
        $stmt->execute([':hash' => $fingerprint]);
        $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($modules)) {
            return null; // No module restrictions for this device
        }

        return in_array($module, $modules, true);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Check IP against whitelist/blacklist rules.
 * Returns: ['allowed' => bool, 'rule' => ?array, 'reason' => string]
 */
function acl_check_ip(string $ip, string $scope = 'global'): array {
    if (!acl_is_enabled()) {
        return ['allowed' => true, 'rule' => null, 'reason' => 'ACL disabled'];
    }

    try {
        $pdo = get_db_conn();
        $mode = acl_get_setting('enforcement_mode', 'blacklist');

        // Get all active IP rules for this scope
        $stmt = $pdo->prepare("SELECT * FROM access_rules 
            WHERE entry_type IN ('ip', 'ip_range') 
            AND (scope = :scope OR scope = 'global')
            AND is_active = true 
            AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
            ORDER BY priority DESC, rule_type ASC");
        $stmt->execute([':scope' => $scope]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            $match = false;
            if ($rule['entry_type'] === 'ip') {
                $match = ($rule['value'] === $ip);
            } elseif ($rule['entry_type'] === 'ip_range') {
                // Use PostgreSQL inet containment — fallback to PHP comparison
                $match = acl_ip_in_cidr($ip, $rule['value']);
            }

            if ($match) {
                if ($rule['rule_type'] === 'whitelist') {
                    return ['allowed' => true, 'rule' => $rule, 'reason' => 'IP whitelisted'];
                } else {
                    return ['allowed' => false, 'rule' => $rule, 'reason' => $rule['reason'] ?: 'IP blacklisted'];
                }
            }
        }

        // No rule matched
        if ($mode === 'whitelist') {
            return ['allowed' => false, 'rule' => null, 'reason' => 'IP not in whitelist'];
        }
        return ['allowed' => true, 'rule' => null, 'reason' => 'No matching rule'];
    } catch (Throwable $e) {
        return ['allowed' => true, 'rule' => null, 'reason' => 'ACL check failed (degraded)'];
    }
}

/**
 * Check if an IP is within a CIDR range.
 */
function acl_ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    $mask = -1 << (32 - $bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Full access check — combines IP, device, and user checks.
 * Returns: ['allowed' => bool, 'reason' => string, 'rule' => ?array]
 */
function acl_check_full(string $ip, ?string $fingerprint, ?int $userId, string $scope = 'global'): array {
    if (!acl_is_enabled()) {
        return ['allowed' => true, 'reason' => 'ACL disabled', 'rule' => null];
    }

    // 1. Check IP
    $ipResult = acl_check_ip($ip, $scope);
    if (!$ipResult['allowed']) {
        // Check for override
        if ($userId && acl_has_active_override($userId, $scope)) {
            return ['allowed' => true, 'reason' => 'Admin override active', 'rule' => $ipResult['rule']];
        }
        return $ipResult;
    }

    // 2. Check device-user binding
    if ($fingerprint && $userId) {
        $bindResult = acl_check_device_user_binding($fingerprint, $userId);
        if ($bindResult === false) {
            if (acl_has_active_override($userId, 'login')) {
                return ['allowed' => true, 'reason' => 'Admin override active for device binding', 'rule' => null];
            }
            return ['allowed' => false, 'reason' => 'This device is not authorized for your account.', 'rule' => null];
        }
    }

    // 3. Check device-module binding
    if ($fingerprint && $scope !== 'global' && $scope !== 'login') {
        $moduleResult = acl_check_device_module($fingerprint, $scope);
        if ($moduleResult === false) {
            return ['allowed' => false, 'reason' => 'This device is not authorized for this module.', 'rule' => null];
        }
    }

    return ['allowed' => true, 'reason' => 'Access granted', 'rule' => null];
}

// ─── Overrides ───────────────────────────────────────────────────────────

/**
 * Grant a temporary override for a user.
 */
function acl_grant_override(int $userId, ?int $ruleId, string $scope, string $reason, int $grantedBy, int $durationMinutes = 60): array {
    try {
        $pdo = get_db_conn();
        $expiresAt = (new DateTimeImmutable("+{$durationMinutes} minutes"))->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare('INSERT INTO access_overrides (user_id, access_rule_id, scope, reason, granted_by, expires_at)
            VALUES (:uid, :rid, :scope, :reason, :by, :expires) RETURNING id');
        $stmt->execute([
            ':uid' => $userId,
            ':rid' => $ruleId,
            ':scope' => $scope,
            ':reason' => $reason,
            ':by' => $grantedBy,
            ':expires' => $expiresAt,
        ]);
        $id = (int)$stmt->fetchColumn();

        acl_log('override_granted', [
            'override_id' => $id,
            'user_id' => $userId,
            'scope' => $scope,
            'duration_minutes' => $durationMinutes,
            'granted_by' => $grantedBy,
        ]);

        return ['ok' => true, 'id' => $id, 'expires_at' => $expiresAt];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to grant override.'];
    }
}

/**
 * Check if a user has an active override for the given scope.
 */
function acl_has_active_override(int $userId, string $scope): bool {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM access_overrides 
            WHERE user_id = :uid 
            AND (scope = :scope OR scope = 'global')
            AND is_active = true 
            AND expires_at > CURRENT_TIMESTAMP");
        $stmt->execute([':uid' => $userId, ':scope' => $scope]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Revoke an override.
 */
function acl_revoke_override(int $overrideId, int $revokedBy): bool {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('UPDATE access_overrides SET is_active = false, revoked_at = CURRENT_TIMESTAMP, revoked_by = :by WHERE id = :id');
        $stmt->execute([':by' => $revokedBy, ':id' => $overrideId]);
        acl_log('override_revoked', ['override_id' => $overrideId, 'by' => $revokedBy]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * List overrides with optional filters.
 */
function acl_list_overrides(array $filters = []): array {
    try {
        $pdo = get_db_conn();
        $where = [];
        $params = [];
        
        if (isset($filters['active_only']) && $filters['active_only']) {
            $where[] = 'ao.is_active = true AND ao.expires_at > CURRENT_TIMESTAMP';
        }
        if (isset($filters['user_id'])) {
            $where[] = 'ao.user_id = :uid';
            $params[':uid'] = $filters['user_id'];
        }

        $sql = 'SELECT ao.*, u.full_name AS user_name, g.full_name AS granted_by_name
            FROM access_overrides ao
            LEFT JOIN users u ON u.id = ao.user_id
            LEFT JOIN users g ON g.id = ao.granted_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ao.granted_at DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ─── Logging ─────────────────────────────────────────────────────────────

/**
 * Log an access control event.
 */
function acl_log(string $eventType, array $context = []): void {
    try {
        $pdo = get_db_conn();
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if ($ip) {
            $ip = trim(explode(',', (string)$ip)[0]);
        }

        $stmt = $pdo->prepare('INSERT INTO access_control_logs (event_type, entry_type, scope, matched_value, matched_rule_id, user_id, ip_address, user_agent, device_fingerprint, details)
            VALUES (:event, :entry, :scope, :value, :rule_id, :uid, :ip, :ua, :fp, :details)');
        $stmt->execute([
            ':event' => $eventType,
            ':entry' => $context['entry_type'] ?? null,
            ':scope' => $context['scope'] ?? null,
            ':value' => $context['matched_value'] ?? null,
            ':rule_id' => $context['matched_rule_id'] ?? null,
            ':uid' => $context['user_id'] ?? ($_SESSION['user']['id'] ?? null),
            ':ip' => $ip,
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ':fp' => $context['fingerprint'] ?? ($_COOKIE['__acl_fp'] ?? null),
            ':details' => json_encode($context, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Silent — don't break app over logging failure
    }
}

/**
 * List access control log entries.
 */
function acl_list_logs(array $filters = [], int $limit = 50, int $offset = 0): array {
    try {
        $pdo = get_db_conn();
        $where = [];
        $params = [];

        if (!empty($filters['event_type'])) {
            $where[] = 'acl.event_type = :event';
            $params[':event'] = $filters['event_type'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'acl.user_id = :uid';
            $params[':uid'] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'acl.created_at >= :from';
            $params[':from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'acl.created_at <= :to';
            $params[':to'] = $filters['date_to'];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM access_control_logs acl' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $sql = 'SELECT acl.*, u.full_name AS user_name
            FROM access_control_logs acl
            LEFT JOIN users u ON u.id = acl.user_id'
            . $whereSql
            . ' ORDER BY acl.created_at DESC LIMIT :limit OFFSET :offset';
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['data' => $rows, 'total' => $total];
    } catch (Throwable $e) {
        return ['data' => [], 'total' => 0];
    }
}

/**
 * Get dashboard stats for the access control module.
 */
function acl_get_stats(): array {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->query("SELECT 
            (SELECT COUNT(*) FROM access_rules WHERE is_active = true) AS active_rules,
            (SELECT COUNT(*) FROM access_rules) AS total_rules,
            (SELECT COUNT(*) FROM device_fingerprints WHERE is_active = true) AS active_devices,
            (SELECT COUNT(*) FROM device_fingerprints) AS total_devices,
            (SELECT COUNT(*) FROM access_overrides WHERE is_active = true AND expires_at > CURRENT_TIMESTAMP) AS active_overrides,
            (SELECT COUNT(*) FROM access_control_logs WHERE created_at >= CURRENT_DATE) AS events_today,
            (SELECT COUNT(*) FROM access_control_logs WHERE event_type = 'blocked' AND created_at >= CURRENT_DATE) AS blocks_today,
            (SELECT COUNT(*) FROM access_rules WHERE entry_type = 'device_user_bind' AND is_active = true) AS device_bindings
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

// ─── Cache ───────────────────────────────────────────────────────────────

/**
 * Invalidate the session-based ACL cache.
 */
function acl_invalidate_cache(): void {
    unset($_SESSION['__acl_settings'], $_SESSION['__acl_settings_ts']);
}

// ─── Helpers ─────────────────────────────────────────────────────────────

/**
 * Get a list of all users (for dropdowns in the admin UI).
 */
function acl_get_users_list(): array {
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->query("SELECT u.id, u.full_name, u.email, u.role, u.status, e.employee_code 
            FROM users u 
            LEFT JOIN employees e ON e.user_id = u.id 
            WHERE u.status = 'active' 
            ORDER BY u.full_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get the list of available modules for module-binding rules.
 */
function acl_get_modules_list(): array {
    return [
        'attendance' => 'Attendance',
        'leave' => 'Leave Management',
        'payroll' => 'Payroll',
        'employees' => 'Employee Records',
        'overtime' => 'Overtime',
        'documents' => 'Documents',
        'inventory' => 'Inventory & POS',
        'performance' => 'Performance Reviews',
        'recruitment' => 'Recruitment',
        'memos' => 'Memos & Announcements',
        'departments' => 'Departments',
        'positions' => 'Positions',
        'admin' => 'Administration',
    ];
}
