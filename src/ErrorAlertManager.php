<?php

declare(strict_types=1);

namespace Deployer;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Error escalation, driven by cli/log_watch.php (every minute):
 *
 *   new error found ──► push notification (Proceed / Ignore) + Gemini SMS summary prepared
 *        │ no decision within push_wait_min (10)
 *        ▼
 *   SMS alert via PhilSMS (information only — decide in the notification/app)
 *        │ no decision within sms_wait_min (10) after the SMS
 *        ▼
 *   log sent to Claude Code automatically
 *
 * All time comparisons happen inside MySQL (NOW()), so PHP/MySQL timezone differences don't matter.
 */
final class ErrorAlertManager
{
    private bool $tableReady = false;

    public function __construct(
        private PDO $pdo,
        private AlertChannels $channels,
        private WebPush $push,
        private ClaudeLogSender $sender,
        private EventLogger $logger
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->channels->configInternal()['enabled'];
    }

    public function hasPending(int $projectId): bool
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `error_alerts` WHERE `project_id` = :p AND `status` IN ('pending','processing')");
        $stmt->execute([':p' => $projectId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Issue fingerprints alerted in the last 24h (any outcome, including Ignore), so the same
     * error does not raise a new alert every 10 minutes.
     *
     * @return array<string, int>
     */
    public function suppressedIssues(int $projectId): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT `issue_hashes` FROM `error_alerts` WHERE `project_id` = :p AND `created_at` > NOW() - INTERVAL 1 DAY');
        $stmt->execute([':p' => $projectId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach ((array) json_decode((string) $json, true) as $h) {
                $out[(string) $h] = time();
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $project
     * @param list<array{hash: string, level: string, headline: string}> $issues new error-level issues
     * @return array<string, mixed>
     */
    public function create(array $project, string $file, array $issues): array
    {
        $this->ensureTable();
        $projectId = (int) $project['id'];
        $name = (string) $project['name'];
        $headlines = array_map(static fn(array $i): string => self::shortHeadline($i['headline']), $issues);
        $summary = $this->channels->summarize($name, $headlines, count($issues));

        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO `error_alerts` (`project_id`, `project_name`, `log_file`, `summary`, `summary_source`, `issue_hashes`, `issue_count`, `status`, `token_hash`, `created_at`)
            VALUES (:p, :n, :f, :s, :src, :h, :c, 'pending', :t, NOW())
        SQL)->execute([
            ':p' => $projectId, ':n' => $name, ':f' => $file, ':s' => $summary['text'], ':src' => $summary['source'],
            ':h' => json_encode(array_column($issues, 'hash')), ':c' => count($issues), ':t' => hash('sha256', $token),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $pushResult = $this->notify($id, $token, "⚠️ {$name}: new error", $summary['text'], true);
        $this->update($id, ['push_result' => $pushResult]);

        $cfg = $this->channels->configInternal();
        $this->logger->log([
            'project_id'   => $projectId,
            'project_name' => $name,
            'category'     => 'audit',
            'event_type'   => 'error_alert_created',
            'status'       => 'info',
            'title'        => "New error alert: {$summary['text']}",
            'output_log'   => "Log file: {$file}\nIssues: " . count($issues) . "\nSummary ({$summary['source']}): {$summary['text']}"
                . ($summary['note'] ? "\n{$summary['note']}" : '')
                . "\nPush: {$pushResult}\nSMS in {$cfg['push_wait_min']} min and Claude Code {$cfg['sms_wait_min']} min after that, unless you choose Proceed or Ignore.",
        ]);

        return ['id' => $id, 'summary' => $summary['text'], 'push' => $pushResult];
    }

    /**
     * User decision from the notification, the alert page or the app.
     *
     * @return array{ok: bool, status: string, message: string, session_url?: string}
     */
    public function decide(string $token, string $choice, string $via): array
    {
        $this->ensureTable();
        if (!in_array($choice, ['proceed', 'ignore'], true)) {
            throw new \InvalidArgumentException('Choice must be proceed or ignore.');
        }
        $alert = $this->findByToken($token);
        if ($alert === null) {
            throw new RuntimeException('This alert link is invalid or older than 24 hours.');
        }
        if (!in_array($alert['status'], ['pending'], true)) {
            return ['ok' => true, 'status' => $alert['status'], 'message' => self::statusText($alert)];
        }
        if (!$this->claim((int) $alert['id'])) {
            $fresh = $this->get((int) $alert['id']);
            return ['ok' => true, 'status' => (string) $fresh['status'], 'message' => self::statusText($fresh)];
        }

        if ($choice === 'ignore') {
            $this->update((int) $alert['id'], ['status' => 'ignored', 'decided_at' => 'NOW()', 'decided_via' => $via]);
            $this->audit($alert, 'Ignored error alert', "Ignored via {$via}. The same errors will not alert again for 24 hours.");
            return ['ok' => true, 'status' => 'ignored', 'message' => 'Ignored. These errors will not alert again for 24 hours.'];
        }

        return $this->sendToClaude($alert, 'proceeded', $via);
    }

    /**
     * Advances the timers of every pending alert. Called every minute by cli/log_watch.php.
     *
     * @return list<string> human-readable lines for the run log
     */
    public function process(): array
    {
        $this->ensureTable();
        $cfg = $this->channels->configInternal();
        $lines = [];

        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT *,
                TIMESTAMPDIFF(SECOND, `created_at`, NOW()) AS `age_s`,
                TIMESTAMPDIFF(SECOND, `sms_sent_at`, NOW()) AS `since_sms_s`
            FROM `error_alerts` WHERE `status` = 'pending' ORDER BY `id`
        SQL);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $id = (int) $a['id'];
            try {
                if ($a['sms_sent_at'] === null) {
                    if ((int) $a['age_s'] >= $cfg['push_wait_min'] * 60) {
                        // Step 2: no decision on the notification → SMS alert (errors only, by construction)
                        try {
                            $res = $this->channels->smsConfigured()
                                ? $this->channels->sendSms((string) $a['summary'])
                                : 'SMS skipped (PhilSMS not configured)';
                        } catch (Throwable $e) {
                            $res = 'SMS failed: ' . $e->getMessage();
                        }
                        $this->update($id, ['sms_sent_at' => 'NOW()', 'sms_result' => $res]);
                        $this->audit($a, 'Error alert: no action on notification → SMS', $res);
                        $lines[] = "{$a['project_name']}: {$res}";
                    }
                } elseif ((int) $a['since_sms_s'] >= $cfg['sms_wait_min'] * 60) {
                    // Step 3: still no decision 10 minutes after the SMS → send to Claude
                    if ($this->claim($id)) {
                        $r = $this->sendToClaude($a, 'auto_sent', 'timeout');
                        $lines[] = "{$a['project_name']}: {$r['message']}";
                    }
                }
            } catch (Throwable $e) {
                $lines[] = "{$a['project_name']}: alert #{$id} error: " . $e->getMessage();
            }
        }

        // Alerts are only actionable for 24 hours
        $this->pdo->exec("UPDATE `error_alerts` SET `status` = 'expired' WHERE `status` = 'pending' AND `created_at` < NOW() - INTERVAL 1 DAY");
        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        $this->ensureTable();
        $limit = max(1, min(100, $limit));
        $rows = $this->pdo->query("SELECT `id`, `project_id`, `project_name`, `summary`, `summary_source`, `issue_count`, `status`, `created_at`, `push_result`, `sms_sent_at`, `sms_result`, `decided_at`, `decided_via`, `session_url`, `error` FROM `error_alerts` ORDER BY `id` DESC LIMIT {$limit}")
            ->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Public view for the alert page (opened from the notification).
     *
     * @return array<string, mixed>|null
     */
    public function viewByToken(string $token): ?array
    {
        $a = $this->findByToken($token);
        if ($a === null) {
            return null;
        }
        return [
            'project_name' => $a['project_name'],
            'summary'      => $a['summary'],
            'issue_count'  => (int) $a['issue_count'],
            'status'       => $a['status'],
            'created_at'   => $a['created_at'],
            'sms_sent_at'  => $a['sms_sent_at'],
            'status_text'  => self::statusText($a),
        ];
    }

    /** Push a test notification to every registered device. */
    public function sendTestPush(): string
    {
        $r = $this->push->sendToAll([
            'title' => '🔔 SSHhh test notification',
            'body'  => 'Push notifications work on this device.',
            'tag'   => 'sshhh-test',
            'url'   => './',
        ], 300);
        return self::pushSummary($r);
    }

    /**
     * @param array<string, mixed> $alert
     * @return array{ok: bool, status: string, message: string, session_url?: string}
     */
    private function sendToClaude(array $alert, string $status, string $via): array
    {
        $id = (int) $alert['id'];
        try {
            $res = $this->sender->send((int) $alert['project_id'], (string) $alert['log_file']);
            $this->update($id, ['status' => $status, 'decided_at' => 'NOW()', 'decided_via' => $via, 'session_url' => (string) $res['session_url']]);
            $label = $status === 'auto_sent' ? 'No response for 20 minutes — sent to Claude Code' : 'Sent to Claude Code';
            $this->notify($id, null, "🤖 {$alert['project_name']}: {$label}", (string) $alert['summary'], false, (string) $res['session_url']);
            return ['ok' => true, 'status' => $status, 'message' => $res['message'], 'session_url' => (string) $res['session_url']];
        } catch (Throwable $e) {
            $this->update($id, ['status' => 'failed', 'decided_at' => 'NOW()', 'decided_via' => $via, 'error' => mb_substr($e->getMessage(), 0, 255)]);
            $this->audit($alert, 'Error alert: sending to Claude failed', $e->getMessage(), 'failed');
            $this->notify($id, null, "❌ {$alert['project_name']}: could not send to Claude", mb_substr($e->getMessage(), 0, 150), false);
            return ['ok' => false, 'status' => 'failed', 'message' => 'Could not send to Claude Code: ' . $e->getMessage()];
        }
    }

    private function notify(int $id, ?string $token, string $title, string $body, bool $withActions, ?string $url = null): string
    {
        try {
            $payload = [
                'title' => $title,
                'body'  => $body,
                'tag'   => 'sshhh-alert-' . $id,
                'url'   => $url ?? ($token !== null ? 'alert.php?t=' . $token : './'),
            ];
            if ($withActions && $token !== null) {
                $payload['token'] = $token;
                $payload['actions'] = [
                    ['action' => 'proceed', 'title' => '🤖 Proceed'],
                    ['action' => 'ignore', 'title' => 'Ignore'],
                ];
                $payload['requireInteraction'] = true;
            }
            return self::pushSummary($this->push->sendToAll($payload));
        } catch (Throwable $e) {
            return 'Push failed: ' . $e->getMessage();
        }
    }

    /**
     * What may leave the machine for the free Gemini tier: the error message itself plus
     * "File.php:line" — never the JSON context or stack trace that Laravel appends.
     */
    public static function shortHeadline(string $line): string
    {
        $where = preg_match('#([A-Za-z0-9_]+\.php)[:(](\d+)#', $line, $m) ? " at {$m[1]}:{$m[2]}" : '';
        $msg = (string) preg_replace('/\s*\{".*$/s', '', $line);          // drop {"exception":...} context
        $msg = (string) preg_replace('/\s*\[stacktrace\].*$/is', '', $msg);
        if ($where !== '' && str_contains($msg, $m[1] . ':' . $m[2])) {
            $where = ''; // message already says where
        }
        return SecretRedactor::redact(mb_substr(trim($msg), 0, 240) . $where);
    }

    /** @param array{sent: int, failed: int, removed: int, errors: list<string>} $r */
    private static function pushSummary(array $r): string
    {
        if ($r['sent'] === 0 && $r['failed'] === 0) {
            return 'No devices have notifications turned on' . ($r['removed'] > 0 ? " ({$r['removed']} expired device(s) removed)" : '');
        }
        return "Push sent to {$r['sent']} device(s)" . ($r['failed'] > 0 ? ", {$r['failed']} failed (" . implode('; ', array_slice(array_unique($r['errors']), 0, 2)) . ')' : '')
            . ($r['removed'] > 0 ? ", {$r['removed']} expired removed" : '');
    }

    /** @param array<string, mixed> $a */
    private static function statusText(array $a): string
    {
        return match ((string) $a['status']) {
            'pending'    => $a['sms_sent_at'] ? 'Waiting for your decision. SMS was sent; it goes to Claude Code automatically soon.' : 'Waiting for your decision.',
            'processing' => 'Sending to Claude Code…',
            'proceeded'  => 'Sent to Claude Code.',
            'auto_sent'  => 'Sent to Claude Code automatically (no response).',
            'ignored'    => 'Ignored.',
            'failed'     => 'Sending to Claude Code failed: ' . ($a['error'] ?? ''),
            'expired'    => 'Expired.',
            default      => (string) $a['status'],
        };
    }

    /** Atomically take a pending alert so a tap and the timer can never both act on it. */
    private function claim(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE `error_alerts` SET `status` = 'processing' WHERE `id` = :id AND `status` = 'pending'");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    private function findByToken(string $token): ?array
    {
        $this->ensureTable();
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM `error_alerts` WHERE `token_hash` = :t AND `created_at` > NOW() - INTERVAL 1 DAY LIMIT 1');
        $stmt->execute([':t' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `error_alerts` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, string> $fields values of 'NOW()' are written as SQL NOW() */
    private function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $val) {
            if ($val === 'NOW()') {
                $sets[] = "`{$col}` = NOW()";
            } else {
                $sets[] = "`{$col}` = :{$col}";
                $params[":{$col}"] = mb_substr((string) $val, 0, 255);
            }
        }
        $this->pdo->prepare('UPDATE `error_alerts` SET ' . implode(', ', $sets) . ' WHERE `id` = :id')->execute($params);
    }

    /** @param array<string, mixed> $alert */
    private function audit(array $alert, string $title, string $detail, string $status = 'info'): void
    {
        try {
            $this->logger->log([
                'project_id'   => (int) $alert['project_id'],
                'project_name' => (string) $alert['project_name'],
                'category'     => 'audit',
                'event_type'   => 'error_alert',
                'status'       => $status,
                'title'        => $title,
                'output_log'   => "Alert #{$alert['id']}: {$alert['summary']}\n{$detail}",
            ]);
        } catch (Throwable) {
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `error_alerts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id` INT UNSIGNED NOT NULL,
                `project_name` VARCHAR(190) NOT NULL,
                `log_file` VARCHAR(255) NOT NULL,
                `summary` VARCHAR(255) NOT NULL,
                `summary_source` VARCHAR(20) NOT NULL DEFAULT 'built-in',
                `issue_hashes` TEXT NULL,
                `issue_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `token_hash` CHAR(64) NOT NULL,
                `created_at` DATETIME NOT NULL,
                `push_result` VARCHAR(255) NULL,
                `sms_sent_at` DATETIME NULL,
                `sms_result` VARCHAR(255) NULL,
                `decided_at` DATETIME NULL,
                `decided_via` VARCHAR(20) NULL,
                `session_url` VARCHAR(255) NULL,
                `error` VARCHAR(255) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_token` (`token_hash`),
                KEY `idx_status` (`status`, `created_at`),
                KEY `idx_project` (`project_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->tableReady = true;
    }
}
