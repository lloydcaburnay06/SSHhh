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

    public function testRedactsDatabaseUrlsAndJsonSecretsInLaravelLogs(): void
    {
        $input = implode("\n", [
            'SQLSTATE[HY000] mysql://forge:SuperSecretPw1@db.internal/shop',
            'redis connect failed: redis://default:R3disPass@cache:6379',
            '{"email":"a@b.com","password":"hunter2-long","remember_token":"tok_abcdef123"}',
            "['api_key' => 'sk_live_998877', 'name' => 'Shop']",
            'Undefined array key "total" for order 103',
        ]);

        $redacted = SecretRedactor::redact($input);

        self::assertStringNotContainsString('SuperSecretPw1', $redacted);
        self::assertStringNotContainsString('R3disPass', $redacted);
        self::assertStringNotContainsString('hunter2-long', $redacted);
        self::assertStringNotContainsString('tok_abcdef123', $redacted);
        self::assertStringNotContainsString('sk_live_998877', $redacted);
        self::assertStringContainsString('mysql://forge:[REDACTED]@db.internal/shop', $redacted);
        self::assertStringContainsString('"email":"a@b.com"', $redacted);
        self::assertStringContainsString("'name' => 'Shop'", $redacted);
        self::assertStringContainsString('Undefined array key "total" for order 103', $redacted);
    }

    public function testHandlesEmptyStringCleanly(): void
    {
        self::assertSame('', SecretRedactor::redact(''));
    }
}
