<?php

declare(strict_types=1);

namespace Deployer;

/**
 * Redacts sensitive credentials, tokens, and private keys from logs and streamed output.
 */
final class SecretRedactor
{
    private const MASK = '[REDACTED]';

    /**
     * @param list<string> $extraSecrets Exact secret values (passwords, passphrases, custom variables) to mask.
     */
    public static function redact(string $text, array $extraSecrets = []): string
    {
        if ($text === '') {
            return '';
        }

        // 1. Redact explicit runtime secrets first (longest first to prevent partial leaks)
        $cleanExtras = [];
        foreach ($extraSecrets as $secret) {
            $s = (string) $secret;
            if (strlen($s) >= 4) {
                $cleanExtras[] = $s;
            }
        }
        if ($cleanExtras !== []) {
            usort($cleanExtras, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($cleanExtras as $secret) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        // 2. Redact PEM / OpenSSH private key blocks
        $text = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]*PRIVATE KEY-----/m',
            '-----BEGIN PRIVATE KEY----- ' . self::MASK . ' -----END PRIVATE KEY-----',
            $text
        ) ?? $text;

        // 3. Redact embedded credentials in URLs: https://user:pass@host or https://token@host
        $text = preg_replace(
            '#\b(https?|ftp|ssh)://([^/\s:@]+):([^/\s@]+)@#i',
            '$1://$2:' . self::MASK . '@',
            $text
        ) ?? $text;
        $text = preg_replace(
            '#\b(https?)://([A-Za-z0-9_\-]{12,})@#i',
            '$1://' . self::MASK . '@',
            $text
        ) ?? $text;

        // 4. Redact Authorization Bearer headers
        $text = preg_replace(
            '/\b(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-~+\/=]{8,}/i',
            '$1' . self::MASK,
            $text
        ) ?? $text;

        // 5. Redact common .env / CLI secret key-value pairs
        $text = preg_replace(
            '/(^|[\s\'"`])([A-Z0-9_]*(?:PASSWORD|PASSWD|PASS|SECRET|TOKEN|API_KEY|PRIVATE_KEY|APP_KEY|ACCESS_KEY|WEBHOOK_URL)[A-Z0-9_]*)=("[^"]*"|\'[^\']*\'|[^\s\r\n]+)/mi',
            '$1$2=' . self::MASK,
            $text
        ) ?? $text;

        return $text;
    }
}
