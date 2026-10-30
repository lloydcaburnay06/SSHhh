<?php

declare(strict_types=1);

use Deployer\Bootstrap;

try {
    require_once __DIR__ . '/vendor/autoload.php';
    $app = Bootstrap::init();
} catch (Throwable $bootError) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'Bootstrap failed: ' . $bootError->getMessage(),
    ]);
    exit;
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * @param array<string, mixed> $payload
 */
function respondJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Invalid JSON request payload.');
    }
    return $decoded;
}

try {
    // Read-only GET endpoints
    if ($method === 'GET') {
        switch ($action) {
            case 'list_projects':
                respondJson([
                    'ok'       => true,
                    'projects' => $app->projects()->listAll(),
                    'stats'    => $app->projects()->getDashboardStats(),
                ]);

            case 'get_project':
                $id = (int) ($_GET['id'] ?? 0);
                $project = $app->projects()->find($id);
                if (!$project) {
                    respondJson(['ok' => false, 'error' => 'Project not found.'], 404);
                }
                respondJson([
                    'ok'       => true,
                    'project'  => $project,
                    'commands' => $app->projects()->listCommands($id),
                ]);

            case 'list_events':
                $projectId = isset($_GET['project_id']) && (int) $_GET['project_id'] > 0
                    ? (int) $_GET['project_id']
                    : null;

                $result = $app->logger()->query($projectId, [
                    'category' => (string) ($_GET['category'] ?? 'all'),
                    'status'   => (string) ($_GET['status'] ?? 'all'),
                    'search'   => (string) ($_GET['search'] ?? ''),
                    'limit'    => (int) ($_GET['limit'] ?? 50),
                    'offset'   => (int) ($_GET['offset'] ?? 0),
                ]);

                respondJson([
                    'ok'     => true,
                    'items'  => $result['items'],
                    'total'  => $result['total'],
                    'counts' => $result['counts'],
                ]);

            case 'get_event':
                $id = (int) ($_GET['id'] ?? 0);
                $event = $app->logger()->get($id);
                if (!$event) {
                    respondJson(['ok' => false, 'error' => 'Event log not found.'], 404);
                }
                respondJson(['ok' => true, 'event' => $event]);

            case 'download_event':
                $id = (int) ($_GET['id'] ?? 0);
                $event = $app->logger()->get($id);
                if (!$event) {
                    http_response_code(404);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "Event log not found.\n";
                    exit;
                }

                $lines = [
                    '====================================================================',
                    'SSHhh EVENT LOG #' . $event['id'],
                    'Deployments so quiet, you can hear a server drop.',
                    '====================================================================',
                    'Project    : ' . ($event['project_name'] ?: 'N/A'),
                    'Category   : ' . strtoupper((string) $event['category']) . ' (' . $event['event_type'] . ')',
                    'Status     : ' . strtoupper((string) $event['status']),
                    'Summary    : ' . $event['title'],
                    'SSH Target : ' . ($event['ssh_target'] ?: 'N/A'),
                    'Exit Code  : ' . ($event['exit_code'] !== null ? (string) $event['exit_code'] : 'N/A'),
                    'Duration   : ' . ($event['duration_ms'] !== null ? ($event['duration_ms'] . ' ms') : 'N/A'),
                    'Started At : ' . $event['created_at'],
                    'Ended At   : ' . ($event['completed_at'] ?: 'N/A'),
                    '====================================================================',
                    '',
                    (string) ($event['output_log'] ?: '(No console output recorded)'),
                    '',
                ];

                header('Content-Type: text/plain; charset=utf-8');
                header(sprintf('Content-Disposition: attachment; filename="sshhh-event-%d.log"', $event['id']));
                echo implode("\n", $lines);
                exit;

            default:
                respondJson(['ok' => false, 'error' => 'Unknown GET action.'], 400);
        }
    }

    if ($method !== 'POST') {
        respondJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $body = readJsonBody();
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? null);
    $app->verifyCsrf(is_string($csrfHeader) ? $csrfHeader : null);

    switch ($action) {
        case 'create_project':
            $project = $app->projects()->create($body);
            respondJson([
                'ok'      => true,
                'project' => $project,
                'stats'   => $app->projects()->getDashboardStats(),
            ], 201);

        case 'update_project':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Valid project ID is required.');
            }
            $project = $app->projects()->update($id, $body);
            respondJson([
                'ok'      => true,
                'project' => $project,
                'stats'   => $app->projects()->getDashboardStats(),
            ]);

        case 'delete_project':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Valid project ID is required.');
            }
            $app->projects()->delete($id);
            respondJson([
                'ok'    => true,
                'stats' => $app->projects()->getDashboardStats(),
            ]);

        case 'reorder_projects':
            $orderedIds = $body['ordered_ids'] ?? null;
            if (!is_array($orderedIds)) {
                throw new InvalidArgumentException('ordered_ids array is required.');
            }
            $projects = $app->projects()->reorder($orderedIds);
            respondJson([
                'ok'       => true,
                'projects' => $projects,
            ]);

        case 'save_commands':
            $projectId = (int) ($body['project_id'] ?? 0);
            $commandsInput = $body['commands'] ?? null;
            if ($projectId <= 0 || !is_array($commandsInput)) {
                throw new InvalidArgumentException('Project ID and commands array are required.');
            }
            $commands = $app->projects()->syncCommands($projectId, $commandsInput, false);
            respondJson([
                'ok'       => true,
                'commands' => $commands,
                'project'  => $app->projects()->find($projectId),
            ]);

        case 'test_ssh':
            $app->releaseSessionLock();
            $result = $app->runner()->testConnection($body);
            $projectId = isset($body['project_id']) && (int) $body['project_id'] > 0
                ? (int) $body['project_id']
                : null;
            respondJson([
                'ok'      => true,
                'result'  => $result,
                'project' => $projectId ? $app->projects()->find($projectId) : null,
            ]);

        case 'execute':
            $projectId = (int) ($body['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new InvalidArgumentException('Valid project ID is required.');
            }

            $mode = trim((string) ($body['mode'] ?? 'deploy_all'));
            $commandId = isset($body['command_id']) && (int) $body['command_id'] > 0
                ? (int) $body['command_id']
                : null;
            $stepIndex = isset($body['step_index']) && is_numeric($body['step_index'])
                ? (int) $body['step_index']
                : null;
            $adhocCommand = isset($body['adhoc_command']) ? (string) $body['adhoc_command'] : null;

            // Auto-save any inline command edits if passed alongside execution
            if (isset($body['commands']) && is_array($body['commands'])) {
                $synced = $app->projects()->syncCommands($projectId, $body['commands'], true);
                if ($mode === 'single_command' && $stepIndex !== null && isset($synced[$stepIndex])) {
                    $commandId = (int) $synced[$stepIndex]['id'];
                }
            }

            $app->releaseSessionLock();

            $stream = !empty($body['stream']);
            if ($stream) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                ob_implicit_flush(true);

                header('Content-Type: application/x-ndjson; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Accel-Buffering: no');

                $app->runner()->execute(
                    projectId: $projectId,
                    mode: $mode,
                    commandId: $commandId,
                    adhocCommand: $adhocCommand,
                    emit: static function (array $packet): void {
                        echo json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                        @flush();
                    }
                );
                exit;
            }

            $final = $app->runner()->execute(
                projectId: $projectId,
                mode: $mode,
                commandId: $commandId,
                adhocCommand: $adhocCommand
            );

            respondJson([
                'ok'     => true,
                'result' => $final,
            ]);

        case 'clear_events':
            $projectId = (int) ($body['project_id'] ?? 0);
            $project = $app->projects()->find($projectId);
            if (!$project) {
                throw new InvalidArgumentException('Project not found.');
            }
            $deleted = $app->logger()->clearForProject($projectId, (string) $project['name']);
            respondJson([
                'ok'      => true,
                'deleted' => $deleted,
            ]);

        default:
            respondJson(['ok' => false, 'error' => 'Unknown POST action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    respondJson(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
}

