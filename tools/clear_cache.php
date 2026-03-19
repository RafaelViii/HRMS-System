<?php
/**
 * Clear PHP OPcache to force reload of all PHP files
 * Run this after making code changes that don't seem to take effect
 */

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "✓ OPcache cleared successfully\n";
    } else {
        echo "✗ Failed to clear OPcache\n";
    }
} else {
    echo "✗ OPcache is not enabled\n";
}

if (function_exists('apc_clear_cache')) {
    // APC cache clearing - suppressed for static analysis
    // Function existence is checked above
    call_user_func('apc_clear_cache');
    echo "✓ APC cache cleared\n";
} else {
    echo "ℹ APC cache is not available\n";
}

echo "\nPHP file cache has been cleared. Changes should now take effect.\n";
