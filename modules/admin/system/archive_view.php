<?php
/**
 * Archive View - Display archived records from a specific table
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

require_login();
require_access('user_management', 'system_management', 'write');

$pdo = get_db_conn();
$user = current_user();

$table = $_GET['table'] ?? '';
$allowedTables = ['employees', 'departments', 'positions', 'memos', 'documents'];

if (!in_array($table, $allowedTables)) {
    flash_error('Invalid table specified');
    header('Location: ' . BASE_URL . '/modules/admin/system/archive');
    exit;
}

// Check if table has deleted_at column
try {
    $columnCheck = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = :table AND column_name = 'deleted_at'
        )
    ");
    $columnCheck->execute([':table' => $table]);
    
    if (!$columnCheck->fetchColumn()) {
        flash_error('Table does not support archiving');
        header('Location: ' . BASE_URL . '/modules/admin/system/archive');
        exit;
    }
} catch (Throwable $e) {
    flash_error('Unable to access table information');
    header('Location: ' . BASE_URL . '/modules/admin/system/archive');
    exit;
}

$tableName = ucwords(str_replace('_', ' ', $table));
$pageTitle = "Archived $tableName";

// Build query based on table structure
$columns = match($table) {
    'employees' => "id, CONCAT(first_name, ' ', last_name) as name, email, employee_id, deleted_at, deleted_by",
    'departments' => "id, name, code, deleted_at, deleted_by",
    'positions' => "id, title, code, deleted_at, deleted_by",
    'memos' => "id, subject, created_by, created_at, deleted_at, deleted_by",
    'documents' => "id, filename, document_type, employee_id, deleted_at, deleted_by",
    default => "id, deleted_at, deleted_by",
};

// Get archived records
try {
    $query = "
        SELECT $columns
        FROM $table
        WHERE deleted_at IS NOT NULL
        ORDER BY deleted_at DESC
        LIMIT 500
    ";
    $stmt = $pdo->query($query);
    $archivedRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('ARCHIVE-VIEW', "Failed to fetch archived records from $table: " . $e->getMessage(), [
        'table' => $table,
        'error' => $e->getMessage()
    ]);
    $archivedRecords = [];
    flash_error('Unable to fetch archived records');
}

// Get user names for deleted_by
$userIds = array_unique(array_filter(array_column($archivedRecords, 'deleted_by')));
$userNames = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $userStmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM employees WHERE id IN ($placeholders)");
    $userStmt->execute($userIds);
    $userNames = $userStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Archived <?= htmlspecialchars($tableName) ?></h1>
            <p class="text-gray-600 mt-2">Viewing <?= count($archivedRecords) ?> archived record<?= count($archivedRecords) !== 1 ? 's' : '' ?></p>
        </div>
        <a href="<?= BASE_URL ?>/modules/admin/system/archive" class="btn btn-light">
            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Archive
        </a>
    </div>

    <?php if (empty($archivedRecords)): ?>
        <div class="bg-white rounded-lg shadow-md p-12 text-center">
            <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
            </svg>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Archived Records</h3>
            <p class="text-gray-500">There are no archived <?= strtolower($tableName) ?> at this time.</p>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if ($table === 'employees'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <?php elseif ($table === 'departments'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <?php elseif ($table === 'positions'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                            <?php elseif ($table === 'memos'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subject</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                            <?php elseif ($table === 'documents'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Filename</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Deleted At</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Deleted By</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($archivedRecords as $record): ?>
                            <tr class="hover:bg-gray-50">
                                <?php if ($table === 'employees'): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($record['employee_id']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($record['name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= htmlspecialchars($record['email']) ?>
                                    </td>
                                <?php elseif ($table === 'departments'): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($record['code']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($record['name']) ?>
                                    </td>
                                <?php elseif ($table === 'positions'): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($record['code']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($record['title']) ?>
                                    </td>
                                <?php elseif ($table === 'memos'): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        #<?= htmlspecialchars($record['id']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <?= htmlspecialchars($record['subject']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= htmlspecialchars(date('Y-m-d', strtotime($record['created_at']))) ?>
                                    </td>
                                <?php elseif ($table === 'documents'): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        #<?= htmlspecialchars($record['id']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <?= htmlspecialchars($record['filename']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= htmlspecialchars($record['document_type'] ?? 'N/A') ?>
                                    </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars(date('Y-m-d H:i', strtotime($record['deleted_at']))) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($userNames[$record['deleted_by']] ?? 'Unknown') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button onclick="recoverRecord('<?= $table ?>', <?= $record['id'] ?>, this)"
                                            class="text-green-600 hover:text-green-900 mr-3">
                                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                        Recover
                                    </button>
                                    <button onclick="permanentDelete('<?= $table ?>', <?= $record['id'] ?>, this)"
                                            class="text-red-600 hover:text-red-900">
                                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Delete Forever
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Confirmation Modals -->
<div id="recoverModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Confirm Recovery</h3>
        <p class="text-gray-600 mb-6">Are you sure you want to recover this record? It will be restored to its original state.</p>
        <div class="flex justify-end gap-3">
            <button onclick="closeRecoverModal()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Cancel
            </button>
            <button onclick="confirmRecover()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Recover
            </button>
        </div>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-semibold text-red-600 mb-4 flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg> Confirm Permanent Deletion</h3>
        <p class="text-gray-600 mb-4">This action cannot be undone. The record will be permanently deleted from the database.</p>
        <p class="text-sm text-red-600 font-medium mb-6">Type "DELETE" to confirm:</p>
        <input type="text" id="deleteConfirmText" class="w-full border border-gray-300 rounded-lg px-4 py-2 mb-6" placeholder="Type DELETE">
        <div class="flex justify-end gap-3">
            <button onclick="closeDeleteModal()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Cancel
            </button>
            <button onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                Delete Forever
            </button>
        </div>
    </div>
</div>

<script>
let currentAction = { table: null, id: null, button: null };

function recoverRecord(table, id, button) {
    currentAction = { table, id, button };
    document.getElementById('recoverModal').classList.remove('hidden');
    document.getElementById('recoverModal').classList.add('flex');
}

function closeRecoverModal() {
    document.getElementById('recoverModal').classList.add('hidden');
    document.getElementById('recoverModal').classList.remove('flex');
    currentAction = { table: null, id: null, button: null };
}

function permanentDelete(table, id, button) {
    currentAction = { table, id, button };
    document.getElementById('deleteConfirmText').value = '';
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    currentAction = { table: null, id: null, button: null };
}

async function confirmRecover() {
    const { table, id, button } = currentAction;
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<svg class="animate-spin h-5 w-5 inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Recovering...';

    try {
        const formData = new FormData();
        formData.append('table', table);
        formData.append('id', id);
        formData.append('csrf_token', '<?= csrf_token() ?>');

        const response = await fetch('<?= BASE_URL ?>/modules/admin/system/archive_recover.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        if (result.success) {
            button.closest('tr').remove();
            closeRecoverModal();
            showFlash('success', 'Record recovered successfully');
        } else {
            showFlash('error', result.message || 'Failed to recover record');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    } catch (error) {
        showFlash('error', 'An error occurred while recovering the record');
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

async function confirmDelete() {
    const confirmText = document.getElementById('deleteConfirmText').value;
    if (confirmText !== 'DELETE') {
        showFlash('error', 'Please type DELETE to confirm');
        return;
    }

    const { table, id, button } = currentAction;
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<svg class="animate-spin h-5 w-5 inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Deleting...';

    try {
        const formData = new FormData();
        formData.append('table', table);
        formData.append('id', id);
        formData.append('csrf_token', '<?= csrf_token() ?>');

        const response = await fetch('<?= BASE_URL ?>/modules/admin/system/archive_delete_permanent.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        if (result.success) {
            button.closest('tr').remove();
            closeDeleteModal();
            showFlash('success', 'Record permanently deleted');
        } else {
            showFlash('error', result.message || 'Failed to delete record');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    } catch (error) {
        showFlash('error', 'An error occurred while deleting the record');
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

function showFlash(type, message) {
    // Use existing flash system or create simple alert
    const flashDiv = document.createElement('div');
    flashDiv.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg ${type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white`;
    flashDiv.textContent = message;
    document.body.appendChild(flashDiv);
    setTimeout(() => flashDiv.remove(), 3000);
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
