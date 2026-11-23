<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * First-run setup: creates the super admin. On an install upgraded from the single-user
 * version, the super admin takes over everything created before user accounts existed
 * and keeps the Google Authenticator that was already linked.
 */
final class InstallationSetup
{
    /** Tables whose pre-users rows are handed to the first super admin. */
    private const CLAIM = ['ssh_profiles', 'projects', 'event_logs', 'push_subscriptions', 'error_alerts', 'log_watch_runs'];

    public function __construct(private Crypto $crypto, private Authenticator $auth)
    {
    }

    public function needed(): bool
    {
        return ! User::query()->exists();
    }

    /** True when the single-user version had an authenticator linked that can be kept. */
    public function hasLegacyAuthenticator(): bool
    {
        try {
            return (bool) DB::table('auth_state')->where('id', 1)->where('is_configured', 1)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createSuperAdmin(array $data): User
    {
        if (! $this->needed()) {
            throw new InvalidArgumentException('SSHhh is already set up.');
        }

        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->assignRole(Roles::SUPER_ADMIN);

            foreach (self::CLAIM as $table) {
                DB::table($table)->whereNull('user_id')->update(['user_id' => $user->id]);
            }
            // Per-user settings (notifications, alert channels, …); VAPID keys stay installation-wide
            DB::table('app_settings')->where('user_id', 0)->whereNotIn('setting_key', SettingsRepository::GLOBAL_KEYS)
                ->update(['user_id' => $user->id]);

            $this->adoptLegacyAuthenticator($user);

            return $user;
        });
    }

    private function adoptLegacyAuthenticator(User $user): void
    {
        $row = DB::table('auth_state')->where('id', 1)->where('is_configured', 1)->first();
        if (! $row || empty($row->totp_secret_encrypted)) {
            return;
        }
        try {
            $secret = $this->crypto->decrypt((string) $row->totp_secret_encrypted, 'totp-secret');
        } catch (Throwable) {
            return; // vault key changed: the admin simply links a new authenticator
        }
        if ($secret !== '') {
            $this->auth->storeSecret($user, $secret);
        }
    }
}
