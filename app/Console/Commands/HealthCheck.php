<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HealthMonitor;
use App\Support\Workspace;
use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'sshhh:health-check';

    protected $description = 'Ping every project\'s Health Check URL, record the result and alert on changes';

    public function handle(HealthMonitor $health, Workspace $workspace): int
    {
        $summary = ['checked' => 0, 'unhealthy' => 0];
        // Every active user's projects, each in that user's workspace (their notifications)
        foreach (User::query()->active()->pluck('id') as $userId) {
            $r = $workspace->runAs((int) $userId, fn () => $this->checkWorkspace($health));
            $summary['checked'] += $r['checked'];
            $summary['unhealthy'] += $r['unhealthy'];
        }

        $this->info(sprintf('Checked %d endpoints (%d unhealthy).', $summary['checked'], $summary['unhealthy']));

        return $summary['unhealthy'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{checked: int, unhealthy: int} */
    private function checkWorkspace(HealthMonitor $health): array
    {
        return $health->checkAll(true, function (array $project, array $res): void {
            $this->line(sprintf(
                '[%s] %-25s %-10s HTTP %d (%dms)',
                date('H:i:s'),
                $project['name'],
                $res['ok'] ? 'HEALTHY' : 'UNHEALTHY',
                $res['status_code'],
                $res['duration_ms'],
            ));
        });
    }
}
