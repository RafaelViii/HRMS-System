<?php
// Lightweight migration runner for database/migrations/*.sql
// - Records applied migrations in schema_migrations
// - Executes .sql files in natural sort order
// - Safe to re-run; skips already applied files (by filename)

require_once __DIR__ . '/../includes/db.php';

// Security: require CLI, local access, or HRMS_TOOL_SECRET
if (php_sapi_name() !== 'cli') {
    $token = $_SERVER['HTTP_X_TOOL_TOKEN'] ?? $_POST['token'] ?? '';
    $expectedToken = getenv('HRMS_TOOL_SECRET') ?: '';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$isLocal && ($expectedToken === '' || !hash_equals($expectedToken, $token))) {
        http_response_code(403);
        echo 'Forbidden - CLI, local access, or HRMS_TOOL_SECRET required';
        exit;
    }
}

header('Content-Type: text/plain; charset=utf-8');

function out($msg) { echo $msg . "\n"; }

$pdo = get_db_conn();

// Ensure migrations registry exists
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        checksum VARCHAR(64) NOT NULL,
        applied_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

// Load applied set
$applied = [];
try {
    $st = $pdo->query('SELECT filename, checksum FROM schema_migrations');
    foreach ($st as $row) { $applied[$row['filename']] = $row['checksum']; }
} catch (Throwable $e) {
    out('[ERR] Could not read schema_migrations: ' . $e->getMessage());
    http_response_code(500);
    exit(1);
}

$dir = realpath(__DIR__ . '/../database/migrations');
if (!$dir || !is_dir($dir)) {
    out('[OK] No migrations directory found. Nothing to do.');
    exit(0);
}

$files = glob($dir . DIRECTORY_SEPARATOR . '*.sql');
if (!$files) { out('[OK] No migration files found.'); exit(0); }
natcasesort($files);

$appliedCount = 0; $skippedCount = 0; $failed = false;

foreach ($files as $path) {
    $file = basename($path);
    $sql = @file_get_contents($path);
    if ($sql === false) { out('[ERR] Cannot read ' . $file); $failed = true; break; }
    $checksum = hash('sha256', $sql);

    if (isset($applied[$file])) {
        if ($applied[$file] !== $checksum) {
            out('[WARN] Checksum mismatch for already-applied migration ' . $file . ' (previous=' . $applied[$file] . ', current=' . $checksum . '). Skipping.');
            $skippedCount++;
        } else {
            out('[SKIP] ' . $file);
            $skippedCount++;
        }
        continue;
    }

    out('[APPLY] ' . $file);
    try {
        // Execute as-is (migration files may contain their own BEGIN/COMMIT)
        $pdo->exec($sql);
        $ins = $pdo->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (:f, :c)');
        $ins->execute([':f' => $file, ':c' => $checksum]);
        $appliedCount++;
    } catch (Throwable $e) {
        out('[ERR] Migration failed: ' . $e->getMessage());
        $failed = true;
        break;
    }
}

if ($failed) { http_response_code(500); }
out('---');
out('Applied: ' . $appliedCount . ', Skipped: ' . $skippedCount . ', Failed: ' . ($failed ? 'yes' : 'no'));
exit($failed ? 1 : 0);
