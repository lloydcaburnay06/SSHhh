<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function testRedactsEnvKeysUrlCredentialsBearerAndPemBlocks(): void
    {
        $input = implode("\n", [
            'DB_PASSWORD=super_secret_db_pass',
            'APP_KEY="base64:abcdef1234567890="',
            'Cloning https://deployuser:ghp_secretToken9988@github.com/org/repo.git',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload.sig',
            '-----BEGIN RSA PRIVATE KEY-----',
            'MIIEowIBAAKCAQEA1234567890',
            '-----END RSA PRIVATE KEY-----',
            'Custom token value: my-explicit-runtime-secret',
            'Normal output: Build succeeded in 1.4s',
        ]);

        $redacted = SecretRedactor::redact($input, ['my-explicit-runtime-secret', 'abc']);

        self::assertStringNotContainsString('super_secret_db_pass', $redacted);
        self::assertStringNotContainsString('abcdef1234567890', $redacted);
        self::assertStringNotContainsString('ghp_secretToken9988', $redacted);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $redacted);
        self::assertStringNotContainsString('MIIEowIBAAKCAQEA1234567890', $redacted);
        self::assertStringNotContainsString('my-explicit-runtime-secret', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
        self::assertStringContainsString('Normal output: Build succeeded in 1.4s', $redacted);
    }

    public function testHandlesEmptyStringCleanly(): void
    {
        self::assertSame('', SecretRedactor::redact(''));
    }
}
