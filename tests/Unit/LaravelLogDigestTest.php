<?php

declare(strict_types=1);

use App\Services\LaravelLogDigest;

function digestEntry(string $time, string $level, string $msg, string $at = '/home/u/app/Http/Controllers/OrderController.php:42'): string
{
    return "[{$time}] production.{$level}: {$msg} {\"exception\":\"[object] (ErrorException(code: 0): {$msg} at {$at})\n"
        . "[stacktrace]\n#0 /home/u/vendor/laravel/framework/src/Foundation/Bootstrap/HandleExceptions.php(255): handleError()\n#1 {main}\n\"}\n";
}

test('groups repeated errors and skips info', function () {
    $raw = digestEntry('2026-11-20 10:00:00', 'ERROR', 'Undefined array key "total" for order 1001')
        . digestEntry('2026-11-20 10:05:00', 'ERROR', 'Undefined array key "total" for order 2002')
        . "[2026-11-20 10:06:00] production.INFO: User logged in\n"
        . digestEntry('2026-11-20 10:07:00', 'CRITICAL', 'SQLSTATE[HY000] [2002] Connection refused', '/home/u/app/Models/Order.php:10');

    $d = LaravelLogDigest::build($raw, ['Project' => 'Shop']);

    expect($d['format'])->toBe('laravel');
    expect($d['entries'])->toBe(4);
    expect($d['groups'])->toBe(2);
    expect($d['included'])->toBe(2);
    expect($d['text'])->toStartWith(LaravelLogDigest::MARKER . "\nProject: Shop\n");
    expect($d['text'])->toContain('CRITICAL ×1');
    expect($d['text'])->toContain('ERROR ×2 (first 2026-11-20 10:00:00, last 2026-11-20 10:05:00)');
    expect($d['text'])->not->toContain('User logged in');
    // Critical sorts before error
    expect(strpos($d['text'], 'CRITICAL ×1'))->toBeLessThan(strpos($d['text'], 'ERROR ×2'));
});

test('respects size limit', function () {
    $raw = '';
    for ($i = 0; $i < 400; $i++) {
        $raw .= digestEntry('2026-11-20 10:00:00', 'ERROR', "Distinct failure kind {$i}x" . str_repeat('y', $i % 7), "/home/u/app/F{$i}.php:1");
    }
    $d = LaravelLogDigest::build($raw, ['Project' => 'Big'], 8000);

    expect(strlen($d['text']))->toBeLessThanOrEqual(8000);
    expect($d['included'])->toBeGreaterThan(0);
    expect($d['included'])->toBeLessThan($d['groups']);
    expect($d['text'])->toContain('lower-priority issues omitted');
});

test('marks already reported issues and exposes fingerprints', function () {
    $raw = digestEntry('2026-11-20 10:00:00', 'ERROR', 'Undefined array key "total" for order 1')
        . "[2026-11-20 10:01:00] production.WARNING: Slow query 2100ms\n";

    $first = LaravelLogDigest::build($raw, ['Project' => 'Shop']);
    expect($first['issues'])->toHaveCount(2);
    expect($first['issues'][0]['known'])->toBeFalse();
    expect(LaravelLogDigest::isErrorLevel($first['issues'][0]['level']))->toBeTrue();
    expect(LaravelLogDigest::isErrorLevel('WARNING'))->toBeFalse();

    // Same error with a different order id is the same issue
    $again = digestEntry('2026-11-20 11:00:00', 'ERROR', 'Undefined array key "total" for order 99');
    $second = LaravelLogDigest::build($again, ['Project' => 'Shop'], 60000, 30, [$first['issues'][0]['hash'] => time()]);
    expect($second['issues'][0]['known'])->toBeTrue();
    expect($second['text'])->toContain('ALREADY REPORTED');
});

test('falls back to raw for non laravel logs', function () {
    $raw = "[20-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /home/u/public_html/index.php:3\n";
    $d = LaravelLogDigest::build($raw, ['Project' => 'Legacy']);

    expect($d['format'])->toBe('raw');
    expect($d['included'])->toBe(1);
    expect($d['text'])->toContain('Call to undefined function foo()');
});
