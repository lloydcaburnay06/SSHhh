<?php

declare(strict_types=1);

namespace Deployer;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    /**
     * @param array{host:string,port:int,database:string,username:string,password:string,charset:string} $config
     */
    public function __construct(array $config, string $storageDir)
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 3306);
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $config['database'] ?? 'ssh_deployer') ?: 'ssh_deployer';
        $user = $config['username'] ?? 'root';
        $pass = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $dsnWithDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
        $needsMigration = false;

        try {
            $this->pdo = new PDO($dsnWithDb, $user, $pass, $options);
        } catch (PDOException $e) {
            // Error 1049 = Unknown database
            if ((int) ($e->errorInfo[1] ?? 0) === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                $dsnServer = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
                $serverPdo = new PDO($dsnServer, $user, $pass, $options);
                $serverPdo->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $dbName
                ));
                unset($serverPdo);
                $this->pdo = new PDO($dsnWithDb, $user, $pass, $options);
                $needsMigration = true;
            } else {
                throw new RuntimeException('MySQL connection failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $markerFile = rtrim($storageDir, '/\\') . '/.schema_v4.ready';
        if ($needsMigration || !is_file($markerFile)) {
            $this->migrate();
            @file_put_contents($markerFile, date('c'));
        } else {
            // Quick sanity check in case tables or columns were altered manually via phpMyAdmin
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'event_logs'");
            $colStmt = $this->pdo->query("SHOW COLUMNS FROM `projects` LIKE 'app_status'");
            $cmdColStmt = $this->pdo->query("SHOW COLUMNS FROM `project_commands` LIKE 'app_state_trigger'");
            $authStmt = $this->pdo->query("SHOW TABLES LIKE 'auth_state'");
            if (
                $stmt === false
                || $stmt->fetchColumn() === false
                || $colStmt === false
                || $colStmt->fetch() === false
                || $cmdColStmt === false
                || $cmdColStmt->fetch() === false
                || $authStmt === false
                || $authStmt->fetchColumn() === false
            ) {
                $this->migrate();
                @file_put_contents($markerFile, date('c'));
            }
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `projects` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `environment` VARCHAR(40) NOT NULL DEFAULT 'production',
                `sort_order` INT NOT NULL DEFAULT 0,
                `description` TEXT NULL,
                `repo_branch` VARCHAR(120) NOT NULL DEFAULT 'main',
                `remote_path` VARCHAR(500) NOT NULL DEFAULT '',
                `ssh_host` VARCHAR(255) NOT NULL,
                `ssh_port` INT UNSIGNED NOT NULL DEFAULT 22,
                `ssh_username` VARCHAR(120) NOT NULL,
                `ssh_auth_type` ENUM('password', 'key') NOT NULL DEFAULT 'password',
                `ssh_password_encrypted` TEXT NULL,
                `ssh_private_key_encrypted` MEDIUMTEXT NULL,
                `ssh_passphrase_encrypted` TEXT NULL,
                `ssh_timeout` INT UNSIGNED NOT NULL DEFAULT 15,
                `command_timeout` INT UNSIGNED NOT NULL DEFAULT 300,
                `host_fingerprint` VARCHAR(255) NULL,
                `last_deploy_status` VARCHAR(30) NOT NULL DEFAULT 'never',
                `last_deployed_at` DATETIME NULL,
                `last_deploy_duration_ms` INT UNSIGNED NULL,
                `app_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
                `app_status_updated_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_environment` (`environment`),
                KEY `idx_sort_order` (`sort_order`, `id`),
                KEY `idx_app_status` (`app_status`),
                KEY `idx_updated_at` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Non-destructive upgrade for existing installations without `projects.sort_order`
        $colStmt = $this->pdo->query("SHOW COLUMNS FROM `projects` LIKE 'sort_order'");
        if ($colStmt === false || $colStmt->fetch() === false) {
            $this->pdo->exec(
                'ALTER TABLE `projects` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `environment`, ADD KEY `idx_sort_order` (`sort_order`, `id`)'
            );
            $existingIds = $this->pdo->query('SELECT `id` FROM `projects` ORDER BY `updated_at` DESC, `id` DESC')->fetchAll(PDO::FETCH_COLUMN);
            $pos = 1;
            $upd = $this->pdo->prepare('UPDATE `projects` SET `sort_order` = :pos, `updated_at` = `updated_at` WHERE `id` = :id');
            foreach ($existingIds as $pid) {
                $upd->execute([':pos' => $pos++, ':id' => (int) $pid]);
            }
        }

        // Non-destructive upgrade for existing installations without `projects.app_status`
        $appStatusCol = $this->pdo->query("SHOW COLUMNS FROM `projects` LIKE 'app_status'");
        if ($appStatusCol === false || $appStatusCol->fetch() === false) {
            $this->pdo->exec(
                "ALTER TABLE `projects` ADD COLUMN `app_status` VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER `last_deploy_duration_ms`, ADD COLUMN `app_status_updated_at` DATETIME NULL AFTER `app_status`, ADD KEY `idx_app_status` (`app_status`)"
            );
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `project_commands` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `command` TEXT NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `stop_on_error` TINYINT(1) NOT NULL DEFAULT 1,
                `app_state_trigger` VARCHAR(20) NOT NULL DEFAULT 'none',
                `last_exit_code` INT NULL,
                `last_run_at` DATETIME NULL,
                `last_duration_ms` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_project_sort` (`project_id`, `sort_order`, `id`),
                KEY `idx_app_state_trigger` (`project_id`, `app_state_trigger`),
                CONSTRAINT `fk_commands_project`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Non-destructive upgrade for existing installations without `project_commands.app_state_trigger`
        $appTriggerCol = $this->pdo->query("SHOW COLUMNS FROM `project_commands` LIKE 'app_state_trigger'");
        if ($appTriggerCol === false || $appTriggerCol->fetch() === false) {
            $this->pdo->exec(
                "ALTER TABLE `project_commands` ADD COLUMN `app_state_trigger` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `stop_on_error`, ADD KEY `idx_app_state_trigger` (`project_id`, `app_state_trigger`)"
            );
            // Auto-detect existing commands for Laravel / Docker maintenance triggers
            $this->pdo->exec(
                "UPDATE `project_commands` SET `app_state_trigger` = 'down' WHERE `app_state_trigger` = 'none' AND (`command` LIKE '%artisan down%' OR `command` LIKE '%compose down%' OR `command` LIKE '%compose stop%')"
            );
            $this->pdo->exec(
                "UPDATE `project_commands` SET `app_state_trigger` = 'up' WHERE `app_state_trigger` = 'none' AND (`command` LIKE '%artisan up%' OR `command` LIKE '%compose up%' OR `command` LIKE '%compose start%')"
            );
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `event_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id` INT UNSIGNED NULL,
                `project_name` VARCHAR(150) NOT NULL DEFAULT '',
                `category` VARCHAR(40) NOT NULL,
                `event_type` VARCHAR(60) NOT NULL,
                `status` VARCHAR(20) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `ssh_target` VARCHAR(300) NULL,
                `command_text` TEXT NULL,
                `output_log` LONGTEXT NULL,
                `steps_json` LONGTEXT NULL,
                `exit_code` INT NULL,
                `duration_ms` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_project_created` (`project_id`, `created_at`, `id`),
                KEY `idx_category_status` (`project_id`, `category`, `status`),
                CONSTRAINT `fk_events_project`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `auth_state` (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                `totp_secret_encrypted` TEXT NULL,
                `is_configured` TINYINT(1) NOT NULL DEFAULT 0,
                `configured_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec(<<<'SQL'
            INSERT IGNORE INTO `auth_state` (`id`, `is_configured`) VALUES (1, 0);
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `remembered_devices` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `token_hash` VARCHAR(64) NOT NULL UNIQUE,
                `user_agent` VARCHAR(255) NULL,
                `ip_address` VARCHAR(60) NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}

