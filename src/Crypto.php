<?php

declare(strict_types=1);

namespace Deployer;

use RuntimeException;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const VERSION_PREFIX = 'v1:';

    private string $derivedKey;

    public function __construct(string $keyFilePath)
    {
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('OpenSSL AES-256-GCM cipher is not available in this PHP build.');
        }

        $rawMaster = $this->loadOrCreateMasterKey($keyFilePath);
        $this->derivedKey = hash_hkdf('sha256', $rawMaster, 32, 'ssh-deployer-aes-256-gcm-v1');
    }

    /**
     * Encrypts a plaintext secret using AES-256-GCM with a unique 96-bit IV and 128-bit auth tag.
     */
    public function encrypt(string $plaintext, string $context = 'ssh-credential'): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_LENGTH
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Failed to encrypt credential with AES-256-GCM.');
        }

        return self::VERSION_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts an AES-256-GCM encrypted payload and verifies its authentication tag.
     */
    public function decrypt(?string $payload, string $context = 'ssh-credential'): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        if (!str_starts_with($payload, self::VERSION_PREFIX)) {
            throw new RuntimeException('Unsupported encrypted credential format.');
        }

        $decoded = base64_decode(substr($payload, strlen(self::VERSION_PREFIX)), true);
        if ($decoded === false || strlen($decoded) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Encrypted credential payload is malformed.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context
        );

        if ($plaintext === false) {
            throw new RuntimeException('Credential decryption failed (authentication tag mismatch or changed master key).');
        }

        return $plaintext;
    }

    private function loadOrCreateMasterKey(string $keyFilePath): string
    {
        $envKey = getenv('DEPLOYER_SECRET_KEY');
        if (is_string($envKey) && strlen(trim($envKey)) >= 32) {
            return trim($envKey);
        }

        if (is_file($keyFilePath)) {
            $existing = trim((string) file_get_contents($keyFilePath));
            if (strlen($existing) >= 32) {
                $hexDecoded = @hex2bin($existing);
                return $hexDecoded !== false ? $hexDecoded : $existing;
            }
        }

        $dir = dirname($keyFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create secure storage directory for encryption key.');
        }

        $rawBytes = random_bytes(32);
        $hexKey = bin2hex($rawBytes);

        $tmpFile = $keyFilePath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpFile, $hexKey . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write encryption master key to storage.');
        }

        @chmod($tmpFile, 0600);
        if (!@rename($tmpFile, $keyFilePath)) {
            @unlink($tmpFile);
            if (is_file($keyFilePath)) {
                $existing = trim((string) file_get_contents($keyFilePath));
                $hexDecoded = @hex2bin($existing);
                return $hexDecoded !== false ? $hexDecoded : $existing;
            }
            throw new RuntimeException('Unable to finalize encryption master key file.');
        }

        return $rawBytes;
    }
}

