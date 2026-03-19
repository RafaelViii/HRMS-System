<?php
// Development router script for PHP's built-in server.
// Mirrors the .htaccess rewrite rules so pretty URLs resolve while
// allowing static assets to pass through untouched.

$root = __DIR__;
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Sanitize: reject any path containing '..' to prevent directory traversal
if (strpos($uri, '..') !== false) {
    http_response_code(400);
    echo 'Bad Request';
    return true;
}

// 1) Serve existing files (assets, real PHP files) as-is
$fullPath = realpath($root . $uri);
if ($uri !== '/' && $fullPath && is_file($fullPath) && strpos($fullPath, $root) === 0) {
    return false;
}

// 2) If the URI points to a directory that has its own index.php, serve it
$dirIndex = realpath($root . $uri);
if ($uri !== '/' && $dirIndex && is_dir($dirIndex) && strpos($dirIndex, $root) === 0 && file_exists(rtrim($dirIndex, '\/') . '/index.php')) {
    require rtrim($dirIndex, '\/') . '/index.php';
    return true;
}

// 3) Try extensionless PHP (e.g., /login => login.php)
$phpPath = $root . $uri . '.php';
$phpReal = realpath($phpPath);
if ($uri !== '/' && $phpReal && is_file($phpReal) && strpos($phpReal, $root) === 0) {
    require $phpReal;
    return true;
}

// 4) Fallback to the main front controller
require $root . '/index.php';
return true;
