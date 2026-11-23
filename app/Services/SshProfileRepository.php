<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Manages reusable Shared SSH Server Profiles so multiple projects on the same host can share encrypted credentials.
 */
final class SshProfileRepository
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
                sp.*,
                (SELECT COUNT(*) FROM `projects` p WHERE p.`ssh_profile_id` = sp.`id`) AS `project_count`
            FROM `ssh_profiles` sp
            ORDER BY sp.`name` ASC, sp.`id` DESC
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        return array_map([$this, 'sanitizeRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT
                sp.*,
                (SELECT COUNT(*) FROM `projects` p WHERE p.`ssh_profile_id` = sp.`id`) AS `project_count`
            FROM `ssh_profiles` sp
            WHERE sp.`id` = :id
            LIMIT 1
        SQL);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->sanitizeRow($row) : null;
    }

    /**
     * @return array{profile:array<string,mixed>,password:string,private_key:string,passphrase:string}
     */
    public function getWithDecryptedCredentials(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `ssh_profiles` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Linked SSH Server Profile not found.');
        }

        try {
            $password = !empty($row['ssh_password_encrypted'])
                ? $this->crypto->decrypt((string) $row['ssh_password_encrypted'], 'ssh-profile-password')
                : '';
            $privateKey = !empty($row['ssh_private_key_encrypted'])
                ? $this->crypto->decrypt((string) $row['ssh_private_key_encrypted'], 'ssh-profile-key')
                : '';
            $passphrase = !empty($row['ssh_passphrase_encrypted'])
                ? $this->crypto->decrypt((string) $row['ssh_passphrase_encrypted'], 'ssh-profile-passphrase')
                : '';
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Could not decrypt SSH credentials for server profile '{$row['name']}'.",
                0,
                $e
            );
        }

        return [
            'profile'     => $this->sanitizeRow($row),
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
        $data = $this->validateInput($input, true);

        $passwordEnc = $data['ssh_password'] !== ''
            ? $this->crypto->encrypt($data['ssh_password'], 'ssh-profile-password')
            : null;
        $privateKeyEnc = $data['ssh_private_key'] !== ''
            ? $this->crypto->encrypt($data['ssh_private_key'], 'ssh-profile-key')
            : null;
        $passphraseEnc = $data['ssh_passphrase'] !== ''
            ? $this->crypto->encrypt($data['ssh_passphrase'], 'ssh-profile-passphrase')
            : null;

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO `ssh_profiles` (
                `name`, `ssh_host`, `ssh_port`, `ssh_username`, `ssh_auth_type`,
                `ssh_password_encrypted`, `ssh_private_key_encrypted`, `ssh_passphrase_encrypted`,
                `ssh_timeout`, `host_fingerprint`
            ) VALUES (
                :name, :ssh_host, :ssh_port, :ssh_username, :ssh_auth_type,
                :ssh_password_encrypted, :ssh_private_key_encrypted, :ssh_passphrase_encrypted,
                :ssh_timeout, :host_fingerprint
            )
        SQL);
        $stmt->execute([
            ':name'                      => $data['name'],
            ':ssh_host'                  => $data['ssh_host'],
            ':ssh_port'                  => $data['ssh_port'],
            ':ssh_username'              => $data['ssh_username'],
            ':ssh_auth_type'             => $data['ssh_auth_type'],
            ':ssh_password_encrypted'    => $passwordEnc,
            ':ssh_private_key_encrypted' => $privateKeyEnc,
            ':ssh_passphrase_encrypted'  => $passphraseEnc,
            ':ssh_timeout'               => $data['ssh_timeout'],
            ':host_fingerprint'          => $data['host_fingerprint'],
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->logger->log([
            'category'   => 'audit',
            'event_type' => 'ssh_profile_created',
            'status'     => 'info',
            'title'      => sprintf('Created shared SSH profile "%s"', $data['name']),
            'ssh_target' => sprintf('%s@%s:%d', $data['ssh_username'], $data['ssh_host'], $data['ssh_port']),
        ]);

        return $this->find($id) ?? throw new RuntimeException('Failed to load created SSH profile.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `ssh_profiles` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new InvalidArgumentException('SSH Profile not found.');
        }

        $merged = [
            'name'            => $input['name'] ?? $existing['name'],
            'ssh_host'        => $input['ssh_host'] ?? $existing['ssh_host'],
            'ssh_port'        => $input['ssh_port'] ?? $existing['ssh_port'],
            'ssh_username'    => $input['ssh_username'] ?? $existing['ssh_username'],
            'ssh_auth_type'   => $input['ssh_auth_type'] ?? $existing['ssh_auth_type'],
            'ssh_timeout'     => $input['ssh_timeout'] ?? $existing['ssh_timeout'],
            'ssh_password'    => $input['ssh_password'] ?? '',
            'ssh_private_key' => $input['ssh_private_key'] ?? '',
            'ssh_passphrase'  => $input['ssh_passphrase'] ?? '',
        ];
        $data = $this->validateInput($merged, false);

        $passwordEnc = $existing['ssh_password_encrypted'];
        if (!empty($input['clear_password'])) {
            $passwordEnc = null;
        } elseif ($data['ssh_password'] !== '') {
            $passwordEnc = $this->crypto->encrypt($data['ssh_password'], 'ssh-profile-password');
        }

        $privateKeyEnc = $existing['ssh_private_key_encrypted'];
        if (!empty($input['clear_private_key'])) {
            $privateKeyEnc = null;
        } elseif ($data['ssh_private_key'] !== '') {
            $privateKeyEnc = $this->crypto->encrypt($data['ssh_private_key'], 'ssh-profile-key');
        }

        $passphraseEnc = $existing['ssh_passphrase_encrypted'];
        if (!empty($input['clear_passphrase'])) {
            $passphraseEnc = null;
        } elseif ($data['ssh_passphrase'] !== '') {
            $passphraseEnc = $this->crypto->encrypt($data['ssh_passphrase'], 'ssh-profile-passphrase');
        }

        $hostChanged = ($existing['ssh_host'] !== $data['ssh_host']) || ((int) $existing['ssh_port'] !== $data['ssh_port']);
        $fingerprint = $hostChanged ? null : $existing['host_fingerprint'];

        DB::beginTransaction();
        try {
            $upd = $this->pdo->prepare(<<<'SQL'
                UPDATE `ssh_profiles` SET
                    `name` = :name,
                    `ssh_host` = :ssh_host,
                    `ssh_port` = :ssh_port,
                    `ssh_username` = :ssh_username,
                    `ssh_auth_type` = :ssh_auth_type,
                    `ssh_password_encrypted` = :ssh_password_encrypted,
                    `ssh_private_key_encrypted` = :ssh_private_key_encrypted,
                    `ssh_passphrase_encrypted` = :ssh_passphrase_encrypted,
                    `ssh_timeout` = :ssh_timeout,
                    `host_fingerprint` = :host_fingerprint
                WHERE `id` = :id
            SQL);
            $upd->execute([
                ':name'                      => $data['name'],
                ':ssh_host'                  => $data['ssh_host'],
                ':ssh_port'                  => $data['ssh_port'],
                ':ssh_username'              => $data['ssh_username'],
                ':ssh_auth_type'             => $data['ssh_auth_type'],
                ':ssh_password_encrypted'    => $passwordEnc,
                ':ssh_private_key_encrypted' => $privateKeyEnc,
                ':ssh_passphrase_encrypted'  => $passphraseEnc,
                ':ssh_timeout'               => $data['ssh_timeout'],
                ':host_fingerprint'          => $fingerprint,
                ':id'                        => $id,
            ]);

            // Sync metadata fields on linked projects for display/search consistency
            $syncProj = $this->pdo->prepare(<<<'SQL'
                UPDATE `projects` SET
                    `ssh_host` = :ssh_host,
                    `ssh_port` = :ssh_port,
                    `ssh_username` = :ssh_username,
                    `ssh_auth_type` = :ssh_auth_type,
                    `ssh_timeout` = :ssh_timeout,
                    `host_fingerprint` = :host_fingerprint
                WHERE `ssh_profile_id` = :profile_id
            SQL);
            $syncProj->execute([
                ':ssh_host'         => $data['ssh_host'],
                ':ssh_port'         => $data['ssh_port'],
                ':ssh_username'     => $data['ssh_username'],
                ':ssh_auth_type'    => $data['ssh_auth_type'],
                ':ssh_timeout'      => $data['ssh_timeout'],
                ':host_fingerprint' => $fingerprint,
                ':profile_id'       => $id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->logger->log([
            'category'   => 'audit',
            'event_type' => 'ssh_profile_updated',
            'status'     => 'info',
            'title'      => sprintf('Updated shared SSH profile "%s"', $data['name']),
            'ssh_target' => sprintf('%s@%s:%d', $data['ssh_username'], $data['ssh_host'], $data['ssh_port']),
        ]);

        return $this->find($id) ?? throw new RuntimeException('Failed to reload SSH profile.');
    }

    public function delete(int $id): void
    {
        $profile = $this->find($id);
        if (!$profile) {
            throw new InvalidArgumentException('SSH Profile not found.');
        }

        // Before unlinking, copy encrypted credentials into any linked projects so they never lose access
        $stmt = $this->pdo->prepare('SELECT * FROM `ssh_profiles` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $rawProfile = $stmt->fetch();

        DB::beginTransaction();
        try {
            if ($rawProfile) {
                $linked = $this->pdo->prepare('SELECT `id` FROM `projects` WHERE `ssh_profile_id` = :pid');
                $linked->execute([':pid' => $id]);
                $projIds = $linked->fetchAll(PDO::FETCH_COLUMN);

                if ($projIds) {
                    $pass = !empty($rawProfile['ssh_password_encrypted'])
                        ? $this->crypto->decrypt((string) $rawProfile['ssh_password_encrypted'], 'ssh-profile-password')
                        : '';
                    $key = !empty($rawProfile['ssh_private_key_encrypted'])
                        ? $this->crypto->decrypt((string) $rawProfile['ssh_private_key_encrypted'], 'ssh-profile-key')
                        : '';
                    $phrase = !empty($rawProfile['ssh_passphrase_encrypted'])
                        ? $this->crypto->decrypt((string) $rawProfile['ssh_passphrase_encrypted'], 'ssh-profile-passphrase')
                        : '';

                    $updProj = $this->pdo->prepare(<<<'SQL'
                        UPDATE `projects` SET
                            `ssh_profile_id` = NULL,
                            `ssh_password_encrypted` = :pw,
                            `ssh_private_key_encrypted` = :pk,
                            `ssh_passphrase_encrypted` = :pp
                        WHERE `id` = :id
                    SQL);
                    foreach ($projIds as $pid) {
                        $updProj->execute([
                            ':pw' => $pass !== '' ? $this->crypto->encrypt($pass, 'ssh-password') : null,
                            ':pk' => $key !== '' ? $this->crypto->encrypt($key, 'ssh-private-key') : null,
                            ':pp' => $phrase !== '' ? $this->crypto->encrypt($phrase, 'ssh-passphrase') : null,
                            ':id' => (int) $pid,
                        ]);
                    }
                }
            }

            $del = $this->pdo->prepare('DELETE FROM `ssh_profiles` WHERE `id` = :id');
            $del->execute([':id' => $id]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->logger->log([
            'category'   => 'audit',
            'event_type' => 'ssh_profile_deleted',
            'status'     => 'warning',
            'title'      => sprintf('Deleted shared SSH profile "%s" (linked projects preserved standalone credentials)', $profile['name']),
        ]);
    }

    public function updateHostFingerprint(int $profileId, string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }
        $fp = mb_substr($fingerprint, 0, 255);
        $this->pdo->prepare('UPDATE `ssh_profiles` SET `host_fingerprint` = :fp WHERE `id` = :id')
            ->execute([':fp' => $fp, ':id' => $profileId]);
        $this->pdo->prepare('UPDATE `projects` SET `host_fingerprint` = :fp WHERE `ssh_profile_id` = :pid')
            ->execute([':fp' => $fp, ':pid' => $profileId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateInput(array $input, bool $isCreate): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Profile name is required.');
        }

        $host = trim((string) ($input['ssh_host'] ?? ''));
        if ($host === '' || preg_match('/[\s\/\\\\]/', $host)) {
            throw new InvalidArgumentException('Valid SSH Host / IP is required.');
        }

        $port = (int) ($input['ssh_port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SSH Port must be between 1 and 65535.');
        }

        $username = trim((string) ($input['ssh_username'] ?? ''));
        if ($username === '') {
            throw new InvalidArgumentException('SSH Username is required.');
        }

        $authType = strtolower(trim((string) ($input['ssh_auth_type'] ?? 'password')));
        if (!in_array($authType, ['password', 'key'], true)) {
            $authType = 'password';
        }

        $timeout = max(3, min(120, (int) ($input['ssh_timeout'] ?? 15)));
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
            'name'             => mb_substr($name, 0, 150),
            'ssh_host'         => mb_substr($host, 0, 255),
            'ssh_port'         => $port,
            'ssh_username'     => mb_substr($username, 0, 120),
            'ssh_auth_type'    => $authType,
            'ssh_timeout'      => $timeout,
            'ssh_password'     => $password,
            'ssh_private_key'  => $privateKey,
            'ssh_passphrase'   => $passphrase,
            'host_fingerprint' => isset($input['host_fingerprint']) ? mb_substr((string) $input['host_fingerprint'], 0, 255) : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeRow(array $row): array
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
        $row['project_count'] = isset($row['project_count']) ? (int) $row['project_count'] : 0;
        $row['has_password'] = $hasPassword;
        $row['has_private_key'] = $hasKey;
        $row['has_passphrase'] = $hasPassphrase;

        return $row;
    }
}
