<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Error-escalation settings plus Gemini (free tier), which writes the short plain-text
 * summary (≤160 characters) shown in the push notification.
 */
final class AlertChannels
{
    public const SUMMARY_MAX = 160;
    public const DEFAULT_MODEL = 'gemini-flash-latest';

    public function __construct(private SettingsRepository $settings)
    {
    }

    /** @return array<string, mixed> */
    public function configInternal(): array
    {
        $s = $this->settings;
        return [
            'enabled'          => $s->get('alert_escalation_enabled', '0') === '1',
            'push_wait_min'    => max(1, (int) ($s->get('alert_push_wait_min', '10') ?? 10)),
            'gemini_key'       => (string) ($s->get('alert_gemini_key', '') ?? ''),
            'gemini_model'     => (string) ($s->get('alert_gemini_model', self::DEFAULT_MODEL) ?: self::DEFAULT_MODEL),
        ];
    }

    /** @return array<string, mixed> */
    public function configPublic(): array
    {
        $this->forgetSmsSettings();
        $c = $this->configInternal();
        return [
            'enabled'        => $c['enabled'],
            'push_wait_min'  => $c['push_wait_min'],
            'has_gemini_key' => $c['gemini_key'] !== '',
            'gemini_model'   => $c['gemini_model'],
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function save(array $in): array
    {
        $model = trim((string) ($in['gemini_model'] ?? '')) ?: self::DEFAULT_MODEL;
        if (!preg_match('/^[a-z0-9.\-]{3,60}$/', $model)) {
            throw new InvalidArgumentException('Invalid Gemini model name.');
        }

        $s = $this->settings;
        $s->set('alert_escalation_enabled', !empty($in['enabled']) ? '1' : '0');
        $s->set('alert_push_wait_min', (string) max(1, min(120, (int) ($in['push_wait_min'] ?? 10))));
        $s->set('alert_gemini_model', $model);
        foreach (['gemini_key' => 'alert_gemini_key'] as $field => $key) {
            if (!empty($in['clear_' . $field])) {
                $s->set($key, '', true);
            } elseif (($clean = self::cleanToken((string) ($in[$field] ?? ''))) !== '') {
                $s->set($key, $clean, true);
            }
        }
        return $this->configPublic();
    }

    /**
     * Writes a short plain-text summary (max 160 chars) of the issues for the notification. Uses Gemini when a key is
     * configured; falls back to a built-in summary if Gemini is unavailable or misbehaves.
     *
     * Only the redacted one-line headline of each issue is sent to Gemini (never stack traces):
     * free-tier prompts may be used by Google and read by human reviewers.
     *
     * @param list<string> $headlines Redacted first lines of each issue, most important first
     * @return array{text: string, source: string, note: ?string}
     */
    public function summarize(string $projectName, array $headlines, int $issueCount): array
    {
        $fallback = self::fallbackSummary($projectName, $headlines, $issueCount);
        $c = $this->configInternal();
        if ($c['gemini_key'] === '') {
            return ['text' => $fallback, 'source' => 'built-in', 'note' => null];
        }

        $list = '';
        foreach (array_slice($headlines, 0, 6) as $i => $h) {
            $list .= ($i + 1) . '. ' . mb_substr($h, 0, 300) . "\n";
        }
        $prompt = "You write short phone notifications for a developer. Summarize these Laravel production errors from the app \"{$projectName}\" "
            . "({$issueCount} unique issue" . ($issueCount === 1 ? '' : 's') . ") as ONE short message.\n"
            . "Rules: plain text only, no emojis, no links, no quotes, no markdown, at most 150 characters. "
            . "Start with \"{$projectName}:\". Name the main error and where it happens in plain words.\n\n"
            . "Errors:\n{$list}";

        try {
            $text = $this->callGemini($c['gemini_key'], $c['gemini_model'], $prompt);
            $clean = self::toShortText($text);
            if (mb_strlen($clean) < 15) {
                throw new RuntimeException('summary too short');
            }
            if (stripos($clean, $projectName) !== 0) {
                $clean = self::toShortText($projectName . ': ' . $clean);
            }
            return ['text' => $clean, 'source' => 'gemini', 'note' => null];
        } catch (Throwable $e) {
            return ['text' => $fallback, 'source' => 'built-in', 'note' => 'Gemini unavailable: ' . $e->getMessage()];
        }
    }

    /** Plain one-line text: ASCII only, no markdown or links, ≤160 chars. */
    public static function toShortText(string $text): string
    {
        $map = ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '×' => 'x', '→' => '->'];
        $t = strtr($text, $map);
        $t = (string) preg_replace('/[*_`#>]+/', '', $t);           // markdown leftovers
        $t = (string) preg_replace('/https?:\/\/\S+/i', '', $t);   // no links
        $t = (string) preg_replace('/[^\x20-\x7E]/', ' ', $t);      // ASCII printable only
        $t = trim((string) preg_replace('/\s+/', ' ', $t));
        if (strlen($t) > self::SUMMARY_MAX) {
            $cut = substr($t, 0, self::SUMMARY_MAX - 3);
            $sp = strrpos($cut, ' ');
            $t = rtrim($sp !== false && $sp > 100 ? substr($cut, 0, $sp) : $cut, " ,.;:-") . '...';
        }
        return $t;
    }

    /** @param list<string> $headlines */
    public static function fallbackSummary(string $projectName, array $headlines, int $issueCount): string
    {
        $first = $headlines[0] ?? 'new error';
        // "[2026-11-21 10:00:00] production.ERROR: Message {context}" → "ERROR: Message"
        $first = (string) preg_replace('/^\[[^\]]+\]\s+[\w-]+\.([A-Z]+):\s*/', '$1: ', $first);
        $first = (string) preg_replace('/\s*\{.*$/s', '', $first);
        if (preg_match('#([A-Za-z0-9_]+\.php):(\d+)#', $headlines[0] ?? '', $m)) {
            $first .= " ({$m[1]}:{$m[2]})";
        }
        $more = $issueCount > 1 ? ' +' . ($issueCount - 1) . ' more' : '';
        return self::toShortText("{$projectName}: {$first}{$more}. Open SSHhh to approve or ignore.");
    }

    /** SMS alerts were removed: delete any PhilSMS credentials saved by the earlier version. */
    private function forgetSmsSettings(): void
    {
        foreach (['alert_philsms_token' => true, 'alert_philsms_sender' => false, 'alert_philsms_to' => false, 'alert_sms_wait_min' => false] as $key => $secret) {
            if ((string) ($this->settings->get($key, '') ?? '') !== '') {
                $this->settings->set($key, '', $secret);
            }
        }
    }

    /** Removes paste mistakes: a leading "Bearer ", quotes, spaces or line breaks. */
    public static function cleanToken(string $raw): string
    {
        $t = trim($raw, " \t\n\r\0\x0B\"'");
        $t = (string) preg_replace('/^Bearer\s+/i', '', $t);
        return (string) preg_replace('/\s+/', '', $t);
    }

    private function callGemini(string $key, string $model, string $prompt): string
    {
        $resp = self::postJson(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . $key],
            [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 256],
            ],
            25
        );
        $data = json_decode($resp['body'], true);
        if ($resp['code'] !== 200 || !is_array($data)) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new RuntimeException("HTTP {$resp['code']}" . ($msg !== '' ? ' ' . mb_substr($msg, 0, 120) : ''));
        }
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (empty($part['thought'])) {
                $text .= (string) ($part['text'] ?? '');
            }
        }
        if (trim($text) === '') {
            throw new RuntimeException('empty response');
        }
        return $text;
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $json
     * @return array{code: int, body: string}
     */
    private static function postJson(string $url, array $headers, array $json, int $timeout): array
    {
        return self::request('POST', $url, $headers, $json, $timeout);
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed>|null $json
     * @return array{code: int, body: string}
     */
    private static function request(string $method, string $url, array $headers, ?array $json, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required.');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
        }
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException("network error: {$err}");
        }
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
}
