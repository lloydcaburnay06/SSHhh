<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventLogger;
use App\Services\ProjectRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class EventController extends Controller
{
    public function __construct(private EventLogger $events, private ProjectRepository $projects)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        $result = $this->events->query($projectId > 0 ? $projectId : null, [
            'category' => (string) $request->query('category', 'all'),
            'status' => (string) $request->query('status', 'all'),
            'search' => (string) $request->query('search', ''),
            'limit' => (int) $request->query('limit', 50),
            'offset' => (int) $request->query('offset', 0),
        ]);

        return response()->json([
            'ok' => true,
            'items' => $result['items'],
            'total' => $result['total'],
            'counts' => $result['counts'],
        ]);
    }

    public function show(int $event): JsonResponse
    {
        $row = $this->events->get($event);
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Event log not found.'], 404);
        }

        return response()->json(['ok' => true, 'event' => $row]);
    }

    public function download(int $event): Response
    {
        $ev = $this->events->get($event);
        if (! $ev) {
            return $this->text("Event log not found.\n", 404);
        }

        $lines = [
            '====================================================================',
            'SSHhh EVENT LOG #'.$ev['id'],
            'Deployments so quiet, you can hear a server drop.',
            '====================================================================',
            'Project    : '.($ev['project_name'] ?: 'N/A'),
            'Category   : '.strtoupper((string) $ev['category']).' ('.$ev['event_type'].')',
            'Status     : '.strtoupper((string) $ev['status']),
            'Summary    : '.$ev['title'],
            'SSH Target : '.($ev['ssh_target'] ?: 'N/A'),
            'Exit Code  : '.($ev['exit_code'] !== null ? (string) $ev['exit_code'] : 'N/A'),
            'Duration   : '.($ev['duration_ms'] !== null ? ($ev['duration_ms'].' ms') : 'N/A'),
            'Started At : '.$ev['created_at'],
            'Ended At   : '.($ev['completed_at'] ?: 'N/A'),
            '====================================================================',
            '',
            (string) ($ev['output_log'] ?: '(No console output recorded)'),
            '',
        ];

        return $this->text(implode("\n", $lines), 200, sprintf('sshhh-event-%d.log', $ev['id']));
    }

    public function downloadForProject(Request $request, int $project): Response
    {
        $status = trim((string) $request->query('status', 'all'));
        $row = $this->projects->find($project);
        if (! $row) {
            return $this->text("Project not found.\n", 404);
        }

        $events = $this->events->getProjectLogsExport($project, $status);
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower((string) $row['name'])) ?: 'project';
        $suffix = $status === 'failed' ? 'error-logs' : 'event-logs';
        $filename = "{$slug}-{$suffix}-".date('Ymd_His').'.log';

        $out = "====================================================================\n";
        $out .= 'SSHhh DEPLOYER '.strtoupper($suffix)."\n";
        $out .= "Project  : {$row['name']} (#{$row['id']})\n";
        $out .= "Target   : {$row['ssh_username']}@{$row['ssh_host']}:{$row['ssh_port']}\n";
        $out .= 'Exported : '.date('Y-m-d H:i:s T')."\n";
        $out .= 'Filter   : '.strtoupper($status).' ('.count($events)." entries)\n";
        $out .= "====================================================================\n\n";

        if ($events === []) {
            $out .= "No matching event logs recorded for this project.\n";
        }
        foreach ($events as $ev) {
            $out .= "--------------------------------------------------------------------\n";
            $out .= "[#{$ev['id']}] {$ev['created_at']} | ".strtoupper((string) $ev['status']).' | '.strtoupper((string) $ev['category'])."\n";
            $out .= "Title    : {$ev['title']}\n";
            if (! empty($ev['command_text'])) {
                $out .= "Command  : {$ev['command_text']}\n";
            }
            if ($ev['exit_code'] !== null) {
                $out .= "Exit Code: {$ev['exit_code']}\n";
            }
            if ($ev['duration_ms'] !== null) {
                $out .= "Duration : {$ev['duration_ms']} ms\n";
            }
            $out .= "Output   :\n";
            $out .= ! empty($ev['output_log']) ? rtrim($ev['output_log'])."\n" : "  (No console output)\n";
            $out .= "\n";
        }

        return $this->text($out, 200, $filename);
    }

    public function clearForProject(Request $request, int $project): JsonResponse
    {
        $status = trim((string) $request->input('status', 'all'));
        $row = $this->projects->find($project);
        if (! $row) {
            throw new InvalidArgumentException('Project not found.');
        }

        return response()->json([
            'ok' => true,
            'deleted' => $this->events->clearForProject($project, (string) $row['name'], $status),
            'status' => $status,
        ]);
    }

    private function text(string $body, int $status, ?string $downloadName = null): Response
    {
        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];
        if ($downloadName !== null) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$downloadName.'"';
        }

        return response($body, $status, $headers);
    }
}
