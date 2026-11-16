<?php

declare(strict_types=1);

namespace Deployer;

use Throwable;

/**
 * Sends outbound deployment & health alerts via Discord, Slack, Telegram, or custom webhooks.
 */
final class Notifier
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notifyDeployResult(array $project, string $status, string $title, int $durationMs, int $exitCode, array $context = []): void
    {
        try {
            $cfg = $this->settings->getNotificationConfigInternal();
            if (empty($cfg['enabled'])) {
                return;
            }
            $isSuccess = ($status === 'success');
            if ($isSuccess && empty($cfg['on_deploy_success'])) {
                return;
            }
            if (!$isSuccess && empty($cfg['on_deploy_failure'])) {
                return;
            }

            $emoji = $isSuccess ? '✅' : '🚨';
            $envLabel = strtoupper((string) ($project['environment'] ?? 'production'));
            $branch = (string) ($project['repo_branch'] ?? 'main');
            $durationText = $durationMs >= 1000
                ? round($durationMs / 1000, 1) . 's'
                : ($durationMs . 'ms');

            $headline = "{$emoji} SSHhh Deploy " . ($isSuccess ? 'Succeeded' : 'Failed') . ": {$project['name']} ({$envLabel})";
            $details = [
                'Project'     => (string) ($project['name'] ?? ''),
                'Environment' => $envLabel,
                'Branch'      => $branch,
                'Status'      => strtoupper($status),
                'Exit Code'   => (string) $exitCode,
                'Duration'    => $durationText,
                'Trigger'     => (string) ($context['trigger'] ?? 'manual'),
            ];

            $this->dispatch($cfg, $headline, $title, $isSuccess ? 'success' : 'error', $details);
        } catch (Throwable) {
            // Never let outbound notification errors interrupt or fail a deployment
        }
    }

    public function notifyHealthAlert(array $project, bool $isHealthy, int $statusCode, ?string $error = null): void
    {
        try {
            $cfg = $this->settings->getNotificationConfigInternal();
            if (empty($cfg['enabled']) || empty($cfg['on_health_down'])) {
                return;
            }

            $envLabel = strtoupper((string) ($project['environment'] ?? 'production'));
            $url = (string) ($project['health_check_url'] ?? '');

            if ($isHealthy) {
                $headline = "🟢 Health Recovered: {$project['name']} ({$envLabel})";
                $summary = "Endpoint {$url} is back online (HTTP {$statusCode}).";
                $level = 'success';
            } else {
                $headline = "🔴 Health Check Failed: {$project['name']} ({$envLabel})";
                $summary = "Endpoint {$url} failed health verification: " . ($error ?: "HTTP {$statusCode}");
                $level = 'error';
            }

            $this->dispatch($cfg, $headline, $summary, $level, [
                'Project'     => (string) ($project['name'] ?? ''),
                'Environment' => $envLabel,
                'URL'         => $url,
                'HTTP Code'   => (string) $statusCode,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Sends a test notification using currently saved settings and throws on failure so the UI can report errors.
     */
    public function sendTest(): void
    {
        $cfg = $this->settings->getNotificationConfigInternal();
        $channel = (string) ($cfg['channel'] ?? 'discord');
        $this->dispatch(
            $cfg,
            '🔔 SSHhh Test Notification',
            'Your outbound alert integration is configured and working properly.',
            'info',
            [
                'Channel' => strtoupper($channel),
                'Status'  => 'CONNECTED',
                'Time'    => date('Y-m-d H:i:s T'),
            ],
            throwOnError: true
        );
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, string> $fields
     */
    private function dispatch(array $cfg, string $headline, string $summary, string $level, array $fields, bool $throwOnError = false): void
    {
        $channel = (string) ($cfg['channel'] ?? 'discord');

        if ($channel === 'telegram') {
            $token = (string) ($cfg['telegram_token'] ?? '');
            $chatId = (string) ($cfg['telegram_chat_id'] ?? '');
            if ($token === '' || $chatId === '') {
                if ($throwOnError) {
                    throw new \RuntimeException('Telegram Bot Token and Chat ID are required.');
                }
                return;
            }
            $lines = [$headline, $summary, ''];
            foreach ($fields as $k => $v) {
                $lines[] = "• {$k}: {$v}";
            }
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $payload = [
                'chat_id' => $chatId,
                'text'    => implode("\n", $lines),
            ];
            $this->postJson($url, $payload, $throwOnError);
            return;
        }

        $webhookUrl = (string) ($cfg['webhook_url'] ?? '');
        if ($webhookUrl === '') {
            if ($throwOnError) {
                throw new \RuntimeException('Notification Webhook URL is not configured.');
            }
            return;
        }

        if ($channel === 'discord') {
            $color = match ($level) {
                'success' => 0x10B981,
                'error'   => 0xEF4444,
                default   => 0x6366F1,
            };
            $embedFields = [];
            foreach ($fields as $k => $v) {
                $embedFields[] = ['name' => $k, 'value' => $v !== '' ? $v : '-', 'inline' => true];
            }
            $payload = [
                'embeds' => [[
                    'title'       => $headline,
                    'description' => $summary,
                    'color'       => $color,
                    'fields'      => $embedFields,
                    'timestamp'   => date('c'),
                ]],
            ];
        } elseif ($channel === 'slack') {
            $color = match ($level) {
                'success' => '#10b981',
                'error'   => '#ef4444',
                default   => '#6366f1',
            };
            $slackFields = [];
            foreach ($fields as $k => $v) {
                $slackFields[] = ['title' => $k, 'value' => $v !== '' ? $v : '-', 'short' => true];
            }
            $payload = [
                'attachments' => [[
                    'color'  => $color,
                    'title'  => $headline,
                    'text'   => $summary,
                    'fields' => $slackFields,
                ]],
            ];
        } else {
            $payload = [
                'event'     => $headline,
                'level'     => $level,
                'summary'   => $summary,
                'fields'    => $fields,
                'timestamp' => date('c'),
            ];
        }

        $this->postJson($webhookUrl, $payload, $throwOnError);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload, bool $throwOnError): void
    {
        if (!function_exists('curl_init')) {
            if ($throwOnError) {
                throw new \RuntimeException('PHP cURL extension is required for outbound notifications.');
            }
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($throwOnError && ($errno !== 0 || $code < 200 || $code >= 300)) {
            $detail = $errno !== 0 ? $err : ("HTTP {$code}" . (is_string($resp) && $resp !== '' ? ': ' . mb_substr($resp, 0, 160) : ''));
            throw new \RuntimeException('Notification delivery failed: ' . $detail);
        }
    }
}
