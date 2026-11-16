<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    private string $tempDir;
    private string|false $originalEnvKey;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'deployer_crypto_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        $this->originalEnvKey = getenv('DEPLOYER_SECRET_KEY');
        putenv('DEPLOYER_SECRET_KEY');
        unset($_ENV['DEPLOYER_SECRET_KEY']);
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvKey === false) {
            putenv('DEPLOYER_SECRET_KEY');
            unset($_ENV['DEPLOYER_SECRET_KEY']);
        } else {
            putenv('DEPLOYER_SECRET_KEY=' . $this->originalEnvKey);
            $_ENV['DEPLOYER_SECRET_KEY'] = $this->originalEnvKey;
        }

        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $keyPath = $this->tempDir . DIRECTORY_SEPARATOR . 'secret.key';
        $crypto = new Crypto($keyPath);

        $plaintext = "-----BEGIN OPENSSH PRIVATE KEY-----\nsuper-secret-material\n-----END OPENSSH PRIVATE KEY-----";
        $ciphertext = $crypto->encrypt($plaintext, 'ssh-profile-credential');

        self::assertStringStartsWith('v1:', $ciphertext);
        self::assertNotSame($plaintext, $ciphertext);
        self::assertSame($plaintext, $crypto->decrypt($ciphertext, 'ssh-profile-credential'));
        self::assertFileExists($keyPath);
    }

    public function testEmptyStringEncryptsAndDecryptsToEmptyString(): void
    {
        $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key');

        self::assertSame('', $crypto->encrypt(''));
        self::assertSame('', $crypto->decrypt(''));
        self::assertSame('', $crypto->decrypt(null));
    }

    public function testEncryptUsesRandomNonceEachCall(): void
    {
        $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key');

        $first = $crypto->encrypt('identical-secret', 'ctx');
        $second = $crypto->encrypt('identical-secret', 'ctx');

        self::assertNotSame($first, $second);
        self::assertSame('identical-secret', $crypto->decrypt($first, 'ctx'));
        self::assertSame('identical-secret', $crypto->decrypt($second, 'ctx'));
    }

    public function testWrongContextThrowsRuntimeException(): void
    {
        $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key');
        $ciphertext = $crypto->encrypt('api-token', 'project-env-vars');

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($ciphertext, 'ssh-credential');
    }

    public function testTamperedCiphertextThrowsRuntimeException(): void
    {
        $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key');
        $ciphertext = $crypto->encrypt('db-password', 'ssh-credential');

        $raw = base64_decode(substr($ciphertext, 3), true);
        self::assertIsString($raw);
        $tamperedByte = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = 'v1:' . base64_encode(substr($raw, 0, -1) . $tamperedByte);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($tampered, 'ssh-credential');
    }
}
