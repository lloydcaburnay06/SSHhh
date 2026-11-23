<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClaudeLogSender;
use App\Services\SshRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live server operations over SSH: pulse, remote .env, logs and releases.
 */
class RemoteController extends Controller
{
    public function __construct(private SshRunner $runner)
    {
    }

    public function pulse(int $project): JsonResponse
    {
        return $this->ok($this->runner->getServerPulse($project));
    }

    public function env(int $project): JsonResponse
    {
        return $this->ok($this->runner->getRemoteEnv($project));
    }

    public function saveEnv(Request $request, int $project): JsonResponse
    {
        return $this->ok($this->runner->saveRemoteEnv($project, (string) $request->input('content', '')));
    }

    public function logs(Request $request, int $project): JsonResponse
    {
        $file = is_string($request->input('file')) ? trim($request->input('file')) : null;
        $lines = $request->has('lines') ? (int) $request->input('lines') : 300;

        return $this->ok($this->runner->getRemoteLogs($project, $file, $lines));
    }

    public function downloadLog(Request $request, int $project): StreamedResponse
    {
        $file = trim((string) $request->query('file', ''));
        if ($file === '') {
            throw new InvalidArgumentException('Project ID and file are required.');
        }
        $name = $this->runner->remoteLogDownloadName($project, $file);

        return response()->streamDownload(function () use ($project, $file): void {
            $this->runner->streamRemoteLogDownload($project, $file, static function (string $chunk): void {
                echo $chunk;
                @flush();
            });
        }, $name, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function clearLog(Request $request, int $project): JsonResponse
    {
        $file = trim((string) $request->input('file', ''));
        if ($file === '') {
            throw new InvalidArgumentException('Project ID and file are required.');
        }

        return $this->ok($this->runner->clearRemoteLog($project, $file));
    }

    public function sendLogToClaude(Request $request, int $project, ClaudeLogSender $sender): JsonResponse
    {
        $file = trim((string) $request->input('file', ''));
        if ($file === '') {
            throw new InvalidArgumentException('Project ID and log file are required.');
        }

        return $this->ok($sender->send($project, $file));
    }

    public function releases(int $project): JsonResponse
    {
        return $this->ok($this->runner->listReleases($project));
    }

    public function rollback(Request $request, int $project): JsonResponse
    {
        $target = $request->has('target_release') ? trim((string) $request->input('target_release')) : null;

        return $this->ok($this->runner->rollbackRelease($project, $target));
    }

    private function ok(mixed $result): JsonResponse
    {
        return response()->json(['ok' => true, 'result' => $result]);
    }
}
