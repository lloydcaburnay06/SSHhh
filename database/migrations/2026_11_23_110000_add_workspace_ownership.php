<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every user gets their own workspace: projects, SSH profiles, event logs, settings,
 * push devices, alerts and log-checker history carry the owner's user_id.
 *
 * Existing rows keep user_id NULL until the first-run setup creates the super admin,
 * which claims them (App\Services\InstallationSetup). Child tables (project_commands,
 * project_claude_links) are reached through their project and need no column.
 */
return new class extends Migration
{
    /** Tables whose rows belong to exactly one user. */
    private const OWNED = ['ssh_profiles', 'projects', 'event_logs', 'push_subscriptions', 'error_alerts', 'log_watch_runs'];

    public function up(): void
    {
        foreach (self::OWNED as $table) {
            if (! Schema::hasColumn($table, 'user_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->unsignedInteger('user_id')->nullable()->after('id');
                    $t->index('user_id', "idx_{$table}_user");
                    $t->foreign('user_id', "fk_{$table}_user")->references('id')->on('users')->cascadeOnDelete();
                });
            }
        }

        Schema::table('event_logs', function (Blueprint $t) {
            // Who did it: differs from user_id when a super admin acts inside another workspace
            $t->unsignedInteger('actor_user_id')->nullable()->after('user_id');
            $t->string('actor_name', 150)->nullable()->after('actor_user_id');
            $t->foreign('actor_user_id', 'fk_event_logs_actor')->references('id')->on('users')->nullOnDelete();
        });

        // app_settings: one row per (user, key); user_id 0 = installation-wide (Web Push VAPID keys)
        if (! Schema::hasColumn('app_settings', 'user_id')) {
            DB::statement('ALTER TABLE `app_settings` ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 0 FIRST, DROP PRIMARY KEY, ADD PRIMARY KEY (`user_id`, `setting_key`)');
        }

        // "Remember this device" is now Laravel's remember-me cookie on the user account;
        // the pre-users device tokens can't be tied to an account, so they are dropped.
        Schema::dropIfExists('remembered_devices');

        // Roles & permissions (spatie/laravel-permission)
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Roles::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate(Roles::SUPER_ADMIN, 'web')->syncPermissions(Roles::PERMISSIONS);
        Role::findOrCreate(Roles::USER, 'web');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `app_settings` DROP PRIMARY KEY, DROP COLUMN `user_id`, ADD PRIMARY KEY (`setting_key`)');
        Schema::table('event_logs', function (Blueprint $t) {
            $t->dropForeign('fk_event_logs_actor');
            $t->dropColumn(['actor_user_id', 'actor_name']);
        });
        foreach (array_reverse(self::OWNED) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("fk_{$table}_user");
                $t->dropIndex("idx_{$table}_user");
                $t->dropColumn('user_id');
            });
        }
    }
};
