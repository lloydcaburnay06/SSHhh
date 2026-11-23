<?php

declare(strict_types=1);

use App\Services\ProjectRepository;
use App\Services\SshRunner;

function invokeSshRunnerPrivate(string $methodName, mixed ...$args): mixed
{
    $ref = new ReflectionClass(SshRunner::class);
    $instance = $ref->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(SshRunner::class, $methodName);

    return $method->invokeArgs($instance, $args);
}

test('parseEnvVars handles comments exports and quotes', function () {
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

    expect($parsed)->toBe([
        'APP_ENV' => 'production',
        'DB_PASS' => 's3cr3t=value',
        'PLAIN_KEY' => 'hello_world',
    ]);
});

test('sanitizeLogPath accepts safe paths and rejects traversal or injection', function () {
    expect(invokeSshRunnerPrivate('sanitizeLogPath', './storage/logs/laravel.log'))->toBe('storage/logs/laravel.log');
    expect(invokeSshRunnerPrivate('sanitizeLogPath', '../error_log'))->toBe('../error_log');
    expect(invokeSshRunnerPrivate('sanitizeLogPath', '../logs/app-error.log'))->toBe('../logs/app-error.log');

    expect(invokeSshRunnerPrivate('sanitizeLogPath', ''))->toBeNull();
    expect(invokeSshRunnerPrivate('sanitizeLogPath', '/var/log/syslog.log'))->toBeNull();
    expect(invokeSshRunnerPrivate('sanitizeLogPath', '~/app.log'))->toBeNull();
    expect(invokeSshRunnerPrivate('sanitizeLogPath', '../../etc/passwd'))->toBeNull();
    expect(invokeSshRunnerPrivate('sanitizeLogPath', 'storage/logs/app.log; id'))->toBeNull();
    expect(invokeSshRunnerPrivate('sanitizeLogPath', 'storage/logs/config.php'))->toBeNull();
});

test('parseSharedEntries filters unsafe entries', function () {
    $entries = invokeSshRunnerPrivate('parseSharedEntries', "/storage/, .env\n../escape, ~/home, public/uploads");

    expect($entries)->toBe(['storage', '.env', 'public/uploads']);
});

test('wrapRemoteCommand expands template vars and exports environment', function () {
    $wrapped = invokeSshRunnerPrivate(
        'wrapRemoteCommand',
        "echo {{DEPLOY_BRANCH}}\necho {{API_TOKEN}}",
        '/var/www/app',
        'main',
        'production',
        true,
        ['API_TOKEN' => 'tok-123']
    );

    expect($wrapped)->toContain("cd '/var/www/app'");
    expect($wrapped)->toContain("export DEPLOY_BRANCH='main' DEPLOY_ENV='production' API_TOKEN='tok-123'");
    expect($wrapped)->toContain("echo main\necho tok-123");
    expect($wrapped)->toContain("\nset -e\n");
});

test('parseServerPulseOutput parses metrics', function () {
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

    $pulse = invokeSshRunnerPrivate('parseServerPulseOutput', $sample, '/var/www/app', 'deploy');

    expect($pulse['os'])->toBe('Ubuntu 24.04 LTS');
    expect($pulse['cpu_load'])->toBe([0.15, 0.22, 0.18]);
    expect($pulse['ram']['total_mb'])->toBe(4096);
    expect($pulse['ram']['used_percent'])->toBe(25);
    expect($pulse['disk']['used_percent'])->toBe(24);
    expect($pulse['account']['disk_used'])->toBe('512M');
    expect($pulse['account']['ram_used_mb'])->toBe(256);
    expect($pulse['account']['process_count'])->toBe(3);
    expect($pulse['php_version'])->toBe('PHP 8.3.6');
    expect($pulse['services'])->toHaveCount(2);
});
