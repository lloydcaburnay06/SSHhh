<?php

declare(strict_types=1);

namespace Deployer;

use InvalidArgumentException;
use PDO;

/**
 * Manages encrypted and plain application-wide settings (e.g., outbound notifications).
 */
final class SettingsRepository
{
    public function __construct(
        private PDO $pdo,
        private Crypto $crypto
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `setting_value_encrypted`, `is_encrypted` FROM `app_settings` WHERE `setting_key` = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default;
        }
        $val = (string) ($row['setting_value_encrypted'] ?? '');
        if ($val === '') {
            return $default;
        }
        if (!empty($row['is_encrypted'])) {
            return $this->crypto->decrypt($val, 'app-setting:' . $key);
        }
        return $val;
    }

    public function set(string $key, string $value, bool $encrypt = false): void
    {
        $stored = ($encrypt && $value !== '')
            ? $this->crypto->encrypt($value, 'app-setting:' . $key)
            : $value;

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO `app_settings` (`setting_key`, `setting_value_encrypted`, `is_encrypted`)
            VALUES (:k, :v, :enc)
            ON DUPLICATE KEY UPDATE
                `setting_value_encrypted` = VALUES(`setting_value_encrypted`),
                `is_encrypted` = VALUES(`is_encrypted`)
        SQL);
        $stmt->execute([
            ':k'   => mb_substr($key, 0, 100),
            ':v'   => $stored,
            ':enc' => $encrypt ? 1 : 0,
        ]);
    }

    /**
     * Full decrypted notification config for internal server-side delivery and vault backups.
     *
     * @return array<string, mixed>
     */
    public function getNotificationConfigInternal(): array
    {
        return [
            'enabled'           => $this->get('notify_enabled', '0') === '1',
            'channel'           => $this->get('notify_channel', 'discord') ?: 'discord',
            'webhook_url'       => $this->get('notify_webhook_url', '') ?? '',
            'telegram_token'    => $this->get('notify_telegram_bot_token', '') ?? '',
            'telegram_chat_id'  => $this->get('notify_telegram_chat_id', '') ?? '',
            'on_deploy_success' => $this->get('notify_on_deploy_success', '1') === '1',
            'on_deploy_failure' => $this->get('notify_on_deploy_failure', '1') === '1',
            'on_health_down'    => $this->get('notify_on_health_down', '1') === '1',
        ];
    }

    /**
     * Secret-free notification config safe to expose to the browser.
     *
     * @return array<string, mixed>
     */
    public function getNotificationConfigPublic(): array
    {
        $cfg = $this->getNotificationConfigInternal();
        return [
            'enabled'            => $cfg['enabled'],
            'channel'            => $cfg['channel'],
            'has_webhook_url'    => $cfg['webhook_url'] !== '',
            'webhook_url_hint'   => $this->maskUrl($cfg['webhook_url']),
            'has_telegram_token' => $cfg['telegram_token'] !== '',
            'telegram_chat_id'   => $cfg['telegram_chat_id'],
            'on_deploy_success'  => $cfg['on_deploy_success'],
            'on_deploy_failure'  => $cfg['on_deploy_failure'],
            'on_health_down'     => $cfg['on_health_down'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveNotificationConfig(array $input): array
    {
        $enabled = !empty($input['enabled']);
        $channel = strtolower(trim((string) ($input['channel'] ?? 'discord')));
        if (!in_array($channel, ['discord', 'slack', 'telegram', 'webhook'], true)) {
            $channel = 'discord';
        }

        $webhookUrl = trim((string) ($input['webhook_url'] ?? ''));
        if ($webhookUrl !== '' && !preg_match('#^https?://#i', $webhookUrl)) {
            throw new InvalidArgumentException('Webhook URL must start with http:// or https://');
        }

        $telegramToken = trim((string) ($input['telegram_token'] ?? ''));
        $telegramChatId = trim((string) ($input['telegram_chat_id'] ?? ''));

        $this->set('notify_enabled', $enabled ? '1' : '0', false);
        $this->set('notify_channel', $channel, false);
        $this->set('notify_on_deploy_success', !empty($input['on_deploy_success']) ? '1' : '0', false);
        $this->set('notify_on_deploy_failure', !empty($input['on_deploy_failure']) ? '1' : '0', false);
        $this->set('notify_on_health_down', !empty($input['on_health_down']) ? '1' : '0', false);
        $this->set('notify_telegram_chat_id', $telegramChatId, false);

        if (!empty($input['clear_webhook_url'])) {
            $this->set('notify_webhook_url', '', true);
        } elseif ($webhookUrl !== '') {
            $this->set('notify_webhook_url', $webhookUrl, true);
        }

        if (!empty($input['clear_telegram_token'])) {
            $this->set('notify_telegram_bot_token', '', true);
        } elseif ($telegramToken !== '') {
            $this->set('notify_telegram_bot_token', $telegramToken, true);
        }

        return $this->getNotificationConfigPublic();
    }

    private function maskUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $len = strlen($url);
        if ($len <= 24) {
            return substr($url, 0, 10) . '••••';
        }
        return substr($url, 0, 26) . '••••' . substr($url, -6);
    }
}
