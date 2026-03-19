<?php
/**
 * Position-Based Permissions System
 * 
 * Provides helper functions for checking and enforcing position-based access control.
 * This replaces the old role-based system with fine-grained resource permissions.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions_catalog.php';

/**
 * Get the position ID for a given user
 * 
 * @param int $userId User ID
 * @return int|null Position ID or null if user has no employee record
 */
function get_user_position_id(int $userId): ?int {
    static $cache = [];
    
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    
    try {
        $pdo = get_db_conn();
        $stmt = $pdo->prepare('
            SELECT e.position_id 
            FROM employees e 
            WHERE e.user_id = :uid 
            AND e.status = \'active\' 
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        $result = $stmt->fetchColumn();
        
        $cache[$userId] = $result ? (int)$result : null;
        return $cache[$userId];
    } catch (Throwable $e) {
        sys_log('PERMS-001', 'Failed to get user position: ' . $e->getMessage(), [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $userId]
        ]);
        return null;
    }
}

/**
 * Get effective access level for a user on a specific resource
 * 
 * @param int $userId User ID
 * @param string $domain Permission domain (e.g., 'payroll', 'hr_core')
 * @param string $resourceKey Resource key (e.g., 'payroll_runs', 'employees')
 * @return string Access level: 'none', 'read', 'write', or 'manage'
 */
function get_user_effective_access(int $userId, string $domain, string $resourceKey): string {
    static $cache = [];
    static $cacheGen = 0;
    
    // Check if cache has been invalidated
    global $__hrms_permission_cache_generation;
    $currentGen = (int)($__hrms_permission_cache_generation ?? 0);
    if ($currentGen !== $cacheGen) {
        $cache = [];
        $cacheGen = $currentGen;
    }
    
    $cacheKey = "{$userId}:{$domain}:{$resourceKey}";
    
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    try {
        $pdo = get_db_conn();
        
        // Check if user is system administrator (bypass all checks)
        $stmt = $pdo->prepare('SELECT is_system_admin FROM users WHERE id = :uid AND status = \'active\' LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $isSysAdmin = $stmt->fetchColumn();
        
        if ($isSysAdmin) {
            $cache[$cacheKey] = 'manage';
            return 'manage';
        }
        
        // Check for self-service resources (available to all users)
        $resourceInfo = get_resource_info($domain, $resourceKey);
        if ($resourceInfo && !empty($resourceInfo['self_service'])) {
            // Self-service resources default to 'write' for all authenticated users
            $cache[$cacheKey] = 'write';
            return 'write';
        }
        
        // Get user's position
        $positionId = get_user_position_id($userId);
        if (!$positionId) {
            // No position = no access (except self-service already handled above)
            $cache[$cacheKey] = 'none';
            return 'none';
        }
        
        // Look up permission in position_access_permissions
        $stmt = $pdo->prepare('
            SELECT access_level 
            FROM position_access_permissions 
            WHERE position_id = :pos 
            AND domain = :domain 
            AND resource_key = :resource 
            LIMIT 1
        ');
        $stmt->execute([
            ':pos' => $positionId,
            ':domain' => $domain,
            ':resource' => $resourceKey
        ]);
        
        $level = $stmt->fetchColumn();
        $result = $level ? (string)$level : 'none';
        
        $cache[$cacheKey] = $result;
        return $result;
        
    } catch (Throwable $e) {
        sys_log('PERMS-002', 'Failed to get effective access: ' . $e->getMessage(), [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => [
                'user_id' => $userId,
                'domain' => $domain,
                'resource_key' => $resourceKey
            ]
        ]);
        return 'none';
    }
}

/**
 * Check if user has at least the required access level for a resource
 * 
 * @param int $userId User ID
 * @param string $domain Permission domain
 * @param string $resourceKey Resource key
 * @param string $requiredLevel Required access level (none/read/write/manage)
 * @return bool True if user has sufficient access
 */
function user_has_access(int $userId, string $domain, string $resourceKey, string $requiredLevel = 'read'): bool {
    $userLevel = get_user_effective_access($userId, $domain, $resourceKey);
    return access_level_includes($userLevel, $requiredLevel);
}

/**
 * Check if current logged-in user has access (convenience wrapper)
 * 
 * @param string $domain Permission domain
 * @param string $resourceKey Resource key
 * @param string $requiredLevel Required access level
 * @return bool True if current user has sufficient access
 */
function user_can(string $domain, string $resourceKey, string $requiredLevel = 'read'): bool {
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        return false;
    }
    
    return user_has_access((int)$user['id'], $domain, $resourceKey, $requiredLevel);
}

/**
 * Require specific access level or redirect to unauthorized page
 * 
 * @param string $domain Permission domain
 * @param string $resourceKey Resource key
 * @param string $requiredLevel Required access level
 * @return void Redirects if access denied
 */
function require_access(string $domain, string $resourceKey, string $requiredLevel = 'read'): void {
    $user = $_SESSION['user'] ?? null;
    
    if (!$user) {
        http_response_code(401);
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
    
    if (!user_has_access((int)$user['id'], $domain, $resourceKey, $requiredLevel)) {
        http_response_code(403);
        
        // Log access denial for security monitoring
        sys_log('PERMS-DENY', 'Access denied', [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => [
                'user_id' => $user['id'],
                'user_email' => $user['email'] ?? null,
                'domain' => $domain,
                'resource_key' => $resourceKey,
                'required_level' => $requiredLevel,
                'user_level' => get_user_effective_access((int)$user['id'], $domain, $resourceKey),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null
            ]
        ]);
        
        header('Location: ' . BASE_URL . '/unauthorized');
        exit;
    }
}

/**
 * Get all permissions for a user (useful for UI rendering)
 * 
 * @param int $userId User ID
 * @return array Array of permissions [domain => [resource_key => access_level]]
 */
function get_user_all_permissions(int $userId): array {
    static $cache = [];
    
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    
    try {
        $pdo = get_db_conn();
        
        // Check if system admin
        $stmt = $pdo->prepare('SELECT is_system_admin FROM users WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            // System admin has manage access to everything
            $catalog = get_permissions_catalog();
            $result = [];
            foreach ($catalog as $domain => $domainData) {
                $result[$domain] = [];
                foreach ($domainData['resources'] as $resourceKey => $resourceData) {
                    $result[$domain][$resourceKey] = 'manage';
                }
            }
            $cache[$userId] = $result;
            return $result;
        }
        
        // Get position
        $positionId = get_user_position_id($userId);
        if (!$positionId) {
            // No position, return only self-service
            $result = [];
            $catalog = get_permissions_catalog();
            foreach ($catalog as $domain => $domainData) {
                foreach ($domainData['resources'] as $resourceKey => $resourceData) {
                    if (!empty($resourceData['self_service'])) {
                        if (!isset($result[$domain])) {
                            $result[$domain] = [];
                        }
                        $result[$domain][$resourceKey] = 'write';
                    }
                }
            }
            $cache[$userId] = $result;
            return $result;
        }
        
        // Fetch all permissions for this position
        $stmt = $pdo->prepare('
            SELECT domain, resource_key, access_level 
            FROM position_access_permissions 
            WHERE position_id = :pos 
            AND access_level != \'none\'
            ORDER BY domain, resource_key
        ');
        $stmt->execute([':pos' => $positionId]);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domain = $row['domain'];
            $resourceKey = $row['resource_key'];
            $accessLevel = $row['access_level'];
            
            if (!isset($result[$domain])) {
                $result[$domain] = [];
            }
            $result[$domain][$resourceKey] = $accessLevel;
        }
        
        // Add self-service resources
        $catalog = get_permissions_catalog();
        foreach ($catalog as $domain => $domainData) {
            foreach ($domainData['resources'] as $resourceKey => $resourceData) {
                if (!empty($resourceData['self_service'])) {
                    if (!isset($result[$domain])) {
                        $result[$domain] = [];
                    }
                    // Only add if not already defined (explicit permission takes precedence)
                    if (!isset($result[$domain][$resourceKey])) {
                        $result[$domain][$resourceKey] = 'write';
                    }
                }
            }
        }
        
        $cache[$userId] = $result;
        return $result;
        
    } catch (Throwable $e) {
        sys_log('PERMS-003', 'Failed to get all user permissions: ' . $e->getMessage(), [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $userId]
        ]);
        return [];
    }
}

/**
 * Get position information including permissions count
 * 
 * @param int $positionId Position ID
 * @return array|null Position data with permission stats
 */
function get_position_with_permissions(int $positionId): ?array {
    try {
        $pdo = get_db_conn();
        
        $stmt = $pdo->prepare('
            SELECT 
                p.*,
                d.name as department_name,
                COUNT(pap.id) as permission_count,
                COUNT(CASE WHEN pap.access_level = \'manage\' THEN 1 END) as manage_count,
                COUNT(CASE WHEN pap.access_level = \'write\' THEN 1 END) as write_count,
                COUNT(CASE WHEN pap.access_level = \'read\' THEN 1 END) as read_count
            FROM positions p
            LEFT JOIN departments d ON d.id = p.department_id
            LEFT JOIN position_access_permissions pap ON pap.position_id = p.id
            WHERE p.id = :id
            GROUP BY p.id, d.name
        ');
        $stmt->execute([':id' => $positionId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        sys_log('PERMS-004', 'Failed to get position with permissions: ' . $e->getMessage(), [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['position_id' => $positionId]
        ]);
        return null;
    }
}

/**
 * Check if user can perform override for a resource
 * 
 * @param int $userId User ID
 * @param string $domain Permission domain
 * @param string $resourceKey Resource key
 * @return bool True if user's position allows override
 */
function user_can_override(int $userId, string $domain, string $resourceKey): bool {
    try {
        $pdo = get_db_conn();
        
        // System admins can always override
        $stmt = $pdo->prepare('SELECT is_system_admin FROM users WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        
        // Check position's allow_override flag
        $positionId = get_user_position_id($userId);
        if (!$positionId) {
            return false;
        }
        
        $stmt = $pdo->prepare('
            SELECT allow_override 
            FROM position_access_permissions 
            WHERE position_id = :pos 
            AND domain = :domain 
            AND resource_key = :resource 
            AND access_level = \'manage\'
            LIMIT 1
        ');
        $stmt->execute([
            ':pos' => $positionId,
            ':domain' => $domain,
            ':resource' => $resourceKey
        ]);
        
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Clear permission cache (call after permission changes)
 */
function clear_permission_cache(): void {
    // Note: PHP static variables in functions can't be externally reset.
    // Mark a global flag so cached functions know to re-query on next call.
    global $__hrms_permission_cache_generation;
    $__hrms_permission_cache_generation = ($__hrms_permission_cache_generation ?? 0) + 1;
}

/**
 * Check if a user can grant permissions (sensitive capability)
 * Only the superadmin (SUPERADMIN_USER_ID from config.php) can modify this flag
 * 
 * @param int $userId User ID to check
 * @return bool True if user can grant permissions above their level
 */
function user_can_grant_permissions(int $userId): bool {
    static $cache = [];
    
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    
    try {
        $pdo = get_db_conn();
        
        // Superadmin always can
        if ($userId === SUPERADMIN_USER_ID) {
            $cache[$userId] = true;
            return true;
        }
        
        // Check the sensitive permission flag
        $stmt = $pdo->prepare('
            SELECT can_grant_permissions 
            FROM users 
            WHERE id = :uid 
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        $result = (bool)$stmt->fetchColumn();
        
        $cache[$userId] = $result;
        return $result;
    } catch (Throwable $e) {
        sys_log('PERMS-GRANT-001', 'Failed to check can_grant_permissions: ' . $e->getMessage(), [
            'module' => 'permissions',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['user_id' => $userId]
        ]);
        return false;
    }
}

/**
 * Check if current session user can modify the can_grant_permissions flag
 * Only the superadmin (configured in config.php) can modify this sensitive permission
 * 
 * @return bool True if current user is superadmin
 */
function can_modify_grant_permissions_flag(): bool {
    return isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === SUPERADMIN_USER_ID;
}
