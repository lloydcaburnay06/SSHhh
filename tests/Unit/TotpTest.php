<?php

declare(strict_types=1);

use App\Services\Totp;

test('base32 encode decode round trip', function () {
    $samples = ['f', 'fo', 'foo', 'foob', 'fooba', 'foobar', '12345678901234567890'];

    foreach ($samples as $sample) {
        $encoded = Totp::base32Encode($sample);
        expect(Totp::base32Decode($encoded))->toBe($sample);
    }

    expect(Totp::base32Encode(''))->toBe('');
    expect(Totp::base32Decode(''))->toBe('');
});

test('calculateCode matches rfc6238 sha1 vector', function () {
    // RFC 6238 / RFC 4226 test secret: ASCII "12345678901234567890"
    $secret = Totp::base32Encode('12345678901234567890');
    expect($secret)->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

    // At t=59s (counter = 1), 8-digit RFC 6238 SHA1 output is 94287082 -> 6-digit is 287082
    expect(Totp::calculateCode($secret, 59, 30))->toBe('287082');
});

test('verify accepts window drift and rejects wrong code', function () {
    $secret = Totp::generateSecret();
    $baseTime = 1_700_000_010;
    $prevStepCode = Totp::calculateCode($secret, $baseTime - 30, 30);
    $nextStepCode = Totp::calculateCode($secret, $baseTime + 30, 30);
    $farStepCode = Totp::calculateCode($secret, $baseTime + 120, 30);

    expect(Totp::verify($secret, $prevStepCode, 1, $baseTime, 30))->toBeTrue();
    expect(Totp::verifyCode($secret, $nextStepCode, 1, $baseTime, 30))->toBeTrue();
    expect(Totp::verify($secret, $farStepCode, 1, $baseTime, 30))->toBeFalse();
    expect(Totp::verify($secret, '12345', 1, $baseTime, 30))->toBeFalse();
});

test('invalid secret throws exception', function () {
    Totp::calculateCode('---', 1_700_000_000);
})->throws(InvalidArgumentException::class);

test('provisioning uri and secret display format', function () {
    $uri = Totp::getProvisioningUri('jbsw-y3dp-ehpk-3pxp', 'ops@example.com', 'SSHhh');
    expect($uri)->toStartWith('otpauth://totp/SSHhh:ops%40example.com?');
    expect($uri)->toContain('secret=JBSWY3DPEHPK3PXP');
    expect(Totp::formatSecretForDisplay('jbswy3dpehpk3pxp'))->toBe('JBSW Y3DP EHPK 3PXP');
});
