<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testBase32EncodeDecodeRoundTrip(): void
    {
        $samples = ['f', 'fo', 'foo', 'foob', 'fooba', 'foobar', '12345678901234567890'];

        foreach ($samples as $sample) {
            $encoded = Totp::base32Encode($sample);
            self::assertSame($sample, Totp::base32Decode($encoded));
        }

        self::assertSame('', Totp::base32Encode(''));
        self::assertSame('', Totp::base32Decode(''));
    }

    public function testCalculateCodeMatchesRfc6238Sha1Vector(): void
    {
        // RFC 6238 / RFC 4226 test secret: ASCII "12345678901234567890"
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        // At t=59s (counter = 1), 8-digit RFC 6238 SHA1 output is 94287082 -> 6-digit is 287082
        self::assertSame('287082', Totp::calculateCode($secret, 59, 30));
    }

    public function testVerifyAcceptsWindowDriftAndRejectsWrongCode(): void
    {
        $secret = Totp::generateSecret();
        $baseTime = 1_700_000_010;
        $prevStepCode = Totp::calculateCode($secret, $baseTime - 30, 30);
        $nextStepCode = Totp::calculateCode($secret, $baseTime + 30, 30);
        $farStepCode = Totp::calculateCode($secret, $baseTime + 120, 30);

        self::assertTrue(Totp::verify($secret, $prevStepCode, 1, $baseTime, 30));
        self::assertTrue(Totp::verifyCode($secret, $nextStepCode, 1, $baseTime, 30));
        self::assertFalse(Totp::verify($secret, $farStepCode, 1, $baseTime, 30));
        self::assertFalse(Totp::verify($secret, '12345', 1, $baseTime, 30));
    }

    public function testInvalidSecretThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::calculateCode('---', 1_700_000_000);
    }

    public function testProvisioningUriAndSecretDisplayFormat(): void
    {
        $uri = Totp::getProvisioningUri('jbsw-y3dp-ehpk-3pxp', 'ops@example.com', 'SSHhh');
        self::assertStringStartsWith('otpauth://totp/SSHhh:ops%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertSame('JBSW Y3DP EHPK 3PXP', Totp::formatSecretForDisplay('jbswy3dpehpk3pxp'));
    }
}
