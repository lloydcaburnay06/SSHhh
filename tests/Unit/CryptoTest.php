<?php

declare(strict_types=1);

use App\Services\Crypto;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'deployer_crypto_test_' . bin2hex(random_bytes(6));
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
    if (is_array($files)) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
    @rmdir($this->tempDir);
});

test('encrypt and decrypt round trip', function () {
    $keyPath = $this->tempDir . DIRECTORY_SEPARATOR . 'secret.key';
    $crypto = new Crypto($keyPath, null);

    $plaintext = "-----BEGIN OPENSSH PRIVATE KEY-----\nsuper-secret-material\n-----END OPENSSH PRIVATE KEY-----";
    $ciphertext = $crypto->encrypt($plaintext, 'ssh-profile-credential');

    expect($ciphertext)->toStartWith('v1:');
    expect($ciphertext)->not->toBe($plaintext);
    expect($crypto->decrypt($ciphertext, 'ssh-profile-credential'))->toBe($plaintext);
    expect($keyPath)->toBeFile();
});

test('empty string encrypts and decrypts to empty string', function () {
    $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key', null);

    expect($crypto->encrypt(''))->toBe('');
    expect($crypto->decrypt(''))->toBe('');
    expect($crypto->decrypt(null))->toBe('');
});

test('encrypt uses random nonce each call', function () {
    $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key', null);

    $first = $crypto->encrypt('identical-secret', 'ctx');
    $second = $crypto->encrypt('identical-secret', 'ctx');

    expect($first)->not->toBe($second);
    expect($crypto->decrypt($first, 'ctx'))->toBe('identical-secret');
    expect($crypto->decrypt($second, 'ctx'))->toBe('identical-secret');
});

test('wrong context throws runtime exception', function () {
    $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key', null);
    $ciphertext = $crypto->encrypt('api-token', 'project-env-vars');

    $crypto->decrypt($ciphertext, 'ssh-credential');
})->throws(RuntimeException::class);

test('tampered ciphertext throws runtime exception', function () {
    $crypto = new Crypto($this->tempDir . DIRECTORY_SEPARATOR . 'secret.key', null);
    $ciphertext = $crypto->encrypt('db-password', 'ssh-credential');

    $raw = base64_decode(substr($ciphertext, 3), true);
    expect($raw)->toBeString();
    $tamperedByte = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
    $tampered = 'v1:' . base64_encode(substr($raw, 0, -1) . $tamperedByte);

    $crypto->decrypt($tampered, 'ssh-credential');
})->throws(RuntimeException::class);
