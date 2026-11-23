<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventLogger;
use App\Services\ProjectRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProjectController extends Controller
{
    public function __construct(private ProjectRepository $projects, private EventLogger $events)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'projects' => $this->projects->listAll(),
            'stats' => $this->projects->getDashboardStats(),
        ]);
    }

    public function show(int $project): JsonResponse
    {
        $row = $this->projects->find($project);
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Project not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'project' => $row,
            'commands' => $this->projects->listCommands($project),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'project' => $this->projects->create($request->all()),
            'stats' => $this->projects->getDashboardStats(),
        ], 201);
    }

    public function update(Request $request, int $project): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'project' => $this->projects->update($project, $request->all()),
            'stats' => $this->projects->getDashboardStats(),
        ]);
    }

    public function destroy(int $project): JsonResponse
    {
        $this->projects->delete($project);

        return response()->json(['ok' => true, 'stats' => $this->projects->getDashboardStats()]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->input('ordered_ids');
        if (! is_array($ids)) {
            throw new InvalidArgumentException('ordered_ids array is required.');
        }

        return response()->json(['ok' => true, 'projects' => $this->projects->reorder($ids)]);
    }

    public function saveCommands(Request $request, int $project): JsonResponse
    {
        $commands = $request->input('commands');
        if (! is_array($commands)) {
            throw new InvalidArgumentException('Project ID and commands array are required.');
        }

        return response()->json([
            'ok' => true,
            'commands' => $this->projects->syncCommands($project, $commands, false),
            'project' => $this->projects->find($project),
        ]);
    }

    public function setAppState(Request $request, int $project): JsonResponse
    {
        $this->projects->updateProjectAppState($project, trim((string) $request->input('app_state', 'unknown')));

        return response()->json([
            'ok' => true,
            'project' => $this->projects->find($project),
            'projects' => $this->projects->listAll(),
            'stats' => $this->projects->getDashboardStats(),
        ]);
    }

    public function vars(int $project): JsonResponse
    {
        return response()->json(['ok' => true, 'env_vars' => $this->projects->getDecryptedEnvVars($project)]);
    }

    public function regenerateWebhookToken(int $project): JsonResponse
    {
        return response()->json(['ok' => true, 'token' => $this->projects->regenerateWebhookToken($project)]);
    }

    public function liveRun(int $project): JsonResponse
    {
        $this->projects->recoverStaleRuns();

        return response()->json([
            'ok' => true,
            'project' => $this->projects->find($project),
            'event' => $this->events->getLatestDeploymentEvent($project),
        ]);
    }
}
