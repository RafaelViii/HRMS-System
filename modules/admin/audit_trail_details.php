<?php
/**
 * Audit Trail Details Endpoint (AJAX)
 * Returns detailed JSON data for a specific audit log entry
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Require audit trail access via permissions
require_login();
require_access('user_management', 'audit_logs', 'read');

$logId = intval($_GET['id'] ?? 0);

if ($logId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid log ID']);
    exit;
}

$pdo = get_db_conn();

// Fetch detailed audit log
$stmt = $pdo->prepare("SELECT * FROM audit_trail_view WHERE id = :id");
$stmt->execute([':id' => $logId]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    echo json_encode(['success' => false, 'error' => 'Audit log not found']);
    exit;
}

// Build HTML for modal
ob_start();
?>

<div class="space-y-4">
    <!-- Basic Info -->
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">Timestamp</label>
            <p class="mt-1 text-sm text-gray-900"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <p class="mt-1">
                <?php
                $statusColors = [
                    'success' => 'bg-green-100 text-green-800',
                    'failed' => 'bg-red-100 text-red-800',
                    'partial' => 'bg-yellow-100 text-yellow-800'
                ];
                $statusClass = $statusColors[$log['status']] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                    <?= htmlspecialchars(ucfirst($log['status'])) ?>
                </span>
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Severity</label>
            <p class="mt-1">
                <?php
                $severityColors = [
                    'low' => 'bg-gray-100 text-gray-600',
                    'normal' => 'bg-blue-100 text-blue-800',
                    'high' => 'bg-orange-100 text-orange-800',
                    'critical' => 'bg-red-100 text-red-800'
                ];
                $severityClass = $severityColors[$log['severity']] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $severityClass ?>">
                    <?= htmlspecialchars(ucfirst($log['severity'])) ?>
                </span>
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Module</label>
            <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['module'] ?? 'N/A') ?></p>
        </div>
    </div>

    <!-- User Info -->
    <div class="border-t pt-4">
        <h4 class="text-sm font-semibold text-gray-900 mb-2">User Information</h4>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['user_full_name'] ?? 'System') ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Email</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['user_email'] ?? 'N/A') ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Role</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['user_role'] ?? 'N/A'))) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">IP Address</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></p>
            </div>
        </div>
    </div>

    <!-- Employee Info -->
    <?php if ($log['employee_code']): ?>
    <div class="border-t pt-4">
        <h4 class="text-sm font-semibold text-gray-900 mb-2">Employee Information</h4>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Employee Code</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['employee_code']) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Name</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Department</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['department_name'] ?? 'N/A') ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Position</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['position_name'] ?? 'N/A') ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Info -->
    <div class="border-t pt-4">
        <h4 class="text-sm font-semibold text-gray-900 mb-2">Action Details</h4>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Action</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['action']) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Action Type</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['action_type'] ?? 'N/A') ?></p>
            </div>
            <?php if ($log['target_type']): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700">Target Type</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['target_type']) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Target ID</label>
                <p class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($log['target_id']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($log['details']): ?>
        <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Details</label>
            <?php 
            $details = $log['details'];
            // Try to parse JSON details for better readability
            if (strpos($details, '{') === 0 || strpos($details, '[') === 0) {
                $parsed = json_decode($details, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    echo '<div class="bg-gray-50 border border-gray-200 rounded p-4 space-y-2">';
                    foreach ($parsed as $key => $value) {
                        echo '<div class="grid grid-cols-3 gap-2 text-sm">';
                        echo '<div class="font-medium text-gray-700">' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</div>';
                        if (is_array($value) || is_object($value)) {
                            echo '<div class="col-span-2 text-gray-900"><pre class="text-xs bg-white p-2 rounded border">' . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
                        } else {
                            echo '<div class="col-span-2 text-gray-900">' . htmlspecialchars($value) . '</div>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                } else {
                    echo '<p class="mt-1 text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded p-3">' . htmlspecialchars($details) . '</p>';
                }
            } else {
                echo '<p class="mt-1 text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded p-3">' . htmlspecialchars($details) . '</p>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Old/New Values -->
    <?php if ($log['old_values'] || $log['new_values']): ?>
    <div class="border-t pt-4">
        <h4 class="text-sm font-semibold text-gray-900 mb-3">Value Changes</h4>
        <?php 
        $oldValues = $log['old_values'] ? json_decode($log['old_values'], true) : null;
        $newValues = $log['new_values'] ? json_decode($log['new_values'], true) : null;
        
        if ($oldValues && $newValues && is_array($oldValues) && is_array($newValues)) {
            // Show side-by-side comparison of changed fields
            echo '<div class="space-y-3">';
            $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
            foreach ($allKeys as $key) {
                $oldVal = $oldValues[$key] ?? null;
                $newVal = $newValues[$key] ?? null;
                
                // Skip if values are the same
                if ($oldVal === $newVal) continue;
                
                echo '<div class="bg-gray-50 border border-gray-200 rounded p-3">';
                echo '<div class="font-medium text-gray-700 text-sm mb-2">' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . '</div>';
                echo '<div class="grid grid-cols-2 gap-3 text-xs">';
                
                // Old value
                echo '<div>';
                echo '<span class="block text-red-700 font-medium mb-1">Before:</span>';
                if (is_array($oldVal) || is_object($oldVal)) {
                    echo '<pre class="bg-red-50 border border-red-200 rounded p-2 overflow-x-auto">' . htmlspecialchars(json_encode($oldVal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
                } else {
                    echo '<div class="bg-red-50 border border-red-200 rounded p-2">' . htmlspecialchars($oldVal ?? '(empty)') . '</div>';
                }
                echo '</div>';
                
                // New value
                echo '<div>';
                echo '<span class="block text-green-700 font-medium mb-1">After:</span>';
                if (is_array($newVal) || is_object($newVal)) {
                    echo '<pre class="bg-green-50 border border-green-200 rounded p-2 overflow-x-auto">' . htmlspecialchars(json_encode($newVal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
                } else {
                    echo '<div class="bg-green-50 border border-green-200 rounded p-2">' . htmlspecialchars($newVal ?? '(empty)') . '</div>';
                }
                echo '</div>';
                
                echo '</div></div>';
            }
            echo '</div>';
        } else {
            // Fallback to showing raw JSON if structure is different
            echo '<div class="grid grid-cols-2 gap-4">';
            if ($oldValues) {
                echo '<div>';
                echo '<label class="block text-sm font-medium text-red-700 mb-2">Old Values</label>';
                echo '<pre class="bg-red-50 border border-red-200 rounded p-3 text-xs overflow-x-auto">' . htmlspecialchars(json_encode($oldValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
                echo '</div>';
            }
            if ($newValues) {
                echo '<div>';
                echo '<label class="block text-sm font-medium text-green-700 mb-2">New Values</label>';
                echo '<pre class="bg-green-50 border border-green-200 rounded p-3 text-xs overflow-x-auto">' . htmlspecialchars(json_encode($newValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- User Agent -->
    <?php if ($log['user_agent']): ?>
    <div class="border-t pt-4">
        <label class="block text-sm font-medium text-gray-700">User Agent</label>
        <p class="mt-1 text-xs text-gray-600 break-all"><?= htmlspecialchars($log['user_agent']) ?></p>
    </div>
    <?php endif; ?>
</div>

<?php
$html = ob_get_clean();

echo json_encode([
    'success' => true,
    'html' => $html
]);
