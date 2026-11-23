<?php

namespace App\Http\Controllers;

use App\Services\LoginThrottle;
use App\Services\ProjectRepository;
use App\Services\SshRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Git push webhooks (GitHub / GitLab / Bitbucket). No session: the per-project
 * webhook token is the credential, and failed tokens count toward the IP lockout.
 */
class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ProjectRepository $projects,
        SshRunner $runner,
        LoginThrottle $throttle,
        ?string $token = null,
    ): JsonResponse {
        if (($locked = $throttle->minutesRemaining((string) $request->ip())) > 0) {
            return response()->json(['ok' => false, 'error' => "Too many failed attempts. Try again in {$locked} minute(s)."], 429);
        }

        $token = trim((string) ($token ?? $request->query('token', $request->input('token', ''))));
        if ($token === '') {
            return response()->json(['ok' => false, 'error' => 'Webhook token is required.'], 401);
        }

        $project = $projects->findByWebhookToken($token);
        if (! $project) {
            return response()->json(['ok' => false, 'error' => 'Invalid webhook token.'], 403);
        }
        if (empty($project['webhook_enabled'])) {
            return response()->json(['ok' => false, 'error' => 'Webhooks are disabled for this project.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true) ?: [];
        $pushedBranch = null;
        if (! empty($payload['ref']) && is_string($payload['ref'])) {
            $pushedBranch = str_replace('refs/heads/', '', $payload['ref']);
        }
        if (! $pushedBranch && ! empty($payload['push']['changes'][0]['new']['name'])) {
            $pushedBranch = (string) $payload['push']['changes'][0]['new']['name'];
        }

        $targetBranch = $project['webhook_branch'] ?: $project['repo_branch'] ?: 'main';
        if ($pushedBranch !== null && $pushedBranch !== $targetBranch) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
                'message' => "Pushed branch '{$pushedBranch}' does not match project webhook target branch '{$targetBranch}'. Deployment skipped.",
            ]);
        }

        $result = $runner->execute(projectId: (int) $project['id'], mode: 'deploy_all', trigger: 'webhook');

        return response()->json([
            'ok' => true,
            'message' => "Deployment pipeline executed for project '{$project['name']}'.",
            'result' => $result,
        ]);
    }
}
