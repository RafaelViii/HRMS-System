/**
 * Field-Level Encryption Helper
 * 
 * Provides AES-256-GCM authenticated encryption for sensitive PII fields
 * (SSS, PhilHealth, Pag-IBIG, TIN, bank account numbers).
 *
 * Key management:
 *   - ENCRYPTION_KEY env var must be a base64-encoded 32-byte key
 *   - Generate: php -r "echo base64_encode(random_bytes(32));"
 *   - Store in Heroku: heroku config:set ENCRYPTION_KEY=...
 *   - NEVER commit the key to source control
 *
 * Usage:
 *   $encrypted = encrypt_field('123-456-789');    // store this in DB
 *   $plain     = decrypt_field($encrypted);       // read back
 *   $masked    = mask_field($plain, 4);           // '***-456-789' for display
 */

/**
 * Get the encryption key from environment. Exits with error if missing.
 */
function encryption_get_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $raw = getenv('ENCRYPTION_KEY');
    if (!$raw && isset($_ENV['ENCRYPTION_KEY'])) {
        $raw = $_ENV['ENCRYPTION_KEY'];
    }
    if (!$raw && defined('ENCRYPTION_KEY')) {
        $raw = ENCRYPTION_KEY;
    }

    if (!$raw) {
        // In production this should never happen — fail loudly
        if (function_exists('sys_log')) {
            sys_log('SEC-ENCRYPT', 'ENCRYPTION_KEY environment variable is not set', ['severity' => 'critical']);
        }
        throw new RuntimeException('Encryption key not configured. Set ENCRYPTION_KEY environment variable.');
    }

    $decoded = base64_decode($raw, true);
    if ($decoded === false || strlen($decoded) < 32) {
        throw new RuntimeException('Invalid ENCRYPTION_KEY. Must be a base64-encoded key of exactly 32 bytes for AES-256.');
    }

    $key = substr($decoded, 0, 32);
    return $key;
}

/**
 * Encrypt a plaintext value using AES-256-GCM (authenticated encryption).
 * Returns base64-encoded string: IV (12 bytes) + GCM tag (16 bytes) + ciphertext.
 * Returns null if input is null/empty.
 */
function encrypt_field(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') {
        return null;
    }

    $key = encryption_get_key();
    $iv = random_bytes(12); // GCM standard IV length
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    if ($ciphertext === false) {
        if (function_exists('sys_log')) {
            sys_log('SEC-ENCRYPT', 'Encryption failed', ['error' => openssl_error_string()]);
        }
        throw new RuntimeException('Field encryption failed.');
    }

    // Format: IV (12) + Tag (16) + Ciphertext
    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt a value previously encrypted with encrypt_field().
 * Supports both AES-256-GCM (new) and AES-256-CBC (legacy) formats.
 * Returns null if input is null/empty or decryption fails.
 */
function decrypt_field(?string $encrypted): ?string {
    if ($encrypted === null || $encrypted === '') {
        return null;
    }

    $key = encryption_get_key();
    $data = base64_decode($encrypted, true);

    if ($data === false || strlen($data) < 17) {
        // Not a valid encrypted value — treat as legacy plaintext
        return $encrypted;
    }

    // Detect format: GCM payload = 12 (IV) + 16 (tag) + ciphertext (minimum 29 bytes)
    // Legacy CBC payload = 16 (IV) + ciphertext (minimum 32 bytes, always 16-byte aligned blocks)
    // Heuristic: if data length minus 28 bytes is NOT a multiple of 16, it's GCM.
    // If (len - 16) is a multiple of 16, try CBC first for backward compat, then GCM.
    $dataLen = strlen($data);

    // Try GCM first (new format): IV(12) + Tag(16) + ciphertext
    if ($dataLen >= 29) {
        $gcmIv = substr($data, 0, 12);
        $gcmTag = substr($data, 12, 16);
        $gcmCiphertext = substr($data, 28);
        $decrypted = openssl_decrypt($gcmCiphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $gcmIv, $gcmTag);
        if ($decrypted !== false) {
            return $decrypted;
        }
    }

    // Fallback: try legacy AES-256-CBC format: IV(16) + ciphertext
    if ($dataLen >= 32) {
        $cbcIv = substr($data, 0, 16);
        $cbcCiphertext = substr($data, 16);
        $decrypted = openssl_decrypt($cbcCiphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $cbcIv);
        if ($decrypted !== false) {
            return $decrypted;
        }
    }

    // Both failed — log and return null (do NOT return raw ciphertext)
    if (function_exists('sys_log')) {
        sys_log('SEC-DECRYPT', 'Decryption failed — value may be plaintext or key mismatch', []);
    }
    return null;
}

/**
 * Mask a value for display, showing only the last N characters.
 * 
 * @param string|null $value  The plaintext value to mask
 * @param int         $show   Number of characters to show at the end
 * @param string      $mask   Character to use for masking
 * @return string|null        Masked string or null
 */
function mask_field(?string $value, int $show = 4, string $mask = '•'): ?string {
    if ($value === null || $value === '') {
        return null;
    }

    $len = strlen($value);
    if ($len <= $show) {
        return $value; // Too short to mask meaningfully
    }

    return str_repeat($mask, $len - $show) . substr($value, -$show);
}

/**
 * Encrypt multiple fields in an associative array.
 * Only encrypts values for the specified field names.
 *
 * @param array    $data         The data array (e.g., form input)
 * @param string[] $fieldNames   Fields to encrypt (e.g., ['sss_number', 'tin'])
 * @return array                 Data with specified fields encrypted
 */
function encrypt_fields(array $data, array $fieldNames): array {
    foreach ($fieldNames as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $data[$field] = encrypt_field($data[$field]);
        }
    }
    return $data;
}

/**
 * Decrypt multiple fields in an associative array.
 *
 * @param array    $data         The data array (e.g., DB row)
 * @param string[] $fieldNames   Fields to decrypt
 * @return array                 Data with specified fields decrypted
 */
function decrypt_fields(array $data, array $fieldNames): array {
    foreach ($fieldNames as $field) {
        if (isset($data[$field]) && $data[$field] !== null) {
            $data[$field] = decrypt_field($data[$field]);
        }
    }
    return $data;
}

/**
 * List of employee fields that should be encrypted at rest.
 *
 * NOTE: salary is NOT included here because it's a NUMERIC column used in
 * payroll SQL arithmetic. Encrypting it at the application level would break
 * all DB-level computations. For RA 10173 compliance, salary should be
 * protected via PostgreSQL column-level encryption (pgcrypto) or
 * Transparent Data Encryption (TDE) at the database/disk layer.
 */
function encrypted_employee_fields(): array {
    return [
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin',
        'bank_account_number',
    ];
}

/**
 * Decrypt an employee record's sensitive fields for display.
 */
function decrypt_employee(array $employee): array {
    return decrypt_fields($employee, encrypted_employee_fields());
}

/**
 * Encrypt an employee record's sensitive fields before storage.
 */
function encrypt_employee(array $data): array {
    return encrypt_fields($data, encrypted_employee_fields());
}
