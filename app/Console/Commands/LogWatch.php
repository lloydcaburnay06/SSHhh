<?php

namespace App\Console\Commands;

use App\Services\LogWatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Automatic Laravel log checker. Windows Task Scheduler runs it every 5 minutes
 * (cli\install_log_watch_task.bat sets that up). Each run:
 *   1. sends alerts that got no decision on the push notification in time to Claude Code;
 *   2. fetches every project's live Laravel log over SSH and checks it for new errors.
 * Every run is recorded in Settings → Log Checker and in storage/log_watch.log.
 */
class LogWatch extends Command
{
    protected $signature = 'sshhh:log-watch
                            {--project= : Check only this project ID now}
                            {--force : Check every project now, even when not due}';

    protected $description = 'Check every project\'s live Laravel log for new errors and escalate them';

    public function handle(LogWatcher $watcher): int
    {
        $runLog = (string) config('sshhh.log_watch.run_log');
        if (is_file($runLog) && filesize($runLog) > 1024 * 1024) {
            @file_put_contents($runLog, substr((string) file_get_contents($runLog), -512 * 1024));
        }
        $out = function (string $line) use ($runLog): void {
            $text = '['.date('Y-m-d H:i:s').'] '.$line;
            $this->line($text);
            @file_put_contents($runLog, $text."\n", FILE_APPEND);
        };

        // Never let two runs overlap (a slow SSH server can make one run take a while)
        $lock = fopen((string) config('sshhh.log_watch.lock_file'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $out('Skipped: the previous run is still working.');

            return self::SUCCESS;
        }

        $only = $this->option('project') !== null ? (int) $this->option('project') : null;
        $force = (bool) $this->option('force');

        try {
            $out('— Run started'.($only !== null ? " (project #{$only})" : ($force ? ' (forced)' : '')));
            $result = $watcher->run(($only !== null || $force) ? 'manual' : 'scheduled', $only, $force, $out);
        } catch (Throwable $e) {
            // e.g. XAMPP MySQL is stopped: the file log is the only record
            $out('❌ Checker could not start: '.$e->getMessage());

            return 2;
        } finally {
            flock($lock, LOCK_UN);
        }

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
