<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProjectRepository;
use App\Services\SshRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExecutionController extends Controller
{
    public function __construct(private ProjectRepository $projects, private SshRunner $runner)
    {
    }

    /**
     * Runs the pipeline, one command or an ad-hoc command. With `stream: true` every
     * output packet is sent as it happens, one JSON object per line (NDJSON).
     */
    public function execute(Request $request, int $project): JsonResponse|StreamedResponse
    {
        $mode = trim((string) $request->input('mode', 'deploy_all'));
        $commandId = (int) $request->input('command_id', 0) > 0 ? (int) $request->input('command_id') : null;
        $stepIndex = is_numeric($request->input('step_index')) ? (int) $request->input('step_index') : null;
        $adhoc = $request->has('adhoc_command') ? (string) $request->input('adhoc_command') : null;
        $directory = is_string($request->input('custom_directory')) ? trim($request->input('custom_directory')) : null;

        // Auto-save inline command edits sent along with the run
        if (is_array($request->input('commands'))) {
            $synced = $this->projects->syncCommands($project, $request->input('commands'), true);
            if ($mode === 'single_command' && $stepIndex !== null && isset($synced[$stepIndex])) {
                $commandId = (int) $synced[$stepIndex]['id'];
            }
        }

        if (! $request->boolean('stream')) {
            return response()->json([
                'ok' => true,
                'result' => $this->runner->execute(
                    projectId: $project,
                    mode: $mode,
                    commandId: $commandId,
                    adhocCommand: $adhoc,
                    customDirectory: $directory,
                ),
            ]);
        }

        return response()->stream(function () use ($project, $mode, $commandId, $adhoc, $directory): void {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $this->runner->execute(
                projectId: $project,
                mode: $mode,
                commandId: $commandId,
                adhocCommand: $adhoc,
                emit: static function (array $packet): void {
                    echo json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @flush();
                },
                customDirectory: $directory,
            );
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function testSsh(Request $request): JsonResponse
    {
        $result = $this->runner->testConnection($request->all());
        $projectId = (int) $request->input('project_id', 0);

        return response()->json([
            'ok' => true,
            'result' => $result,
            'project' => $projectId > 0 ? $this->projects->find($projectId) : null,
        ]);
    }

    public function remoteDirs(Request $request, int $project): JsonResponse
    {
        $path = is_string($request->input('path')) ? trim($request->input('path')) : null;
        try {
            return response()->json(['ok' => true, 'result' => $this->runner->listRemoteDirectories($project, $path)]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
