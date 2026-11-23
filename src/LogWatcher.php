<?php

declare(strict_types=1);

namespace Deployer;

use PDO;
use Throwable;

/**
 * One run of the automatic Laravel log checker (cli/log_watch.php every 5 minutes, or
 * "Run check now" in Settings → Log Checker). Every project's live Laravel log is fetched
 * over SSH every 5 minutes:
 *   - projects linked to a Claude Code task (automatic sending on) → alert / send to Claude
 *   - every other project → push notification for new errors only
 * Every run is recorded in `log_watch_runs`, so you can see the checker is alive.
 */
final class LogWatcher
{
    public const CHECK_EVERY_MINUTES = 5;
    /** The scheduled task runs every 5 minutes; warn when no run for two intervals plus slack. */
    private const STALE_AFTER_SECONDS = 11 * 60;
    private const KEEP_DAYS = 7;

    private bool $tableReady = false;

    public function __construct(private Bootstrap $app, private PDO $pdo)
    {
    }

    /**
     * @param callable(string): void|null $out receives each log line as it happens
     * @return array<string, mixed> the recorded run
     */
    public function run(string $trigger, ?int $onlyProject = null, bool $force = false, ?callable $out = null): array
    {
        $this->ensureTable();
        $started = microtime(true);
        $this->pdo->prepare("INSERT INTO `log_watch_runs` (`started_at`, `trigger_type`, `status`) VALUES (NOW(), :t, 'running')")
            ->execute([':t' => $trigger]);
        $runId = (int) $this->pdo->lastInsertId();

        $lines = [];
        $say = static function (string $line) use (&$lines, $out): void {
            $lines[] = $line;
            if ($out !== null) {
                $out($line);
            }
        };
        $stats = ['watched' => 0, 'checked' => 0, 'alerts' => 0, 'sent' => 0, 'errors' => 0];
        $status = 'ok';

        try {
            $alerts = $this->app->alerts();
            $links = $this->app->claudeLinks();
            $escalate = $alerts->enabled();

            // 1) Escalation timers (no decision on a notification in time → Claude)
            foreach ($alerts->process() as $line) {
                $say('⏱ ' . $line);
                if (str_contains($line, 'Sent ')) {
                    $stats['sent']++;
                }
            }

            // 2) Log checks: every project
            $projects = $this->app->projects()->listAll();
            if ($onlyProject !== null) {
                $projects = array_values(array_filter($projects, static fn(array $p): bool => (int) $p['id'] === $onlyProject));
                $force = true;
            }
            $stats['watched'] = count($projects);
            if ($projects === []) {
                $say('No projects yet.');
            }

            foreach ($projects as $project) {
                $id = (int) $project['id'];
                $name = (string) $project['name'];

                if (!$force && !$links->isDueForCheck($id, self::CHECK_EVERY_MINUTES)) {
                    $say("{$name}: not due yet (next check in " . $this->minutesUntilDue($id) . ' min)');
                    continue;
                }
                $toClaude = $links->sendsToClaude($id);
                if ($toClaude && $escalate && $alerts->hasPending($id)) {
                    $links->recordWatch($id, 'Waiting: an error alert is open for this project.');
                    $say("{$name}: waiting for your decision on an open alert");
                    continue;
                }

                $stats['checked']++;
                $t = microtime(true);
                try {
                    $res = $toClaude
                        ? $this->app->claudeSender()->check($id, $escalate ? $alerts->suppressedIssues($id) : [], $escalate)
                        : $this->app->claudeSender()->monitor($id, $alerts->suppressedIssues($id));
                    $ms = (int) round((microtime(true) - $t) * 1000);
                    if ($res['action'] === 'alert') {
                        $a = $alerts->create($project, (string) $res['file'], $res['issues']);
                        $stats['alerts']++;
                        $say("{$name}: 🔔 ALERT — {$a['summary']} ({$a['push']}) [{$ms} ms]");
                    } elseif ($res['action'] === 'notify') {
                        $a = $alerts->notifyOnly($project, (string) $res['file'], $res['issues']);
                        $stats['alerts']++;
                        $say("{$name}: 🔔 NOTIFIED — {$a['summary']} ({$a['push']}) [{$ms} ms]");
                    } elseif ($res['action'] === 'sent') {
                        $stats['sent']++;
                        $say("{$name}: 🤖 SENT — {$res['message']} [{$ms} ms]");
                    } else {
                        $say("{$name}: ✅ " . strtoupper($res['action']) . " — {$res['message']} [{$ms} ms]");
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $status = 'error';
                    try {
                        $links->recordWatch($id, 'Error: ' . $e->getMessage());
                    } catch (Throwable) {
                    }
                    $say("{$name}: ❌ ERROR — " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $status = 'error';
            $say('❌ Checker failed: ' . $e->getMessage());
        }

        $summary = "Watched {$stats['watched']} · checked {$stats['checked']} · {$stats['alerts']} alert(s) · {$stats['sent']} sent · {$stats['errors']} error(s)";
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->pdo->prepare(<<<'SQL'
            UPDATE `log_watch_runs` SET `finished_at` = NOW(), `duration_ms` = :d, `status` = :s,
                `projects_watched` = :w, `projects_checked` = :c, `alerts` = :a, `sent` = :sent, `errors` = :e,
                `summary` = :sum, `details` = :det
            WHERE `id` = :id
        SQL)->execute([
            ':d' => $durationMs, ':s' => $status, ':w' => $stats['watched'], ':c' => $stats['checked'],
            ':a' => $stats['alerts'], ':sent' => $stats['sent'], ':e' => $stats['errors'],
            ':sum' => $summary, ':det' => implode("\n", $lines), ':id' => $runId,
        ]);
        $say($summary . " ({$durationMs} ms)");

        // Keep a week of history (about 10,000 runs at one per minute)
        if (random_int(1, 60) === 1) {
            $this->pdo->exec('DELETE FROM `log_watch_runs` WHERE `started_at` < NOW() - INTERVAL ' . self::KEEP_DAYS . ' DAY');
        }

        return ['id' => $runId, 'status' => $status, 'summary' => $summary, 'duration_ms' => $durationMs, 'lines' => $lines] + $stats;
    }

    /**
     * Everything the "Log Checker" tab shows.
     *
     * @return array<string, mixed>
     */
    public function overview(int $runLimit = 40): array
    {
        $this->ensureTable();
        $last = $this->pdo->query("SELECT *, TIMESTAMPDIFF(SECOND, `started_at`, NOW()) AS `seconds_ago` FROM `log_watch_runs` WHERE `trigger_type` = 'scheduled' ORDER BY `id` DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC) ?: null;

        $health = match (true) {
            $last === null                     => ['state' => 'never', 'text' => 'The scheduled checker has never run. Double-click cli\\install_log_watch_task.bat on the PC running SSHhh.'],
            (int) $last['seconds_ago'] > self::STALE_AFTER_SECONDS => ['state' => 'stopped', 'text' => 'The scheduled checker has not run for ' . self::ago((int) $last['seconds_ago']) . '. Check that the PC is on and signed in, XAMPP MySQL is running, and the "SSHhh Log Watch" task exists (re-run cli\\install_log_watch_task.bat).'],
            $last['status'] === 'error'        => ['state' => 'error', 'text' => 'The checker is running (last run ' . self::ago((int) $last['seconds_ago']) . ' ago), but the last run had errors. See the run history below.'],
            default                            => ['state' => 'ok', 'text' => 'The checker is running. Last run ' . self::ago((int) $last['seconds_ago']) . ' ago.'],
        };

        $limit = max(5, min(200, $runLimit));
        $runs = $this->pdo->query("SELECT `id`, `started_at`, `trigger_type`, `status`, `duration_ms`, `projects_watched`, `projects_checked`, `alerts`, `sent`, `errors`, `summary`, `details` FROM `log_watch_runs` ORDER BY `id` DESC LIMIT {$limit}")
            ->fetchAll(PDO::FETCH_ASSOC);

        $today = $this->pdo->query("SELECT COUNT(*) AS `runs`, COALESCE(SUM(`projects_checked`),0) AS `checks`, COALESCE(SUM(`alerts`),0) AS `alerts`, COALESCE(SUM(`sent`),0) AS `sent`, COALESCE(SUM(`errors`),0) AS `errors` FROM `log_watch_runs` WHERE `started_at` > NOW() - INTERVAL 1 DAY")
            ->fetch(PDO::FETCH_ASSOC);

        return [
            'health'   => $health,
            'projects' => $this->app->claudeLinks()->watchOverview(),
            'last_24h' => array_map('intval', $today ?: []),
            'runs'     => $runs,
        ];
    }

    private function minutesUntilDue(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, `last_watch_at`, NOW()) FROM `project_claude_links` WHERE `project_id` = :id');
        $stmt->execute([':id' => $projectId]);
        $since = (int) $stmt->fetchColumn();
        return max(1, (int) ceil((self::CHECK_EVERY_MINUTES * 60 - 20 - $since) / 60));
    }

    private static function ago(int $s): string
    {
        return match (true) {
            $s < 90    => $s . ' seconds',
            $s < 5400  => round($s / 60) . ' minutes',
            $s < 172800 => round($s / 3600) . ' hours',
            default    => round($s / 86400) . ' days',
        };
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `log_watch_runs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `started_at` DATETIME NOT NULL,
                `finished_at` DATETIME NULL,
                `trigger_type` VARCHAR(12) NOT NULL DEFAULT 'scheduled',
                `status` VARCHAR(10) NOT NULL DEFAULT 'running',
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `projects_watched` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `projects_checked` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `alerts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `sent` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `errors` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `summary` VARCHAR(255) NULL,
                `details` MEDIUMTEXT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_started` (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->tableReady = true;
    }
}
