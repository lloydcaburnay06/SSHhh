<?php

namespace App\Console\Commands;

use App\Services\HealthMonitor;
use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'sshhh:health-check';

    protected $description = 'Ping every project\'s Health Check URL, record the result and alert on changes';

    public function handle(HealthMonitor $health): int
    {
        $summary = $health->checkAll(true, function (array $project, array $res): void {
            $this->line(sprintf(
                '[%s] %-25s %-10s HTTP %d (%dms)',
                date('H:i:s'),
                $project['name'],
                $res['ok'] ? 'HEALTHY' : 'UNHEALTHY',
                $res['status_code'],
                $res['duration_ms'],
            ));
        });

        $this->info(sprintf('Checked %d endpoints (%d unhealthy).', $summary['checked'], $summary['unhealthy']));

        return $summary['unhealthy'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
