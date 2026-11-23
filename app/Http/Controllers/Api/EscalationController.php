<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertChannels;
use App\Services\ClaudeLinkRepository;
use App\Services\ErrorAlertManager;
use App\Services\EventLogger;
use App\Services\LogWatcher;
use App\Services\ProjectRepository;
use App\Services\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Error escalation: alert channels, push devices, the automatic log checker and
 * per-project Claude Code links.
 */
class EscalationController extends Controller
{
    public function __construct(
        private AlertChannels $channels,
        private WebPush $push,
        private ErrorAlertManager $alerts,
        private EventLogger $events,
    ) {
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->ok([
            'config' => $this->channels->configPublic(),
            'vapid_key' => $this->push->publicKey(),
            'devices' => $this->push->listDevices(),
            'alerts' => $this->alerts->recent(10),
            'secure' => $request->isSecure(),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $config = $this->channels->save($request->all());
        $this->events->log(['category' => 'audit', 'event_type' => 'alert_settings_updated', 'status' => 'info', 'title' => 'Updated error escalation settings']);

        return $this->ok(['config' => $config]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $this->push->saveSubscription((array) $request->input('subscription', []), (string) $request->userAgent());

        return $this->ok(['devices' => $this->push->listDevices()]);
    }

    public function removeDevice(int $device): JsonResponse
    {
        $this->push->removeDevice($device);

        return $this->ok(['devices' => $this->push->listDevices()]);
    }

    public function testPush(): JsonResponse
    {
        return $this->ok(['message' => $this->alerts->sendTestPush()]);
    }

    public function testSummary(): JsonResponse
    {
        $s = $this->channels->summarize('Bansay', [
            '[2026-11-21 10:00:00] production.ERROR: Undefined array key "total" at /home/u/app/Http/Controllers/OrderController.php:42',
            '[2026-11-21 10:02:00] production.CRITICAL: SQLSTATE[HY000] [2002] Connection refused',
        ], 2);

        return $this->ok($s + ['length' => strlen($s['text'])]);
    }

    public function logChecker(Request $request, LogWatcher $watcher): JsonResponse
    {
        return $this->ok($watcher->overview((int) $request->query('limit', 40)));
    }

    public function runLogCheck(LogWatcher $watcher): JsonResponse
    {
        @set_time_limit(300);
        $lock = fopen((string) config('sshhh.log_watch.lock_file'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('A check is running right now (scheduled task). Try again in a moment.');
        }
        try {
            $run = $watcher->run('manual', null, true);
        } finally {
            flock($lock, LOCK_UN);
        }

        return $this->ok($run);
    }

    public function claudeLink(int $project, ProjectRepository $projects, ClaudeLinkRepository $links): JsonResponse
    {
        return $this->ok($links->getPublic($this->project($projects, $project)));
    }

    public function saveClaudeLink(Request $request, int $project, ProjectRepository $projects, ClaudeLinkRepository $links): JsonResponse
    {
        $row = $this->project($projects, $project);
        $links->save($project, $request->all());
        $this->events->log([
            'project_id' => $project,
            'project_name' => (string) $row['name'],
            'category' => 'audit',
            'event_type' => 'claude_link_updated',
            'status' => 'info',
            'title' => $request->boolean('remove') ? 'Removed Claude Code link' : 'Updated Claude Code link',
        ]);

        return $this->ok($links->getPublic($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function project(ProjectRepository $projects, int $id): array
    {
        return $projects->find($id) ?? throw new InvalidArgumentException('Project not found.');
    }

    private function ok(mixed $result): JsonResponse
    {
        return response()->json(['ok' => true, 'result' => $result]);
    }
}
