<?php

declare(strict_types=1);

namespace App\Services;

use Composer\CaBundle\CaBundle;
use RuntimeException;

/**
 * Starts a Claude Code scheduled task (formerly "routine") through its API trigger.
 * https://platform.claude.com/docs/en/api/claude-code/routines-fire
 */
final class ClaudeRoutineClient
{
    public const MAX_TEXT = 65536;

    /**
     * @return array{session_url: string, session_id: string}
     */
    public function fire(string $fireUrl, string $token, string $text): array
    {
        if (!preg_match(ClaudeLinkRepository::FIRE_URL_RE, $fireUrl)) {
            throw new RuntimeException('Invalid Claude Code fire URL.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required (enable extension=curl in php.ini).');
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT - 40) . "\n…(truncated to fit)";
        }

        $ch = curl_init($fireUrl);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: experimental-cc-routine-2026-04-01',
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // XAMPP's PHP usually has no CA list configured; fall back to Mozilla's bundle.
        if (class_exists(CaBundle::class)) {
            $ca = CaBundle::getSystemCaRootBundlePath();
            $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Could not reach Claude Code ({$err}).");
        }

        $data = is_string($resp) ? json_decode($resp, true) : null;
        if ($code === 200 && is_array($data) && !empty($data['claude_code_session_url'])) {
            return [
                'session_url' => (string) $data['claude_code_session_url'],
                'session_id'  => (string) ($data['claude_code_session_id'] ?? ''),
            ];
        }

        $apiMsg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        $hint = match ($code) {
            400     => 'Request rejected — the scheduled task may be paused.',
            401     => 'Token rejected — generate a new token for this scheduled task and save it again.',
            403     => 'Your Claude account does not have access to scheduled-task API triggers.',
            404     => 'Scheduled task not found — check the fire URL.',
            429     => 'Hourly limit reached (30 runs per task per hour). Try again later.',
            503     => 'Claude Code is temporarily overloaded. Try again shortly.',
            default => "Unexpected response (HTTP {$code}).",
        };
        throw new RuntimeException($hint . ($apiMsg !== '' ? " ({$apiMsg})" : ''));
    }
}
