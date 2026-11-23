<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthMonitor;
use App\Services\ProjectRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(private HealthMonitor $health, private ProjectRepository $projects)
    {
    }

    /**
     * Pings a URL (or the project's health check URL) and records the project's health.
     */
    public function check(Request $request): JsonResponse
    {
        $url = trim((string) $request->input('url', ''));
        $projectId = (int) $request->input('project_id', 0);
        $project = $projectId > 0 ? $this->projects->find($projectId) : null;

        return response()->json(['ok' => true, 'result' => $this->health->check($url, $project)]);
    }

    public function checkAll(): JsonResponse
    {
        $summary = $this->health->checkAll();

        return response()->json([
            'ok' => true,
            ...$summary,
            'projects' => $this->projects->listAll(),
            'stats' => $this->projects->getDashboardStats(),
        ]);
    }
}
