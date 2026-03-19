<?php
/**
 * Position Permissions Management
 * 
 * Allows administrators to manage fine-grained access permissions for each position.
 * Permissions are organized by domain (system, hr_core, payroll, etc.) and resource.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/permissions.php';

// Require system-level access to manage positions
require_access('system', 'system_settings', 'manage');

$pdo = get_db_conn();
$positionId = (int)($_GET['id'] ?? 0);

if (!$positionId) {
    flash_error('Invalid position ID');
    header('Location: ' . BASE_URL . '/modules/positions/index');
    exit;
}

// Fetch position details
try {
    $stmt = $pdo->prepare('
        SELECT p.id, p.name, p.department_id, d.name AS department_name,
               (SELECT COUNT(*) FROM employees WHERE position_id = p.id) AS employee_count
        FROM positions p
        LEFT JOIN departments d ON d.id = p.department_id
        WHERE p.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $positionId]);
    $position = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$position) {
        flash_error('Position not found');
        header('Location: ' . BASE_URL . '/modules/positions/index');
        exit;
    }
} catch (Throwable $e) {
    sys_log('DB-PERMS-001', 'Failed to fetch position: ' . $e->getMessage(), [
        'module' => 'positions',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['position_id' => $positionId]
    ]);
    flash_error('Database error occurred');
    header('Location: ' . BASE_URL . '/modules/positions/index');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_permissions') {
        $permissions = $_POST['permissions'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $allowOverride = $_POST['allow_override'] ?? [];
        
        $pdo->beginTransaction();
        try {
            // Delete existing permissions
            $stmt = $pdo->prepare('DELETE FROM position_access_permissions WHERE position_id = :pid');
            $stmt->execute([':pid' => $positionId]);
            
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
                    ':pid' => $positionId,
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
                'position_id' => $positionId,
                'position_name' => $position['name'],
                'permissions_count' => $savedCount
            ]));
            
            action_log('positions', 'update_permissions', 'success', [
                'position_id' => $positionId,
                'permissions_saved' => $savedCount
            ]);
            
            flash_success('Permissions updated successfully (' . $savedCount . ' active permissions)');
            header('Location: ' . BASE_URL . '/modules/positions/permissions?id=' . $positionId);
            exit;
            
        } catch (Throwable $e) {
            $pdo->rollBack();
            sys_log('DB-PERMS-002', 'Failed to update permissions: ' . $e->getMessage(), [
                'module' => 'positions',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['position_id' => $positionId]
            ]);
            flash_error('Failed to update permissions');
        }
    }
    
    if ($action === 'copy_from_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        
        if ($templateId && $templateId !== $positionId) {
            try {
                $pdo->beginTransaction();
                
                // Delete existing permissions
                $stmt = $pdo->prepare('DELETE FROM position_access_permissions WHERE position_id = :pid');
                $stmt->execute([':pid' => $positionId]);
                
                // Copy from template
                $stmt = $pdo->prepare('
                    INSERT INTO position_access_permissions 
                    (position_id, domain, resource_key, access_level, allow_override, notes)
                    SELECT :new_pid, domain, resource_key, access_level, allow_override, 
                           CONCAT(\'Copied from \', (SELECT name FROM positions WHERE id = :template_pid))
                    FROM position_access_permissions
                    WHERE position_id = :template_pid
                ');
                $stmt->execute([':new_pid' => $positionId, ':template_pid' => $templateId]);
                
                $copiedCount = $stmt->rowCount();
                $pdo->commit();
                
                clear_permission_cache();
                
                audit('copy_position_permissions', json_encode([
                    'position_id' => $positionId,
                    'template_id' => $templateId,
                    'permissions_copied' => $copiedCount
                ]));
                
                flash_success('Copied ' . $copiedCount . ' permissions from template');
                header('Location: ' . BASE_URL . '/modules/positions/permissions?id=' . $positionId);
                exit;
                
            } catch (Throwable $e) {
                $pdo->rollBack();
                sys_log('DB-PERMS-003', 'Failed to copy permissions: ' . $e->getMessage(), [
                    'module' => 'positions',
                    'file' => __FILE__,
                    'line' => __LINE__
                ]);
                flash_error('Failed to copy permissions from template');
            }
        }
    }
}

// Get current permissions
$currentPermissions = [];
try {
    $stmt = $pdo->prepare('
        SELECT domain, resource_key, access_level, allow_override, notes
        FROM position_access_permissions
        WHERE position_id = :pid
    ');
    $stmt->execute([':pid' => $positionId]);
    
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
        'module' => 'positions',
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
    $stmt->execute([':current_id' => $positionId]);
    $templatePositions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error, template feature just won't be available
}

// Get permissions catalog
$catalog = get_permissions_catalog();
$accessLevels = get_access_levels();

?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="flex items-center gap-3 mb-4">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/positions/index">Back to List</a>
    <h1 class="text-xl font-semibold">Edit Position</h1>
</div>

<!-- Tab Navigation -->
<div class="mb-4 border-b border-gray-200">
    <nav class="flex gap-4">
        <a href="<?= BASE_URL ?>/modules/positions/edit?id=<?= $positionId ?>" 
           class="px-4 py-2 border-b-2 border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300">
            Details
        </a>
        <a href="<?= BASE_URL ?>/modules/positions/permissions?id=<?= $positionId ?>" 
           class="px-4 py-2 border-b-2 border-blue-500 text-blue-600 font-medium">
            Permissions
        </a>
    </nav>
</div>

<!-- Position Info Card -->
<div class="card mb-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($position['name']) ?></h2>
            <p class="text-sm text-gray-600">
                <?= htmlspecialchars($position['department_name'] ?? 'No Department') ?>
                • <?= $position['employee_count'] ?> employee(s)
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
            Changes affect <?= $position['employee_count'] ?> employee(s) immediately
        </span>
    </div>
</div>

<!-- Permissions Form -->
<form method="post" id="permissions-form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="update_permissions">
    
    <?php foreach ($catalog as $domainKey => $domain): ?>
    <div class="card mb-4">
        <div class="mb-4 pb-3 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($domain['label']) ?></h3>
            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($domain['description']) ?></p>
        </div>
        
        <div class="space-y-3">
            <?php foreach ($domain['resources'] as $resourceKey => $resource): ?>
            <?php 
                $fullKey = $domainKey . '.' . $resourceKey;
                $current = $currentPermissions[$fullKey] ?? null;
                $currentLevel = $current['level'] ?? 'none';
                $currentOverride = $current['override'] ?? false;
                $currentNotes = $current['notes'] ?? '';
            ?>
            <div class="flex flex-col md:flex-row md:items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="flex-1 min-w-0">
                    <label class="font-medium text-gray-900">
                        <?= htmlspecialchars($resource['label']) ?>
                    </label>
                    <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($resource['description']) ?></p>
                    <?php if ($resource['self_service']): ?>
                        <span class="inline-block mt-1 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                            Self-Service (All Users)
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="flex items-center gap-2">
                    <select name="permissions[<?= htmlspecialchars($fullKey) ?>]" 
                            class="input-text text-sm permission-select" 
                            data-resource="<?= htmlspecialchars($fullKey) ?>"
                            <?= $resource['self_service'] ? 'disabled' : '' ?>>
                        <?php foreach ($accessLevels as $levelKey => $level): ?>
                            <option value="<?= $levelKey ?>" 
                                    <?= $currentLevel === $levelKey ? 'selected' : '' ?>
                                    style="color: <?= $level['color'] ?>">
                                <?= htmlspecialchars($level['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <?php if (!$resource['self_service']): ?>
                    <label class="flex items-center gap-1 text-xs text-gray-600 whitespace-nowrap cursor-pointer" 
                           title="Allow this user to authorize others for this action">
                        <input type="checkbox" 
                               name="allow_override[<?= htmlspecialchars($fullKey) ?>]"
                               class="form-checkbox h-4 w-4 text-blue-600 override-checkbox"
                               data-resource="<?= htmlspecialchars($fullKey) ?>"
                               <?= $currentOverride ? 'checked' : '' ?>
                               <?= $currentLevel === 'none' || $currentLevel === 'read' ? 'disabled' : '' ?>>
                        <span class="override-label">Override</span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    
    <div class="flex items-center gap-3 sticky bottom-0 bg-white p-4 border-t border-gray-200 shadow-lg">
        <button type="submit" class="btn btn-primary">
            Save Permissions
        </button>
        <a href="<?= BASE_URL ?>/modules/positions/edit?id=<?= $positionId ?>" class="btn btn-outline">
            Cancel
        </a>
        <span class="text-sm text-gray-600 ml-auto">
            <span id="changed-count">0</span> change(s) pending
        </span>
    </div>
</form>

<script>
// Track changes for user feedback
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('permissions-form');
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
            override: document.querySelector(`input[name="allow_override[${key}]"]`)?.checked || false
        };
    });
    
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
    
    overrideCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateChangeCount);
    });
    
    // Warn on unsaved changes
    let formSubmitting = false;
    form.addEventListener('submit', () => formSubmitting = true);
    
    window.addEventListener('beforeunload', function(e) {
        if (changes > 0 && !formSubmitting) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
