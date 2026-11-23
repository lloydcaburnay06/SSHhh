<?php

/*
 * Compatibility shim: the "SSHhh Log Watch" task created before the Laravel port runs
 * this file. It forwards to `php artisan sshhh:log-watch` (same options: --project=ID, --force).
 * Re-running cli\install_log_watch_task.bat points the task at artisan directly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$_SERVER['argv'] = array_merge([dirname(__DIR__).'/artisan', 'sshhh:log-watch'], array_slice($argv, 1));
$_SERVER['argc'] = count($_SERVER['argv']);
$argv = $_SERVER['argv'];

require dirname(__DIR__).'/artisan';
