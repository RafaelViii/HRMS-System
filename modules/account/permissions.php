<?php
/**
 * Position Permissions Management
 * 
 * Centralized view for managing position-based permissions across all positions.
 * This replaces the old roles system with the new position-based permission model.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/permissions.php';

// Require write access to user accounts (which includes permission management)
require_access('user_management', 'user_accounts', 'write');

$pdo = get_db_conn();

// Handle quick navigation to specific position
if (isset($_GET['id'])) {
    $positionId = (int)$_GET['id'];
    // Redirect to position-specific permissions page
    header('Location: ' . BASE_URL . '/modules/account/permissions?position=' . $positionId);
    exit;
}

$selectedPositionId = (int)($_GET['position'] ?? 0);

// Get all positions
$positions = [];
try {
    $stmt = $pdo->query('
        SELECT p.id, p.name, p.department_id, d.name AS department_name,
               (SELECT COUNT(*) FROM employees WHERE position_id = p.id) AS employee_count,
               (SELECT COUNT(*) FROM position_access_permissions WHERE position_id = p.id) AS permission_count
        FROM positions p
        LEFT JOIN departments d ON d.id = p.department_id
        ORDER BY p.name
    ');
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('DB-PERMS-001', 'Failed to fetch positions: ' . $e->getMessage(), [
        'module' => 'account',
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}

// If a specific position is selected, handle permission updates
$selectedPosition = null;
if ($selectedPositionId > 0) {
    foreach ($positions as $pos) {
        if ((int)$pos['id'] === $selectedPositionId) {
            $selectedPosition = $pos;
            break;
        }
    }
    
    if (!$selectedPosition) {
        flash_error('Position not found');
        header('Location: ' . BASE_URL . '/modules/account/permissions');
        exit;
    }
}

// Handle form submission
if ($selectedPosition && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_permissions') {
        $permissions = $_POST['permissions'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $allowOverride = $_POST['allow_override'] ?? [];
        
        $pdo->beginTransaction();
        try {
            // Delete existing permissions
            $stmt = $pdo->prepare('DELETE FROM position_access_permissions WHERE position_id = :pid');
            $stmt->execute([':pid' => $selectedPositionId]);
            
            // Insert new permissions
            $insertStmt = $pdo->prepare('
                INSERT INTO position_access_permissions 
                (position_id, domain, resource_key, access_level, allow_override, notes)
                VALUES (:pid, :domain, :resource, :level, :override, :notes)
            ');
            
            $savedCount = 0;
            foreach ($permissions as $key => $level) {
                // Skip 'none' permissions (don't insert them)
                if ($level === 'none') continue;
                
                // Parse domain.resource format
                if (strpos($key, '.') === false) continue;
                [$domain, $resource] = explode('.', $key, 2);
                
                    $insertStmt->execute([
                        ':pid' => $selectedPositionId,
                        ':domain' => $domain,
                        ':resource' => $resource,
                        ':level' => $level,
                        ':override' => isset($allowOverride[$key]) ? 1 : 0,
                        ':notes' => trim($notes[$key] ?? '') ?: null
                    ]);
                    $savedCount++;
                }
                
                $pdo->commit();
                
                // Clear permission cache
                clear_permission_cache();
                
                // Log the change
                audit('update_position_permissions', json_encode([
                    'position_id' => $selectedPositionId,
                    'position_name' => $selectedPosition['name'],
                    'permissions_count' => $savedCount
                ]));
                
                action_log('account', 'update_permissions', 'success', [
                    'position_id' => $selectedPositionId,
                    'permissions_saved' => $savedCount
                ]);
                
                flash_success('Permissions updated successfully (' . $savedCount . ' active permissions)');
                header('Location: ' . BASE_URL . '/modules/account/permissions?position=' . $selectedPositionId);
                exit;        } catch (Throwable $e) {
            $pdo->rollBack();
            sys_log('DB-PERMS-002', 'Failed to update permissions: ' . $e->getMessage(), [
                'module' => 'account',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['position_id' => $selectedPositionId]
            ]);
            flash_error('Failed to update permissions');
        }
    }
    
    if ($action === 'copy_from_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        
        if ($templateId && $templateId !== $selectedPositionId) {
            try {
                $pdo->beginTransaction();
                
                // Delete existing permissions
                $stmt = $pdo->prepare('DELETE FROM position_access_permissions WHERE position_id = :pid');
                $stmt->execute([':pid' => $selectedPositionId]);
                
                // Copy from template
                $stmt = $pdo->prepare('
                    INSERT INTO position_access_permissions 
                    (position_id, domain, resource_key, access_level, allow_override, notes)
                    SELECT :new_pid, domain, resource_key, access_level, allow_override, 
                           CONCAT(\'Copied from \', (SELECT name FROM positions WHERE id = :template_pid))
                    FROM position_access_permissions
                    WHERE position_id = :template_pid
                ');
                $stmt->execute([':new_pid' => $selectedPositionId, ':template_pid' => $templateId]);
                
                $copiedCount = $stmt->rowCount();
                $pdo->commit();
                
                clear_permission_cache();
                
                audit('copy_position_permissions', json_encode([
                    'position_id' => $selectedPositionId,
                    'template_id' => $templateId,
                    'permissions_copied' => $copiedCount
                ]));
                
                flash_success('Copied ' . $copiedCount . ' permissions from template');
                header('Location: ' . BASE_URL . '/modules/account/permissions?position=' . $selectedPositionId);
                exit;
                
            } catch (Throwable $e) {
                $pdo->rollBack();
                sys_log('DB-PERMS-003', 'Failed to copy permissions: ' . $e->getMessage(), [
                    'module' => 'account',
                    'file' => __FILE__,
                    'line' => __LINE__
                ]);
                flash_error('Failed to copy permissions from template');
            }
        }
    }
}

// Get current permissions for selected position
$currentPermissions = [];
if ($selectedPosition) {
    try {
        $stmt = $pdo->prepare('
            SELECT domain, resource_key, access_level, allow_override, notes
            FROM position_access_permissions
            WHERE position_id = :pid
        ');
        $stmt->execute([':pid' => $selectedPositionId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['domain'] . '.' . $row['resource_key'];
        $currentPermissions[$key] = [
            'level' => $row['access_level'],
            'override' => (bool)$row['allow_override'],
            'notes' => $row['notes']
        ];
    }
} catch (Throwable $e) {
    sys_log('DB-PERMS-004', 'Failed to fetch current permissions: ' . $e->getMessage(), [
        'module' => 'account',
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}

    // Get all positions for template selection
    $templatePositions = [];
    try {
        $stmt = $pdo->prepare('
            SELECT p.id, p.name, COUNT(pap.id) AS perm_count
            FROM positions p
            LEFT JOIN position_access_permissions pap ON pap.position_id = p.id
            WHERE p.id != :current_id
            GROUP BY p.id, p.name
            HAVING COUNT(pap.id) > 0
            ORDER BY p.name
        ');
        $stmt->execute([':current_id' => $selectedPositionId]);
        $templatePositions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Ignore error, template feature just won't be available
    }
}

// Get permissions catalog
$catalog = get_permissions_catalog();
$accessLevels = get_access_levels();

$pageTitle = 'Manage Permissions';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto p-4">
  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="flex items-center gap-3 mb-1">
        <h1 class="text-2xl font-semibold text-gray-800">Manage Permissions</h1>
        <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded">Position-Based Access Control</span>
      </div>
      <p class="text-sm text-gray-600">Configure fine-grained access permissions for each position</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/account/index" class="btn btn-light">
      <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Accounts
    </a>
  </div>

  <?php if (!$selectedPosition): ?>
    <!-- Position Selection View -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        <div>
          <p class="text-sm font-semibold text-blue-900 mb-1">Position-Based Permission Model</p>
          <p class="text-xs text-blue-800">Each position has specific permissions assigned. Employees inherit permissions from their position. Select a position below to manage its permissions.</p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-gray-800">All Positions (<?= count($positions) ?>)</h2>
      </div>
      
      <div class="divide-y divide-gray-100">
        <?php if ($positions): ?>
          <?php foreach ($positions as $pos): ?>
            <div class="px-6 py-4 hover:bg-gray-50 transition-colors">
              <div class="flex items-center justify-between">
                <div class="flex-1">
                  <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($pos['name']) ?></h3>
                  <div class="flex items-center gap-4 mt-1 text-sm text-gray-600">
                    <span><?= htmlspecialchars($pos['department_name'] ?? 'No Department') ?></span>
                    <span class="text-gray-400">•</span>
                    <span><?= $pos['employee_count'] ?> employee(s)</span>
                    <span class="text-gray-400">•</span>
                    <span class="font-medium <?= $pos['permission_count'] > 0 ? 'text-emerald-600' : 'text-gray-400' ?>">
                      <?= $pos['permission_count'] ?> permission(s) configured
                    </span>
                  </div>
                </div>
                <a href="<?= BASE_URL ?>/modules/account/permissions?position=<?= $pos['id'] ?>" class="btn btn-primary btn-sm">
                  Manage Permissions
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="px-6 py-8 text-center text-gray-500">
            <p>No positions found. Create positions first in the Positions module.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  
  <?php else: ?>
    <!-- Permission Editor View -->
    <div class="mb-4">
      <nav class="flex items-center gap-2 text-sm text-gray-600">
        <a href="<?= BASE_URL ?>/modules/account/permissions" class="hover:text-blue-600">All Positions</a>
        <span>›</span>
        <span class="font-semibold text-gray-900"><?= htmlspecialchars($selectedPosition['name']) ?></span>
      </nav>
    </div>

    <!-- Position Info Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($selectedPosition['name']) ?></h2>
          <p class="text-sm text-gray-600 mt-1">
            <?= htmlspecialchars($selectedPosition['department_name'] ?? 'No Department') ?>
            • <?= $selectedPosition['employee_count'] ?> employee(s)
          </p>
        </div>
        
        <?php if ($templatePositions): ?>
        <div class="flex items-center gap-2">
            <form method="post" id="copy-form" class="flex items-center gap-2">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="copy_from_template">
                <select name="template_id" class="input-text text-sm" required>
                    <option value="">Copy from template...</option>
                    <?php foreach ($templatePositions as $tpl): ?>
                        <option value="<?= $tpl['id'] ?>">
                            <?= htmlspecialchars($tpl['name']) ?> (<?= $tpl['perm_count'] ?> perms)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm" 
                        data-confirm="This will replace all current permissions. Continue?">
                    Copy
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <?php 
    $activePerms = count($currentPermissions);
    $totalResources = 0;
    foreach ($catalog as $domain) {
        $totalResources += count($domain['resources']);
    }
    ?>
      <div class="mt-3 flex items-center gap-4 text-sm">
        <span class="text-gray-600">
          <strong class="text-emerald-600"><?= $activePerms ?></strong> of 
          <strong><?= $totalResources ?></strong> permissions configured
        </span>
        <span class="text-gray-400">•</span>
        <span class="text-xs text-gray-500">
            Changes affect <?= $selectedPosition['employee_count'] ?> employee(s) immediately
        </span>
      </div>
    </div>

    <!-- Permissions Form -->
    <form method="post" id="permissions-form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_permissions">
      
      <?php foreach ($catalog as $domainKey => $domain): ?>
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-3">
        <!-- Collapsible Header -->
        <button type="button" 
                class="w-full px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors domain-toggle"
                data-domain="<?= htmlspecialchars($domainKey) ?>">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-gray-400 transition-transform duration-200 chevron-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
            <div class="text-left">
              <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($domain['label']) ?></h3>
              <p class="text-xs text-gray-600 mt-0.5"><?= htmlspecialchars($domain['description']) ?></p>
            </div>
          </div>
          <span class="px-3 py-1 bg-gray-100 text-gray-700 text-xs font-medium rounded-full domain-count">
            <?php 
              $domainPerms = array_filter($currentPermissions, function($key) use ($domainKey) {
                  return strpos($key, $domainKey . '.') === 0;
              }, ARRAY_FILTER_USE_KEY);
              echo count($domainPerms) . ' / ' . count($domain['resources']);
            ?>
          </span>
        </button>
        
        <!-- Collapsible Content -->
        <div class="domain-content" data-domain="<?= htmlspecialchars($domainKey) ?>" style="display: none;">
          <div class="border-t border-gray-200">
            <!-- Table Header -->
            <div class="grid grid-cols-12 gap-4 px-6 py-3 bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-700 uppercase tracking-wider">
              <div class="col-span-5">Resource</div>
              <div class="col-span-3 text-center">Access Level</div>
              <div class="col-span-2 text-center">Override</div>
              <div class="col-span-2 text-center">Status</div>
            </div>
            
            <!-- Table Rows -->
            <?php foreach ($domain['resources'] as $resourceKey => $resource): ?>
            <?php 
                $fullKey = $domainKey . '.' . $resourceKey;
                $current = $currentPermissions[$fullKey] ?? null;
                $currentLevel = $current['level'] ?? 'none';
                $currentOverride = $current['override'] ?? false;
                $currentNotes = $current['notes'] ?? '';
            ?>
            <div class="grid grid-cols-12 gap-4 px-6 py-4 border-b border-gray-100 hover:bg-blue-50 transition-colors items-center">
              <!-- Resource Info -->
              <div class="col-span-5">
                <label class="block font-medium text-sm text-gray-900 cursor-pointer">
                  <?= htmlspecialchars($resource['label']) ?>
                </label>
                <p class="text-xs text-gray-600 mt-0.5 leading-tight"><?= htmlspecialchars($resource['description']) ?></p>
                <?php if ($resource['self_service']): ?>
                  <span class="inline-block mt-1.5 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                    <svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                    Self-Service
                  </span>
                <?php endif; ?>
              </div>
              
              <!-- Access Level -->
              <div class="col-span-3 flex justify-center">
                <select name="permissions[<?= htmlspecialchars($fullKey) ?>]" 
                        class="input-text text-sm w-full max-w-[140px] permission-select" 
                        data-resource="<?= htmlspecialchars($fullKey) ?>"
                        data-label="<?= htmlspecialchars($resource['label']) ?>"
                        <?= $resource['self_service'] ? 'disabled' : '' ?>>
                  <?php foreach ($accessLevels as $levelKey => $level): ?>
                    <option value="<?= $levelKey ?>" 
                            <?= $currentLevel === $levelKey ? 'selected' : '' ?>
                            data-color="<?= $level['color'] ?>">
                      <?= htmlspecialchars($level['label']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <!-- Override Checkbox -->
              <div class="col-span-2 flex justify-center">
                <?php if (!$resource['self_service']): ?>
                  <label class="flex items-center gap-2 cursor-pointer group" 
                         title="Allow authorization of destructive actions">
                    <input type="checkbox" 
                           name="allow_override[<?= htmlspecialchars($fullKey) ?>]"
                           class="form-checkbox h-5 w-5 text-red-600 rounded border-gray-300 focus:ring-red-500 override-checkbox"
                           data-resource="<?= htmlspecialchars($fullKey) ?>"
                           data-label="<?= htmlspecialchars($resource['label']) ?>"
                           <?= $currentOverride ? 'checked' : '' ?>
                           <?= $currentLevel === 'none' || $currentLevel === 'read' ? 'disabled' : '' ?>>
                    <span class="text-xs text-gray-500 group-hover:text-gray-700">
                      <?= $currentOverride ? '<svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> Enabled' : 'Disabled' ?>
                    </span>
                  </label>
                <?php else: ?>
                  <span class="text-xs text-gray-400">N/A</span>
                <?php endif; ?>
              </div>
              
              <!-- Status Badge -->
              <div class="col-span-2 flex justify-center">
                <?php if ($currentLevel === 'none'): ?>
                  <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs font-medium rounded">
                    No Access
                  </span>
                <?php elseif ($currentLevel === 'read'): ?>
                  <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded">
                    <svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Read Only
                  </span>
                <?php elseif ($currentLevel === 'write'): ?>
                  <span class="px-2 py-1 bg-emerald-100 text-emerald-700 text-xs font-medium rounded">
                    <svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    Can Edit
                  </span>
                <?php elseif ($currentLevel === 'manage'): ?>
                  <span class="px-2 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded">
                    <svg class="w-3.5 h-3.5 inline mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Full Control
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      
      <div class="flex items-center gap-3 sticky bottom-0 bg-white p-4 border-t border-gray-200 shadow-lg rounded-lg">
        <button type="submit" class="btn btn-primary">
          Save Permissions
        </button>
        <a href="<?= BASE_URL ?>/modules/account/permissions" class="btn btn-outline">
          Back to List
        </a>
        <span class="text-sm text-gray-600 ml-auto">
          <span id="changed-count">0</span> change(s) pending
        </span>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- Override Explanation Modal -->
<div id="override-modal" class="hidden fixed inset-0 z-50 overflow-y-auto" style="display: none;">
  <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" onclick="closeOverrideModal()"></div>
  <div class="flex min-h-full items-center justify-center p-4">
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-2xl ring-1 ring-gray-200">
      <div class="px-6 py-4 bg-red-50 border-b border-red-200 rounded-t-xl">
        <h3 class="text-lg font-semibold text-red-900 flex items-center gap-2">
          <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
          </svg>
          Override Capability - Important Notice
        </h3>
      </div>
      <div class="p-6">
        <div class="space-y-4">
          <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-yellow-900 mb-2 flex items-center gap-1.5">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              What is Override?
            </p>
            <p class="text-sm text-yellow-800">
              Override allows users to <strong>authorize sensitive or destructive actions</strong> after re-authenticating with their password.
            </p>
          </div>
          
          <div>
            <p class="text-sm font-semibold text-gray-900 mb-2">Override enables:</p>
            <ul class="text-sm text-gray-700 space-y-1 ml-4 list-disc">
              <li>Deleting records permanently</li>
              <li>Releasing payroll to banks</li>
              <li>Reversing completed transactions</li>
              <li>Overriding system validations</li>
              <li>Other high-risk operations</li>
            </ul>
          </div>
          
          <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-red-900 mb-2 flex items-center gap-1.5">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Security Implications:
            </p>
            <ul class="text-sm text-red-800 space-y-1 ml-4 list-disc">
              <li>All override actions are <strong>audited with user identity</strong></li>
              <li>Requires password re-entry (two-factor confirmation)</li>
              <li>Cannot be delegated to others</li>
              <li>Grant only to trusted senior staff</li>
            </ul>
          </div>
          
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-blue-900 mb-2 flex items-center gap-1.5">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
              Recommendation:
            </p>
            <p class="text-sm text-blue-800">
              Only enable override for <strong>department heads and above</strong> who need to perform destructive actions as part of their duties.
            </p>
          </div>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-xl flex justify-end">
        <button type="button" class="btn btn-primary" onclick="closeOverrideModal()">
          I Understand
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Save Confirmation Modal -->
<div id="confirm-modal" class="hidden fixed inset-0 z-50 overflow-y-auto" style="display: none;">
  <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
  <div class="flex min-h-full items-center justify-center p-4">
    <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl ring-1 ring-gray-200">
      <div class="px-6 py-4 border-b border-gray-200 rounded-t-xl">
        <h3 class="text-lg font-semibold text-gray-900">Confirm Permission Changes</h3>
      </div>
      <div class="p-6">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
          <p class="text-sm text-blue-900 flex items-center gap-1.5">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <strong>These changes will affect <?= isset($selectedPosition) ? (int)($selectedPosition['employee_count'] ?? 0) : 0 ?> employee(s) immediately.</strong>
          </p>
          <p class="text-xs text-blue-800 mt-1">Review the changes below before proceeding.</p>
        </div>
        
        <div class="max-h-96 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0">
              <tr>
                <th class="px-4 py-2 text-left font-semibold text-gray-700">Resource</th>
                <th class="px-4 py-2 text-left font-semibold text-gray-700">Change</th>
                <th class="px-4 py-2 text-left font-semibold text-gray-700">Override</th>
              </tr>
            </thead>
            <tbody id="changes-list" class="divide-y divide-gray-200">
              <!-- Populated by JavaScript -->
            </tbody>
          </table>
        </div>
        
        <div class="mt-4 text-xs text-gray-600">
          <p><strong>Note:</strong> All changes are logged and can be audited. This action cannot be undone automatically.</p>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-xl flex justify-end gap-3">
        <button type="button" class="btn btn-outline" onclick="closeConfirmModal()">
          Cancel
        </button>
        <button type="button" class="btn btn-primary" onclick="confirmSave()">
          Confirm & Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Permissions UI Management
function _initPermissionsPage() {
    const form = document.getElementById('permissions-form');
    if (!form) return;
    
    const selects = form.querySelectorAll('.permission-select');
    const overrideCheckboxes = form.querySelectorAll('.override-checkbox');
    const changedCount = document.getElementById('changed-count');
    let changes = 0;
    
    // Store initial values
    const initialValues = {};
    selects.forEach(select => {
        const key = select.dataset.resource;
        initialValues[key] = {
            level: select.value,
            override: document.querySelector(`input[name="allow_override[${key}]"]`)?.checked || false,
            label: select.dataset.label || key
        };
    });
    
    // Collapsible domain sections
    document.querySelectorAll('.domain-toggle').forEach(button => {
        button.addEventListener('click', function() {
            const domain = this.dataset.domain;
            const content = document.querySelector(`.domain-content[data-domain="${domain}"]`);
            const chevron = this.querySelector('.chevron-icon');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                content.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        });
    });
    
    // Expand first domain by default
    const firstToggle = document.querySelector('.domain-toggle');
    if (firstToggle) {
        firstToggle.click();
    }
    
    function updateChangeCount() {
        changes = 0;
        selects.forEach(select => {
            const key = select.dataset.resource;
            const overrideCheck = document.querySelector(`input[name="allow_override[${key}]"]`);
            
            if (select.value !== initialValues[key].level) changes++;
            if (overrideCheck && overrideCheck.checked !== initialValues[key].override) changes++;
        });
        changedCount.textContent = changes;
        changedCount.parentElement.style.fontWeight = changes > 0 ? 'bold' : 'normal';
        
        // Update domain counts
        updateDomainCounts();
    }
    
    function updateDomainCounts() {
        document.querySelectorAll('.domain-toggle').forEach(toggle => {
            const domain = toggle.dataset.domain;
            const content = document.querySelector(`.domain-content[data-domain="${domain}"]`);
            const countBadge = toggle.querySelector('.domain-count');
            
            if (content && countBadge) {
                const rows = content.querySelectorAll('.permission-select');
                let configured = 0;
                rows.forEach(select => {
                    if (select.value !== 'none') configured++;
                });
                countBadge.textContent = `${configured} / ${rows.length}`;
            }
        });
    }
    
    // Enable/disable override checkbox based on access level
    selects.forEach(select => {
        select.addEventListener('change', function() {
            const key = this.dataset.resource;
            const overrideCheck = document.querySelector(`input[name="allow_override[${key}]"]`);
            
            if (overrideCheck) {
                // Only write/manage levels can have override capability
                if (this.value === 'none' || this.value === 'read') {
                    overrideCheck.disabled = true;
                    overrideCheck.checked = false;
                } else {
                    overrideCheck.disabled = false;
                }
            }
            
            updateChangeCount();
        });
    });
    
    // Show override explanation modal when checkbox is clicked
    overrideCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function(e) {
            if (this.checked) {
                // Show modal explaining override
                showOverrideModal(this);
            }
            updateChangeCount();
        });
    });
    
    // Intercept form submission to show confirmation modal
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (changes === 0) {
            alert('No changes to save.');
            return;
        }
        
        showConfirmModal();
    });
    
    // Warn on unsaved changes
    let formSubmitting = false;
    window.addEventListener('beforeunload', function(e) {
        if (changes > 0 && !formSubmitting) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Modal management
    window.showOverrideModal = function(checkbox) {
        const modal = document.getElementById('override-modal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        modal.dataset.targetCheckbox = checkbox.dataset.resource;
    };
    
    window.closeOverrideModal = function() {
        const modal = document.getElementById('override-modal');
        modal.classList.add('hidden');
        modal.style.display = 'none';
    };
    
    window.showConfirmModal = function() {
        const modal = document.getElementById('confirm-modal');
        const changesList = document.getElementById('changes-list');
        changesList.innerHTML = '';
        
        // Build changes list
        selects.forEach(select => {
            const key = select.dataset.resource;
            const overrideCheck = document.querySelector(`input[name="allow_override[${key}]"]`);
            const label = select.dataset.label || key;
            
            const levelChanged = select.value !== initialValues[key].level;
            const overrideChanged = overrideCheck && (overrideCheck.checked !== initialValues[key].override);
            
            if (levelChanged || overrideChanged) {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50';
                
                let changeText = '';
                if (levelChanged) {
                    changeText = `${initialValues[key].level} → ${select.value}`;
                }
                
                let overrideText = '';
                if (overrideChanged) {
                    overrideText = overrideCheck.checked ? '<svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>Enabled' : 'Disabled';
                }
                
                row.innerHTML = `
                    <td class="px-4 py-2 text-gray-900">${label}</td>
                    <td class="px-4 py-2">
                        ${levelChanged ? `<span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded">${changeText}</span>` : '<span class="text-gray-400">No change</span>'}
                    </td>
                    <td class="px-4 py-2">
                        ${overrideChanged ? `<span class="px-2 py-1 bg-red-100 text-red-700 text-xs font-medium rounded">${overrideText}</span>` : '<span class="text-gray-400">No change</span>'}
                    </td>
                `;
                changesList.appendChild(row);
            }
        });
        
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    };
    
    window.closeConfirmModal = function() {
        const modal = document.getElementById('confirm-modal');
        modal.classList.add('hidden');
        modal.style.display = 'none';
    };
    
    window.confirmSave = function() {
        formSubmitting = true;
        form.submit();
    };
    
    // Initialize counts
    updateDomainCounts();
}

// Initialize on first load and on SPA navigation
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _initPermissionsPage);
} else {
    _initPermissionsPage();
}
document.addEventListener('spa:loaded', _initPermissionsPage);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
