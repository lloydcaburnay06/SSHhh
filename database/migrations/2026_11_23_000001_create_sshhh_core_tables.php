<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SSHhh schema (v6, before the Laravel port). Every table is guarded with
 * hasTable() so running this against an existing pre-Laravel database is a no-op:
 * the data stays exactly as it was and Laravel just records the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ssh_profiles')) {
            Schema::create('ssh_profiles', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 150)->index('idx_profile_name');
                $table->string('ssh_host', 255);
                $table->unsignedInteger('ssh_port')->default(22);
                $table->string('ssh_username', 120);
                $table->enum('ssh_auth_type', ['password', 'key'])->default('password');
                $table->text('ssh_password_encrypted')->nullable();
                $table->mediumText('ssh_private_key_encrypted')->nullable();
                $table->text('ssh_passphrase_encrypted')->nullable();
                $table->unsignedInteger('ssh_timeout')->default(15);
                $table->string('host_fingerprint', 255)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 150);
                $table->string('environment', 40)->default('production')->index('idx_environment');
                $table->integer('sort_order')->default(0);
                $table->text('description')->nullable();
                $table->string('repo_branch', 120)->default('main');
                $table->string('remote_path', 500)->default('');
                $table->unsignedInteger('ssh_profile_id')->nullable()->index('idx_ssh_profile');
                $table->string('ssh_host', 255);
                $table->unsignedInteger('ssh_port')->default(22);
                $table->string('ssh_username', 120);
                $table->enum('ssh_auth_type', ['password', 'key'])->default('password');
                $table->text('ssh_password_encrypted')->nullable();
                $table->mediumText('ssh_private_key_encrypted')->nullable();
                $table->text('ssh_passphrase_encrypted')->nullable();
                $table->text('env_vars_encrypted')->nullable();
                $table->unsignedInteger('ssh_timeout')->default(15);
                $table->unsignedInteger('command_timeout')->default(300);
                $table->string('host_fingerprint', 255)->nullable();
                $table->string('last_deploy_status', 30)->default('never');
                $table->dateTime('last_deployed_at')->nullable();
                $table->unsignedInteger('last_deploy_duration_ms')->nullable();
                $table->string('app_status', 20)->default('unknown')->index('idx_app_status');
                $table->dateTime('app_status_updated_at')->nullable();
                $table->string('health_check_url', 500)->nullable();
                $table->string('last_health_status', 20)->default('unknown');
                $table->dateTime('last_health_checked_at')->nullable();
                $table->integer('last_health_response_code')->nullable();
                $table->boolean('backup_db_before_deploy')->default(false);
                $table->string('backup_db_driver', 30)->default('auto');
                $table->boolean('webhook_enabled')->default(false);
                $table->string('webhook_token', 64)->nullable()->index('idx_webhook_token');
                $table->string('webhook_branch', 120)->default('main');
                $table->string('deploy_strategy', 30)->default('direct');
                $table->unsignedInteger('releases_to_keep')->default(5);
                $table->text('shared_dirs')->nullable();
                $table->text('shared_files')->nullable();
                $table->string('current_release_id', 60)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->index('idx_updated_at');

                $table->index(['sort_order', 'id'], 'idx_sort_order');
                $table->foreign('ssh_profile_id', 'fk_projects_ssh_profile')
                    ->references('id')->on('ssh_profiles')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_commands')) {
            Schema::create('project_commands', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->string('name', 150);
                $table->text('command');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->boolean('stop_on_error')->default(true);
                $table->string('app_state_trigger', 20)->default('none');
                $table->integer('last_exit_code')->nullable();
                $table->dateTime('last_run_at')->nullable();
                $table->unsignedInteger('last_duration_ms')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->index(['project_id', 'sort_order', 'id'], 'idx_project_sort');
                $table->index(['project_id', 'app_state_trigger'], 'idx_app_state_trigger');
                $table->foreign('project_id', 'fk_commands_project')
                    ->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('event_logs')) {
            Schema::create('event_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('project_id')->nullable();
                $table->string('project_name', 150)->default('');
                $table->string('category', 40);
                $table->string('event_type', 60);
                $table->string('status', 20);
                $table->string('title', 255);
                $table->string('ssh_target', 300)->nullable();
                $table->text('command_text')->nullable();
                $table->longText('output_log')->nullable();
                $table->longText('steps_json')->nullable();
                $table->integer('exit_code')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();

                $table->index(['project_id', 'created_at', 'id'], 'idx_project_created');
                $table->index(['project_id', 'category', 'status'], 'idx_category_status');
                $table->foreign('project_id', 'fk_events_project')
                    ->references('id')->on('projects')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('auth_state')) {
            Schema::create('auth_state', function (Blueprint $table) {
                $table->unsignedInteger('id')->default(1)->primary();
                $table->text('totp_secret_encrypted')->nullable();
                $table->boolean('is_configured')->default(false);
                $table->dateTime('configured_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }
        DB::statement('INSERT IGNORE INTO `auth_state` (`id`, `is_configured`) VALUES (1, 0)');

        if (! Schema::hasTable('remembered_devices')) {
            Schema::create('remembered_devices', function (Blueprint $table) {
                $table->increments('id');
                $table->string('token_hash', 64)->unique();
                $table->string('user_agent', 255)->nullable();
                $table->string('ip_address', 60)->nullable();
                $table->dateTime('expires_at')->index('idx_expires');
                $table->dateTime('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('ip_address', 60);
                $table->dateTime('attempted_at');
                $table->index(['ip_address', 'attempted_at'], 'idx_ip_time');
            });
        }

        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value_encrypted')->nullable();
                $table->boolean('is_encrypted')->default(false);
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('project_claude_links')) {
            Schema::create('project_claude_links', function (Blueprint $table) {
                $table->unsignedInteger('project_id')->primary();
                $table->string('fire_url', 255);
                $table->text('token_encrypted')->nullable();
                $table->string('merge_branch', 120)->default('');
                $table->boolean('clear_after_send')->default(true);
                $table->dateTime('last_sent_at')->nullable();
                $table->string('last_session_url', 255)->nullable();
                $table->dateTime('updated_at');
                $table->boolean('auto_watch')->default(false);
                $table->string('watch_file', 255)->default('');
                $table->mediumText('recent_issues')->nullable();
                $table->dateTime('last_watch_at')->nullable();
                $table->string('last_watch_result', 255)->nullable();
            });
        }

        if (! Schema::hasTable('error_alerts')) {
            Schema::create('error_alerts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->string('project_name', 190);
                $table->string('log_file', 255);
                $table->string('summary', 255);
                $table->string('summary_source', 20)->default('built-in');
                $table->text('issue_hashes')->nullable();
                $table->unsignedInteger('issue_count')->default(0);
                $table->string('status', 20)->default('pending');
                $table->char('token_hash', 64)->unique('uniq_token');
                $table->dateTime('created_at');
                $table->string('push_result', 255)->nullable();
                $table->dateTime('sms_sent_at')->nullable();
                $table->string('sms_result', 255)->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->string('decided_via', 20)->nullable();
                $table->string('session_url', 255)->nullable();
                $table->string('error', 255)->nullable();

                $table->index(['status', 'created_at'], 'idx_status');
                $table->index(['project_id', 'created_at'], 'idx_project');
            });
        }

        if (! Schema::hasTable('log_watch_runs')) {
            Schema::create('log_watch_runs', function (Blueprint $table) {
                $table->increments('id');
                $table->dateTime('started_at')->index('idx_started');
                $table->dateTime('finished_at')->nullable();
                $table->string('trigger_type', 12)->default('scheduled');
                $table->string('status', 10)->default('running');
                $table->unsignedInteger('duration_ms')->default(0);
                $table->unsignedSmallInteger('projects_watched')->default(0);
                $table->unsignedSmallInteger('projects_checked')->default(0);
                $table->unsignedSmallInteger('alerts')->default(0);
                $table->unsignedSmallInteger('sent')->default(0);
                $table->unsignedSmallInteger('errors')->default(0);
                $table->string('summary', 255)->nullable();
                $table->mediumText('details')->nullable();
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->increments('id');
                $table->char('endpoint_hash', 64)->unique('uniq_endpoint');
                $table->text('endpoint');
                $table->string('p256dh', 120);
                $table->string('auth', 40);
                $table->string('user_agent', 255)->default('');
                $table->dateTime('created_at');
                $table->dateTime('last_ok_at')->nullable();
                $table->unsignedInteger('fail_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'push_subscriptions', 'log_watch_runs', 'error_alerts', 'project_claude_links',
            'app_settings', 'login_attempts', 'remembered_devices', 'auth_state',
            'event_logs', 'project_commands', 'projects', 'ssh_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
