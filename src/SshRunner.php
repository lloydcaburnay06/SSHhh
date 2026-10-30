<?php

declare(strict_types=1);

namespace Deployer;

use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

final class SshRunner
{
    public function __construct(
        private ProjectRepository $projects,
        private EventLogger $logger
    ) {
    }

    /**
     * Tests an SSH connection using either a saved project's credentials or form-supplied overrides.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function testConnection(array $input): array
    {
        $projectId = isset($input['project_id']) && (int) $input['project_id'] > 0
            ? (int) $input['project_id']
            : null;

        $savedBundle = null;
        if ($projectId !== null) {
            $savedBundle = $this->projects->getWithDecryptedCredentials($projectId);
        }

        $sourceProjectId = ($projectId === null && !empty($input['reuse_source_credentials']) && isset($input['source_project_id']) && (int) $input['source_project_id'] > 0)
            ? (int) $input['source_project_id']
            : null;
        $fallbackBundle = $savedBundle;
        if ($fallbackBundle === null && $sourceProjectId !== null) {
            $fallbackBundle = $this->projects->getWithDecryptedCredentials($sourceProjectId);
        }

        $savedProject = $savedBundle['project'] ?? null;

        $host = trim((string) ($input['ssh_host'] ?? ($savedProject['ssh_host'] ?? '')));
        $port = (int) ($input['ssh_port'] ?? ($savedProject['ssh_port'] ?? 22));
        $username = trim((string) ($input['ssh_username'] ?? ($savedProject['ssh_username'] ?? '')));
        $authType = strtolower(trim((string) ($input['ssh_auth_type'] ?? ($savedProject['ssh_auth_type'] ?? 'password'))));
        $remotePath = trim((string) ($input['remote_path'] ?? ($savedProject['remote_path'] ?? '')));
        $timeout = max(3, min(60, (int) ($input['ssh_timeout'] ?? ($savedProject['ssh_timeout'] ?? 15))));

        $password = (string) ($input['ssh_password'] ?? '');
        if ($password === '' && $fallbackBundle !== null) {
            $password = $fallbackBundle['password'];
        }

        $rawPrivateKey = trim((string) ($input['ssh_private_key'] ?? ''));
        $privateKey = $rawPrivateKey;
        if ($privateKey === '' && $fallbackBundle !== null) {
            $privateKey = $fallbackBundle['private_key'];
        }

        $passphrase = (string) ($input['ssh_passphrase'] ?? '');
        if ($passphrase === '' && $rawPrivateKey === '' && $fallbackBundle !== null) {
            $passphrase = $fallbackBundle['passphrase'];
        }

        if ($host === '' || $username === '') {
            throw new InvalidArgumentException('SSH Host and SSH Username are required to test the connection.');
        }

        $target = $this->projects->formatSshTarget($username, $host, $port, $remotePath);
        $startedAt = hrtime(true);

        try {
            $conn = $this->connect(
                host: $host,
                port: $port,
                username: $username,
                authType: $authType,
                password: $password,
                privateKey: $privateKey,
                passphrase: $passphrase,
                timeout: $timeout
            );
            $ssh = $conn['ssh'];
            $fingerprint = $conn['fingerprint'];
            $banner = $conn['server_banner'];

            $probeCmd = 'echo "USER=$(whoami)"; echo "HOST=$(hostname)"; echo "OS=$(uname -srm 2>/dev/null || uname -a)"; echo "HOME_PWD=$(pwd)"';
            if ($remotePath !== '') {
                $cdExpr = $this->buildCdTarget($remotePath);
                $probeCmd .= "; if cd {$cdExpr} 2>/dev/null; then echo \"PATH_CHECK=OK (\$(pwd))\"; else echo \"PATH_CHECK=MISSING ({$remotePath})\"; fi";
            }

            $ssh->setTimeout($timeout);
            $probeOutput = trim((string) $ssh->exec($probeCmd));
            $ssh->disconnect();

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $pathMissing = str_contains($probeOutput, 'PATH_CHECK=MISSING');

            if ($projectId !== null && $savedProject !== null) {
                $this->projects->updateHostFingerprint($projectId, $fingerprint);
                $this->logger->log([
                    'project_id'   => $projectId,
                    'project_name' => (string) $savedProject['name'],
                    'category'     => 'ssh',
                    'event_type'   => 'ssh_test',
                    'status'       => $pathMissing ? 'warning' : 'success',
                    'title'        => $pathMissing
                        ? sprintf('SSH authenticated (%dms), but remote path "%s" does not exist yet', $durationMs, $remotePath)
                        : sprintf('SSH connection test succeeded (%dms)', $durationMs),
                    'ssh_target'   => $target,
                    'command_text' => 'SSH Handshake + Environment Probe',
                    'output_log'   => implode("\n", array_filter([
                        "Target: {$target}",
                        "Auth Method: {$authType}",
                        $banner !== '' ? "Server Banner: {$banner}" : null,
                        $fingerprint !== '' ? "Host Fingerprint: {$fingerprint}" : null,
                        "Latency: {$durationMs} ms",
                        "--- Remote Probe ---",
                        $probeOutput,
                    ])),
                    'exit_code'    => 0,
                    'duration_ms'  => $durationMs,
                ]);
            }

            return [
                'connected'    => true,
                'path_ok'      => !$pathMissing,
                'duration_ms'  => $durationMs,
                'fingerprint'  => $fingerprint,
                'banner'       => $banner,
                'probe_output' => $probeOutput,
                'message'      => $pathMissing
                    ? "SSH authenticated in {$durationMs}ms, warning: remote directory '{$remotePath}' was not found."
                    : "SSH connection verified in {$durationMs}ms.",
            ];
        } catch (Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if ($projectId !== null && $savedProject !== null) {
                $this->logger->log([
                    'project_id'   => $projectId,
                    'project_name' => (string) $savedProject['name'],
                    'category'     => 'ssh',
                    'event_type'   => 'ssh_test',
                    'status'       => 'failed',
                    'title'        => 'SSH connection test failed: ' . $e->getMessage(),
                    'ssh_target'   => $target,
                    'command_text' => 'SSH Handshake + Environment Probe',
                    'output_log'   => sprintf("Target: %s\nAuth Method: %s\nError: %s", $target, $authType, $e->getMessage()),
                    'exit_code'    => 255,
                    'duration_ms'  => $durationMs,
                ]);
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Executes either the full enabled deployment pipeline ('deploy_all'),
     * a single saved command ('single_command'), or an ad-hoc command ('adhoc_command').
     *
     * @param callable(array<string, mixed>):void|null $emit
     * @return array<string, mixed>
     */
    public function execute(
        int $projectId,
        string $mode = 'deploy_all',
        ?int $commandId = null,
        ?string $adhocCommand = null,
        ?callable $emit = null
    ): array {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $send = static function (array $payload) use ($emit): void {
            if ($emit !== null) {
                $emit($payload);
            }
        };

        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $projectName = (string) $project['name'];
        $remotePath = (string) $project['remote_path'];
        $branch = (string) $project['repo_branch'];
        $env = (string) $project['environment'];
        $target = $this->projects->formatSshTarget(
            (string) $project['ssh_username'],
            (string) $project['ssh_host'],
            (int) $project['ssh_port'],
            $remotePath
        );

        // Determine steps to execute
        $stepsToRun = [];
        if ($mode === 'single_command') {
            if ($commandId === null || $commandId <= 0) {
                throw new InvalidArgumentException('Command ID is required for single command execution.');
            }
            $cmd = $this->projects->findCommand($projectId, $commandId);
            if (!$cmd) {
                throw new InvalidArgumentException('Deployment command not found.');
            }
            $stepsToRun[] = [
                'id'                => (int) $cmd['id'],
                'name'              => (string) $cmd['name'],
                'command'           => (string) $cmd['command'],
                'stop_on_error'     => true,
                'app_state_trigger' => (string) ($cmd['app_state_trigger'] ?? 'none'),
            ];
            $category = 'command';
            $eventType = 'single_command';
            $initialTitle = sprintf('Running command: %s', $cmd['name']);
            $commandSummary = (string) $cmd['command'];
        } elseif ($mode === 'adhoc_command') {
            $cleanAdhoc = trim((string) $adhocCommand);
            if ($cleanAdhoc === '') {
                throw new InvalidArgumentException('Command text cannot be empty.');
            }
            $firstLine = mb_substr(trim(explode("\n", $cleanAdhoc)[0]), 0, 80);
            $stepsToRun[] = [
                'id'                => null,
                'name'              => 'Quick Command: ' . $firstLine,
                'command'           => $cleanAdhoc,
                'stop_on_error'     => true,
                'app_state_trigger' => 'none',
            ];
            $category = 'command';
            $eventType = 'adhoc_command';
            $initialTitle = sprintf('Running quick command: %s', $firstLine);
            $commandSummary = $cleanAdhoc;
        } else {
            $mode = 'deploy_all';
            $allCommands = $this->projects->listCommands($projectId);
            foreach ($allCommands as $cmd) {
                if (!empty($cmd['is_enabled'])) {
                    $stepsToRun[] = [
                        'id'                => (int) $cmd['id'],
                        'name'              => (string) $cmd['name'],
                        'command'           => (string) $cmd['command'],
                        'stop_on_error'     => (bool) $cmd['stop_on_error'],
                        'app_state_trigger' => (string) ($cmd['app_state_trigger'] ?? 'none'),
                    ];
                }
            }

            if (!$stepsToRun) {
                throw new InvalidArgumentException('No enabled deployment commands found. Add or enable at least one command first.');
            }

            $category = 'deployment';
            $eventType = 'deploy_all';
            $initialTitle = sprintf('Deploying %s (%d steps)', $projectName, count($stepsToRun));
            $commandSummary = implode("\n", array_map(static fn(array $s): string => $s['command'], $stepsToRun));
        }

        $eventId = $this->logger->log([
            'project_id'   => $projectId,
            'project_name' => $projectName,
            'category'     => $category,
            'event_type'   => $eventType,
            'status'       => 'running',
            'title'        => $initialTitle,
            'ssh_target'   => $target,
            'command_text' => $commandSummary,
            'completed'    => false,
        ]);

        if ($mode === 'deploy_all') {
            $this->projects->updateProjectDeployStatus($projectId, 'running', null);
        }

        $totalSteps = count($stepsToRun);
        $startedAt = hrtime(true);
        $transcript = [];
        $stepResults = [];
        $overallStatus = 'success';
        $finalExitCode = 0;

        $headerLine = sprintf(
            "[%s] Starting %s on %s",
            date('H:i:s'),
            $mode === 'deploy_all' ? 'full deployment' : 'command execution',
            $target
        );
        $transcript[] = $headerLine;

        $send([
            'type'        => 'init',
            'event_id'    => $eventId,
            'mode'        => $mode,
            'target'      => $target,
            'total_steps' => $totalSteps,
            'message'     => $headerLine,
        ]);

        $ssh = null;
        try {
            $conn = $this->connect(
                host: (string) $project['ssh_host'],
                port: (int) $project['ssh_port'],
                username: (string) $project['ssh_username'],
                authType: (string) $project['ssh_auth_type'],
                password: $bundle['password'],
                privateKey: $bundle['private_key'],
                passphrase: $bundle['passphrase'],
                timeout: (int) $project['ssh_timeout']
            );
            $ssh = $conn['ssh'];
            $fingerprint = $conn['fingerprint'];
            $this->projects->updateHostFingerprint($projectId, $fingerprint);

            $connLine = sprintf(
                "[%s] Authenticated via SSH (%s)%s",
                date('H:i:s'),
                $project['ssh_auth_type'],
                $fingerprint !== '' ? " • Host key {$fingerprint}" : ''
            );
            $transcript[] = $connLine;
            $send([
                'type'        => 'connected',
                'fingerprint' => $fingerprint,
                'message'     => $connLine,
            ]);

            $ssh->disableQuietMode();
            $ssh->setTimeout((int) $project['command_timeout']);

            foreach ($stepsToRun as $index => $step) {
                $stepNum = $index + 1;
                $stepStart = hrtime(true);
                $stepHeader = sprintf(
                    "\n[%s] === Step %d/%d: %s ===\n$ %s",
                    date('H:i:s'),
                    $stepNum,
                    $totalSteps,
                    $step['name'],
                    str_replace("\n", "\n  ", $step['command'])
                );
                $transcript[] = $stepHeader;

                $send([
                    'type'        => 'step_start',
                    'step_number' => $stepNum,
                    'total_steps' => $totalSteps,
                    'command_id'  => $step['id'],
                    'name'        => $step['name'],
                    'command'     => $step['command'],
                    'message'     => $stepHeader,
                ]);

                $wrappedCommand = $this->wrapRemoteCommand(
                    command: $step['command'],
                    remotePath: $remotePath,
                    branch: $branch,
                    environment: $env,
                    stopOnError: $step['stop_on_error']
                );

                $stepOutput = '';
                $execResult = $ssh->exec($wrappedCommand, static function (string $chunk) use (&$stepOutput, $send, $stepNum): void {
                    $stepOutput .= $chunk;
                    $send([
                        'type'        => 'chunk',
                        'step_number' => $stepNum,
                        'data'        => $chunk,
                    ]);
                });

                $stepDurationMs = (int) round((hrtime(true) - $stepStart) / 1_000_000);
                $timedOut = $ssh->isTimeout();
                $rawExit = $ssh->getExitStatus();

                if ($timedOut) {
                    $exitCode = 124;
                    $timeoutNotice = sprintf("\n[ERROR] Command timed out after %d seconds.", (int) $project['command_timeout']);
                    $stepOutput .= $timeoutNotice;
                    $send([
                        'type'        => 'chunk',
                        'step_number' => $stepNum,
                        'data'        => $timeoutNotice . "\n",
                    ]);
                } elseif ($execResult === false && $rawExit === false) {
                    $exitCode = 255;
                } else {
                    $exitCode = is_int($rawExit) ? $rawExit : 0;
                }

                if ($stepOutput !== '') {
                    $transcript[] = rtrim($stepOutput, "\r\n");
                }

                if ($step['id'] !== null) {
                    $this->projects->updateCommandExecutionMetrics((int) $step['id'], $exitCode, $stepDurationMs);
                }

                $stepSucceeded = ($exitCode === 0);
                $stepStatus = $stepSucceeded ? 'success' : ($step['stop_on_error'] ? 'failed' : 'warning');

                if ($stepSucceeded && !empty($step['app_state_trigger']) && in_array($step['app_state_trigger'], ['down', 'up'], true)) {
                    $this->projects->updateProjectAppState($projectId, $step['app_state_trigger']);
                }

                $currentProject = $this->projects->find($projectId);
                $currentAppStatus = $currentProject['app_status'] ?? 'unknown';

                $stepFooter = sprintf(
                    "[%s] -> Exit code %d (%s)",
                    date('H:i:s'),
                    $exitCode,
                    $this->formatDuration($stepDurationMs)
                );
                $transcript[] = $stepFooter;

                $stepRecord = [
                    'step_number' => $stepNum,
                    'command_id'  => $step['id'],
                    'name'        => $step['name'],
                    'command'     => $step['command'],
                    'status'      => $stepStatus,
                    'exit_code'   => $exitCode,
                    'duration_ms' => $stepDurationMs,
                    'output'      => $stepOutput,
                ];
                $stepResults[] = $stepRecord;

                $send([
                    'type'        => 'step_end',
                    'step_number' => $stepNum,
                    'command_id'  => $step['id'],
                    'status'      => $stepStatus,
                    'exit_code'   => $exitCode,
                    'duration_ms' => $stepDurationMs,
                    'app_status'  => $currentAppStatus,
                    'message'     => $stepFooter,
                ]);

                if (!$stepSucceeded) {
                    $finalExitCode = $exitCode;
                    if ($step['stop_on_error']) {
                        $overallStatus = 'failed';
                        $abortMsg = sprintf(
                            "\n[%s] Pipeline halted at step %d/%d due to non-zero exit code (%d).",
                            date('H:i:s'),
                            $stepNum,
                            $totalSteps,
                            $exitCode
                        );
                        $transcript[] = $abortMsg;
                        $send([
                            'type'    => 'aborted',
                            'message' => $abortMsg,
                        ]);
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $overallStatus = 'failed';
            $finalExitCode = $finalExitCode !== 0 ? $finalExitCode : 255;
            $errLine = sprintf("\n[%s] [SSH ERROR] %s", date('H:i:s'), $e->getMessage());
            $transcript[] = $errLine;
            $send([
                'type'    => 'error',
                'message' => $e->getMessage(),
            ]);
        } finally {
            if ($ssh instanceof SSH2 && $ssh->isConnected()) {
                $ssh->disconnect();
            }
        }

        $totalDurationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $completedStepsCount = count(array_filter(
            $stepResults,
            static fn(array $r): bool => $r['exit_code'] === 0
        ));

        if ($mode === 'deploy_all') {
            $finalTitle = $overallStatus === 'success'
                ? sprintf('Deployment succeeded (%d/%d steps in %s)', $completedStepsCount, $totalSteps, $this->formatDuration($totalDurationMs))
                : sprintf('Deployment failed (%d/%d steps completed, exit %d)', $completedStepsCount, $totalSteps, $finalExitCode);

            $this->projects->updateProjectDeployStatus($projectId, $overallStatus, $totalDurationMs);
        } else {
            $label = $stepsToRun[0]['name'] ?? 'Command';
            $finalTitle = $overallStatus === 'success'
                ? sprintf('%s — succeeded (%s)', $label, $this->formatDuration($totalDurationMs))
                : sprintf('%s — failed (exit %d)', $label, $finalExitCode);
        }

        $summaryLine = sprintf(
            "\n[%s] Finished with status: %s (%s)",
            date('H:i:s'),
            strtoupper($overallStatus),
            $this->formatDuration($totalDurationMs)
        );
        $transcript[] = $summaryLine;
        $fullTranscript = implode("\n", $transcript);

        $this->logger->finalize($eventId, [
            'status'      => $overallStatus,
            'title'       => $finalTitle,
            'output_log'  => $fullTranscript,
            'steps'       => $stepResults,
            'exit_code'   => $finalExitCode,
            'duration_ms' => $totalDurationMs,
        ]);

        $finalPayload = [
            'type'        => 'complete',
            'event_id'    => $eventId,
            'mode'        => $mode,
            'status'      => $overallStatus,
            'title'       => $finalTitle,
            'exit_code'   => $finalExitCode,
            'duration_ms' => $totalDurationMs,
            'steps'       => $stepResults,
            'project'     => $this->projects->find($projectId),
            'commands'    => $this->projects->listCommands($projectId),
        ];

        $send($finalPayload);

        return $finalPayload;
    }

    /**
     * @return array{ssh: SSH2, fingerprint: string, server_banner: string}
     */
    private function connect(
        string $host,
        int $port,
        string $username,
        string $authType,
        string $password,
        string $privateKey,
        string $passphrase,
        int $timeout
    ): array {
        $ssh = new SSH2($host, $port, $timeout);

        $hostKeyRaw = $ssh->getServerPublicHostKey();
        $fingerprint = $this->computeOpenSshFingerprint(is_string($hostKeyRaw) ? $hostKeyRaw : '');
        $serverIdent = $ssh->getServerIdentification();
        $banner = is_string($serverIdent) ? trim($serverIdent) : '';

        if ($authType === 'key') {
            if ($privateKey === '') {
                throw new RuntimeException('No SSH private key is stored for this project. Edit SSH settings to add one.');
            }
            $keyObj = $passphrase !== ''
                ? PublicKeyLoader::load($privateKey, $passphrase)
                : PublicKeyLoader::load($privateKey);

            if (!$ssh->login($username, $keyObj)) {
                throw new RuntimeException("SSH key authentication failed for {$username}@{$host}:{$port}.");
            }
        } else {
            if ($password === '') {
                throw new RuntimeException('No SSH password is stored for this project. Edit SSH settings to save your password.');
            }
            if (!$ssh->login($username, $password)) {
                throw new RuntimeException("SSH password authentication failed for {$username}@{$host}:{$port}.");
            }
        }

        return [
            'ssh'           => $ssh,
            'fingerprint'   => $fingerprint,
            'server_banner' => $banner,
        ];
    }

    private function wrapRemoteCommand(
        string $command,
        string $remotePath,
        string $branch,
        string $environment,
        bool $stopOnError
    ): string {
        // Normalize CRLF -> LF so Linux/macOS shells never encounter stray \r characters
        $clean = str_replace(["\r\n", "\r"], "\n", trim($command));

        $lines = [];
        $lines[] = sprintf(
            'export DEPLOY_BRANCH=%s DEPLOY_ENV=%s',
            $this->posixQuote($branch),
            $this->posixQuote($environment)
        );

        if ($remotePath !== '') {
            $cdTarget = $this->buildCdTarget($remotePath);
            $errMsg = $this->posixQuote("Remote working directory does not exist: {$remotePath}");
            $lines[] = "cd {$cdTarget} || { echo {$errMsg} >&2; exit 1; }";
        }

        if ($stopOnError && str_contains($clean, "\n")) {
            $lines[] = 'set -e';
        }

        $lines[] = $clean;

        return implode("\n", $lines);
    }

    private function buildCdTarget(string $remotePath): string
    {
        if ($remotePath === '~') {
            return '~';
        }
        if (str_starts_with($remotePath, '~/')) {
            return '~/' . $this->posixQuote(substr($remotePath, 2));
        }

        return $this->posixQuote($remotePath);
    }

    private function posixQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private function computeOpenSshFingerprint(string $publicHostKey): string
    {
        if ($publicHostKey === '') {
            return '';
        }

        $parts = preg_split('/\s+/', trim($publicHostKey));
        if (!is_array($parts) || count($parts) < 2) {
            return '';
        }

        $blob = base64_decode($parts[1], true);
        if ($blob === false) {
            return '';
        }

        $algo = $parts[0];
        $hash = rtrim(base64_encode(hash('sha256', $blob, true)), '=');

        return "{$algo} SHA256:{$hash}";
    }

    private function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return "{$ms}ms";
        }
        return number_format($ms / 1000, 2) . 's';
    }
}

