<?php

declare(strict_types=1);

use App\Services\AlertChannels;

test('summary is plain ascii and at most 160 chars', function () {
    $long = "**Bansay:** “Undefined array key” in OrderController — see https://example.com/x 🚨 " . str_repeat('very long detail ', 20);
    $sms = AlertChannels::toShortText($long);

    expect(strlen($sms))->toBeLessThanOrEqual(160);
    expect($sms)->toMatch('/^[\x20-\x7E]+$/');
    expect($sms)->not->toContain('http');
    expect($sms)->not->toContain('**');
    expect($sms)->toStartWith('Bansay: "Undefined array key" in OrderController - see');
    expect($sms)->toEndWith('...');
});

test('fallback summary names error and location', function () {
    $s = AlertChannels::fallbackSummary('Bansay', [
        '[2026-11-21 10:00:00] production.ERROR: Undefined array key "total" {"exception":"[object] (ErrorException at /home/u/app/Http/Controllers/OrderController.php:42)"}',
    ], 3);

    expect($s)->toBe('Bansay: ERROR: Undefined array key "total" (OrderController.php:42) +2 more. Open SSHhh to approve or ignore.');
    expect(strlen($s))->toBeLessThanOrEqual(160);
});

test('cleans pasted api tokens', function () {
    expect(AlertChannels::cleanToken("  Bearer 49|LNFe8abc \n"))->toBe('49|LNFe8abc');
    expect(AlertChannels::cleanToken('"49|LNFe8 abc"'))->toBe('49|LNFe8abc');
    expect(AlertChannels::cleanToken('AIzaSyX'))->toBe('AIzaSyX');
});
