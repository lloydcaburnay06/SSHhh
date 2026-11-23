<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Workspace;
use PDO;
use Throwable;

/**
 * One run of the automatic Laravel log checker (`php artisan sshhh:log-watch` every 5 minutes, or
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


    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private ClaudeLinkRepository $links,
        private ClaudeLogSender $claudeSender,
        private ErrorAlertManager $alerts,
        private Workspace $workspace,
    ) {
    }

    /**
     * One check. Inside a workspace ("Run check now") only that user's projects are checked.
     * Outside one (the scheduled task) pending alert timers are advanced first, then every
     * active user's projects are checked in that user's own workspace, each run recorded
     * separately so a user only ever sees their own history.
     *
     * @param callable(string): void|null $out receives each log line as it happens
     * @return array<string, mixed> the recorded run (or the totals of all of them)
     */
    public function run(string $trigger, ?int $onlyProject = null, bool $force = false, ?callable $out = null): array
    {
        if ($this->workspace->isSet()) {
            return $this->runForWorkspace($trigger, $onlyProject, $force, $out);
        }

        $say = static function (string $line) use ($out): void {
            if ($out !== null) {
                $out($line);
            }
        };
        $totals = ['id' => null, 'status' => 'ok', 'summary' => '', 'duration_ms' => 0, 'lines' => [], 'watched' => 0, 'checked' => 0, 'alerts' => 0, 'sent' => 0, 'errors' => 0];

        // 1) Escalation timers (no decision on a notification in time → Claude), for every user
        try {
            foreach ($this->alerts->process() as $line) {
                $say('⏱ ' . $line);
                if (str_contains($line, 'Sent ')) {
                    $totals['sent']++;
                }
            }
        } catch (Throwable $e) {
            $totals['errors']++;
            $say('❌ Alert timers failed: ' . $e->getMessage());
        }

        // 2) Each active user's projects, in that user's workspace
        if ($onlyProject !== null) {
            $stmt = $this->pdo->prepare('SELECT `user_id` FROM `projects` WHERE `id` = :id');
            $stmt->execute([':id' => $onlyProject]);
            $owners = array_filter([(int) $stmt->fetchColumn()]);
        } else {
            $owners = array_map('intval', $this->pdo->query(
                'SELECT DISTINCT p.`user_id` FROM `projects` p JOIN `users` u ON u.`id` = p.`user_id` WHERE u.`is_active` = 1 ORDER BY p.`user_id`'
            )->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($owners === []) {
            // Still record the run, so Settings → Log Checker can tell the task is alive
            $this->pdo->prepare("INSERT INTO `log_watch_runs` (`user_id`, `started_at`, `finished_at`, `trigger_type`, `status`, `summary`) VALUES (NULL, NOW(), NOW(), :t, 'ok', 'No projects yet.')")
                ->execute([':t' => $trigger]);
            $say('No projects yet.');

            return $totals;
        }

        foreach ($owners as $ownerId) {
            $r = $this->workspace->runAs($ownerId, fn () => $this->runForWorkspace($trigger, $onlyProject, $force, $out));
            foreach (['watched', 'checked', 'alerts', 'sent', 'errors'] as $k) {
                $totals[$k] += $r[$k];
            }
            $totals['lines'] = [...$totals['lines'], ...$r['lines']];
            if ($r['status'] === 'error') {
                $totals['status'] = 'error';
            }
        }

        return $totals;
    }

    /**
     * @param callable(string): void|null $out receives each log line as it happens
     * @return array<string, mixed> the recorded run
     */
    private function runForWorkspace(string $trigger, ?int $onlyProject, bool $force, ?callable $out): array
    {
        $started = microtime(true);
        $this->pdo->prepare("INSERT INTO `log_watch_runs` (`user_id`, `started_at`, `trigger_type`, `status`) VALUES (:uid, NOW(), :t, 'running')")
            ->execute([':uid' => $this->workspace->owner(), ':t' => $trigger]);
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
            $alerts = $this->alerts;
            $links = $this->links;
            $escalate = $alerts->enabled();

            // Log checks: every project in this workspace
            $projects = $this->projects->listAll();
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
                        ? $this->claudeSender->check($id, $escalate ? $alerts->suppressedIssues($id) : [], $escalate)
                        : $this->claudeSender->monitor($id, $alerts->suppressedIssues($id));
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
        $last = $this->pdo->query("SELECT *, TIMESTAMPDIFF(SECOND, `started_at`, NOW()) AS `seconds_ago` FROM `log_watch_runs` WHERE `trigger_type` = 'scheduled' ORDER BY `id` DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC) ?: null;

        $health = match (true) {
            $last === null                     => ['state' => 'never', 'text' => 'The scheduled checker has never run. Double-click cli\\install_log_watch_task.bat on the PC running SSHhh.'],
            (int) $last['seconds_ago'] > self::STALE_AFTER_SECONDS => ['state' => 'stopped', 'text' => 'The scheduled checker has not run for ' . self::ago((int) $last['seconds_ago']) . '. Check that the PC is on and signed in, XAMPP MySQL is running, and the "SSHhh Log Watch" task exists (re-run cli\\install_log_watch_task.bat).'],
            $last['status'] === 'error'        => ['state' => 'error', 'text' => 'The checker is running (last run ' . self::ago((int) $last['seconds_ago']) . ' ago), but the last run had errors. See the run history below.'],
            default                            => ['state' => 'ok', 'text' => 'The checker is running. Last run ' . self::ago((int) $last['seconds_ago']) . ' ago.'],
        };

        $limit = max(5, min(200, $runLimit));
        $uid = [':uid' => $this->workspace->owner()];
        $runsStmt = $this->pdo->prepare("SELECT `id`, `started_at`, `trigger_type`, `status`, `duration_ms`, `projects_watched`, `projects_checked`, `alerts`, `sent`, `errors`, `summary`, `details` FROM `log_watch_runs` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT {$limit}");
        $runsStmt->execute($uid);
        $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);

        $todayStmt = $this->pdo->prepare("SELECT COUNT(*) AS `runs`, COALESCE(SUM(`projects_checked`),0) AS `checks`, COALESCE(SUM(`alerts`),0) AS `alerts`, COALESCE(SUM(`sent`),0) AS `sent`, COALESCE(SUM(`errors`),0) AS `errors` FROM `log_watch_runs` WHERE `user_id` = :uid AND `started_at` > NOW() - INTERVAL 1 DAY");
        $todayStmt->execute($uid);
        $today = $todayStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'health'   => $health,
            'projects' => $this->links->watchOverview(),
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
}
