<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\VaultBackup;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VaultBackupTest extends TestCase
{
    public function testEncryptAndDecryptBundleRoundTrip(): void
    {
        $payload = [
            'version' => 1,
            'ssh_profiles' => [
                ['name' => 'prod-vps', 'host' => '203.0.113.10', 'password' => 'top-secret-pw'],
            ],
            'projects' => [
                ['name' => 'demo-app', 'env_vars' => "API_KEY=xyz123\n"],
            ],
        ];

        $bundle = VaultBackup::encryptBundle($payload, 'correct-horse-battery-staple');
        self::assertArrayHasKey('ciphertext', $bundle);
        self::assertArrayHasKey('salt', $bundle);
        self::assertArrayHasKey('iv', $bundle);
        self::assertArrayHasKey('tag', $bundle);

        $restored = VaultBackup::decryptBundle($bundle, 'correct-horse-battery-staple');
        self::assertSame($payload, $restored);
    }

    public function testShortPassphraseIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VaultBackup::encryptBundle(['projects' => []], 'short');
    }

    public function testWrongPassphraseThrowsRuntimeException(): void
    {
        $bundle = VaultBackup::encryptBundle(['secret' => 'value'], 'valid-passphrase-123');

        $this->expectException(RuntimeException::class);
        VaultBackup::decryptBundle($bundle, 'wrong-passphrase-123');
    }

    public function testMalformedBundleThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VaultBackup::decryptBundle(['format' => 'unknown'], 'valid-passphrase-123');
    }
}
