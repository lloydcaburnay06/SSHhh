<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Deployer\Bootstrap;

$app = Bootstrap::init();
$projects = $app->projects()->listAll();
$checked = 0;
$failed = 0;

foreach ($projects as $proj) {
    $url = trim((string) ($proj['health_check_url'] ?? ''));
    if ($url === '') {
        continue;
    }

    $checked++;
    $pid = (int) $proj['id'];
    $prevStatus = (string) ($proj['last_health_status'] ?? 'unknown');
    $res = $app->runner()->checkHealth($url);
    $newStatus = $res['ok'] ? 'healthy' : 'unhealthy';

    $app->projects()->updateHealthStatus($pid, $newStatus, $res['status_code']);

    if (!$res['ok']) {
        $failed++;
    }

    if ($newStatus !== $prevStatus || $newStatus === 'unhealthy') {
        $app->logger()->log([
            'project_id'   => $pid,
            'project_name' => (string) $proj['name'],
            'category'     => 'audit',
            'event_type'   => 'health_check',
            'status'       => $res['ok'] ? 'success' : 'failed',
            'title'        => $res['ok']
                ? sprintf('Health check OK (HTTP %d in %dms)', $res['status_code'], $res['duration_ms'])
                : sprintf('Health check FAILED (%s)', $res['error'] ?? ('HTTP ' . $res['status_code'])),
            'output_log'   => sprintf("URL: %s\nStatus: %d\nLatency: %d ms", $url, $res['status_code'], $res['duration_ms']),
            'duration_ms'  => $res['duration_ms'],
        ]);
    }

    if ($newStatus === 'unhealthy' || ($prevStatus === 'unhealthy' && $newStatus === 'healthy')) {
        $app->notifier()->notifyHealthAlert($proj, $res['ok'], (int) $res['status_code'], $res['error'] ?? null);
    }

    echo sprintf(
        "[%s] %-25s %-10s HTTP %d (%dms)\n",
        date('H:i:s'),
        $proj['name'],
        strtoupper($newStatus),
        $res['status_code'],
        $res['duration_ms']
    );
}

echo sprintf("Checked %d endpoints (%d unhealthy).\n", $checked, $failed);
exit($failed > 0 ? 1 : 0);
