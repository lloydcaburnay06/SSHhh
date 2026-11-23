<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * "Send to Claude": snapshot a remote Laravel log → build a de-duplicated, redacted
 * report → start the project's Claude Code scheduled task → remove the sent part of the log.
 *
 * send()  — manual button in the log viewer (errors are reported to the user).
 * check() — automatic checker (cli/log_watch.php). Only starts a Claude session when the
 *           log has an error-level issue that was not already reported in the last 24 hours.
 */
final class ClaudeLogSender
{
    private const COOLDOWN_SECONDS = 30;

    public function __construct(
        private SshRunner $runner,
        private ProjectRepository $projects,
        private ClaudeLinkRepository $links,
        private ClaudeRoutineClient $client,
        private EventLogger $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function send(int $projectId, string $file): array
    {
        $link = $this->links->getInternal($projectId);
        if ($link === null) {
            throw new RuntimeException('This project is not linked to Claude Code yet. Open "Claude link" in the log viewer and add the scheduled task\'s fire URL and token.');
        }
        if ($this->inCooldown($link)) {
            throw new RuntimeException('A log was just sent for this project. Wait a few seconds before sending again.');
        }

        $bundle = $this->snapshot($projectId, $file);
        if (trim(self::content($bundle)) === '') {
            throw new RuntimeException(self::label($bundle) . ' — nothing to send (empty).');
        }

        $result = $this->deliver($projectId, $link, $bundle, false);
        if ($result === null) {
            throw new RuntimeException('No warnings or errors found in the log (only info/debug lines). Nothing was sent.');
        }
        return $result;
    }

    /**
     * One automatic check. Never throws for "nothing to do"; returns what happened.
     *
     * With $escalate (error escalation on) new errors are NOT sent: the result has action
     * "alert" with the new issues so ErrorAlertManager can ask the user first.
     *
     * @param array<string, int> $suppress extra issue hashes to treat as already handled (alerted/ignored)
     * @return array{action: string, message: string, session_url?: string, file?: string, issues?: list<array<string, mixed>>}
     */
    public function check(int $projectId, array $suppress = [], bool $escalate = false): array
    {
        $link = $this->links->getInternal($projectId);
        if ($link === null) {
            return $this->watchResult($projectId, 'skipped', 'Not linked to Claude Code.');
        }
        if ($this->inCooldown($link)) {
            return $this->watchResult($projectId, 'skipped', 'Sent moments ago; will check next run.');
        }

        $public = $this->links->getPublic($this->projects->find($projectId) ?? ['id' => $projectId]);
        $bundle = $this->snapshot($projectId, (string) $public['watch_file']);
        $read = 'Read ' . self::filesReport($bundle) . '. ';

        if (trim(self::content($bundle)) === '') {
            return $this->watchResult($projectId, 'clean', $read . 'No log lines to check.');
        }

        if ($escalate) {
            $digest = LaravelLogDigest::build(self::content($bundle), [], ClaudeRoutineClient::MAX_TEXT - 4000, 30, $this->links->recentIssues($projectId) + $suppress);
            $new = array_values(array_filter(
                $digest['issues'],
                static fn(array $i): bool => !$i['known'] && LaravelLogDigest::isErrorLevel($i['level'])
            ));
            if ($new === []) {
                return $this->watchResult($projectId, 'clean', $read . 'No new errors (only info/warnings, or errors already reported/alerted in the last 24h).');
            }
            $this->watchResult($projectId, 'alert', $read . count($new) . ' new error(s) found; asking you first (push notification, then Claude).');
            return ['action' => 'alert', 'message' => $read . count($new) . ' new error(s)', 'file' => (string) $public['watch_file'], 'issues' => $new];
        }

        $result = $this->deliver($projectId, $link, $bundle, true);
        if ($result === null) {
            return $this->watchResult($projectId, 'clean', $read . 'No new errors (only info/warnings, or errors already reported in the last 24h).');
        }
        return $this->watchResult($projectId, 'sent', $read . $result['message'], $result['session_url']);
    }
    /**
     * Monitor-only check for projects that don't send to Claude (no Claude link, or automatic
     * sending turned off). Reads the logs over SSH and reports new error-level issues;
     * never sends or clears anything.
     *
     * @param array<string, int> $suppress issue hashes already notified in the last 24h
     * @return array{action: string, message: string, file?: string, issues?: list<array<string, mixed>>}
     */
    public function monitor(int $projectId, array $suppress = []): array
    {
        $file = $this->links->watchFileFor($projectId);
        $bundle = $this->snapshot($projectId, $file);
        $read = 'Read ' . self::filesReport($bundle) . '. ';
        if (trim(self::content($bundle)) === '') {
            return $this->watchResult($projectId, 'clean', $read . 'No log lines to check.');
        }

        $digest = LaravelLogDigest::build(self::content($bundle), [], ClaudeRoutineClient::MAX_TEXT - 4000, 30, $suppress);
        $new = array_values(array_filter(
            $digest['issues'],
            static fn(array $i): bool => !$i['known'] && LaravelLogDigest::isErrorLevel($i['level'])
        ));
        if ($new === []) {
            return $this->watchResult($projectId, 'clean', $read . 'No new errors.');
        }
        $this->watchResult($projectId, 'notify', $read . count($new) . ' new error(s) — notification sent (not linked to Claude).');
        return ['action' => 'notify', 'message' => $read . count($new) . ' new error(s)', 'file' => $file, 'issues' => $new];
    }

    /**
     * @param array<string, mixed> $link
     * @param array{project: array<string, mixed>, files: list<array{file: string, size: int, content: string, truncated: bool}>} $bundle
     * @return array<string, mixed>|null null when there is nothing worth sending
     */
    private function deliver(int $projectId, array $link, array $bundle, bool $auto): ?array
    {
        $started = microtime(true);
        $project = $bundle['project'];
        $sizes = array_sum(array_column($bundle['files'], 'size'));
        $truncated = in_array(true, array_column($bundle['files'], 'truncated'), true);
        $known = $this->links->recentIssues($projectId);

        $mergeBranch = $link['merge_branch'] !== '' ? $link['merge_branch'] : (string) ($project['repo_branch'] ?? 'main');
        $digest = LaravelLogDigest::build(self::content($bundle), [
            'Project'             => (string) $project['name'],
            'Environment'         => (string) ($project['environment'] ?? 'production'),
            'Deploy branch'       => (string) ($project['repo_branch'] ?? 'main'),
            'Merge target branch' => $mergeBranch,
            'Log file' . (count($bundle['files']) > 1 ? 's' : '') => self::label($bundle),
            'Snapshot'            => number_format($sizes) . ' bytes' . ($truncated ? ' (only the newest part was analysed)' : ''),
            'Sent by'             => $auto ? 'automatic log checker' : 'manual send',
            'Sent at'             => date('c'),
        ], ClaudeRoutineClient::MAX_TEXT - 4000, 30, $known);

        if ($digest['included'] === 0) {
            return null;
        }
        if ($auto) {
            $newErrors = array_filter(
                $digest['issues'],
                static fn(array $i): bool => !$i['known'] && LaravelLogDigest::isErrorLevel($i['level'])
            );
            if ($newErrors === []) {
                return null; // leave the log untouched; nothing new for Claude
            }
        }

        $text = SecretRedactor::redact($digest['text']);
        $fire = $this->client->fire($link['fire_url'], $link['token'], $text);
        $this->links->markSent($projectId, $fire['session_url']);
        $this->links->rememberIssues($projectId, array_column($digest['issues'], 'hash'));

        // Only clear after Claude Code confirmed it received the report.
        // Each file is trimmed by exactly the bytes that were read from it.
        $clear = ['status' => 'kept', 'kept_bytes' => 0];
        $clearError = null;
        if ($link['clear_after_send']) {
            $statuses = [];
            foreach ($bundle['files'] as $f) {
                if ($f['size'] <= 0) {
                    continue;
                }
                try {
                    $r = $this->runner->consumeRemoteLog($projectId, $f['file'], $f['size']);
                    $statuses[] = $r['status'];
                    $clear['kept_bytes'] += $r['kept_bytes'];
                } catch (Throwable $e) {
                    $clearError = ($clearError ? $clearError . '; ' : '') . basename($f['file']) . ': ' . $e->getMessage();
                }
            }
            $clear['status'] = in_array('changed', $statuses, true) ? 'changed' : ($statuses !== [] ? 'cleared' : 'kept');
        }

        $clearNote = match (true) {
            $clearError !== null           => "Sent, but the log could not be cleared: {$clearError}",
            $clear['status'] === 'cleared' => 'Sent lines were removed from the log' . ($clear['kept_bytes'] > 0 ? ' (' . number_format($clear['kept_bytes']) . ' bytes of newer entries kept).' : '.'),
            $clear['status'] === 'changed' => 'The log changed on the server during sending (rotated or cleared), so it was left as is.',
            default                        => 'Log was kept (clear after send is off).',
        };

        $knownCount = count(array_filter($digest['issues'], static fn(array $i): bool => $i['known']));
        $issueText = "{$digest['included']} issue" . ($digest['included'] === 1 ? '' : 's') . ($knownCount > 0 ? " ({$knownCount} already reported)" : '');

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->log([
            'project_id'   => $projectId,
            'project_name' => (string) $project['name'],
            'category'     => 'command',
            'event_type'   => $auto ? 'claude_log_auto_sent' : 'claude_log_sent',
            'status'       => $clearError === null ? 'success' : 'failed',
            'title'        => ($auto ? 'Auto-sent ' : 'Sent ') . self::label($bundle) . " to Claude Code ({$issueText})",
            'ssh_target'   => $project['ssh_username'] . '@' . $project['ssh_host'],
            'command_text' => $auto ? 'Automatic log check → Claude Code' : 'Send Laravel log to Claude Code',
            'output_log'   => "Claude Code session: {$fire['session_url']}\n{$clearNote}\n\n--- Report sent ---\n{$text}",
            'duration_ms'  => $durationMs,
        ]);

        return [
            'ok'          => true,
            'session_url' => $fire['session_url'],
            'issues'      => $digest['included'],
            'groups'      => $digest['groups'],
            'entries'     => $digest['entries'],
            'cleared'     => $clear['status'] === 'cleared',
            'message'     => "Sent {$issueText} to Claude Code. {$clearNote}",
        ];
    }

    /**
     * 'auto' = every recently changed storage/logs/laravel*.log; otherwise the one file given.
     *
     * @return array{project: array<string, mixed>, files: list<array{file: string, size: int, content: string, truncated: bool}>}
     */
    private function snapshot(int $projectId, string $file): array
    {
        if ($file === ClaudeLinkRepository::AUTO_WATCH) {
            return $this->runner->snapshotLaravelLogs($projectId);
        }
        $snap = $this->runner->snapshotRemoteLog($projectId, $file);
        return ['project' => $snap['project'], 'files' => [[
            'file' => $snap['file'], 'size' => $snap['size'], 'content' => $snap['content'], 'truncated' => $snap['truncated'],
        ]]];
    }

    /** @param array{files: list<array{content: string}>} $bundle */
    private static function content(array $bundle): string
    {
        $parts = array_filter(array_column($bundle['files'], 'content'), static fn(string $c): bool => trim($c) !== '');
        return implode("\n", array_map('rtrim', $parts));
    }

    /** @param array{files: list<array{file: string}>} $bundle */
    private static function label(array $bundle): string
    {
        $names = array_column($bundle['files'], 'file');
        return match (count($names)) {
            0       => 'no Laravel log files changed in the last 2 days',
            1       => $names[0],
            default => implode(', ', $names),
        };
    }

    /** e.g. "laravel-2026-11-22.log (12 KB), laravel.log (empty)" — shown in the checker's run log */
    private static function filesReport(array $bundle): string
    {
        if ($bundle['files'] === []) {
            return 'no laravel*.log files changed in the last 2 days';
        }
        $out = [];
        foreach ($bundle['files'] as $f) {
            $size = $f['size'] === 0 ? 'empty' : ($f['size'] < 1024 ? $f['size'] . ' B' : ($f['size'] < 1048576 ? round($f['size'] / 1024) . ' KB' : round($f['size'] / 1048576, 1) . ' MB'));
            $out[] = basename($f['file']) . " ({$size})";
        }
        return implode(', ', $out);
    }

    /**
     * @param array<string, mixed> $link
     */
    private function inCooldown(array $link): bool
    {
        // Compared inside MySQL (NOW() vs stored NOW()), so PHP/MySQL timezone differences don't matter.
        $since = $link['seconds_since_sent'];
        return $since !== null && $since >= 0 && $since < self::COOLDOWN_SECONDS;
    }

    /**
     * @return array{action: string, message: string, session_url?: string}
     */
    private function watchResult(int $projectId, string $action, string $message, ?string $sessionUrl = null): array
    {
        try {
            $this->links->recordWatch($projectId, ucfirst($action) . ': ' . $message);
        } catch (Throwable) {
        }
        $out = ['action' => $action, 'message' => $message];
        if ($sessionUrl !== null) {
            $out['session_url'] = $sessionUrl;
        }
        return $out;
    }
}
