<?php

declare(strict_types=1);

namespace Deployer;

/**
 * Turns a raw Laravel (or PHP error_log) log file into a compact, de-duplicated
 * report that fits in a Claude Code scheduled-task payload (max 65,536 chars).
 *
 * Repeated errors are grouped by a normalised signature, so 500 copies of the same
 * exception become one issue with a count, first/last seen time and one sample
 * stack trace.
 */
final class LaravelLogDigest
{
    public const MARKER = 'SSHhh-Deployer Laravel log report';

    private const HEADER_RE = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s+([\w-]+)\.([A-Z]+):\s?(.*)$/';

    private const SEVERITY = [
        'EMERGENCY' => 0,
        'ALERT'     => 1,
        'CRITICAL'  => 2,
        'ERROR'     => 3,
        'WARNING'   => 4,
        'NOTICE'    => 5,
    ];

    /**
     * @param array<string, string> $meta Header lines (label => value) shown above the issues.
     * @param array<string, int> $known Issue hash => unix time it was last reported (marks repeats in the report).
     * @return array{text: string, entries: int, groups: int, included: int, format: string, issues: list<array{hash: string, level: string, known: bool, headline: string}>}
     */
    public static function build(string $raw, array $meta, int $maxChars = 60000, int $traceLines = 30, array $known = []): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $entries = self::parseLaravel($raw);

        $header = self::MARKER . "\n";
        foreach ($meta as $label => $value) {
            $header .= "{$label}: {$value}\n";
        }

        if ($entries === []) {
            // Not a Laravel-formatted log (e.g. PHP error_log): send the newest raw lines that fit.
            $budget = max(1000, $maxChars - strlen($header) - 200);
            $body = trim($raw);
            if (strlen($body) > $budget) {
                $body = "…(older lines omitted)…\n" . substr($body, -$budget);
                $nl = strpos($body, "\n", 24);
                if ($nl !== false) {
                    $body = "…(older lines omitted)…\n" . substr($body, $nl + 1);
                }
            }
            $text = $header . "Format: raw (not Laravel-formatted)\n\n=== Raw log tail ===\n" . $body . "\n";
            $lastLine = (string) strrchr("\n" . $body, "\n");
            $issues = $body === '' ? [] : [['hash' => self::hash('raw|' . md5($body)), 'level' => 'ERROR', 'known' => false, 'headline' => mb_substr(trim($lastLine), 0, 300)]];
            return ['text' => $text, 'entries' => 0, 'groups' => $body === '' ? 0 : 1, 'included' => $body === '' ? 0 : 1, 'format' => 'raw', 'issues' => $issues];
        }

        /** @var array<string, array{level: string, count: int, first: string, last: string, sample: string, rank: int}> $groups */
        $groups = [];
        foreach ($entries as $e) {
            if (!isset(self::SEVERITY[$e['level']])) {
                continue; // skip INFO / DEBUG
            }
            $sig = $e['level'] . '|' . self::signature($e['message'], $e['body']);
            if (!isset($groups[$sig])) {
                $groups[$sig] = [
                    'hash'   => self::hash($sig),
                    'level'  => $e['level'],
                    'count'  => 0,
                    'first'  => $e['time'],
                    'last'   => $e['time'],
                    'sample' => '',
                    'rank'   => self::SEVERITY[$e['level']],
                ];
            }
            $groups[$sig]['count']++;
            $groups[$sig]['last'] = $e['time'];
            // Keep the most recent occurrence as the sample
            $groups[$sig]['sample'] = self::trimEntry($e['full'], $traceLines);
        }

        uasort($groups, static function (array $a, array $b): int {
            return [$a['rank'], -$a['count'], $b['last']] <=> [$b['rank'], -$b['count'], $a['last']];
        });

        $total = count($groups);
        $summary = "Format: laravel\nEntries in snapshot: " . count($entries) . "\nUnique issues (warning and above): {$total}\n";
        $budget = $maxChars - strlen($header) - strlen($summary) - 120;

        $blocks = '';
        $included = 0;
        $i = 0;
        $issues = [];
        foreach ($groups as $g) {
            $i++;
            $isKnown = isset($known[$g['hash']]);
            $title = "\n=== Issue {$i} of {$total} — {$g['level']} ×{$g['count']} (first {$g['first']}, last {$g['last']})"
                . ($isKnown ? ' — ALREADY REPORTED ' . date('Y-m-d H:i', $known[$g['hash']]) . '; a fix may be merged but not deployed yet, check before changing code' : '')
                . " ===\n";
            $block = $title . $g['sample'] . "\n";
            if (strlen($blocks) + strlen($block) > $budget) {
                // Try a shorter trace before giving up on this issue
                $short = $title . self::trimEntry($g['sample'], 6) . "\n";
                if (strlen($blocks) + strlen($short) > $budget) {
                    break;
                }
                $block = $short;
            }
            $blocks .= $block;
            $included++;
            $issues[] = ['hash' => $g['hash'], 'level' => $g['level'], 'known' => $isKnown, 'headline' => mb_substr((string) strtok($g['sample'], "\n"), 0, 300)];
        }

        $footer = $included < $total ? "\n(" . ($total - $included) . " lower-priority issues omitted to fit the size limit.)\n" : '';
        $text = $header . $summary . "Issues included: {$included}\n" . $blocks . $footer;

        return ['text' => $text, 'entries' => count($entries), 'groups' => $total, 'included' => $included, 'format' => 'laravel', 'issues' => $issues];
    }

    /** Levels that justify starting an automatic fix run. */
    public static function isErrorLevel(string $level): bool
    {
        return isset(self::SEVERITY[$level]) && self::SEVERITY[$level] <= self::SEVERITY['ERROR'];
    }

    private static function hash(string $signature): string
    {
        return substr(sha1($signature), 0, 16);
    }

    /**
     * @return list<array{time: string, env: string, level: string, message: string, body: string, full: string}>
     */
    private static function parseLaravel(string $raw): array
    {
        $entries = [];
        $current = null;
        foreach (explode("\n", $raw) as $line) {
            if (preg_match(self::HEADER_RE, $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'time'    => $m[1],
                    'env'     => $m[2],
                    'level'   => strtoupper($m[3]),
                    'message' => $m[4],
                    'body'    => '',
                    'full'    => $line,
                ];
                continue;
            }
            if ($current !== null) {
                $current['body'] .= $line . "\n";
                $current['full'] .= "\n" . $line;
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }
        return $entries;
    }

    /**
     * Normalised identity of an error: same exception at the same place → same signature,
     * even when ids, timestamps, numbers or quoted values in the message differ.
     */
    private static function signature(string $message, string $body): string
    {
        $where = '';
        if (preg_match('/ at (\/[^\s:)]+:\d+)/', $message . "\n" . $body, $m)) {
            $where = $m[1];
        }
        $msg = preg_replace('/\s*\{"(?:exception|userId|context)".*$/s', '', $message) ?? $message;
        $msg = preg_replace('/(["\'`]).*?\1/', '?', $msg) ?? $msg;
        $msg = preg_replace('/\b[0-9a-f]{8,}\b/i', '#', $msg) ?? $msg;
        $msg = preg_replace('/\d+/', '#', $msg) ?? $msg;
        return mb_substr(trim($msg), 0, 240) . '@' . $where;
    }

    private static function trimEntry(string $entry, int $traceLines): string
    {
        $lines = explode("\n", rtrim($entry));
        $first = array_shift($lines) ?? '';
        if (strlen($first) > 3000) {
            $first = substr($first, 0, 3000) . ' …(truncated)';
        }
        $kept = [];
        $omitted = 0;
        foreach ($lines as $l) {
            if (count($kept) < $traceLines) {
                $kept[] = strlen($l) > 400 ? substr($l, 0, 400) . '…' : $l;
            } else {
                $omitted++;
            }
        }
        if ($omitted > 0) {
            $kept[] = "…({$omitted} more trace lines)";
        }
        return trim($first . "\n" . implode("\n", $kept));
    }
}
