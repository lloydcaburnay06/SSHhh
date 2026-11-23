<?php

declare(strict_types=1);

namespace App\Services;

/**
 * HTTP health checks for projects with a Health Check URL: records the result on the
 * project and sends a notification when a project goes down or recovers.
 */
final class HealthMonitor
{
    public function __construct(
        private SshRunner $runner,
        private ProjectRepository $projects,
        private Notifier $notifier,
        private EventLogger $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $project  when given, its URL is the default and its status is updated
     * @return array<string, mixed>
     */
    public function check(string $url, ?array $project = null): array
    {
        if ($url === '' && $project !== null) {
            $url = trim((string) ($project['health_check_url'] ?? ''));
        }
        $res = $this->runner->checkHealth($url);
        if ($project !== null && $url !== '') {
            $this->record($project, $res);
        }

        return $res;
    }

    /**
     * Checks every project that has a Health Check URL.
     *
     * @param  bool  $logEvents  also write an audit event when a project's health changes (scheduled checks)
     * @param  callable(array<string, mixed>, array<string, mixed>): void|null  $each  called with (project, result)
     * @return array{checked: int, healthy: int, unhealthy: int, results: list<array<string, mixed>>}
     */
    public function checkAll(bool $logEvents = false, ?callable $each = null): array
    {
        $results = [];
        $healthy = 0;
        $unhealthy = 0;

        foreach ($this->projects->listAll() as $project) {
            $url = trim((string) ($project['health_check_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $res = $this->runner->checkHealth($url);
            $res['ok'] ? $healthy++ : $unhealthy++;
            $this->record($project, $res, $logEvents ? $url : null);
            if ($each !== null) {
                $each($project, $res);
            }

            $results[] = [
                'project_id' => (int) $project['id'],
                'project_name' => (string) $project['name'],
                'ok' => $res['ok'],
                'status_code' => $res['status_code'],
                'duration_ms' => $res['duration_ms'],
                'error' => $res['error'],
            ];
        }

        return ['checked' => count($results), 'healthy' => $healthy, 'unhealthy' => $unhealthy, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $res
     */
    private function record(array $project, array $res, ?string $logUrl = null): void
    {
        $id = (int) $project['id'];
        $prev = (string) ($project['last_health_status'] ?? 'unknown');
        $new = $res['ok'] ? 'healthy' : 'unhealthy';
        $this->projects->updateHealthStatus($id, $new, $res['status_code']);

        if ($logUrl !== null && ($new !== $prev || $new === 'unhealthy')) {
            $this->logger->log([
                'project_id' => $id,
                'project_name' => (string) $project['name'],
                'category' => 'audit',
                'event_type' => 'health_check',
                'status' => $res['ok'] ? 'success' : 'failed',
                'title' => $res['ok']
                    ? sprintf('Health check OK (HTTP %d in %dms)', $res['status_code'], $res['duration_ms'])
                    : sprintf('Health check FAILED (%s)', $res['error'] ?? ('HTTP '.$res['status_code'])),
                'output_log' => sprintf("URL: %s\nStatus: %d\nLatency: %d ms", $logUrl, $res['status_code'], $res['duration_ms']),
                'duration_ms' => $res['duration_ms'],
            ]);
        }

        if ($new === 'unhealthy' || ($prev === 'unhealthy' && $new === 'healthy')) {
            $this->notifier->notifyHealthAlert($project, (bool) $res['ok'], (int) $res['status_code'], $res['error'] ?? null);
        }
    }
}
