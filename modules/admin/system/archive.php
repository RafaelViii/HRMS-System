<?php
/**
 * Archive Management System
 * View, recover, and permanently delete archived (soft-deleted) records
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

// Require write permission for system management
require_login();
require_access('user_management', 'system_management', 'write');

$pdo = get_db_conn();
$user = current_user();
$pageTitle = 'Archive Management';

// Get archive settings
try {
    $settingsStmt = $pdo->query("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key LIKE 'archive_%'
    ");
    $settingsRaw = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    $settingsRaw = [];
}

$archiveSettings = [
    'auto_delete_days' => (int)($settingsRaw['archive_auto_delete_days'] ?? 90),
    'enabled' => (bool)($settingsRaw['archive_enabled'] ?? true),
];

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_error('Invalid security token. Please try again.');
        header('Location: ' . BASE_URL . '/modules/admin/system/archive');
        exit;
    }
    
    $autoDeleteDays = max(0, (int)($_POST['auto_delete_days'] ?? 90));
    $enabled = isset($_POST['archive_enabled']);
    
    try {
        $pdo->beginTransaction();
        
        // Upsert settings
        $upsertStmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (:key, :value, NOW())
            ON CONFLICT (setting_key) 
            DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
        ");
        
        $upsertStmt->execute([':key' => 'archive_auto_delete_days', ':value' => (string)$autoDeleteDays]);
        $upsertStmt->execute([':key' => 'archive_enabled', ':value' => $enabled ? '1' : '0']);
        
        $pdo->commit();
        
        action_log('system_management', 'archive_settings_update', 'success', [
            'target_type' => 'system_settings',
            'old_values' => $archiveSettings,
            'new_values' => ['auto_delete_days' => $autoDeleteDays, 'enabled' => $enabled],
        ]);
        
        flash_success('Archive settings updated successfully');
        header('Location: ' . BASE_URL . '/modules/admin/system/archive');
        exit;
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        sys_log('ARCHIVE-SETTINGS', 'Failed to update archive settings: ' . $e->getMessage(), [
            'module' => 'system_management',
            'file' => __FILE__,
            'line' => __LINE__
        ]);
        flash_error('Failed to update archive settings');
    }
}

// Get archived items counts by table
$archiveCounts = [];
$archiveTables = ['employees', 'departments', 'positions', 'memos', 'documents'];

foreach ($archiveTables as $table) {
    try {
        // Check if table has deleted_at column
        $columnCheck = $pdo->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns 
                WHERE table_name = :table AND column_name = 'deleted_at'
            )
        ");
        $columnCheck->execute([':table' => $table]);
        
        if ($columnCheck->fetchColumn()) {
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NOT NULL
            ");
            $countStmt->execute();
            $archiveCounts[$table] = (int)$countStmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Table might not exist or no deleted_at column
        continue;
    }
}

$totalArchived = array_sum($archiveCounts);

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Archive Management</h1>
            <p class="text-gray-600 mt-2">Manage archived records, set retention policies, and recover deleted items</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/admin/system" class="btn btn-light">
            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to System Management
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-amber-100 text-sm font-medium">Total Archived</p>
                    <p class="text-4xl font-bold mt-2"><?= number_format($totalArchived) ?></p>
                </div>
                <div class="bg-white/20 rounded-full p-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-blue-500 to-cyan-500 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Auto-Delete In</p>
                    <p class="text-4xl font-bold mt-2"><?= $archiveSettings['auto_delete_days'] ?> Days</p>
                </div>
                <div class="bg-white/20 rounded-full p-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-emerald-500 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Archive Status</p>
                    <p class="text-2xl font-bold mt-2"><?= $archiveSettings['enabled'] ? 'Enabled' : 'Disabled' ?></p>
                </div>
                <div class="bg-white/20 rounded-full p-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php if ($archiveSettings['enabled']): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php else: ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php endif; ?>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Archive Settings -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Archive Settings</h2>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="update_settings" value="1">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Auto-Delete After (Days)
                        <span class="text-gray-500 font-normal ml-2">Set to 0 to disable auto-delete</span>
                    </label>
                    <input type="number" 
                           name="auto_delete_days" 
                           value="<?= $archiveSettings['auto_delete_days'] ?>" 
                           min="0" 
                           max="3650"
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="mt-1 text-xs text-gray-500">
                        Archived items older than this will be permanently deleted automatically.
                    </p>
                </div>

                <div>
                    <label class="flex items-center space-x-3 cursor-pointer">
                        <input type="checkbox" 
                               name="archive_enabled" 
                               <?= $archiveSettings['enabled'] ? 'checked' : '' ?>
                               class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <div>
                            <div class="font-medium text-gray-700">Enable Archive System</div>
                            <div class="text-xs text-gray-500">When disabled, delete operations will fail</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition font-medium">
                    Save Settings
                </button>
            </div>
        </form>
    </div>

    <!-- Archived Items by Category -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Archived Items</h2>
            <p class="text-sm text-gray-600 mt-1">Click on a category to view and manage archived records</p>
        </div>

        <div class="divide-y divide-gray-200">
            <?php foreach ($archiveTables as $table): ?>
                <?php 
                    $count = $archiveCounts[$table] ?? 0;
                    $tableName = ucwords(str_replace('_', ' ', $table));
                    $icon = match($table) {
                        'employees' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
                        'departments' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
                        'positions' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
                        'memos' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
                        'documents' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
                        default => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>',
                    };
                ?>
                <a href="<?= BASE_URL ?>/modules/admin/system/archive_view?table=<?= urlencode($table) ?>" 
                   class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition">
                    <div class="flex items-center gap-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg <?= $count > 0 ? 'bg-amber-100' : 'bg-gray-100' ?>">
                            <svg class="h-5 w-5 <?= $count > 0 ? 'text-amber-600' : 'text-gray-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?= $icon ?>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900"><?= $tableName ?></h3>
                            <p class="text-sm text-gray-500"><?= $count ?> archived record<?= $count !== 1 ? 's' : '' ?></p>
                        </div>
                    </div>
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
