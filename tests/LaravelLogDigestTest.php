<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\LaravelLogDigest;
use PHPUnit\Framework\TestCase;

final class LaravelLogDigestTest extends TestCase
{
    private function entry(string $time, string $level, string $msg, string $at = '/home/u/app/Http/Controllers/OrderController.php:42'): string
    {
        return "[{$time}] production.{$level}: {$msg} {\"exception\":\"[object] (ErrorException(code: 0): {$msg} at {$at})\n"
            . "[stacktrace]\n#0 /home/u/vendor/laravel/framework/src/Foundation/Bootstrap/HandleExceptions.php(255): handleError()\n#1 {main}\n\"}\n";
    }

    public function testGroupsRepeatedErrorsAndSkipsInfo(): void
    {
        $raw = $this->entry('2026-11-20 10:00:00', 'ERROR', 'Undefined array key "total" for order 1001')
            . $this->entry('2026-11-20 10:05:00', 'ERROR', 'Undefined array key "total" for order 2002')
            . "[2026-11-20 10:06:00] production.INFO: User logged in\n"
            . $this->entry('2026-11-20 10:07:00', 'CRITICAL', 'SQLSTATE[HY000] [2002] Connection refused', '/home/u/app/Models/Order.php:10');

        $d = LaravelLogDigest::build($raw, ['Project' => 'Shop']);

        $this->assertSame('laravel', $d['format']);
        $this->assertSame(4, $d['entries']);
        $this->assertSame(2, $d['groups']);
        $this->assertSame(2, $d['included']);
        $this->assertStringStartsWith(LaravelLogDigest::MARKER . "\nProject: Shop\n", $d['text']);
        $this->assertStringContainsString('CRITICAL ×1', $d['text']);
        $this->assertStringContainsString('ERROR ×2 (first 2026-11-20 10:00:00, last 2026-11-20 10:05:00)', $d['text']);
        $this->assertStringNotContainsString('User logged in', $d['text']);
        // Critical sorts before error
        $this->assertLessThan(strpos($d['text'], 'ERROR ×2'), strpos($d['text'], 'CRITICAL ×1'));
    }

    public function testRespectsSizeLimit(): void
    {
        $raw = '';
        for ($i = 0; $i < 400; $i++) {
            $raw .= $this->entry('2026-11-20 10:00:00', 'ERROR', "Distinct failure kind {$i}x" . str_repeat('y', $i % 7), "/home/u/app/F{$i}.php:1");
        }
        $d = LaravelLogDigest::build($raw, ['Project' => 'Big'], 8000);

        $this->assertLessThanOrEqual(8000, strlen($d['text']));
        $this->assertGreaterThan(0, $d['included']);
        $this->assertLessThan($d['groups'], $d['included']);
        $this->assertStringContainsString('lower-priority issues omitted', $d['text']);
    }

    public function testMarksAlreadyReportedIssuesAndExposesFingerprints(): void
    {
        $raw = $this->entry('2026-11-20 10:00:00', 'ERROR', 'Undefined array key "total" for order 1')
            . "[2026-11-20 10:01:00] production.WARNING: Slow query 2100ms\n";

        $first = LaravelLogDigest::build($raw, ['Project' => 'Shop']);
        $this->assertCount(2, $first['issues']);
        $this->assertFalse($first['issues'][0]['known']);
        $this->assertTrue(LaravelLogDigest::isErrorLevel($first['issues'][0]['level']));
        $this->assertFalse(LaravelLogDigest::isErrorLevel('WARNING'));

        // Same error with a different order id is the same issue
        $again = $this->entry('2026-11-20 11:00:00', 'ERROR', 'Undefined array key "total" for order 99');
        $second = LaravelLogDigest::build($again, ['Project' => 'Shop'], 60000, 30, [$first['issues'][0]['hash'] => time()]);
        $this->assertTrue($second['issues'][0]['known']);
        $this->assertStringContainsString('ALREADY REPORTED', $second['text']);
    }

    public function testFallsBackToRawForNonLaravelLogs(): void
    {
        $raw = "[20-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /home/u/public_html/index.php:3\n";
        $d = LaravelLogDigest::build($raw, ['Project' => 'Legacy']);

        $this->assertSame('raw', $d['format']);
        $this->assertSame(1, $d['included']);
        $this->assertStringContainsString('Call to undefined function foo()', $d['text']);
    }
}
