<?php
// Robust session bootstrap and validation

if (!defined('HRMS_SESSION_IDLE_TIMEOUT')) {
    define('HRMS_SESSION_IDLE_TIMEOUT', 10800); // 3 hours
}
if (!defined('HRMS_SESSION_ABSOLUTE_TIMEOUT')) {
    define('HRMS_SESSION_ABSOLUTE_TIMEOUT', 86400); // 24 hours (rolling)
}

function session_client_ip(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }
    return '0.0.0.0';
}

function session_ip_bucket(string $ip): string {
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return 'unknown';
    }
    if (strlen($packed) === 4) {
        $long = unpack('N', $packed)[1] ?? 0;
        $masked = $long & 0xFFFFFF00; // /24 network bucket
        return long2ip($masked) ?: 'unknown';
    }
    if (strlen($packed) === 16) {
        $prefix = substr($packed, 0, 8) . str_repeat("\0", 8); // keep /64
        return inet_ntop($prefix) ?: 'unknown';
    }
    return 'unknown';
}

function session_calc_fingerprint(bool $loose = false): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($loose) {
        // Loose mode: User-Agent only — tolerates IP changes (mobile networks, load balancers)
        return substr(hash('sha1', $ua), 0, 32);
    }
    $ip = session_client_ip();
    $ipPart = $ip;
    if ($ipPart === '' || $ipPart === '0.0.0.0') {
        $ipPart = 'unknown';
    }
    return substr(hash('sha1', $ua . '|' . $ipPart), 0, 32);
}

/**
 * Send security headers to mitigate common web vulnerabilities.
 * Called from session_bootstrap() to ensure headers are set on every response.
 */
function send_security_headers(): void {
    if (headers_sent()) return;

    // Prevent clickjacking (aligned with CSP frame-ancestors 'none')
    header('X-Frame-Options: DENY');

    // Prevent MIME-sniffing
    header('X-Content-Type-Options: nosniff');

    // XSS Protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions Policy
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // HSTS — only on HTTPS connections
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Content Security Policy
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdn.tailwindcss.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "connect-src 'self' https://cdn.jsdelivr.net https://cdn.tailwindcss.com wss://localhost:8181 wss://localhost:8282 wss://localhost:8383 wss://localhost:8484 ws://localhost:8182 ws://localhost:8283 ws://localhost:8384 ws://localhost:8485",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

function session_bootstrap(): void {
    // If a session was auto-started with the default name, destroy it and restart
    // with our hardened settings (fixes PHPSESSID without HttpOnly flag)
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== 'HRMSSESSID') {
        session_unset();
        session_destroy();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        send_security_headers();
        return;
    }
    ini_set('session.auto_start', '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // On local XAMPP we usually don't have HTTPS; set secure flag conditionally
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('HRMSSESSID');
    session_start();
    send_security_headers();
    if (empty($_SESSION['__meta'])) {
        $now = time();
        $_SESSION['__meta'] = [
            'created' => $now,
            'last_active' => $now,
            'fingerprint' => session_calc_fingerprint(false),
            'fingerprint_loose' => session_calc_fingerprint(true),
            'rotate_at' => $now + 900, // 15 min
            'idle_timeout' => HRMS_SESSION_IDLE_TIMEOUT,
            'absolute_timeout' => HRMS_SESSION_ABSOLUTE_TIMEOUT,
            'idle_expires_at' => $now + HRMS_SESSION_IDLE_TIMEOUT,
            'absolute_expires_at' => $now + HRMS_SESSION_ABSOLUTE_TIMEOUT,
            'server_now' => $now,
        ];
    }
}

function session_validate(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $now = time();
    $idleTimeout = HRMS_SESSION_IDLE_TIMEOUT;
    $absoluteTimeout = HRMS_SESSION_ABSOLUTE_TIMEOUT;
    if (empty($_SESSION['__meta']) || !is_array($_SESSION['__meta'])) return;
    // Work with the session meta by reference to allow in-place fixes
    $meta =& $_SESSION['__meta'];
    // Ensure required keys exist (handles older sessions after code updates)
    $strictCurrent = session_calc_fingerprint(false);
    $looseCurrent = session_calc_fingerprint(true);
    if (empty($meta['fingerprint']) || strlen((string)$meta['fingerprint']) < 20) {
        $meta['fingerprint'] = $strictCurrent;
    }
    if (empty($meta['fingerprint_loose']) || strlen((string)$meta['fingerprint_loose']) < 20) {
        $meta['fingerprint_loose'] = $looseCurrent;
    }
    $fp = $strictCurrent;
    if (empty($meta['fingerprint'])) {
        $meta['fingerprint'] = $fp;
    }
    if (!isset($meta['created'])) { $meta['created'] = $now; }
    if (!isset($meta['last_active'])) { $meta['last_active'] = $now; }
    if (!isset($meta['rotate_at'])) { $meta['rotate_at'] = $now + 900; }
    if (!isset($meta['idle_timeout'])) { $meta['idle_timeout'] = $idleTimeout; }
    if (!isset($meta['absolute_timeout'])) { $meta['absolute_timeout'] = $absoluteTimeout; }

    if (!hash_equals((string)$meta['fingerprint'], (string)$fp)) {
        // IP changed — check loose fingerprint (User-Agent only) to allow mobile/LB IP drift
        if (!hash_equals((string)$meta['fingerprint_loose'], (string)$looseCurrent)) {
            // User-Agent changed entirely — likely session hijack attempt
            $_SESSION = [];
            session_destroy();
            return;
        }
        // IP drifted but UA matches — accept and update strict fingerprint
        $meta['fingerprint'] = $fp;
        $meta['fingerprint_loose'] = $looseCurrent;
    } else {
        $meta['fingerprint_loose'] = $looseCurrent;
    }
    if (($now - (int)($meta['last_active'] ?? $now)) > $idleTimeout || ($now - (int)($meta['created'] ?? $now)) > $absoluteTimeout) {
        $_SESSION = [];
        session_destroy();
        return;
    }
    // Rotate session ID periodically (every 15 min) to limit fixation window
    // Use false to keep the old session briefly (avoids race conditions on Heroku)
    if ((int)($meta['rotate_at'] ?? 0) < $now) {
        session_regenerate_id(false);
        $meta['rotate_at'] = $now + 900;
    }
    $meta['last_active'] = $now;
    // Don't reset created — it tracks session start for absolute timeout
    $meta['idle_timeout'] = $idleTimeout;
    $meta['absolute_timeout'] = $absoluteTimeout;
    $meta['idle_expires_at'] = $now + $idleTimeout;
    $meta['absolute_expires_at'] = (int)($meta['created'] ?? $now) + $absoluteTimeout;
    $meta['server_now'] = $now;
}
