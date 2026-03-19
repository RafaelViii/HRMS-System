<?php
// Basic config
define('APP_NAME', 'HydroMed HRMS');
// Centralized time configuration (Philippines / 12-hour display)
if (!defined('APP_TIMEZONE')) {
	define('APP_TIMEZONE', 'Asia/Manila');
}
if (!defined('APP_DISPLAY_TIME_FORMAT')) {
	define('APP_DISPLAY_TIME_FORMAT', 'M d, Y h:i A');
}
if (!defined('APP_DISPLAY_TIME_FORMAT_WITH_SECONDS')) {
	define('APP_DISPLAY_TIME_FORMAT_WITH_SECONDS', 'M d, Y h:i:s A');
}
date_default_timezone_set(APP_TIMEZONE);
// Polyfill for PHP < 8: str_starts_with
if (!function_exists('str_starts_with')) {
	function str_starts_with(string $haystack, string $needle): bool {
		if ($needle === '') return true;
		return strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}
// Dynamically detect base URL relative to web root (robust on Windows/Linux)
if (!defined('BASE_URL')) {
	// Always use empty BASE_URL for production - app should be at domain root
	define('BASE_URL', '');
}
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads');
if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0755, true); }

// Trust proxy TLS signal for secure cookies and URL generation
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
	$_SERVER['HTTPS'] = 'on';
	if (PHP_VERSION_ID >= 70300) {
		// Ensure session cookies are secure on HTTPS
		ini_set('session.cookie_secure', '1');
		ini_set('session.cookie_samesite', 'Lax');
	}
}

// Company info for PDFs
define('COMPANY_NAME', 'HydroMed HRMS');
define('COMPANY_ADDRESS', '');
define('COMPANY_LOGO', __DIR__ . '/../assets/resources/logo.jpg');

// Superadmin account — the highest-privilege account that cannot be edited or deleted.
// MUST be set via environment variables (Heroku config vars or .env). No hardcoded fallbacks.
if (!defined('SUPERADMIN_USER_ID')) {
	define('SUPERADMIN_USER_ID', (int)(getenv('SUPERADMIN_USER_ID') ?: 0));
}
if (!defined('SUPERADMIN_EMAIL')) {
	define('SUPERADMIN_EMAIL', getenv('SUPERADMIN_EMAIL') ?: 'admin@hrms.local');
}
if (!defined('SUPERADMIN_DEFAULT_PASSWORD')) {
	$_saPassEnv = getenv('SUPERADMIN_DEFAULT_PASSWORD');
	if (!$_saPassEnv || $_saPassEnv === '') {
		// No hardcoded fallback — generate a secure random password for first-run bootstrap.
		// Admin must set SUPERADMIN_DEFAULT_PASSWORD env var for a known password.
		$_saPassEnv = bin2hex(random_bytes(16));
		error_log('[SEC-CONFIG] WARNING: SUPERADMIN_DEFAULT_PASSWORD not set. Using random password. Set the env var for a known password.');
	}
	define('SUPERADMIN_DEFAULT_PASSWORD', $_saPassEnv);
	unset($_saPassEnv);
}

// Annual leave entitlements per leave type; adjust to match company policy.
if (!defined('LEAVE_DEFAULT_ENTITLEMENTS')) {
	define('LEAVE_DEFAULT_ENTITLEMENTS', [
		'sick' => 10,
		'vacation' => 12,
		'emergency' => 5,
		'unpaid' => 0,
		'other' => 0,
	]);
}
