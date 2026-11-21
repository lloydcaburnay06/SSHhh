<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\AlertChannels;
use PHPUnit\Framework\TestCase;

final class AlertChannelsTest extends TestCase
{
    public function testSmsTextIsPlainAsciiAndAtMost160Chars(): void
    {
        $long = "**Bansay:** “Undefined array key” in OrderController — see https://example.com/x 🚨 " . str_repeat('very long detail ', 20);
        $sms = AlertChannels::toSms($long);

        self::assertLessThanOrEqual(160, strlen($sms));
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $sms);
        self::assertStringNotContainsString('http', $sms);
        self::assertStringNotContainsString('**', $sms);
        self::assertStringStartsWith('Bansay: "Undefined array key" in OrderController - see', $sms);
        self::assertStringEndsWith('...', $sms);
    }

    public function testFallbackSummaryNamesErrorAndLocation(): void
    {
        $s = AlertChannels::fallbackSummary('Bansay', [
            '[2026-11-21 10:00:00] production.ERROR: Undefined array key "total" {"exception":"[object] (ErrorException at /home/u/app/Http/Controllers/OrderController.php:42)"}',
        ], 3);

        self::assertSame('Bansay: ERROR: Undefined array key "total" (OrderController.php:42) +2 more. Open SSHhh to approve or ignore.', $s);
        self::assertLessThanOrEqual(160, strlen($s));
    }

    public function testNormalizesPhilippineMobileNumbers(): void
    {
        self::assertSame('639171234567', AlertChannels::normalizeNumber('0917 123 4567'));
        self::assertSame('639171234567', AlertChannels::normalizeNumber('+63 917-123-4567'));
        self::assertSame('639171234567', AlertChannels::normalizeNumber('9171234567'));
        self::assertSame('', AlertChannels::normalizeNumber('02 8123 4567'));
    }

    public function testCleansPastedApiTokens(): void
    {
        self::assertSame('49|LNFe8abc', AlertChannels::cleanToken("  Bearer 49|LNFe8abc \n"));
        self::assertSame('49|LNFe8abc', AlertChannels::cleanToken('"49|LNFe8 abc"'));
        self::assertSame('AIzaSyX', AlertChannels::cleanToken('AIzaSyX'));
    }
}
