<?php

namespace App\Providers;

use App\Services\AlertChannels;
use App\Services\ClaudeLinkRepository;
use App\Services\ClaudeLogSender;
use App\Services\ClaudeRoutineClient;
use App\Services\Crypto;
use App\Services\ErrorAlertManager;
use App\Services\EventLogger;
use App\Services\LoginThrottle;
use App\Services\LogWatcher;
use App\Services\Notifier;
use App\Services\ProjectRepository;
use App\Services\SettingsRepository;
use App\Services\SshProfileRepository;
use App\Services\SshRunner;
use App\Services\VaultBackup;
use App\Services\WebPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use PDO;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The SSHhh domain services work on the raw PDO handle of Laravel's default
     * connection (MySQL-specific SQL such as GET_LOCK and ON DUPLICATE KEY).
     * Every service is a singleton, as it was in the pre-Laravel bootstrap.
     */
    public function register(): void
    {
        $this->app->singleton(PDO::class, fn () => DB::connection()->getPdo());

        $this->app->singleton(Crypto::class, fn () => new Crypto(
            (string) config('sshhh.key_file'),
            config('sshhh.secret_key') ?: null,
        ));

        $this->app->singleton(LoginThrottle::class, fn ($app) => new LoginThrottle(
            $app->make(PDO::class),
            (int) config('sshhh.auth.max_failed_attempts', 5),
            (int) config('sshhh.auth.lockout_minutes', 15),
        ));

        foreach ([
            EventLogger::class,
            SettingsRepository::class,
            SshProfileRepository::class,
            Notifier::class,
            ProjectRepository::class,
            SshRunner::class,
            VaultBackup::class,
            ClaudeLinkRepository::class,
            ClaudeRoutineClient::class,
            ClaudeLogSender::class,
            AlertChannels::class,
            WebPush::class,
            ErrorAlertManager::class,
            LogWatcher::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        //
    }
}
