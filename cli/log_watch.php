<?php

declare(strict_types=1);

/**
 * Automatic Laravel log checker. Runs EVERY 5 MINUTES from Windows Task Scheduler
 * (cli\install_log_watch_task.bat sets this up):
 *
 *   C:\xampp\php\php-win.exe C:\xampp\htdocs\deployer\cli\log_watch.php
 *
 * Each run (see src/LogWatcher.php):
 *   1. Sends alerts that got no decision on the push notification in time to Claude Code.
 *   2. Fetches every project's live Laravel log over SSH and checks it for new errors
 *      (linked projects → Claude flow; others → push notification only).
 *
 * Every run is recorded — in Settings → 🩺 Log Checker and in storage/log_watch.log —
 * including runs where nothing was due, so you can tell the checker is working.
 *
 * Options: --project=ID (check one project now), --force (check every project now)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$runLog = $root . '/storage/log_watch.log';
if (is_file($runLog) && filesize($runLog) > 1024 * 1024) {
    @file_put_contents($runLog, substr((string) file_get_contents($runLog), -512 * 1024));
}
$stamp = static fn(): string => date('Y-m-d H:i:s');
$out = static function (string $line) use ($runLog, $stamp): void {
    $text = '[' . $stamp() . '] ' . $line . "\n";
    echo $text;
    @file_put_contents($runLog, $text, FILE_APPEND);
};

// Never let two runs overlap (many projects or a slow SSH server can make a run take a while)
$lock = fopen($root . '/storage/log_watch.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $out('Skipped: the previous run is still working.');
    exit(0);
}

$only = null;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--project=(\d+)$/', $arg, $m)) {
        $only = (int) $m[1];
    } elseif ($arg === '--force') {
        $force = true;
    }
}

try {
    require_once $root . '/vendor/autoload.php';
    $app = Deployer\Bootstrap::init();
} catch (Throwable $e) {
    // Can't reach the database (e.g. XAMPP MySQL is stopped): the file log is the only record
    $out('❌ Checker could not start: ' . $e->getMessage());
    exit(2);
}

$out('— Run started' . ($only !== null ? " (project #{$only})" : ($force ? ' (forced)' : '')));
$result = $app->logWatcher()->run(($only !== null || $force) ? 'manual' : 'scheduled', $only, $force, $out);

flock($lock, LOCK_UN);
exit($result['errors'] > 0 ? 1 : 0);
