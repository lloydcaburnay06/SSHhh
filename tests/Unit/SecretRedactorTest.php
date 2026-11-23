<?php

declare(strict_types=1);

use App\Services\SecretRedactor;

test('redacts env keys url credentials bearer and pem blocks', function () {
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

    expect($redacted)->not->toContain('super_secret_db_pass');
    expect($redacted)->not->toContain('abcdef1234567890');
    expect($redacted)->not->toContain('ghp_secretToken9988');
    expect($redacted)->not->toContain('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9');
    expect($redacted)->not->toContain('MIIEowIBAAKCAQEA1234567890');
    expect($redacted)->not->toContain('my-explicit-runtime-secret');
    expect($redacted)->toContain('[REDACTED]');
    expect($redacted)->toContain('Normal output: Build succeeded in 1.4s');
});

test('redacts database urls and json secrets in laravel logs', function () {
    $input = implode("\n", [
        'SQLSTATE[HY000] mysql://forge:SuperSecretPw1@db.internal/shop',
        'redis connect failed: redis://default:R3disPass@cache:6379',
        '{"email":"a@b.com","password":"hunter2-long","remember_token":"tok_abcdef123"}',
        "['api_key' => 'sk_live_998877', 'name' => 'Shop']",
        'Undefined array key "total" for order 103',
    ]);

    $redacted = SecretRedactor::redact($input);

    expect($redacted)->not->toContain('SuperSecretPw1');
    expect($redacted)->not->toContain('R3disPass');
    expect($redacted)->not->toContain('hunter2-long');
    expect($redacted)->not->toContain('tok_abcdef123');
    expect($redacted)->not->toContain('sk_live_998877');
    expect($redacted)->toContain('mysql://forge:[REDACTED]@db.internal/shop');
    expect($redacted)->toContain('"email":"a@b.com"');
    expect($redacted)->toContain("'name' => 'Shop'");
    expect($redacted)->toContain('Undefined array key "total" for order 103');
});

test('handles empty string cleanly', function () {
    expect(SecretRedactor::redact(''))->toBe('');
});
