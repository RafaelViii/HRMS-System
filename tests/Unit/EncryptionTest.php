<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the field-level encryption helpers in includes/encryption.php.
 */
class EncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        // Set a test encryption key
        putenv('ENCRYPTION_KEY=' . base64_encode(str_repeat('A', 32)));
    }

    protected function tearDown(): void
    {
        // Clear the static key cache by resetting the env
        // The static $key inside encryption_get_key() persists across tests, which is fine
        // as long as the key doesn't change mid-test
    }

    // =====================================================================
    // encrypt_field / decrypt_field
    // =====================================================================

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $plain = '12-3456789-0';
        $encrypted = encrypt_field($plain);

        $this->assertNotNull($encrypted);
        $this->assertNotSame($plain, $encrypted);

        $decrypted = decrypt_field($encrypted);
        $this->assertSame($plain, $decrypted);
    }

    public function testEncryptNullReturnsNull(): void
    {
        $this->assertNull(encrypt_field(null));
    }

    public function testEncryptEmptyStringReturnsNull(): void
    {
        $this->assertNull(encrypt_field(''));
    }

    public function testDecryptNullReturnsNull(): void
    {
        $this->assertNull(decrypt_field(null));
    }

    public function testDecryptEmptyStringReturnsNull(): void
    {
        $this->assertNull(decrypt_field(''));
    }

    public function testDecryptPlaintextReturnsAsIs(): void
    {
        // If someone passes a non-encrypted string, it should return as-is
        // (backward compatibility)
        $plain = 'ABC';
        // Short strings that aren't valid base64+IV should be returned as-is
        $result = decrypt_field($plain);
        $this->assertSame($plain, $result);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        // Due to random IV, same plaintext should produce different ciphertext
        $plain = '999-888-777';
        $enc1 = encrypt_field($plain);
        $enc2 = encrypt_field($plain);
        $this->assertNotSame($enc1, $enc2);
        // But both should decrypt to the same value
        $this->assertSame($plain, decrypt_field($enc1));
        $this->assertSame($plain, decrypt_field($enc2));
    }

    public function testEncryptedOutputIsBase64(): void
    {
        $encrypted = encrypt_field('test-value');
        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded);
        // IV (16 bytes) + at least 1 block of ciphertext (16 bytes for AES)
        $this->assertGreaterThanOrEqual(32, strlen($decoded));
    }

    // =====================================================================
    // mask_field
    // =====================================================================

    public function testMaskFieldDefault(): void
    {
        $result = mask_field('1234567890', 4);
        // Should show last 4 chars, mask the rest
        $this->assertStringEndsWith('7890', $result);
        // mask_field uses str_repeat with multi-byte mask char (•) so check visible structure
        $expected = str_repeat('•', 6) . '7890';
        $this->assertSame($expected, $result);
    }

    public function testMaskFieldNullReturnsNull(): void
    {
        $this->assertNull(mask_field(null));
    }

    public function testMaskFieldEmptyReturnsNull(): void
    {
        $this->assertNull(mask_field(''));
    }

    public function testMaskFieldShortValue(): void
    {
        // Value shorter than show count → return as-is
        $result = mask_field('AB', 4);
        $this->assertSame('AB', $result);
    }

    public function testMaskFieldCustomMaskChar(): void
    {
        $result = mask_field('12345', 2, '*');
        $this->assertSame('***45', $result);
    }

    // =====================================================================
    // encrypt_fields / decrypt_fields
    // =====================================================================

    public function testEncryptFieldsBatch(): void
    {
        $data = [
            'name' => 'John',
            'sss_number' => '12-345',
            'tin' => '999-000',
            'email' => 'john@test.com',
        ];
        $result = encrypt_fields($data, ['sss_number', 'tin']);

        // Non-listed fields should be unchanged
        $this->assertSame('John', $result['name']);
        $this->assertSame('john@test.com', $result['email']);

        // Listed fields should be encrypted
        $this->assertNotSame('12-345', $result['sss_number']);
        $this->assertNotSame('999-000', $result['tin']);

        // Verify they decrypt back
        $decrypted = decrypt_fields($result, ['sss_number', 'tin']);
        $this->assertSame('12-345', $decrypted['sss_number']);
        $this->assertSame('999-000', $decrypted['tin']);
    }

    public function testEncryptFieldsSkipsEmptyValues(): void
    {
        $data = ['sss_number' => '', 'tin' => null];
        $result = encrypt_fields($data, ['sss_number', 'tin']);
        $this->assertSame('', $result['sss_number']);
        $this->assertNull($result['tin']);
    }

    // =====================================================================
    // encrypted_employee_fields
    // =====================================================================

    public function testEncryptedEmployeeFieldsList(): void
    {
        $fields = encrypted_employee_fields();
        $this->assertContains('sss_number', $fields);
        $this->assertContains('philhealth_number', $fields);
        $this->assertContains('pagibig_number', $fields);
        $this->assertContains('tin', $fields);
        $this->assertContains('bank_account_number', $fields);
        $this->assertCount(5, $fields);
    }

    // =====================================================================
    // encrypt_employee / decrypt_employee
    // =====================================================================

    public function testEncryptDecryptEmployee(): void
    {
        $employee = [
            'id' => 1,
            'first_name' => 'Juan',
            'sss_number' => '12-3456789-0',
            'philhealth_number' => '01-234567890-1',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789-000',
            'bank_account_number' => '0001234567890',
            'bank_name' => 'BDO',
        ];

        $encrypted = encrypt_employee($employee);

        // Non-sensitive fields unchanged
        $this->assertSame(1, $encrypted['id']);
        $this->assertSame('Juan', $encrypted['first_name']);
        $this->assertSame('BDO', $encrypted['bank_name']); // bank_name is NOT in encrypted list

        // Sensitive fields encrypted
        $this->assertNotSame('12-3456789-0', $encrypted['sss_number']);
        $this->assertNotSame('01-234567890-1', $encrypted['philhealth_number']);

        // Decrypt round-trip
        $decrypted = decrypt_employee($encrypted);
        $this->assertSame('12-3456789-0', $decrypted['sss_number']);
        $this->assertSame('01-234567890-1', $decrypted['philhealth_number']);
        $this->assertSame('1234-5678-9012', $decrypted['pagibig_number']);
        $this->assertSame('123-456-789-000', $decrypted['tin']);
        $this->assertSame('0001234567890', $decrypted['bank_account_number']);
    }
}
