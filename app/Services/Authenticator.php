<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Sign-in: email + password, then the user's own Google Authenticator code.
 *
 * Step 1 (password) stores the user id as "pending" in the session; step 2 (TOTP code)
 * completes the Laravel login. "Remember this device" is Laravel's remember-me cookie,
 * so a remembered browser skips both steps for 30 days. Users without 2FA yet are
 * signed in and then sent to the setup screen by the EnsureUnlocked middleware.
 * Failed passwords and codes count toward the per-IP lockout (LoginThrottle).
 */
final class Authenticator
{
    private const PENDING_USER = 'login.pending_user_id';

    private const PENDING_REMEMBER = 'login.remember';

    private const SETUP_SECRET = 'two_factor.pending_secret';

    public const REMEMBER_DAYS = 30;

    public function __construct(
        private Crypto $crypto,
        private LoginThrottle $throttle,
    ) {
    }

    /**
     * Step 1. Returns an error message, or null when the password is right
     * (the caller then checks pendingUser() / Auth::check()).
     */
    public function attemptPassword(Request $request, string $email, string $password, bool $remember): ?string
    {
        $ip = (string) $request->ip();
        if (($locked = $this->throttle->minutesRemaining($ip)) > 0) {
            return "Too many failed attempts. Try again in {$locked} minute(s).";
        }

        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->throttle->recordFailure($ip);
            usleep(300_000);

            return 'These credentials do not match our records.';
        }
        if (! $user->is_active) {
            return 'This account is disabled. Ask the super admin to enable it.';
        }

        if ($user->hasTwoFactor()) {
            $request->session()->put(self::PENDING_USER, $user->id);
            $request->session()->put(self::PENDING_REMEMBER, $remember);

            return null;
        }

        // No authenticator yet: sign in now, the middleware sends them to the 2FA setup
        $this->completeLogin($request, $user, $remember);

        return null;
    }

    public function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_USER);

        return $id ? User::query()->active()->find($id) : null;
    }

    /**
     * Step 2.
     *
     * @return string|null error message, or null when signed in
     */
    public function attemptCode(Request $request, string $code): ?string
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return 'Your sign-in expired. Please enter your email and password again.';
        }
        $ip = (string) $request->ip();
        if (($locked = $this->throttle->minutesRemaining($ip)) > 0) {
            return "Too many incorrect codes. Try again in {$locked} minute(s).";
        }
        if (! $this->verifyUserCode($user, $code)) {
            $this->throttle->recordFailure($ip);
            usleep(350_000);

            return 'Invalid verification code. Please check your Google Authenticator app.';
        }

        $remember = (bool) $request->session()->pull(self::PENDING_REMEMBER, false);
        $request->session()->forget(self::PENDING_USER);
        $this->throttle->clear($ip);
        $this->completeLogin($request, $user, $remember);

        return null;
    }

    public function verifyUserCode(User $user, string $code): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (! preg_match('/^[0-9]{6}$/', $code) || $user->two_factor_secret === null) {
            return false;
        }
        try {
            $secret = $this->crypto->decrypt($user->two_factor_secret, 'user-totp:'.$user->id);
        } catch (Throwable) {
            return false;
        }

        return $secret !== '' && Totp::verifyCode($secret, $code, 1);
    }

    /**
     * QR code and manual key for setting up (or replacing) the signed-in user's authenticator.
     *
     * @return array{secret: string, secret_display: string, qr: string}
     */
    public function setupScreen(Request $request, User $user): array
    {
        $secret = $request->session()->get(self::SETUP_SECRET);
        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret(20);
            $request->session()->put(self::SETUP_SECRET, $secret);
        }

        return [
            'secret' => $secret,
            'secret_display' => trim(chunk_split($secret, 4, ' ')),
            'qr' => Totp::getQrCodeDataUri(Totp::getProvisioningUri($secret, $user->email, (string) config('app.name', 'SSHhh'))),
        ];
    }

    /**
     * @return string|null error message, or null when the authenticator is now active
     */
    public function confirmSetup(Request $request, User $user, string $code): ?string
    {
        $secret = (string) $request->session()->get(self::SETUP_SECRET, '');
        $code = (string) preg_replace('/\s+/', '', $code);
        if ($secret === '') {
            return 'The setup expired. Scan the new QR code and try again.';
        }
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            return 'Please enter a valid 6-digit verification code.';
        }
        if (! Totp::verifyCode($secret, $code, 1)) {
            $this->throttle->recordFailure((string) $request->ip());

            return 'Incorrect verification code. Ensure your device clock is accurate and try again.';
        }

        $this->storeSecret($user, $secret);
        $request->session()->forget(self::SETUP_SECRET);

        return null;
    }

    /** Encrypts and stores an (already verified) TOTP secret for the user. */
    public function storeSecret(User $user, string $secret): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->crypto->encrypt($secret, 'user-totp:'.$user->id),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function resetTwoFactor(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        // Remembered browsers must go through the new authenticator
        $user->setRememberToken(null);
        $user->save();
    }

    /**
     * A request made on the machine running SSHhh (not through a proxy or tunnel).
     */
    public function isLocalRequest(Request $request): bool
    {
        $forwarded = $request->headers->has('X-Forwarded-For')
            || $request->headers->has('CF-Connecting-IP')
            || $request->headers->has('Forwarded');

        return ! $forwarded && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function completeLogin(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => substr((string) $request->ip(), 0, 60)])->save();
    }
}
