<?php

declare(strict_types=1);

namespace Deployer;

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

        $snap = $this->runner->snapshotRemoteLog($projectId, $file);
        if (trim($snap['content']) === '') {
            throw new RuntimeException("'{$snap['file']}' is empty — nothing to send.");
        }

        $result = $this->deliver($projectId, $link, $snap, false);
        if ($result === null) {
            throw new RuntimeException('No warnings or errors found in the log (only info/debug lines). Nothing was sent.');
        }
        return $result;
    }

    /**
     * One automatic check. Never throws for "nothing to do"; returns what happened.
     *
     * @return array{action: string, message: string, session_url?: string}
     */
    public function check(int $projectId): array
    {
        $link = $this->links->getInternal($projectId);
        if ($link === null) {
            return $this->watchResult($projectId, 'skipped', 'Not linked to Claude Code.');
        }
        if ($this->inCooldown($link)) {
            return $this->watchResult($projectId, 'skipped', 'Sent moments ago; will check next run.');
        }

        $public = $this->links->getPublic($this->projects->find($projectId) ?? ['id' => $projectId]);
        $file = (string) $public['watch_file'];

        try {
            $snap = $this->runner->snapshotRemoteLog($projectId, $file);
        } catch (RuntimeException $e) {
            // Release-based deploys keep Laravel's log in shared/
            if ($file === ClaudeLinkRepository::DEFAULT_WATCH_FILE && str_contains($e->getMessage(), 'not found')) {
                $snap = $this->runner->snapshotRemoteLog($projectId, 'shared/' . $file);
            } else {
                throw $e;
            }
        }

        if (trim($snap['content']) === '') {
            return $this->watchResult($projectId, 'clean', 'Log is empty.');
        }

        $result = $this->deliver($projectId, $link, $snap, true);
        if ($result === null) {
            return $this->watchResult($projectId, 'clean', 'No new errors (only info/warnings, or errors already reported in the last 24h).');
        }
        return $this->watchResult($projectId, 'sent', $result['message'], $result['session_url']);
    }

    /**
     * @param array<string, mixed> $link
     * @param array{file: string, size: int, content: string, truncated: bool, project: array<string, mixed>} $snap
     * @return array<string, mixed>|null null when there is nothing worth sending
     */
    private function deliver(int $projectId, array $link, array $snap, bool $auto): ?array
    {
        $started = microtime(true);
        $project = $snap['project'];
        $known = $this->links->recentIssues($projectId);

        $mergeBranch = $link['merge_branch'] !== '' ? $link['merge_branch'] : (string) ($project['repo_branch'] ?? 'main');
        $digest = LaravelLogDigest::build($snap['content'], [
            'Project'             => (string) $project['name'],
            'Environment'         => (string) ($project['environment'] ?? 'production'),
            'Deploy branch'       => (string) ($project['repo_branch'] ?? 'main'),
            'Merge target branch' => $mergeBranch,
            'Log file'            => $snap['file'],
            'Snapshot'            => number_format($snap['size']) . ' bytes' . ($snap['truncated'] ? ' (only the newest part was analysed)' : ''),
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
        $clear = ['status' => 'kept', 'kept_bytes' => $snap['size']];
        $clearError = null;
        if ($link['clear_after_send']) {
            try {
                $clear = $this->runner->consumeRemoteLog($projectId, $snap['file'], $snap['size']);
            } catch (Throwable $e) {
                $clearError = $e->getMessage();
            }
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
            'title'        => ($auto ? 'Auto-sent ' : 'Sent ') . "{$snap['file']} to Claude Code ({$issueText})",
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
