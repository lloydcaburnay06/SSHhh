<?php

declare(strict_types=1);

/**
 * Automatic log checker + error escalation. Run EVERY MINUTE from Windows Task Scheduler
 * (cli\install_log_watch_task.bat sets this up):
 *
 *   C:\xampp\php\php-win.exe C:\xampp\htdocs\deployer\cli\log_watch.php
 *
 * Every run:
 *   1. Advances pending error alerts: no decision 10 min after the push → SMS,
 *      no decision 10 min after the SMS → send to Claude Code.
 *   2. Checks each project's log (projects with "Check this log automatically" on),
 *      at most once every 10 minutes per project.
 *      - Error escalation ON  → a new error raises an alert (push notification first).
 *      - Error escalation OFF → a new error is sent to Claude Code right away.
 *
 * Options: --project=ID (check one project now), --force (ignore the 10-minute spacing)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Deployer\Bootstrap;

const CHECK_EVERY_MINUTES = 10;

// Never let two runs overlap (a slow SSH server can make a run take longer than a minute)
$lockFile = dirname(__DIR__) . '/storage/log_watch.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another log check is still running; skipping.\n";
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

// Task Scheduler discards output, so also keep a small run log in storage/log_watch.log
$runLog = dirname(__DIR__) . '/storage/log_watch.log';
if (is_file($runLog) && filesize($runLog) > 512 * 1024) {
    @file_put_contents($runLog, substr((string) file_get_contents($runLog), -256 * 1024));
}
$out = static function (string $line) use ($runLog): void {
    echo $line;
    @file_put_contents($runLog, date('Y-m-d ') . $line, FILE_APPEND);
};

$app = Bootstrap::init();
$alerts = $app->alerts();
$escalate = $alerts->enabled();
$errors = 0;

// 1) Escalation timers
foreach ($alerts->process() as $line) {
    $out(sprintf("[%s] %s\n", date('H:i:s'), $line));
}

// 2) Log checks
$ids = $app->claudeLinks()->autoWatchProjectIds();
if ($only !== null) {
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id === $only));
    $force = true;
}

$checked = 0;
foreach ($ids as $id) {
    if (!$force && !$app->claudeLinks()->isDueForCheck($id, CHECK_EVERY_MINUTES)) {
        continue;
    }
    $project = $app->projects()->find($id);
    if ($project === null) {
        continue;
    }
    $name = (string) $project['name'];
    if ($escalate && $alerts->hasPending($id)) {
        $app->claudeLinks()->recordWatch($id, 'Waiting: an error alert is open for this project.');
        continue; // one open alert per project; Proceed/auto-send will include anything new
    }
    $checked++;
    try {
        $res = $app->claudeSender()->check($id, $escalate ? $alerts->suppressedIssues($id) : [], $escalate);
        if ($res['action'] === 'alert') {
            $a = $alerts->create($project, (string) $res['file'], $res['issues']);
            $out(sprintf("[%s] %-25s %-8s %s | %s\n", date('H:i:s'), $name, 'ALERT', $a['summary'], $a['push']));
        } else {
            $out(sprintf("[%s] %-25s %-8s %s\n", date('H:i:s'), $name, strtoupper($res['action']), $res['message']));
        }
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

if ($checked > 0) {
    $out(sprintf("[%s] Checked %d project log(s), %d error(s). Escalation %s.\n", date('H:i:s'), $checked, $errors, $escalate ? 'on' : 'off'));
}
flock($lock, LOCK_UN);
exit($errors > 0 ? 1 : 0);
