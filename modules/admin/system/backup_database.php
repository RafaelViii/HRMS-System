<?php
/**
 * Database Backup Generator
 * Creates a downloadable SQL backup of the entire database
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

// Require write permission for system management
require_login();
require_access('user_management', 'system_management', 'write');

$pdo = get_db_conn();
$user = current_user();
$pageTitle = 'Create Database Backup';

// Check if this is a POST request to actually create the backup
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show confirmation page
    require_once __DIR__ . '/../../../includes/header.php';
    ?>
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="bg-blue-600 px-6 py-4">
                <h1 class="text-2xl font-bold text-white">Create Database Backup</h1>
            </div>
            
            <div class="p-6 space-y-6">
                <div class="flex items-start space-x-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <h3 class="text-sm font-semibold text-blue-900">About This Backup</h3>
                        <p class="text-sm text-blue-800 mt-1">
                            This will create a complete SQL dump of the entire database including all tables, data, and structure.
                            The backup file will be downloaded to your computer.
                        </p>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="font-semibold text-gray-800">Backup Details:</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Complete database structure and data
                        </li>
                        <li class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Format: SQL file (.sql)
                        </li>
                        <li class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Includes timestamp and user information
                        </li>
                        <li class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Logged in backup history
                        </li>
                    </ul>
                </div>

                <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <div class="flex items-start space-x-3">
                        <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div>
                            <h4 class="text-sm font-semibold text-amber-900">Security Notice</h4>
                            <p class="text-sm text-amber-800 mt-1">
                                The backup file contains sensitive data. Store it securely and do not share it publicly.
                            </p>
                        </div>
                    </div>
                </div>

                <form method="POST" id="backupForm">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="confirm_backup" value="1">
                    
                    <div class="flex gap-3 justify-end">
                        <a href="<?= BASE_URL ?>/modules/admin/system/backup_history" class="btn btn-secondary">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition font-medium flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Create Backup
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('backupForm').addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Creating Backup...';
        
        // After download completes, redirect back to history
        setTimeout(function() {
            window.location.href = '<?= BASE_URL ?>/modules/admin/system/backup_history?backup=success';
        }, 2000);
    });
    </script>

    <?php
    require_once __DIR__ . '/../../../includes/footer.php';
    exit;
}

// Verify CSRF token
if (!csrf_verify($_POST['csrf'] ?? '')) {
    flash_error('Invalid request. Please try again.');
    header('Location: ' . BASE_URL . '/modules/admin/system/backup_history');
    exit;
}

try {
    // Get database connection info from environment
    $env = function(array $keys, $default = null) {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') return $v;
        }
        return $default;
    };
    
    $dbHost = $env(['db-host', 'DB_HOST'], 'localhost');
    $dbUser = $env(['db-user', 'DB_USER'], 'postgres');
    $dbPass = $env(['db-password', 'DB_PASSWORD'], '');
    $dbName = $env(['db-name', 'DB_NAME'], 'hrms');
    $dbPort = $env(['db-port', 'DB_PORT'], '5432');
    
    // Set headers for download
    $filename = 'hrms_backup_' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Generate backup using pg_dump
    $pgDumpPath = 'pg_dump'; // Assumes pg_dump is in PATH
    
    // Set password via environment variable (more secure than command line)
    putenv("PGPASSWORD=$dbPass");
    
    // Build pg_dump command
    $command = sprintf(
        '%s --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-acl --clean --if-exists --inserts 2>&1',
        escapeshellcmd($pgDumpPath),
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        escapeshellarg($dbName)
    );
    
    // Add header comment
    echo "-- HRMS Database Backup\n";
    echo "-- Database: " . $dbName . "\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Generated by: " . ($user['full_name'] ?? 'System') . "\n";
    echo "-- WARNING: This file contains sensitive data. Store securely.\n\n";
    
    // Execute pg_dump and stream output
    $handle = popen($command, 'r');
    
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        
        $returnCode = pclose($handle);
        
        if ($returnCode !== 0) {
            // Log error but backup file is already being downloaded
            sys_log('DB-BACKUP-ERROR', 'pg_dump returned non-zero exit code', [
                'module' => 'system_management',
                'return_code' => $returnCode
            ]);
        }
    } else {
        throw new Exception('Failed to execute pg_dump');
    }
    
    // Clear password from environment
    putenv("PGPASSWORD");
    
    // Calculate approximate file size (won't be exact since streaming)
    $fileSize = 0; // Will be updated if we can determine it
    
    // Log to backup history table
    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO database_backup_history 
            (filename, file_size, backup_type, initiated_by, status, created_at)
            VALUES (?, ?, 'manual', ?, 'completed', NOW())
        ");
        $insertStmt->execute([$filename, $fileSize, $user['id']]);
    } catch (Throwable $historyError) {
        // Don't fail the backup if history logging fails
        sys_log('BACKUP-HISTORY', 'Failed to log backup history: ' . $historyError->getMessage());
    }
    
    // Log successful backup
    action_log('system_management', 'database_backup', 'success', [
        'target_type' => 'database',
        'target_id' => 0,
        'severity' => 'high',
        'details' => 'Database backup created and downloaded',
        'filename' => $filename,
    ]);
    
} catch (Throwable $e) {
    // Clear password from environment
    putenv("PGPASSWORD");
    
    sys_log('DB-BACKUP-FATAL', 'Database backup failed: ' . $e->getMessage(), [
        'module' => 'system_management',
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    
    // Log failed backup attempt
    action_log('system_management', 'database_backup', 'failed', [
        'target_type' => 'database',
        'target_id' => 0,
        'severity' => 'critical',
        'details' => 'Database backup failed: ' . $e->getMessage(),
    ]);
    
    // If headers not sent yet, send error response
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to create backup']);
    }
}
