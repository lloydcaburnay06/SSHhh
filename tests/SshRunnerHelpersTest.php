<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\ProjectRepository;
use Deployer\SshRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SshRunnerHelpersTest extends TestCase
{
    private function invokePrivate(string $methodName, mixed ...$args): mixed
    {
        $ref = new ReflectionClass(SshRunner::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SshRunner::class, $methodName);

        return $method->invokeArgs($instance, $args);
    }

    public function testParseEnvVarsHandlesCommentsExportsAndQuotes(): void
    {
        $raw = implode("\n", [
            '# Database config',
            '',
            'export APP_ENV="production"',
            "DB_PASS='s3cr3t=value'",
            'PLAIN_KEY=hello_world',
            '123INVALID=skip_me',
            'NO_EQUALS_LINE',
        ]);

        $parsed = ProjectRepository::parseEnvVars($raw);

        self::assertSame([
            'APP_ENV' => 'production',
            'DB_PASS' => 's3cr3t=value',
            'PLAIN_KEY' => 'hello_world',
        ], $parsed);
    }

    public function testSanitizeLogPathAcceptsSafePathsAndRejectsTraversalOrInjection(): void
    {
        self::assertSame('storage/logs/laravel.log', $this->invokePrivate('sanitizeLogPath', './storage/logs/laravel.log'));
        self::assertSame('../error_log', $this->invokePrivate('sanitizeLogPath', '../error_log'));
        self::assertSame('../logs/app-error.log', $this->invokePrivate('sanitizeLogPath', '../logs/app-error.log'));

        self::assertNull($this->invokePrivate('sanitizeLogPath', ''));
        self::assertNull($this->invokePrivate('sanitizeLogPath', '/var/log/syslog.log'));
        self::assertNull($this->invokePrivate('sanitizeLogPath', '~/app.log'));
        self::assertNull($this->invokePrivate('sanitizeLogPath', '../../etc/passwd'));
        self::assertNull($this->invokePrivate('sanitizeLogPath', 'storage/logs/app.log; id'));
        self::assertNull($this->invokePrivate('sanitizeLogPath', 'storage/logs/config.php'));
    }

    public function testParseSharedEntriesFiltersUnsafeEntries(): void
    {
        $entries = $this->invokePrivate('parseSharedEntries', "/storage/, .env\n../escape, ~/home, public/uploads");

        self::assertSame(['storage', '.env', 'public/uploads'], $entries);
    }

    public function testWrapRemoteCommandExpandsTemplateVarsAndExportsEnvironment(): void
    {
        $wrapped = $this->invokePrivate(
            'wrapRemoteCommand',
            "echo {{DEPLOY_BRANCH}}\necho {{API_TOKEN}}",
            '/var/www/app',
            'main',
            'production',
            true,
            ['API_TOKEN' => 'tok-123']
        );

        self::assertStringContainsString("cd '/var/www/app'", $wrapped);
        self::assertStringContainsString("export DEPLOY_BRANCH='main' DEPLOY_ENV='production' API_TOKEN='tok-123'", $wrapped);
        self::assertStringContainsString("echo main\necho tok-123", $wrapped);
        self::assertStringContainsString("\nset -e\n", $wrapped);
    }

    public function testParseServerPulseOutputParsesMetrics(): void
    {
        $sample = implode("\n", [
            '===OS===',
            'Ubuntu 24.04 LTS',
            '===UPTIME===',
            ' 12:34:56 up 14 days,  2:10,  1 user,  load average: 0.15, 0.22, 0.18',
            '===MEMORY===',
            '              total        used        free      shared  buff/cache   available',
            'Mem:           4096        1024        2048         100        1024        2800',
            '===DISK===',
            'Filesystem      Size  Used Avail Use% Mounted on',
            '/dev/vda1        50G   12G   38G  24% /',
            '===ACCOUNT_DISK===',
            '512M    /var/www/app',
            '===ACCOUNT_RSS===',
            '262144',
            '===ACCOUNT_PROCS===',
            '3',
            '===SERVICES===',
            'nginx: active',
            'mysql: active',
            '===PHP===',
            'PHP 8.3.6',
        ]);

        $pulse = $this->invokePrivate('parseServerPulseOutput', $sample, '/var/www/app', 'deploy');

        self::assertSame('Ubuntu 24.04 LTS', $pulse['os']);
        self::assertSame([0.15, 0.22, 0.18], $pulse['cpu_load']);
        self::assertSame(4096, $pulse['ram']['total_mb']);
        self::assertSame(25, $pulse['ram']['used_percent']);
        self::assertSame(24, $pulse['disk']['used_percent']);
        self::assertSame('512M', $pulse['account']['disk_used']);
        self::assertSame(256, $pulse['account']['ram_used_mb']);
        self::assertSame(3, $pulse['account']['process_count']);
        self::assertSame('PHP 8.3.6', $pulse['php_version']);
        self::assertCount(2, $pulse['services']);
    }
}
