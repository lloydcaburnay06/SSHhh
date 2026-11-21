<?php

declare(strict_types=1);

/**
 * Automatic log checker. Run every 10 minutes from Windows Task Scheduler:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\deployer\cli\log_watch.php
 *
 * For every project with "Check this log automatically" turned on in its Claude link,
 * reads the watched Laravel log over SSH and, when it contains an error that was not
 * already reported in the last 24 hours, sends it to the project's Claude Code task
 * (same as the 🤖 Send to Claude button). Optional: --project=ID to check one project.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Deployer\Bootstrap;

// Never let two runs overlap (a slow SSH server can make a run take longer than 10 minutes)
$lockFile = dirname(__DIR__) . '/storage/log_watch.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another log check is still running; skipping.\n";
    exit(0);
}

$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--project=(\d+)$/', $arg, $m)) {
        $only = (int) $m[1];
    }
}

$app = Bootstrap::init();
$ids = $app->claudeLinks()->autoWatchProjectIds();
if ($only !== null) {
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id === $only));
}

// Task Scheduler discards output, so also keep a small run log in storage/log_watch.log
$runLog = dirname(__DIR__) . '/storage/log_watch.log';
if (is_file($runLog) && filesize($runLog) > 512 * 1024) {
    @file_put_contents($runLog, substr((string) file_get_contents($runLog), -256 * 1024));
}
$out = static function (string $line) use ($runLog): void {
    echo $line;
    @file_put_contents($runLog, date('Y-m-d ') . $line, FILE_APPEND);
};

$sent = 0;
$errors = 0;
foreach ($ids as $id) {
    $project = $app->projects()->find($id);
    if ($project === null) {
        continue;
    }
    $name = (string) $project['name'];
    try {
        $res = $app->claudeSender()->check($id);
        if ($res['action'] === 'sent') {
            $sent++;
        }
        $out(sprintf("[%s] %-25s %-8s %s\n", date('H:i:s'), $name, strtoupper($res['action']), $res['message']));
    } catch (Throwable $e) {
        $errors++;
        $msg = $e->getMessage();
        try {
            $app->claudeLinks()->recordWatch($id, 'Error: ' . $msg);
        } catch (Throwable) {
        }
        $out(sprintf("[%s] %-25s %-8s %s\n", date('H:i:s'), $name, 'ERROR', $msg));
    }
}

$out(sprintf("[%s] Checked %d project(s): %d sent to Claude, %d error(s).\n", date('H:i:s'), count($ids), $sent, $errors));
flock($lock, LOCK_UN);
exit($errors > 0 ? 1 : 0);
