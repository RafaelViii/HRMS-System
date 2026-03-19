<?php
require_once __DIR__ . '/config.php';

/**
 * Establish and cache a native PDO connection.
 * Reads: DATABASE_URL (Heroku) or discrete env vars. Supports PostgreSQL and MySQL.
 * Returns PDO configured with exceptions and assoc default fetch.
 */
function get_db_conn(bool $fresh = false): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO && !$fresh) {
        return $pdo;
    }

    $env = function(array $keys, $default = null) {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') return $v;
        }
        return $default;
    };

    $uri = $env(['DATABASE_URL', 'db-uri']);
    $host = null; $user = null; $pass = null; $db = null; $port = null; $driver = 'pgsql';
    if ($uri) {
        $parts = parse_url($uri);
        if ($parts !== false) {
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $driver = in_array($scheme, ['postgres','postgresql','pgsql'], true) ? 'pgsql' : 'mysql';
            $host = $parts['host'] ?? $host;
            $user = $parts['user'] ?? $user;
            $pass = $parts['pass'] ?? $pass;
            $port = isset($parts['port']) ? (int)$parts['port'] : $port;
            if (!empty($parts['path'])) $db = ltrim($parts['path'], '/');
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                $db   = $db   ?? ($q['dbname'] ?? null);
                $user = $user ?? ($q['user'] ?? null);
                $pass = $pass ?? ($q['password'] ?? null);
                $host = $host ?? ($q['host'] ?? null);
                $port = $port ?? (isset($q['port']) ? (int)$q['port'] : null);
            }
        }
    }
    $host = $host ?? $env(['db-host', 'DB_HOST'], 'localhost');
    $user = $user ?? $env(['db-user', 'DB_USER'], 'postgres');
    $pass = $pass ?? $env(['db-password', 'DB_PASSWORD'], '');
    $db   = $db   ?? $env(['db-name', 'DB_NAME'], 'hrms');
    $port = (int)($port ?? $env(['db-port', 'DB_PORT'], $driver === 'mysql' ? '3306' : '5432'));

    // Warn if using default no-password credentials in a non-local environment
    if ($pass === '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        error_log('[DB-SECURITY] WARNING: Connecting to remote database without a password is insecure. Set DB_PASSWORD or DATABASE_URL.');
    }

    // Connection timeout (seconds). For PostgreSQL, use DSN parameter connect_timeout.
    $connectTimeout = (int)$env(['DB_CONNECT_TIMEOUT', 'db-connect-timeout'], '5');
    // SSL mode for PostgreSQL (require for remote hosts, prefer for local)
    $sslMode = $env(['DB_SSLMODE'], 'prefer');
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $sslMode = $env(['DB_SSLMODE'], 'require');
    }
    $dsn = '';
    if ($driver === 'mysql') {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    } else {
        // For Postgres, prefer a short connect timeout to avoid long H12 timeouts on cold DNS/network
        $dsn = "pgsql:host={$host};port={$port};dbname={$db};connect_timeout={$connectTimeout};sslmode={$sslMode}";
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $connection = new PDO($dsn, $user, $pass, $options);
        // For MySQL, ensure UTF8MB4
        if ($driver === 'mysql') { $connection->exec("SET NAMES 'utf8mb4'"); }
        if (!$fresh) {
            $pdo = $connection;
        }
        return $connection;
    } catch (Throwable $e) {
        error_log('[DB1001] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('A system error occurred. Please contact the administrator.');
    }
}

/** Sanitize an identifier (table/column) to safe characters for interpolation. */
function pg_ident(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}
