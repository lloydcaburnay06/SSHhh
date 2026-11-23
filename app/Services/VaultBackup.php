<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Exports and restores encrypted backups of SSH profiles, projects, variables, commands, and settings.
 */
final class VaultBackup
{
    private const FORMAT_VERSION = 'sshhh-vault-v1';
    private const PBKDF2_ITERATIONS = 120_000;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private SshProfileRepository $profiles,
        private SettingsRepository $settings,
        private Crypto $crypto,
        private EventLogger $logger
    ) {
    }

    /**
     * Encrypts an arbitrary JSON-serializable payload with a user-supplied passphrase.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function encryptBundle(array $payload, string $passphrase): array
    {
        if (strlen($passphrase) < 8) {
            throw new InvalidArgumentException('Backup passphrase must be at least 8 characters long.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode vault payload.');
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::FORMAT_VERSION,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Failed to encrypt vault backup.');
        }

        return [
            'format'     => self::FORMAT_VERSION,
            'kdf'        => 'pbkdf2-sha256',
            'iterations' => self::PBKDF2_ITERATIONS,
            'cipher'     => self::CIPHER,
            'salt'       => base64_encode($salt),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
            'exported_at'=> date('c'),
        ];
    }

    /**
     * Decrypts a vault bundle produced by encryptBundle().
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    public static function decryptBundle(array $bundle, string $passphrase): array
    {
        if (($bundle['format'] ?? '') !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported or unrecognized vault backup format.');
        }
        if ($passphrase === '') {
            throw new InvalidArgumentException('Passphrase is required to decrypt the vault backup.');
        }

        $salt = base64_decode((string) ($bundle['salt'] ?? ''), true);
        $iv = base64_decode((string) ($bundle['iv'] ?? ''), true);
        $tag = base64_decode((string) ($bundle['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($bundle['ciphertext'] ?? ''), true);
        $iterations = max(10_000, min(1_000_000, (int) ($bundle['iterations'] ?? self::PBKDF2_ITERATIONS)));

        if ($salt === false || $iv === false || $tag === false || $ciphertext === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new InvalidArgumentException('Corrupted or malformed vault backup payload.');
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::FORMAT_VERSION
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: wrong passphrase or tampered backup file.');
        }

        $decoded = json_decode($plaintext, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Decrypted vault backup did not contain valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportEncryptedVault(string $passphrase): array
    {
        $exportedProfiles = [];
        foreach ($this->profiles->listAll() as $p) {
            $bundle = $this->profiles->getWithDecryptedCredentials((int) $p['id']);
            $exportedProfiles[] = [
                'source_id'        => (int) $p['id'],
                'name'             => (string) $p['name'],
                'ssh_host'         => (string) $p['ssh_host'],
                'ssh_port'         => (int) $p['ssh_port'],
                'ssh_username'     => (string) $p['ssh_username'],
                'ssh_auth_type'    => (string) $p['ssh_auth_type'],
                'ssh_timeout'      => (int) $p['ssh_timeout'],
                'host_fingerprint' => $p['host_fingerprint'] ?? null,
                'ssh_password'     => $bundle['password'],
                'ssh_private_key'  => $bundle['private_key'],
                'ssh_passphrase'   => $bundle['passphrase'],
            ];
        }

        $exportedProjects = [];
        foreach ($this->projects->listAll() as $proj) {
            $pid = (int) $proj['id'];
            $bundle = $this->projects->getWithDecryptedCredentials($pid, false);
            $commands = $this->projects->listCommands($pid);

            $exportedProjects[] = [
                'name'                    => (string) $proj['name'],
                'environment'             => (string) $proj['environment'],
                'sort_order'              => (int) ($proj['sort_order'] ?? 0),
                'description'             => $proj['description'] ?? null,
                'repo_branch'             => (string) $proj['repo_branch'],
                'remote_path'             => (string) $proj['remote_path'],
                'ssh_profile_source_id'   => $proj['ssh_profile_id'] ?? null,
                'ssh_host'                => (string) $proj['ssh_host'],
                'ssh_port'                => (int) $proj['ssh_port'],
                'ssh_username'            => (string) $proj['ssh_username'],
                'ssh_auth_type'           => (string) $proj['ssh_auth_type'],
                'ssh_timeout'             => (int) $proj['ssh_timeout'],
                'command_timeout'         => (int) $proj['command_timeout'],
                'host_fingerprint'        => $proj['host_fingerprint'] ?? null,
                'ssh_password'            => $bundle['password'],
                'ssh_private_key'         => $bundle['private_key'],
                'ssh_passphrase'          => $bundle['passphrase'],
                'env_vars'                => $bundle['env_vars'] ?? '',
                'health_check_url'        => $proj['health_check_url'] ?? null,
                'backup_db_before_deploy' => (int) ($proj['backup_db_before_deploy'] ?? 0),
                'backup_db_driver'        => (string) ($proj['backup_db_driver'] ?? 'auto'),
                'webhook_enabled'         => (int) ($proj['webhook_enabled'] ?? 0),
                'webhook_branch'          => (string) ($proj['webhook_branch'] ?? 'main'),
                'deploy_strategy'         => (string) ($proj['deploy_strategy'] ?? 'direct'),
                'releases_to_keep'        => (int) ($proj['releases_to_keep'] ?? 5),
                'shared_dirs'             => (string) ($proj['shared_dirs'] ?? 'storage'),
                'shared_files'            => (string) ($proj['shared_files'] ?? '.env'),
                'commands'                => array_map(static fn(array $c): array => [
                    'name'              => (string) $c['name'],
                    'command'           => (string) $c['command'],
                    'is_enabled'        => !empty($c['is_enabled']) ? 1 : 0,
                    'stop_on_error'     => !empty($c['stop_on_error']) ? 1 : 0,
                    'app_state_trigger' => (string) ($c['app_state_trigger'] ?? 'none'),
                ], $commands),
            ];
        }

        $payload = [
            'version'       => 1,
            'exported_at'   => date('c'),
            'ssh_profiles'  => $exportedProfiles,
            'projects'      => $exportedProjects,
            'notifications' => $this->settings->getNotificationConfigInternal(),
        ];

        $this->logger->log([
            'category'   => 'audit',
            'event_type' => 'vault_exported',
            'status'     => 'info',
            'title'      => sprintf('Exported encrypted vault backup (%d profiles, %d projects)', count($exportedProfiles), count($exportedProjects)),
        ]);

        return self::encryptBundle($payload, $passphrase);
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array{profiles_imported:int,projects_imported:int,projects_skipped:int}
     */
    public function importEncryptedVault(array $bundle, string $passphrase, bool $overwriteExisting = false): array
    {
        $payload = self::decryptBundle($bundle, $passphrase);

        $existingProfiles = [];
        foreach ($this->profiles->listAll() as $sp) {
            $existingProfiles[strtolower((string) $sp['name'])] = (int) $sp['id'];
        }

        $existingProjects = [];
        foreach ($this->projects->listAll() as $pr) {
            $existingProjects[strtolower((string) $pr['name']) . '|' . strtolower((string) $pr['environment'])] = (int) $pr['id'];
        }

        $profileMap = [];
        $profilesImported = 0;

        foreach ((array) ($payload['ssh_profiles'] ?? []) as $rawProf) {
            if (!is_array($rawProf)) {
                continue;
            }
            $sourceId = (int) ($rawProf['source_id'] ?? 0);
            $key = strtolower(trim((string) ($rawProf['name'] ?? '')));
            if ($key === '') {
                continue;
            }
            if (isset($existingProfiles[$key])) {
                $existingId = $existingProfiles[$key];
                if ($overwriteExisting) {
                    $this->profiles->update($existingId, $rawProf);
                    $profilesImported++;
                }
                if ($sourceId > 0) {
                    $profileMap[$sourceId] = $existingId;
                }
                continue;
            }
            $created = $this->profiles->create($rawProf);
            $newId = (int) $created['id'];
            $existingProfiles[$key] = $newId;
            if ($sourceId > 0) {
                $profileMap[$sourceId] = $newId;
            }
            $profilesImported++;
        }

        $projectsImported = 0;
        $projectsSkipped = 0;

        foreach ((array) ($payload['projects'] ?? []) as $rawProj) {
            if (!is_array($rawProj)) {
                continue;
            }
            $name = trim((string) ($rawProj['name'] ?? ''));
            $env = strtolower(trim((string) ($rawProj['environment'] ?? 'production')));
            if ($name === '') {
                continue;
            }

            $lookupKey = strtolower($name) . '|' . $env;
            $srcProfileId = isset($rawProj['ssh_profile_source_id']) ? (int) $rawProj['ssh_profile_source_id'] : 0;
            if ($srcProfileId > 0 && isset($profileMap[$srcProfileId])) {
                $rawProj['ssh_profile_id'] = $profileMap[$srcProfileId];
            } else {
                $rawProj['ssh_profile_id'] = null;
            }
            $rawProj['preset'] = 'empty';

            if (isset($existingProjects[$lookupKey])) {
                if (!$overwriteExisting) {
                    $projectsSkipped++;
                    continue;
                }
                $existingId = $existingProjects[$lookupKey];
                $this->projects->update($existingId, $rawProj);
                if (!empty($rawProj['commands']) && is_array($rawProj['commands'])) {
                    $this->projects->syncCommands($existingId, $rawProj['commands'], true);
                }
                $projectsImported++;
                continue;
            }

            $created = $this->projects->create($rawProj);
            $newPid = (int) $created['id'];
            if (!empty($rawProj['commands']) && is_array($rawProj['commands'])) {
                $this->projects->syncCommands($newPid, $rawProj['commands'], true);
            }
            $existingProjects[$lookupKey] = $newPid;
            $projectsImported++;
        }

        if (!empty($payload['notifications']) && is_array($payload['notifications'])) {
            $n = $payload['notifications'];
            $this->settings->saveNotificationConfig([
                'enabled'           => !empty($n['enabled']),
                'channel'           => $n['channel'] ?? 'discord',
                'webhook_url'       => $n['webhook_url'] ?? '',
                'telegram_token'    => $n['telegram_token'] ?? '',
                'telegram_chat_id'  => $n['telegram_chat_id'] ?? '',
                'on_deploy_success' => !empty($n['on_deploy_success']),
                'on_deploy_failure' => !empty($n['on_deploy_failure']),
                'on_health_down'    => !empty($n['on_health_down']),
            ]);
        }

        $this->logger->log([
            'category'   => 'audit',
            'event_type' => 'vault_imported',
            'status'     => 'success',
            'title'      => sprintf('Restored encrypted vault (%d profiles, %d projects imported, %d skipped)', $profilesImported, $projectsImported, $projectsSkipped),
        ]);

        return [
            'profiles_imported' => $profilesImported,
            'projects_imported' => $projectsImported,
            'projects_skipped'  => $projectsSkipped,
        ];
    }
}
