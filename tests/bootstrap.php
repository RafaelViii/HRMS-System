<?php
/**
 * PHPUnit Bootstrap
 * 
 * Stubs framework dependencies so payroll computation functions
 * can be tested in isolation without a database or web context.
 */

// Prevent "session already started" warnings
if (session_status() === PHP_SESSION_NONE) {
    // Don't start sessions in test environment
}

// Define constants that the includes need (only if not already defined)
if (!defined('BASE_URL')) define('BASE_URL', '');

// Set timezone
date_default_timezone_set('Asia/Manila');

// ------------------------------------------------------------------
// Stub functions that payroll.php's file-level requires pull in
// ------------------------------------------------------------------

// Stub sys_log (from utils.php) — just capture for assertions if needed
if (!function_exists('sys_log')) {
    function sys_log(string $code, string $message, array $meta = []): void {
        // No-op in tests
    }
}

// Stub audit (from auth.php)
if (!function_exists('audit')) {
    function audit(string $action, string $details = '', array $context = []): void {
        // No-op in tests
    }
}

// Stub action_log (from auth.php)
if (!function_exists('action_log')) {
    function action_log(string $module, string $actionType, string $status, array $meta = []): void {
        // No-op in tests
    }
}

// Stub flash helpers
if (!function_exists('flash_success')) {
    function flash_success(string $msg): void {}
}
if (!function_exists('flash_error')) {
    function flash_error(string $msg): void {}
}

// Stub CSRF helpers
if (!function_exists('csrf_token')) {
    function csrf_token(): string { return 'test-csrf-token'; }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify(string $token): bool { return true; }
}

// Stub get_db_conn — returns null (tests should never call DB functions)
if (!function_exists('get_db_conn')) {
    function get_db_conn(bool $fresh = false): ?PDO { return null; }
}

// Stub branches helpers
if (!function_exists('branches_fetch_all')) {
    function branches_fetch_all($pdo): array { return []; }
}
if (!function_exists('branches_get_default_id')) {
    function branches_get_default_id($pdo): ?int { return null; }
}

// Stub notification helper
if (!function_exists('notify_user')) {
    function notify_user(int $userId, string $type, string $title, string $message, ?string $link = null): void {}
}

// Stub leave helpers that payroll.php may reference
if (!function_exists('leave_calculate_balances')) {
    function leave_calculate_balances($pdo, int $employeeId): array { return []; }
}
if (!function_exists('leave_collect_entitlement_layers')) {
    function leave_collect_entitlement_layers($pdo, int $employeeId): array {
        return ['defaults' => [], 'global' => [], 'department' => [], 'employee' => [], 'effective' => [], 'sources' => []];
    }
}

// ------------------------------------------------------------------
// Now load the actual files under test — config first (it defines constants)
// ------------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';

// Load payroll — but skip its require_once chain by pre-defining stubs above
// We need to load the file directly to get the computation functions
$payrollContent = file_get_contents(__DIR__ . '/../includes/payroll.php');
// Strip the require_once lines that would pull in db.php + utils.php
$payrollContent = preg_replace('/require_once\s+__DIR__\s*\.\s*\'\/[^\']+\'\s*;/', '// [stubbed for tests]', $payrollContent);
// Write to a temp file and include it
$tmpPayroll = tempnam(sys_get_temp_dir(), 'payroll_test_');
file_put_contents($tmpPayroll, $payrollContent);
require_once $tmpPayroll;
// Clean up temp file on shutdown
register_shutdown_function(function () use ($tmpPayroll) {
    @unlink($tmpPayroll);
});

// Load encryption helpers
require_once __DIR__ . '/../includes/encryption.php';
