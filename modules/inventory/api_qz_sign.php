<?php
/**
 * QZ Tray Signing Endpoint
 *
 * Signs connection requests so QZ Tray auto-trusts this website
 * without showing the "Trust this site?" dialog every session.
 *
 * How it works:
 *   1. Browser sends the QZ Tray challenge string (toSign) via POST
 *   2. Server signs it with the private key (never exposed to browser)
 *   3. Browser sends the signature + public certificate to QZ Tray
 *   4. QZ Tray validates and auto-trusts this origin
 *
 * Setup:
 *   - Import assets/certs/qz-cert.pem into QZ Tray:
 *     QZ Tray icon → Advanced → Site Manager → + → Browse → select qz-cert.pem
 *   - Or place qz-cert.pem in QZ Tray's trusted certs folder:
 *     Windows: %LOCALAPPDATA%\QZ Tray\sslcert\
 *     macOS:   ~/Library/Application Support/QZ Tray/sslcert/
 *     Linux:   ~/.qz/sslcert/
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'read');

header('Content-Type: text/plain');
header('Cache-Control: no-store');

// Only accept POST with data to sign
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$toSign = file_get_contents('php://input');
if (empty($toSign)) {
    http_response_code(400);
    echo 'No data to sign';
    exit;
}

// Load private key — from env var (Heroku) or file (local dev)
$keyPem = null;
if (!empty($_ENV['QZ_PRIVATE_KEY_B64'])) {
    $keyPem = base64_decode($_ENV['QZ_PRIVATE_KEY_B64']);
} elseif (!empty(getenv('QZ_PRIVATE_KEY_B64'))) {
    $keyPem = base64_decode(getenv('QZ_PRIVATE_KEY_B64'));
} else {
    $keyPath = __DIR__ . '/../../assets/certs/qz-private-key.pem';
    if (file_exists($keyPath)) {
        $keyPem = file_get_contents($keyPath);
    }
}

if (empty($keyPem)) {
    http_response_code(500);
    echo 'Signing key not configured';
    exit;
}

$privateKey = openssl_pkey_get_private($keyPem);
if (!$privateKey) {
    http_response_code(500);
    echo 'Invalid signing key';
    exit;
}

// Sign the data with SHA-512 + RSA (QZ Tray's expected algorithm)
$signature = '';
if (openssl_sign($toSign, $signature, $privateKey, OPENSSL_ALGO_SHA512)) {
    echo base64_encode($signature);
} else {
    http_response_code(500);
    echo 'Signing failed';
}
