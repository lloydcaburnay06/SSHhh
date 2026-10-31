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
     * Lists subdirectories on the remote server for interactive folder browsing.
     *
     * @return array{current_dir: string, parent_dir: ?string, directories: list<string>}
     */
    public function listRemoteDirectories(int $projectId, ?string $path = null): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $remotePath = trim((string) ($path ?? $project['remote_path']));
        if ($remotePath === '') {
            $remotePath = '~';
        }

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: (string) $bundle['password'],
            privateKey: (string) $bundle['private_key'],
            passphrase: (string) $bundle['passphrase'],
            timeout: max(3, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $cdTarget = $this->buildCdTarget($remotePath);
            $cmd = "cd {$cdTarget} 2>/dev/null && pwd && ls -1 -F 2>/dev/null | grep '/$' | head -n 40";
            $output = trim((string) $ssh->exec($cmd));
            $lines = array_filter(array_map('trim', explode("\n", $output)), static fn($l) => $l !== '');
            $pwd = !empty($lines) ? array_shift($lines) : $remotePath;
            $dirs = [];
            foreach ($lines as $line) {
                $dirName = rtrim($line, '/');
                if ($dirName !== '' && $dirName !== '.' && $dirName !== '..') {
                    $dirs[] = $dirName;
                }
            }
            sort($dirs);
            $parentDir = null;
            if ($pwd !== '/' && $pwd !== '~' && $pwd !== '') {
                $p = dirname($pwd);
                $parentDir = ($p !== '' && $p !== '.') ? $p : '/';
            }
            return [
                'current_dir' => $pwd,
                'parent_dir'  => $parentDir,
                'directories' => array_values($dirs),
            ];
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
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
        ?callable $emit = null,
        ?string $customDirectory = null
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
        if ($customDirectory !== null && trim($customDirectory) !== '') {
            $remotePath = trim($customDirectory);
        }
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

            $symlinkInfo = null;
            if ($mode === 'deploy_all') {
                if (!empty($project['backup_db_before_deploy'])) {
                    $this->runPreDeployBackup($ssh, $project, $remotePath, $send, $transcript);
                }

                if (($project['deploy_strategy'] ?? 'direct') === 'symlink') {
                    $symlinkInfo = $this->prepareSymlinkRelease($ssh, $project, $remotePath, $send, $transcript);
                    if ($symlinkInfo !== null) {
                        $remotePath = $symlinkInfo['release_dir'];
                    }
                }
            }

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

            if ($symlinkInfo !== null) {
                if ($overallStatus === 'success') {
                    $this->activateSymlinkRelease($ssh, $project, $symlinkInfo, $send, $transcript);
                } else {
                    $failMsg = "\n[ATOMIC] Deployment failed. Active release remains unchanged pointing to previous release.\n";
                    $transcript[] = $failMsg;
                    $send(['type' => 'chunk', 'step_number' => $totalSteps, 'data' => $failMsg]);
                }
            }

            if ($overallStatus === 'success' && $mode === 'deploy_all' && !empty($project['health_check_url'])) {
                $this->runHealthCheckVerification($project, $send, $transcript);
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

    /**
     * Inspect remote server vital metrics (OS, uptime, load averages, RAM %, Disk %, active services).
     *
     * @return array<string, mixed>
     */
    public function getServerPulse(int $projectId): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: $bundle['password'],
            privateKey: $bundle['private_key'],
            passphrase: $bundle['passphrase'],
            timeout: max(5, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $remotePath = trim((string) $project['remote_path']);
            $targetDir = ($remotePath !== '' && $remotePath !== '~') ? escapeshellarg($remotePath) : '~';

            $script = <<<'BASH'
echo "===OS==="
uname -srm 2>/dev/null || uname -a 2>/dev/null
echo "===UPTIME==="
uptime 2>/dev/null
echo "===MEMORY==="
free -m 2>/dev/null || cat /proc/meminfo 2>/dev/null | head -n 4
echo "===DISK==="
df -hP %s 2>/dev/null || df -hP . 2>/dev/null || df -hP / 2>/dev/null
echo "===SERVICES==="
for s in nginx apache2 caddy php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm php-fpm mysql mariadb mysqld postgresql redis redis-server supervisor supervisord; do
  if command -v systemctl >/dev/null 2>&1; then
    st=$(systemctl is-active "$s" 2>/dev/null)
    if [ "$st" = "active" ] || [ "$st" = "failed" ] || [ "$st" = "activating" ]; then
      echo "$s:$st"
    fi
  fi
done
echo "===PHP==="
php -v 2>/dev/null | head -n 1
BASH;
            $fullCmd = sprintf($script, $targetDir);
            $rawOutput = (string) $ssh->exec($fullCmd);
            return $this->parseServerPulseOutput($rawOutput, (string) $project['remote_path']);
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }

    /**
     * Reads remote .env and .env.example, comparing keys to detect missing environment variables.
     *
     * @return array<string, mixed>
     */
    public function getRemoteEnv(int $projectId): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $remotePath = trim((string) $project['remote_path']);
        if ($remotePath === '') {
            $remotePath = '~';
        }

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: $bundle['password'],
            privateKey: $bundle['private_key'],
            passphrase: $bundle['passphrase'],
            timeout: max(5, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $cdTarget = $this->buildCdTarget($remotePath);
            $cmd = "cd {$cdTarget} && pwd && (if [ -f shared/.env ]; then cat shared/.env; elif [ -f .env ]; then cat .env; elif [ -f current/.env ]; then cat current/.env; else echo '__DEPLOYER_NO_ENV__'; fi) && echo '===EXAMPLE===' && (if [ -f .env.example ]; then cat .env.example; elif [ -f current/.env.example ]; then cat current/.env.example; else echo '__DEPLOYER_NO_EXAMPLE__'; fi)";
            $output = (string) $ssh->exec($cmd);

            $parts = explode('===EXAMPLE===', $output, 2);
            $envPart = $parts[0] ?? '';
            $examplePart = $parts[1] ?? '';

            $envLines = explode("\n", trim($envPart));
            $pwd = array_shift($envLines);
            $envBody = implode("\n", $envLines);

            $hasEnv = !str_contains($envBody, '__DEPLOYER_NO_ENV__');
            $hasExample = !str_contains($examplePart, '__DEPLOYER_NO_EXAMPLE__');

            $envContent = $hasEnv ? trim($envBody) : '';
            $exampleContent = $hasExample ? trim($examplePart) : '';

            $envKeys = $this->extractEnvKeys($envContent);
            $exampleKeys = $this->extractEnvKeys($exampleContent);

            $missingInEnv = array_values(array_diff($exampleKeys, $envKeys));
            $extraInEnv = array_values(array_diff($envKeys, $exampleKeys));

            return [
                'current_dir'    => $pwd,
                'has_env'        => $hasEnv,
                'env'            => $envContent,
                'has_example'    => $hasExample,
                'env_example'    => $exampleContent,
                'missing_keys'   => $missingInEnv,
                'extra_keys'     => $extraInEnv,
            ];
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }

    /**
     * Creates an automated timestamped backup and saves new remote .env content.
     *
     * @return array<string, mixed>
     */
    public function saveRemoteEnv(int $projectId, string $content): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $remotePath = trim((string) $project['remote_path']);
        if ($remotePath === '') {
            $remotePath = '~';
        }

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: $bundle['password'],
            privateKey: $bundle['private_key'],
            passphrase: $bundle['passphrase'],
            timeout: max(5, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $cdTarget = $this->buildCdTarget($remotePath);
            $b64 = base64_encode($content);
            $timestamp = date('Ymd_His');

            $script = <<<BASH
cd {$cdTarget} || exit 1
TARGET_FILE=".env"
if [ -d "shared" ]; then
    TARGET_FILE="shared/.env"
fi
if [ -f "\$TARGET_FILE" ]; then
    cp "\$TARGET_FILE" "\${TARGET_FILE}.backup.{$timestamp}" || exit 2
fi
echo '{$b64}' | base64 -d > "\$TARGET_FILE" || exit 3
chmod 600 "\$TARGET_FILE" 2>/dev/null || true
echo "SAVED:\$TARGET_FILE:\${TARGET_FILE}.backup.{$timestamp}"
BASH;

            $output = trim((string) $ssh->exec($script));
            $exitCode = $ssh->getExitStatus();
            if ($exitCode !== 0) {
                throw new RuntimeException("Failed to save remote .env (exit code {$exitCode}): " . $output);
            }

            $this->logger->log([
                'project_id'   => $projectId,
                'project_name' => (string) $project['name'],
                'category'     => 'audit',
                'event_type'   => 'env_updated',
                'status'       => 'info',
                'title'        => 'Updated remote .env configuration',
                'ssh_target'   => $project['ssh_username'] . '@' . $project['ssh_host'],
                'output_log'   => "Remote .env updated. Backup created: .env.backup.{$timestamp}",
            ]);

            return [
                'ok'            => true,
                'message'       => 'Remote .env updated successfully.',
                'backup_file'   => ".env.backup.{$timestamp}",
            ];
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }

    /**
     * Checks HTTP health check URL and measures response time.
     *
     * @return array<string, mixed>
     */
    public function checkHealth(string $url): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return [
                'ok'          => false,
                'status_code' => 0,
                'duration_ms' => 0,
                'error'       => 'Invalid URL provided.',
            ];
        }

        $start = hrtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'SSHhh-Deployer-HealthCheck/1.0',
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
        $isOk = ($errno === 0 && $code >= 200 && $code < 400);

        return [
            'ok'          => $isOk,
            'status_code' => $code,
            'duration_ms' => $durationMs,
            'error'       => $errno !== 0 ? $error : ($isOk ? null : "HTTP {$code}"),
            'snippet'     => is_string($body) ? mb_substr(strip_tags($body), 0, 160) : '',
        ];
    }

    /**
     * Lists releases in releases/ folder and detects active symlink.
     *
     * @return array<string, mixed>
     */
    public function listReleases(int $projectId): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $remotePath = trim((string) $project['remote_path']);
        if ($remotePath === '') {
            $remotePath = '~';
        }

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: $bundle['password'],
            privateKey: $bundle['private_key'],
            passphrase: $bundle['passphrase'],
            timeout: max(5, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $cdTarget = $this->buildCdTarget($remotePath);
            $script = <<<BASH
cd {$cdTarget} || exit 1
CURRENT_LINK=""
if [ -L "current" ]; then
    CURRENT_LINK=$(readlink current | sed 's|.*/||')
fi
echo "CURRENT:\$CURRENT_LINK"
if [ -d "releases" ]; then
    cd releases
    ls -1 -dt 20* 2>/dev/null | head -n 25
fi
BASH;
            $output = trim((string) $ssh->exec($script));
            $lines = array_filter(array_map('trim', explode("\n", $output)), static fn($l) => $l !== '');
            $currentRelease = '';
            $releases = [];

            foreach ($lines as $line) {
                if (str_starts_with($line, 'CURRENT:')) {
                    $currentRelease = trim(substr($line, 8));
                } elseif (preg_match('/^\d{8}_\d{6}$/', $line) || preg_match('/^\d{14}$/', $line)) {
                    $releases[] = [
                        'id'        => $line,
                        'is_active' => ($line === $currentRelease),
                        'date'      => $this->formatReleaseDate($line),
                    ];
                }
            }

            return [
                'deploy_strategy' => $project['deploy_strategy'],
                'current_release' => $currentRelease,
                'releases'        => $releases,
            ];
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }

    /**
     * Atomically rolls back 'current' symlink to a previous release.
     *
     * @return array<string, mixed>
     */
    public function rollbackRelease(int $projectId, ?string $targetRelease = null): array
    {
        $bundle = $this->projects->getWithDecryptedCredentials($projectId);
        $project = $bundle['project'];
        $remotePath = trim((string) $project['remote_path']);
        if ($remotePath === '') {
            throw new InvalidArgumentException('Remote path is not configured.');
        }

        $list = $this->listReleases($projectId);
        $releases = $list['releases'];
        if (empty($releases)) {
            throw new RuntimeException('No historical releases found on remote server to roll back to.');
        }

        $chosen = $targetRelease;
        if ($chosen === null || $chosen === '') {
            $currIdx = null;
            foreach ($releases as $idx => $r) {
                if ($r['is_active']) {
                    $currIdx = $idx;
                    break;
                }
            }
            if ($currIdx !== null && isset($releases[$currIdx + 1])) {
                $chosen = $releases[$currIdx + 1]['id'];
            } elseif (isset($releases[0]) && !$releases[0]['is_active']) {
                $chosen = $releases[0]['id'];
            } elseif (isset($releases[1])) {
                $chosen = $releases[1]['id'];
            } else {
                throw new RuntimeException('Only one release exists; cannot roll back further.');
            }
        }

        $found = false;
        foreach ($releases as $r) {
            if ($r['id'] === $chosen) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new InvalidArgumentException("Release '{$chosen}' does not exist in releases directory.");
        }

        $conn = $this->connect(
            host: (string) $project['ssh_host'],
            port: (int) $project['ssh_port'],
            username: (string) $project['ssh_username'],
            authType: (string) $project['ssh_auth_type'],
            password: $bundle['password'],
            privateKey: $bundle['private_key'],
            passphrase: $bundle['passphrase'],
            timeout: max(5, min(20, (int) ($project['ssh_timeout'] ?? 10)))
        );

        /** @var SSH2 $ssh */
        $ssh = $conn['ssh'];

        try {
            $cdTarget = $this->buildCdTarget($remotePath);
            $script = <<<BASH
cd {$cdTarget} || exit 1
if [ ! -d "releases/{$chosen}" ]; then
    echo "ERROR: Target release directory does not exist."
    exit 2
fi
ln -sfn "releases/{$chosen}" current_new && mv -Tf current_new current || exit 3
if command -v php >/dev/null 2>&1; then
    (cd current && php artisan queue:restart 2>/dev/null || true)
fi
echo "ROLLBACK_OK"
BASH;
            $output = trim((string) $ssh->exec($script));
            if (!str_contains($output, 'ROLLBACK_OK')) {
                throw new RuntimeException("Rollback failed: " . $output);
            }

            $this->projects->updateCurrentRelease($projectId, $chosen);

            $this->logger->log([
                'project_id'   => $projectId,
                'project_name' => (string) $project['name'],
                'category'     => 'deployment',
                'event_type'   => 'rollback',
                'status'       => 'warning',
                'title'        => "Rolled back to release {$chosen}",
                'ssh_target'   => $project['ssh_username'] . '@' . $project['ssh_host'],
                'output_log'   => "Atomic symlink 'current' repointed to releases/{$chosen}.\nArtisan queue workers signaled to restart.",
            ]);

            return [
                'ok'             => true,
                'rolled_back_to' => $chosen,
                'message'        => "Successfully rolled back to release {$chosen}.",
            ];
        } finally {
            if ($ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }

    private function runPreDeployBackup(
        SSH2 $ssh,
        array $project,
        string $remotePath,
        callable $send,
        array &$transcript
    ): void {
        $start = hrtime(true);
        $cdTarget = $this->buildCdTarget($remotePath);
        $timestamp = date('Ymd_His');
        $backupFile = "storage/backups/db_backup_{$timestamp}.sql.gz";

        $msg = sprintf("[%s] [BACKUP] Creating pre-deploy database snapshot...", date('H:i:s'));
        $transcript[] = $msg;
        $send(['type' => 'chunk', 'step_number' => 0, 'data' => $msg . "\n"]);

        $backupScript = <<<BASH
cd {$cdTarget} || exit 1
mkdir -p storage/backups || exit 2
BACKUP_PATH="{$backupFile}"

# Extract DB credentials from .env if present
if [ -f ".env" ]; then
    DB_CONN=$(grep -E '^DB_CONNECTION=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
    DB_H=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
    DB_P=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
    DB_D=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
    DB_U=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
    DB_PW=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2- | tr -d '"\047\r ')
fi

if [ "\$DB_CONN" = "sqlite" ] || [ -f "database/database.sqlite" ]; then
    SQLITE_FILE=\${DB_D:-database/database.sqlite}
    if [ -f "\$SQLITE_FILE" ]; then
        gzip -c "\$SQLITE_FILE" > "\$BACKUP_PATH"
        echo "SUCCESS:SQLITE:\$(ls -lh \$BACKUP_PATH | awk '{print \$5}')"
        exit 0
    fi
fi

if command -v mysqldump >/dev/null 2>&1 && [ -n "\$DB_D" ]; then
    MYSQL_PWD="\$DB_PW" mysqldump -h "\${DB_H:-127.0.0.1}" -P "\${DB_P:-3306}" -u "\${DB_U:-root}" "\$DB_D" 2>/dev/null | gzip > "\$BACKUP_PATH"
    if [ -s "\$BACKUP_PATH" ]; then
        echo "SUCCESS:MYSQL:\$(ls -lh \$BACKUP_PATH | awk '{print \$5}')"
        exit 0
    fi
fi

if command -v pg_dump >/dev/null 2>&1 && [ -n "\$DB_D" ]; then
    PGPASSWORD="\$DB_PW" pg_dump -h "\${DB_H:-127.0.0.1}" -p "\${DB_P:-5432}" -U "\${DB_U:-postgres}" "\$DB_D" 2>/dev/null | gzip > "\$BACKUP_PATH"
    if [ -s "\$BACKUP_PATH" ]; then
        echo "SUCCESS:POSTGRES:\$(ls -lh \$BACKUP_PATH | awk '{print \$5}')"
        exit 0
    fi
fi

echo "SKIPPED:No database found or dump utility unavailable."
BASH;

        $res = trim((string) $ssh->exec($backupScript));
        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if (str_starts_with($res, 'SUCCESS:')) {
            $parts = explode(':', $res);
            $size = $parts[2] ?? '';
            $okMsg = sprintf("[%s] [BACKUP] Snapshot saved: %s (%s) in %s\n", date('H:i:s'), $backupFile, $size, $this->formatDuration($durationMs));
            $transcript[] = $okMsg;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $okMsg]);
        } else {
            $warnMsg = sprintf("[%s] [BACKUP] Notice: %s\n", date('H:i:s'), $res ?: 'Could not generate database snapshot (continuing pipeline).');
            $transcript[] = $warnMsg;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $warnMsg]);
        }
    }

    private function prepareSymlinkRelease(
        SSH2 $ssh,
        array $project,
        string $basePath,
        callable $send,
        array &$transcript
    ): ?array {
        $timestamp = date('Ymd_His');
        $cdTarget = $this->buildCdTarget($basePath);
        $repoBranch = escapeshellarg($project['repo_branch'] ?: 'main');

        $msg = sprintf("[%s] [ATOMIC] Preparing zero-downtime release: releases/%s", date('H:i:s'), $timestamp);
        $transcript[] = $msg;
        $send(['type' => 'chunk', 'step_number' => 0, 'data' => $msg . "\n"]);

        $script = <<<BASH
cd {$cdTarget} || exit 1
mkdir -p releases/{$timestamp} shared/storage shared/bootstrap/cache || exit 2

if [ ! -f "shared/.env" ]; then
    if [ -f ".env" ]; then
        cp .env shared/.env
    elif [ -f "current/.env" ]; then
        cp current/.env shared/.env
    fi
fi

if [ -d ".git" ]; then
    git archive {$repoBranch} | tar -x -C "releases/{$timestamp}" || exit 3
elif [ -d "current/.git" ]; then
    (cd current && git archive {$repoBranch} | tar -x -C "../releases/{$timestamp}") || exit 3
fi

rm -rf "releases/{$timestamp}/storage"
ln -sfn "../../shared/storage" "releases/{$timestamp}/storage"

rm -f "releases/{$timestamp}/.env"
ln -sfn "../../shared/.env" "releases/{$timestamp}/.env"

echo "RELEASE_PREPARED:releases/{$timestamp}"
BASH;

        $out = trim((string) $ssh->exec($script));
        if (!str_contains($out, 'RELEASE_PREPARED')) {
            $warn = sprintf("[%s] [ATOMIC WARNING] Symlink prep issue: %s. Continuing in base directory.\n", date('H:i:s'), $out);
            $transcript[] = $warn;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $warn]);
            return null;
        }

        return [
            'base_path'    => $basePath,
            'timestamp'    => $timestamp,
            'release_dir'  => rtrim($basePath, '/\\') . "/releases/{$timestamp}",
        ];
    }

    private function activateSymlinkRelease(
        SSH2 $ssh,
        array $project,
        array $symlinkInfo,
        callable $send,
        array &$transcript
    ): void {
        $basePath = $symlinkInfo['base_path'];
        $timestamp = $symlinkInfo['timestamp'];
        $cdTarget = $this->buildCdTarget($basePath);
        $keep = max(2, min(30, (int) ($project['releases_to_keep'] ?? 5)));

        $actScript = <<<BASH
cd {$cdTarget} || exit 1
ln -sfn "releases/{$timestamp}" current_new && mv -Tf current_new current || exit 2

if [ -d "releases" ]; then
    (cd releases && ls -1 -dt 20* 2>/dev/null | tail -n +$(({$keep} + 1)) | xargs rm -rf 2>/dev/null || true)
fi

echo "RELEASE_ACTIVE:{$timestamp}"
BASH;

        $out = trim((string) $ssh->exec($actScript));
        if (str_contains($out, 'RELEASE_ACTIVE')) {
            $this->projects->updateCurrentRelease((int) $project['id'], $timestamp);
            $msg = sprintf("[%s] [ATOMIC] Zero-downtime release %s activated successfully! 'current' symlink updated.\n", date('H:i:s'), $timestamp);
            $transcript[] = $msg;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $msg]);
        }
    }

    private function runHealthCheckVerification(
        array $project,
        callable $send,
        array &$transcript
    ): void {
        $url = (string) $project['health_check_url'];
        $msg = sprintf("[%s] [HEALTH] Checking health URL: %s", date('H:i:s'), $url);
        $transcript[] = $msg;
        $send(['type' => 'chunk', 'step_number' => 0, 'data' => $msg . "\n"]);

        $res = $this->checkHealth($url);
        $projectId = (int) $project['id'];

        if ($res['ok']) {
            $this->projects->updateHealthStatus($projectId, 'healthy', $res['status_code']);
            $okMsg = sprintf("[%s] [HEALTH VERIFIED] HTTP %d OK in %d ms! Site is responding normally.\n", date('H:i:s'), $res['status_code'], $res['duration_ms']);
            $transcript[] = $okMsg;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $okMsg]);
        } else {
            $this->projects->updateHealthStatus($projectId, 'unhealthy', $res['status_code']);
            $failMsg = sprintf("[%s] [HEALTH WARNING] Health check returned %s (%d ms). Check server logs.\n", date('H:i:s'), $res['error'] ?? ('HTTP ' . $res['status_code']), $res['duration_ms']);
            $transcript[] = $failMsg;
            $send(['type' => 'chunk', 'step_number' => 0, 'data' => $failMsg]);
        }
    }

    private function parseServerPulseOutput(string $raw, string $remotePath): array
    {
        $sections = [
            'os'       => '',
            'uptime'   => '',
            'memory'   => '',
            'disk'     => '',
            'services' => '',
            'php'      => '',
        ];

        $currentSection = null;
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $trim = trim($line);
            if (preg_match('/^===([A-Z]+)===$/', $trim, $m)) {
                $secName = strtolower($m[1]);
                $currentSection = array_key_exists($secName, $sections) ? $secName : null;
                continue;
            }
            if ($currentSection !== null) {
                $sections[$currentSection] .= $line . "\n";
            }
        }

        $os = trim($sections['os']);

        $uptimeRaw = trim($sections['uptime']);
        $loadAverages = [0.0, 0.0, 0.0];
        if (preg_match('/load average[s]?:\s*([0-9.]+),?\s*([0-9.]+),?\s*([0-9.]+)/i', $uptimeRaw, $lm)) {
            $loadAverages = [(float) $lm[1], (float) $lm[2], (float) $lm[3]];
        }

        $ram = [
            'total_mb'     => null,
            'used_mb'      => null,
            'free_mb'      => null,
            'used_percent' => null,
        ];
        foreach (explode("\n", $sections['memory']) as $mLine) {
            if (str_starts_with(trim($mLine), 'Mem:')) {
                $parts = preg_split('/\s+/', trim($mLine));
                if (count($parts) >= 3) {
                    $tot = (int) $parts[1];
                    $used = (int) $parts[2];
                    $ram['total_mb'] = $tot;
                    $ram['used_mb'] = $used;
                    $ram['free_mb'] = max(0, $tot - $used);
                    $ram['used_percent'] = $tot > 0 ? (int) round(($used / $tot) * 100) : 0;
                }
                break;
            }
        }

        $disk = [
            'filesystem'   => null,
            'size'         => null,
            'used'         => null,
            'avail'        => null,
            'used_percent' => null,
            'mount'        => null,
        ];
        $diskLines = array_values(array_filter(array_map('trim', explode("\n", $sections['disk']))));
        if (count($diskLines) >= 2) {
            $dataLine = $diskLines[1];
            $parts = preg_split('/\s+/', $dataLine);
            if (count($parts) >= 6) {
                $disk['filesystem'] = $parts[0];
                $disk['size'] = $parts[1];
                $disk['used'] = $parts[2];
                $disk['avail'] = $parts[3];
                $disk['used_percent'] = (int) rtrim($parts[4], '%');
                $disk['mount'] = $parts[5];
            }
        }

        $services = [];
        foreach (explode("\n", trim($sections['services'])) as $sLine) {
            $sLine = trim($sLine);
            if (str_contains($sLine, ':')) {
                [$svc, $st] = explode(':', $sLine, 2);
                $services[] = [
                    'name'   => trim($svc),
                    'status' => trim($st),
                ];
            }
        }

        $php = trim($sections['php']);

        return [
            'os'          => $os,
            'uptime_raw'  => $uptimeRaw,
            'cpu_load'    => $loadAverages,
            'ram'         => $ram,
            'disk'        => $disk,
            'services'    => $services,
            'php_version' => $php,
        ];
    }

    private function extractEnvKeys(string $content): array
    {
        $keys = [];
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                $key = trim(explode('=', $line, 2)[0]);
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                    $keys[] = $key;
                }
            }
        }
        return array_values(array_unique($keys));
    }

    private function formatReleaseDate(string $rel): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $rel, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        return $rel;
    }
}

