<?php

declare(strict_types=1);

namespace Deployer;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ProjectRepository
{
    public function __construct(
        private PDO $pdo,
        private Crypto $crypto,
        private EventLogger $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $sql = <<<'SQL'
            SELECT
                p.*,
                (SELECT COUNT(*) FROM `project_commands` c WHERE c.`project_id` = p.`id`) AS `command_count`,
                (SELECT COUNT(*) FROM `project_commands` c WHERE c.`project_id` = p.`id` AND c.`is_enabled` = 1) AS `enabled_command_count`,
                (SELECT COUNT(*) FROM `event_logs` e WHERE e.`project_id` = p.`id` AND e.`category` = 'deployment') AS `deployment_count`
            FROM `projects` p
            ORDER BY p.`updated_at` DESC, p.`id` DESC
        SQL;

        $rows = $this->pdo->query($sql)->fetchAll();
        return array_map([$this, 'sanitizeProjectRow'], $rows);
    }

    /**
     * @return array{total_projects:int,total_deployments:int,successful_deployments:int,failed_deployments:int}
     */
    public function getDashboardStats(): array
    {
        $totalProjects = (int) $this->pdo->query('SELECT COUNT(*) FROM `projects`')->fetchColumn();
        $deployStatsStmt = $this->pdo->query(<<<'SQL'
            SELECT
                COUNT(*) AS `total`,
                SUM(CASE WHEN `status` = 'success' THEN 1 ELSE 0 END) AS `succeeded`,
                SUM(CASE WHEN `status` = 'failed' THEN 1 ELSE 0 END) AS `failed`
            FROM `event_logs`
            WHERE `category` = 'deployment'
        SQL);
        $dRow = $deployStatsStmt->fetch() ?: [];

        return [
            'total_projects'         => $totalProjects,
            'total_deployments'      => (int) ($dRow['total'] ?? 0),
            'successful_deployments' => (int) ($dRow['succeeded'] ?? 0),
            'failed_deployments'     => (int) ($dRow['failed'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $sql = <<<'SQL'
            SELECT
                p.*,
                (SELECT COUNT(*) FROM `project_commands` c WHERE c.`project_id` = p.`id`) AS `command_count`,
                (SELECT COUNT(*) FROM `project_commands` c WHERE c.`project_id` = p.`id` AND c.`is_enabled` = 1) AS `enabled_command_count`,
                (SELECT COUNT(*) FROM `event_logs` e WHERE e.`project_id` = p.`id` AND e.`category` = 'deployment') AS `deployment_count`
            FROM `projects` p
            WHERE p.`id` = :id
            LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->sanitizeProjectRow($row) : null;
    }

    /**
     * Internal use by SshRunner only — never expose over HTTP responses.
     *
     * @return array{
     *   project: array<string, mixed>,
     *   password: string,
     *   private_key: string,
     *   passphrase: string
     * }
     */
    public function getWithDecryptedCredentials(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `projects` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new InvalidArgumentException('Project not found.');
        }

        $password = !empty($row['ssh_password_encrypted'])
            ? $this->crypto->decrypt((string) $row['ssh_password_encrypted'], 'ssh-password')
            : '';

        $privateKey = !empty($row['ssh_private_key_encrypted'])
            ? $this->crypto->decrypt((string) $row['ssh_private_key_encrypted'], 'ssh-private-key')
            : '';

        $passphrase = !empty($row['ssh_passphrase_encrypted'])
            ? $this->crypto->decrypt((string) $row['ssh_passphrase_encrypted'], 'ssh-passphrase')
            : '';

        return [
            'project'     => $this->sanitizeProjectRow($row),
            'password'    => $password,
            'private_key' => $privateKey,
            'passphrase'  => $passphrase,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $sourceProjectId = isset($input['source_project_id']) && (int) $input['source_project_id'] > 0
            ? (int) $input['source_project_id']
            : null;
        $reuseSourceCredentials = $sourceProjectId !== null && !empty($input['reuse_source_credentials']);
        $sourceBundle = $reuseSourceCredentials
            ? $this->getWithDecryptedCredentials($sourceProjectId)
            : null;
        $sourceProjectMeta = $sourceBundle['project']
            ?? ($sourceProjectId !== null ? $this->find($sourceProjectId) : null);

        $resolvedInput = $input;
        $reusedFromSourceVault = false;
        if ($sourceBundle !== null) {
            $rawPassword = (string) ($input['ssh_password'] ?? '');
            $rawPrivateKey = trim((string) ($input['ssh_private_key'] ?? ''));
            $rawPassphrase = (string) ($input['ssh_passphrase'] ?? '');

            if ($rawPassword === '' && $sourceBundle['password'] !== '') {
                $resolvedInput['ssh_password'] = $sourceBundle['password'];
                $reusedFromSourceVault = true;
            }
            if ($rawPrivateKey === '' && $sourceBundle['private_key'] !== '') {
                $resolvedInput['ssh_private_key'] = $sourceBundle['private_key'];
                if ($rawPassphrase === '' && $sourceBundle['passphrase'] !== '') {
                    $resolvedInput['ssh_passphrase'] = $sourceBundle['passphrase'];
                }
                $reusedFromSourceVault = true;
            }
        }

        $data = $this->validateProjectInput($resolvedInput, true);

        // Pre-validate template project before opening transaction if cloning from an existing project
        $preset = trim((string) ($input['preset'] ?? 'git_basic'));
        $templateProject = null;
        $clonedCommands = null;
        if (str_starts_with($preset, 'project:')) {
            $templateProjectId = (int) substr($preset, 8);
            $templateProject = $templateProjectId > 0 ? $this->find($templateProjectId) : null;
            if (!$templateProject) {
                throw new InvalidArgumentException('Selected command template project was not found.');
            }
            $clonedCommands = $this->listCommands($templateProjectId);
        }

        $passwordEnc = $data['ssh_password'] !== ''
            ? $this->crypto->encrypt($data['ssh_password'], 'ssh-password')
            : null;

        $privateKeyEnc = $data['ssh_private_key'] !== ''
            ? $this->crypto->encrypt($data['ssh_private_key'], 'ssh-private-key')
            : null;

        $passphraseEnc = $data['ssh_passphrase'] !== ''
            ? $this->crypto->encrypt($data['ssh_passphrase'], 'ssh-passphrase')
            : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                INSERT INTO `projects` (
                    `name`,
                    `environment`,
                    `description`,
                    `repo_branch`,
                    `remote_path`,
                    `ssh_host`,
                    `ssh_port`,
                    `ssh_username`,
                    `ssh_auth_type`,
                    `ssh_password_encrypted`,
                    `ssh_private_key_encrypted`,
                    `ssh_passphrase_encrypted`,
                    `ssh_timeout`,
                    `command_timeout`
                ) VALUES (
                    :name,
                    :environment,
                    :description,
                    :repo_branch,
                    :remote_path,
                    :ssh_host,
                    :ssh_port,
                    :ssh_username,
                    :ssh_auth_type,
                    :ssh_password_encrypted,
                    :ssh_private_key_encrypted,
                    :ssh_passphrase_encrypted,
                    :ssh_timeout,
                    :command_timeout
                )
            SQL);

            $stmt->execute([
                ':name'                     => $data['name'],
                ':environment'              => $data['environment'],
                ':description'              => $data['description'],
                ':repo_branch'              => $data['repo_branch'],
                ':remote_path'              => $data['remote_path'],
                ':ssh_host'                 => $data['ssh_host'],
                ':ssh_port'                 => $data['ssh_port'],
                ':ssh_username'             => $data['ssh_username'],
                ':ssh_auth_type'            => $data['ssh_auth_type'],
                ':ssh_password_encrypted'   => $passwordEnc,
                ':ssh_private_key_encrypted'=> $privateKeyEnc,
                ':ssh_passphrase_encrypted' => $passphraseEnc,
                ':ssh_timeout'              => $data['ssh_timeout'],
                ':command_timeout'          => $data['command_timeout'],
            ]);

            $projectId = (int) $this->pdo->lastInsertId();

            // Seed command pipeline from either a cloned project template or a built-in starter preset
            $cmdStmt = $this->pdo->prepare(<<<'SQL'
                INSERT INTO `project_commands` (`project_id`, `name`, `command`, `sort_order`, `is_enabled`, `stop_on_error`)
                VALUES (:project_id, :name, :command, :sort_order, :is_enabled, :stop_on_error)
            SQL);

            $order = 1;
            $seededCount = 0;
            if ($templateProject !== null && is_array($clonedCommands)) {
                foreach ($clonedCommands as $cmd) {
                    $cmdStmt->execute([
                        ':project_id'    => $projectId,
                        ':name'          => (string) $cmd['name'],
                        ':command'       => (string) $cmd['command'],
                        ':sort_order'    => $order++,
                        ':is_enabled'    => !empty($cmd['is_enabled']) ? 1 : 0,
                        ':stop_on_error' => !empty($cmd['stop_on_error']) ? 1 : 0,
                    ]);
                    $seededCount++;
                }
                $templateSummary = sprintf(
                    'Cloned %d commands from "%s" (#%d)',
                    $seededCount,
                    (string) $templateProject['name'],
                    (int) $templateProject['id']
                );
            } else {
                $starterCommands = $this->getStarterPresetCommands($preset, $data['repo_branch']);
                foreach ($starterCommands as $cmd) {
                    $cmdStmt->execute([
                        ':project_id'    => $projectId,
                        ':name'          => $cmd['name'],
                        ':command'       => $cmd['command'],
                        ':sort_order'    => $order++,
                        ':is_enabled'    => 1,
                        ':stop_on_error' => 1,
                    ]);
                    $seededCount++;
                }
                $templateSummary = sprintf('%d (%s preset)', $seededCount, $preset !== '' ? $preset : 'git_basic');
            }

            $target = $this->formatSshTarget($data['ssh_username'], $data['ssh_host'], $data['ssh_port'], $data['remote_path']);
            $vaultStatus = ($passwordEnc !== null || $privateKeyEnc !== null)
                ? ($reusedFromSourceVault ? 're-encrypted from source vault with fresh AES-256-GCM IV' : 'encrypted with AES-256-GCM')
                : 'no secret stored yet';

            $eventTitle = $sourceProjectMeta !== null
                ? sprintf(
                    'Created project "%s" (%s) duplicated from "%s"',
                    $data['name'],
                    strtoupper($data['environment']),
                    (string) $sourceProjectMeta['name']
                )
                : sprintf('Created project "%s" (%s)', $data['name'], strtoupper($data['environment']));

            $this->logger->log([
                'project_id'   => $projectId,
                'project_name' => $data['name'],
                'category'     => 'audit',
                'event_type'   => 'project_created',
                'status'       => 'info',
                'title'        => $eventTitle,
                'ssh_target'   => $target,
                'output_log'   => sprintf(
                    "Project initialized.\nEnvironment: %s\nBranch: %s\nSSH Target: %s\nAuth Method: %s (%s)\nCommand Template: %s",
                    $data['environment'],
                    $data['repo_branch'],
                    $target,
                    $data['ssh_auth_type'],
                    $vaultStatus,
                    $templateSummary
                ),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $created = $this->find($projectId);
        if (!$created) {
            throw new RuntimeException('Failed to load newly created project.');
        }

        return $created;
    }

    /**
     * Updates project details and/or SSH settings while keeping existing encrypted credentials if blank.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `projects` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            throw new InvalidArgumentException('Project not found.');
        }

        // Allow partial updates (e.g. updating SSH settings only or project info only)
        $mergedInput = [
            'name'            => $input['name'] ?? $existing['name'],
            'environment'     => $input['environment'] ?? $existing['environment'],
            'description'     => array_key_exists('description', $input) ? $input['description'] : $existing['description'],
            'repo_branch'     => $input['repo_branch'] ?? $existing['repo_branch'],
            'remote_path'     => array_key_exists('remote_path', $input) ? $input['remote_path'] : $existing['remote_path'],
            'ssh_host'        => $input['ssh_host'] ?? $existing['ssh_host'],
            'ssh_port'        => $input['ssh_port'] ?? $existing['ssh_port'],
            'ssh_username'    => $input['ssh_username'] ?? $existing['ssh_username'],
            'ssh_auth_type'   => $input['ssh_auth_type'] ?? $existing['ssh_auth_type'],
            'ssh_timeout'     => $input['ssh_timeout'] ?? $existing['ssh_timeout'],
            'command_timeout' => $input['command_timeout'] ?? $existing['command_timeout'],
            'ssh_password'    => $input['ssh_password'] ?? '',
            'ssh_private_key' => $input['ssh_private_key'] ?? '',
            'ssh_passphrase'  => $input['ssh_passphrase'] ?? '',
        ];

        $data = $this->validateProjectInput($mergedInput, false);

        $passwordEnc = $existing['ssh_password_encrypted'];
        $passwordChanged = false;
        if (!empty($input['clear_password'])) {
            $passwordEnc = null;
            $passwordChanged = true;
        } elseif ($data['ssh_password'] !== '') {
            $passwordEnc = $this->crypto->encrypt($data['ssh_password'], 'ssh-password');
            $passwordChanged = true;
        }

        $privateKeyEnc = $existing['ssh_private_key_encrypted'];
        $keyChanged = false;
        if (!empty($input['clear_private_key'])) {
            $privateKeyEnc = null;
            $keyChanged = true;
        } elseif ($data['ssh_private_key'] !== '') {
            $privateKeyEnc = $this->crypto->encrypt($data['ssh_private_key'], 'ssh-private-key');
            $keyChanged = true;
        }

        $passphraseEnc = $existing['ssh_passphrase_encrypted'];
        $passphraseChanged = false;
        if (!empty($input['clear_passphrase'])) {
            $passphraseEnc = null;
            $passphraseChanged = true;
        } elseif ($data['ssh_passphrase'] !== '') {
            $passphraseEnc = $this->crypto->encrypt($data['ssh_passphrase'], 'ssh-passphrase');
            $passphraseChanged = true;
        }

        $hostChanged = ($existing['ssh_host'] !== $data['ssh_host']) || ((int) $existing['ssh_port'] !== $data['ssh_port']);
        $fingerprint = $hostChanged ? null : $existing['host_fingerprint'];

        $updStmt = $this->pdo->prepare(<<<'SQL'
            UPDATE `projects` SET
                `name` = :name,
                `environment` = :environment,
                `description` = :description,
                `repo_branch` = :repo_branch,
                `remote_path` = :remote_path,
                `ssh_host` = :ssh_host,
                `ssh_port` = :ssh_port,
                `ssh_username` = :ssh_username,
                `ssh_auth_type` = :ssh_auth_type,
                `ssh_password_encrypted` = :ssh_password_encrypted,
                `ssh_private_key_encrypted` = :ssh_private_key_encrypted,
                `ssh_passphrase_encrypted` = :ssh_passphrase_encrypted,
                `ssh_timeout` = :ssh_timeout,
                `command_timeout` = :command_timeout,
                `host_fingerprint` = :host_fingerprint
            WHERE `id` = :id
        SQL);

        $updStmt->execute([
            ':name'                      => $data['name'],
            ':environment'               => $data['environment'],
            ':description'               => $data['description'],
            ':repo_branch'               => $data['repo_branch'],
            ':remote_path'               => $data['remote_path'],
            ':ssh_host'                  => $data['ssh_host'],
            ':ssh_port'                  => $data['ssh_port'],
            ':ssh_username'              => $data['ssh_username'],
            ':ssh_auth_type'             => $data['ssh_auth_type'],
            ':ssh_password_encrypted'    => $passwordEnc,
            ':ssh_private_key_encrypted' => $privateKeyEnc,
            ':ssh_passphrase_encrypted'  => $passphraseEnc,
            ':ssh_timeout'               => $data['ssh_timeout'],
            ':command_timeout'           => $data['command_timeout'],
            ':host_fingerprint'          => $fingerprint,
            ':id'                        => $id,
        ]);

        // Build clean, secret-free audit diff
        $changes = [];
        $sshTouched = false;
        foreach (['name', 'environment', 'repo_branch', 'remote_path', 'description'] as $field) {
            if ((string) ($existing[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                $changes[] = sprintf('%s: "%s" -> "%s"', $field, (string) ($existing[$field] ?? ''), (string) ($data[$field] ?? ''));
            }
        }
        foreach (['ssh_host', 'ssh_port', 'ssh_username', 'ssh_auth_type', 'ssh_timeout', 'command_timeout'] as $sField) {
            if ((string) ($existing[$sField] ?? '') !== (string) ($data[$sField] ?? '')) {
                $changes[] = sprintf('%s: "%s" -> "%s"', $sField, (string) ($existing[$sField] ?? ''), (string) ($data[$sField] ?? ''));
                $sshTouched = true;
            }
        }
        if ($passwordChanged) {
            $changes[] = $passwordEnc !== null
                ? 'ssh_password: updated & encrypted (AES-256-GCM)'
                : 'ssh_password: cleared';
            $sshTouched = true;
        }
        if ($keyChanged) {
            $changes[] = $privateKeyEnc !== null
                ? 'ssh_private_key: updated & encrypted (AES-256-GCM)'
                : 'ssh_private_key: cleared';
            $sshTouched = true;
        }
        if ($passphraseChanged) {
            $changes[] = $passphraseEnc !== null
                ? 'ssh_passphrase: updated & encrypted (AES-256-GCM)'
                : 'ssh_passphrase: cleared';
            $sshTouched = true;
        }

        if ($changes) {
            $target = $this->formatSshTarget($data['ssh_username'], $data['ssh_host'], $data['ssh_port'], $data['remote_path']);
            $this->logger->log([
                'project_id'   => $id,
                'project_name' => $data['name'],
                'category'     => 'audit',
                'event_type'   => $sshTouched ? 'ssh_updated' : 'project_updated',
                'status'       => 'info',
                'title'        => $sshTouched
                    ? 'Updated project & SSH configuration'
                    : 'Updated project settings',
                'ssh_target'   => $target,
                'output_log'   => "Configuration changes applied:\n- " . implode("\n- ", $changes),
            ]);
        }

        $updated = $this->find($id);
        if (!$updated) {
            throw new RuntimeException('Failed to reload project after update.');
        }

        return $updated;
    }

    public function delete(int $id): void
    {
        $project = $this->find($id);
        if (!$project) {
            throw new InvalidArgumentException('Project not found.');
        }

        $target = $this->formatSshTarget(
            (string) $project['ssh_username'],
            (string) $project['ssh_host'],
            (int) $project['ssh_port'],
            (string) $project['remote_path']
        );

        $this->logger->log([
            'project_id'   => $id,
            'project_name' => (string) $project['name'],
            'category'     => 'audit',
            'event_type'   => 'project_deleted',
            'status'       => 'warning',
            'title'        => sprintf('Deleted project "%s"', $project['name']),
            'ssh_target'   => $target,
        ]);

        $stmt = $this->pdo->prepare('DELETE FROM `projects` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCommands(int $projectId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT *
            FROM `project_commands`
            WHERE `project_id` = :project_id
            ORDER BY `sort_order` ASC, `id` ASC
        SQL);
        $stmt->execute([':project_id' => $projectId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->hydrateCommandRow($row);
        }
        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCommand(int $projectId, int $commandId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT *
            FROM `project_commands`
            WHERE `id` = :id AND `project_id` = :project_id
            LIMIT 1
        SQL);
        $stmt->execute([':id' => $commandId, ':project_id' => $projectId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateCommandRow($row) : null;
    }

    /**
     * Atomically syncs the entire ordered deployment command list for a project.
     *
     * @param array<int, array<string, mixed>> $commandsInput
     * @return array<int, array<string, mixed>>
     */
    public function syncCommands(int $projectId, array $commandsInput, bool $silentIfUnchanged = true): array
    {
        $project = $this->find($projectId);
        if (!$project) {
            throw new InvalidArgumentException('Project not found.');
        }

        $existingRows = $this->listCommands($projectId);
        $existingMap = [];
        foreach ($existingRows as $row) {
            $existingMap[(int) $row['id']] = $row;
        }

        $normalized = [];
        $order = 1;
        foreach ($commandsInput as $idx => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $cmdText = trim((string) ($raw['command'] ?? ''));
            $nameText = trim((string) ($raw['name'] ?? ''));

            if ($cmdText === '') {
                throw new InvalidArgumentException(sprintf('Step #%d cannot have an empty shell command.', $idx + 1));
            }
            if ($nameText === '') {
                // Auto-derive label from first line of command if omitted
                $firstLine = trim(explode("\n", $cmdText)[0]);
                $nameText = mb_substr($firstLine, 0, 80);
            }

            $cmdId = isset($raw['id']) && (int) $raw['id'] > 0 ? (int) $raw['id'] : null;
            if ($cmdId !== null && !isset($existingMap[$cmdId])) {
                $cmdId = null;
            }

            $normalized[] = [
                'id'            => $cmdId,
                'name'          => mb_substr($nameText, 0, 150),
                'command'       => $cmdText,
                'sort_order'    => $order++,
                'is_enabled'    => !empty($raw['is_enabled']) ? 1 : 0,
                'stop_on_error' => array_key_exists('stop_on_error', $raw) ? (!empty($raw['stop_on_error']) ? 1 : 0) : 1,
            ];
        }

        // Detect whether anything actually changed
        $addedCount = 0;
        $updatedCount = 0;
        $keptIds = [];
        foreach ($normalized as $item) {
            if ($item['id'] === null) {
                $addedCount++;
            } else {
                $keptIds[] = $item['id'];
                $prev = $existingMap[$item['id']];
                if (
                    $prev['name'] !== $item['name']
                    || $prev['command'] !== $item['command']
                    || (int) $prev['sort_order'] !== $item['sort_order']
                    || (int) $prev['is_enabled'] !== $item['is_enabled']
                    || (int) $prev['stop_on_error'] !== $item['stop_on_error']
                ) {
                    $updatedCount++;
                }
            }
        }
        $deletedIds = array_values(array_diff(array_keys($existingMap), $keptIds));
        $deletedCount = count($deletedIds);
        $hasChanges = ($addedCount > 0 || $updatedCount > 0 || $deletedCount > 0);

        if (!$hasChanges && $silentIfUnchanged) {
            return $existingRows;
        }

        $this->pdo->beginTransaction();
        try {
            if ($deletedIds) {
                $placeholders = implode(',', array_fill(0, count($deletedIds), '?'));
                $delStmt = $this->pdo->prepare(
                    "DELETE FROM `project_commands` WHERE `project_id` = ? AND `id` IN ({$placeholders})"
                );
                $delStmt->execute(array_merge([$projectId], $deletedIds));
            }

            $insStmt = $this->pdo->prepare(<<<'SQL'
                INSERT INTO `project_commands` (
                    `project_id`, `name`, `command`, `sort_order`, `is_enabled`, `stop_on_error`
                ) VALUES (
                    :project_id, :name, :command, :sort_order, :is_enabled, :stop_on_error
                )
            SQL);

            $updStmt = $this->pdo->prepare(<<<'SQL'
                UPDATE `project_commands` SET
                    `name` = :name,
                    `command` = :command,
                    `sort_order` = :sort_order,
                    `is_enabled` = :is_enabled,
                    `stop_on_error` = :stop_on_error
                WHERE `id` = :id AND `project_id` = :project_id
            SQL);

            foreach ($normalized as $item) {
                if ($item['id'] === null) {
                    $insStmt->execute([
                        ':project_id'    => $projectId,
                        ':name'          => $item['name'],
                        ':command'       => $item['command'],
                        ':sort_order'    => $item['sort_order'],
                        ':is_enabled'    => $item['is_enabled'],
                        ':stop_on_error' => $item['stop_on_error'],
                    ]);
                } else {
                    $updStmt->execute([
                        ':id'            => $item['id'],
                        ':project_id'    => $projectId,
                        ':name'          => $item['name'],
                        ':command'       => $item['command'],
                        ':sort_order'    => $item['sort_order'],
                        ':is_enabled'    => $item['is_enabled'],
                        ':stop_on_error' => $item['stop_on_error'],
                    ]);
                }
            }

            if ($hasChanges) {
                $summaryLines = [];
                foreach ($normalized as $item) {
                    $flag = $item['is_enabled'] ? 'ENABLED' : 'SKIPPED';
                    $summaryLines[] = sprintf('#%d [%s] %s: %s', $item['sort_order'], $flag, $item['name'], $item['command']);
                }

                $this->logger->log([
                    'project_id'   => $projectId,
                    'project_name' => (string) $project['name'],
                    'category'     => 'audit',
                    'event_type'   => 'commands_saved',
                    'status'       => 'info',
                    'title'        => sprintf(
                        'Saved deployment commands (%d total: +%d added, %d edited, -%d removed)',
                        count($normalized),
                        $addedCount,
                        $updatedCount,
                        $deletedCount
                    ),
                    'output_log'   => $summaryLines
                        ? ("Active command pipeline:\n" . implode("\n", $summaryLines))
                        : 'All deployment commands were removed.',
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->listCommands($projectId);
    }

    public function updateCommandExecutionMetrics(int $commandId, int $exitCode, int $durationMs): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE `project_commands`
            SET `last_exit_code` = :exit_code,
                `last_run_at` = :last_run_at,
                `last_duration_ms` = :duration_ms
            WHERE `id` = :id
        SQL);
        $stmt->execute([
            ':exit_code'   => $exitCode,
            ':last_run_at' => date('Y-m-d H:i:s'),
            ':duration_ms' => $durationMs,
            ':id'          => $commandId,
        ]);
    }

    public function updateProjectDeployStatus(int $projectId, string $status, ?int $durationMs = null): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE `projects`
            SET `last_deploy_status` = :status,
                `last_deployed_at` = :deployed_at,
                `last_deploy_duration_ms` = :duration_ms
            WHERE `id` = :id
        SQL);
        $stmt->execute([
            ':status'      => $status,
            ':deployed_at' => date('Y-m-d H:i:s'),
            ':duration_ms' => $durationMs,
            ':id'          => $projectId,
        ]);
    }

    public function updateHostFingerprint(int $projectId, string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE `projects` SET `host_fingerprint` = :fp WHERE `id` = :id');
        $stmt->execute([':fp' => mb_substr($fingerprint, 0, 255), ':id' => $projectId]);
    }

    public function formatSshTarget(string $user, string $host, int $port, string $path = ''): string
    {
        $base = sprintf('%s@%s:%d', $user, $host, $port);
        return $path !== '' ? sprintf('%s (%s)', $base, $path) : $base;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateProjectInput(array $input, bool $isCreate): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Project name is required.');
        }

        $env = strtolower(trim((string) ($input['environment'] ?? 'production')));
        if (!in_array($env, ['production', 'staging', 'development', 'testing'], true)) {
            $env = 'production';
        }

        $description = trim((string) ($input['description'] ?? ''));
        $branch = trim((string) ($input['repo_branch'] ?? 'main')) ?: 'main';
        $remotePath = trim((string) ($input['remote_path'] ?? ''));

        $sshHost = trim((string) ($input['ssh_host'] ?? ''));
        if ($sshHost === '') {
            throw new InvalidArgumentException('SSH Host / IP address is required.');
        }
        if (preg_match('/[\s\/\\\\]/', $sshHost)) {
            throw new InvalidArgumentException('SSH Host must be a valid hostname or IP address without spaces or slashes.');
        }

        $sshPort = (int) ($input['ssh_port'] ?? 22);
        if ($sshPort < 1 || $sshPort > 65535) {
            throw new InvalidArgumentException('SSH Port must be between 1 and 65535.');
        }

        $sshUser = trim((string) ($input['ssh_username'] ?? ''));
        if ($sshUser === '') {
            throw new InvalidArgumentException('SSH Username is required.');
        }

        $authType = strtolower(trim((string) ($input['ssh_auth_type'] ?? 'password')));
        if (!in_array($authType, ['password', 'key'], true)) {
            $authType = 'password';
        }

        $sshTimeout = max(3, min(120, (int) ($input['ssh_timeout'] ?? 15)));
        $commandTimeout = max(5, min(3600, (int) ($input['command_timeout'] ?? 300)));

        $password = (string) ($input['ssh_password'] ?? '');
        $privateKey = trim((string) ($input['ssh_private_key'] ?? ''));
        $passphrase = (string) ($input['ssh_passphrase'] ?? '');

        if ($isCreate && $authType === 'password' && $password === '') {
            throw new InvalidArgumentException('SSH Password is required for password authentication.');
        }
        if ($isCreate && $authType === 'key' && $privateKey === '') {
            throw new InvalidArgumentException('SSH Private Key is required for key authentication.');
        }

        return [
            'name'            => mb_substr($name, 0, 150),
            'environment'     => $env,
            'description'     => $description !== '' ? $description : null,
            'repo_branch'     => mb_substr($branch, 0, 120),
            'remote_path'     => mb_substr($remotePath, 0, 500),
            'ssh_host'        => mb_substr($sshHost, 0, 255),
            'ssh_port'        => $sshPort,
            'ssh_username'    => mb_substr($sshUser, 0, 120),
            'ssh_auth_type'   => $authType,
            'ssh_password'    => $password,
            'ssh_private_key' => $privateKey,
            'ssh_passphrase'  => $passphrase,
            'ssh_timeout'     => $sshTimeout,
            'command_timeout' => $commandTimeout,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeProjectRow(array $row): array
    {
        $hasPassword = !empty($row['ssh_password_encrypted']);
        $hasKey = !empty($row['ssh_private_key_encrypted']);
        $hasPassphrase = !empty($row['ssh_passphrase_encrypted']);

        unset(
            $row['ssh_password_encrypted'],
            $row['ssh_private_key_encrypted'],
            $row['ssh_passphrase_encrypted']
        );

        $row['id'] = (int) $row['id'];
        $row['ssh_port'] = (int) $row['ssh_port'];
        $row['ssh_timeout'] = (int) $row['ssh_timeout'];
        $row['command_timeout'] = (int) $row['command_timeout'];
        $row['last_deploy_duration_ms'] = $row['last_deploy_duration_ms'] !== null ? (int) $row['last_deploy_duration_ms'] : null;
        $row['command_count'] = isset($row['command_count']) ? (int) $row['command_count'] : 0;
        $row['enabled_command_count'] = isset($row['enabled_command_count']) ? (int) $row['enabled_command_count'] : 0;
        $row['deployment_count'] = isset($row['deployment_count']) ? (int) $row['deployment_count'] : 0;
        $row['has_password'] = $hasPassword;
        $row['has_private_key'] = $hasKey;
        $row['has_passphrase'] = $hasPassphrase;

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateCommandRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_enabled'] = (bool) $row['is_enabled'];
        $row['stop_on_error'] = (bool) $row['stop_on_error'];
        $row['last_exit_code'] = $row['last_exit_code'] !== null ? (int) $row['last_exit_code'] : null;
        $row['last_duration_ms'] = $row['last_duration_ms'] !== null ? (int) $row['last_duration_ms'] : null;

        return $row;
    }

    /**
     * @return array<int, array{name:string,command:string}>
     */
    private function getStarterPresetCommands(string $preset, string $branch): array
    {
        $safeBranch = preg_replace('/[^a-zA-Z0-9._\/-]/', '', $branch) ?: 'main';

        return match ($preset) {
            'laravel' => [
                ['name' => 'Enable maintenance mode', 'command' => 'php artisan down || true'],
                ['name' => 'Pull latest code', 'command' => "git pull origin {$safeBranch}"],
                ['name' => 'Install Composer dependencies', 'command' => 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev'],
                ['name' => 'Run database migrations', 'command' => 'php artisan migrate --force'],
                ['name' => 'Rebuild config & route cache', 'command' => 'php artisan optimize'],
                ['name' => 'Bring application live', 'command' => 'php artisan up'],
            ],
            'php_composer' => [
                ['name' => 'Check repository status', 'command' => 'git status -sb'],
                ['name' => 'Pull latest code', 'command' => "git pull origin {$safeBranch}"],
                ['name' => 'Install Composer packages', 'command' => 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev'],
            ],
            'node_pm2' => [
                ['name' => 'Pull latest code', 'command' => "git pull origin {$safeBranch}"],
                ['name' => 'Install Node dependencies', 'command' => 'npm ci'],
                ['name' => 'Build production bundle', 'command' => 'npm run build'],
                ['name' => 'Reload PM2 process', 'command' => 'pm2 reload all'],
            ],
            'empty' => [],
            default => [
                ['name' => 'Verify current branch & status', 'command' => 'git status -sb'],
                ['name' => 'Pull latest changes', 'command' => "git pull origin {$safeBranch}"],
            ],
        };
    }
}

