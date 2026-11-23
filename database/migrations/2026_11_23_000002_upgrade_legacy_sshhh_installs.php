<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings databases created by older (pre-v6, pre-Laravel) SSHhh versions up to the
 * current schema. Each step checks for its column first, so it never touches a
 * database that already has it (including fresh installs from the previous migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'sort_order')) {
            DB::statement('ALTER TABLE `projects` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `environment`, ADD KEY `idx_sort_order` (`sort_order`, `id`)');
            $pos = 1;
            foreach (DB::table('projects')->orderByDesc('updated_at')->orderByDesc('id')->pluck('id') as $id) {
                DB::update('UPDATE `projects` SET `sort_order` = ?, `updated_at` = `updated_at` WHERE `id` = ?', [$pos++, $id]);
            }
        }

        if (! Schema::hasColumn('projects', 'app_status')) {
            DB::statement("ALTER TABLE `projects` ADD COLUMN `app_status` VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER `last_deploy_duration_ms`, ADD COLUMN `app_status_updated_at` DATETIME NULL AFTER `app_status`, ADD KEY `idx_app_status` (`app_status`)");
        }

        // v5: health check, DB backup, webhooks, zero-downtime releases
        if (! Schema::hasColumn('projects', 'health_check_url')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `projects`
                    ADD COLUMN `health_check_url` VARCHAR(500) NULL AFTER `app_status_updated_at`,
                    ADD COLUMN `last_health_status` VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER `health_check_url`,
                    ADD COLUMN `last_health_checked_at` DATETIME NULL AFTER `last_health_status`,
                    ADD COLUMN `last_health_response_code` INT NULL AFTER `last_health_checked_at`,
                    ADD COLUMN `backup_db_before_deploy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_health_response_code`,
                    ADD COLUMN `backup_db_driver` VARCHAR(30) NOT NULL DEFAULT 'auto' AFTER `backup_db_before_deploy`,
                    ADD COLUMN `webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `backup_db_driver`,
                    ADD COLUMN `webhook_token` VARCHAR(64) NULL AFTER `webhook_enabled`,
                    ADD COLUMN `webhook_branch` VARCHAR(120) NOT NULL DEFAULT 'main' AFTER `webhook_token`,
                    ADD COLUMN `deploy_strategy` VARCHAR(30) NOT NULL DEFAULT 'direct' AFTER `webhook_branch`,
                    ADD COLUMN `releases_to_keep` INT UNSIGNED NOT NULL DEFAULT 5 AFTER `deploy_strategy`,
                    ADD COLUMN `shared_dirs` TEXT NULL AFTER `releases_to_keep`,
                    ADD COLUMN `shared_files` TEXT NULL AFTER `shared_dirs`,
                    ADD COLUMN `current_release_id` VARCHAR(60) NULL AFTER `shared_files`,
                    ADD KEY `idx_webhook_token` (`webhook_token`)
            SQL);

            foreach (DB::table('projects')->whereNull('webhook_token')->orWhere('webhook_token', '')->pluck('id') as $id) {
                DB::update('UPDATE `projects` SET `webhook_token` = ?, `updated_at` = `updated_at` WHERE `id` = ?', [bin2hex(random_bytes(24)), $id]);
            }
        }

        // v6: shared SSH profiles and encrypted project variables
        if (! Schema::hasColumn('projects', 'ssh_profile_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `projects`
                    ADD COLUMN `ssh_profile_id` INT UNSIGNED NULL AFTER `remote_path`,
                    ADD COLUMN `env_vars_encrypted` TEXT NULL AFTER `ssh_passphrase_encrypted`,
                    ADD KEY `idx_ssh_profile` (`ssh_profile_id`),
                    ADD CONSTRAINT `fk_projects_ssh_profile`
                        FOREIGN KEY (`ssh_profile_id`) REFERENCES `ssh_profiles` (`id`)
                        ON DELETE SET NULL
            SQL);
        }

        if (! Schema::hasColumn('project_commands', 'app_state_trigger')) {
            DB::statement("ALTER TABLE `project_commands` ADD COLUMN `app_state_trigger` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `stop_on_error`, ADD KEY `idx_app_state_trigger` (`project_id`, `app_state_trigger`)");
            DB::statement("UPDATE `project_commands` SET `app_state_trigger` = 'down' WHERE `app_state_trigger` = 'none' AND (`command` LIKE '%artisan down%' OR `command` LIKE '%compose down%' OR `command` LIKE '%compose stop%')");
            DB::statement("UPDATE `project_commands` SET `app_state_trigger` = 'up' WHERE `app_state_trigger` = 'none' AND (`command` LIKE '%artisan up%' OR `command` LIKE '%compose up%' OR `command` LIKE '%compose start%')");
        }

        // Columns the automatic log checker added to the first version of project_claude_links
        $claudeColumns = [
            'auto_watch' => 'ADD COLUMN `auto_watch` TINYINT(1) NOT NULL DEFAULT 0',
            'watch_file' => "ADD COLUMN `watch_file` VARCHAR(255) NOT NULL DEFAULT ''",
            'recent_issues' => 'ADD COLUMN `recent_issues` MEDIUMTEXT NULL',
            'last_watch_at' => 'ADD COLUMN `last_watch_at` DATETIME NULL',
            'last_watch_result' => 'ADD COLUMN `last_watch_result` VARCHAR(255) NULL',
        ];
        $missing = array_filter($claudeColumns, fn (string $col) => ! Schema::hasColumn('project_claude_links', $col), ARRAY_FILTER_USE_KEY);
        if ($missing !== []) {
            DB::statement('ALTER TABLE `project_claude_links` '.implode(', ', $missing));
        }
    }

    public function down(): void
    {
        // Upgrades of legacy data are not reversible.
    }
};
