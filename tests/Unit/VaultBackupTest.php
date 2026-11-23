<?php

declare(strict_types=1);

use App\Services\VaultBackup;

test('encrypt and decrypt bundle round trip', function () {
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
    expect($bundle)->toHaveKey('ciphertext');
    expect($bundle)->toHaveKey('salt');
    expect($bundle)->toHaveKey('iv');
    expect($bundle)->toHaveKey('tag');

    $restored = VaultBackup::decryptBundle($bundle, 'correct-horse-battery-staple');
    expect($restored)->toBe($payload);
});

test('short passphrase is rejected', function () {
    VaultBackup::encryptBundle(['projects' => []], 'short');
})->throws(InvalidArgumentException::class);

test('wrong passphrase throws runtime exception', function () {
    $bundle = VaultBackup::encryptBundle(['secret' => 'value'], 'valid-passphrase-123');

    VaultBackup::decryptBundle($bundle, 'wrong-passphrase-123');
})->throws(RuntimeException::class);

test('malformed bundle throws invalid argument exception', function () {
    VaultBackup::decryptBundle(['format' => 'unknown'], 'valid-passphrase-123');
})->throws(InvalidArgumentException::class);
