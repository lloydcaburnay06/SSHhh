<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use PDO;
use Throwable;

/**
 * Single-user Google Authenticator (TOTP) sign-in, ported from the pre-Laravel AccessGuard.
 * The session flag `deployer_authenticated` marks an unlocked browser; a remembered device
 * carries a random token whose SHA-256 hash is stored in `remembered_devices`.
 */
final class Authenticator
{
    public const SESSION_FLAG = 'deployer_authenticated';

    private const PENDING_SECRET = 'pending_totp_secret';

    public function __construct(
        private PDO $pdo,
        private Crypto $crypto,
        private LoginThrottle $throttle,
    ) {
    }

    public function isConfigured(): bool
    {
        try {
            $stmt = $this->pdo->query('SELECT `is_configured` FROM `auth_state` WHERE `id` = 1 LIMIT 1');

            return (bool) ($stmt ? $stmt->fetchColumn() : false);
        } catch (Throwable) {
            return false;
        }
    }

    public function isAuthenticated(Request $request): bool
    {
        return (bool) $request->session()->get(self::SESSION_FLAG, false);
    }

    /**
     * Unlocks the session from a valid remembered-device cookie. Clears a stale cookie.
     */
    public function restoreFromDeviceCookie(Request $request): bool
    {
        $name = $this->cookieName();
        $token = (string) $request->cookie($name, '');
        if ($token === '') {
            return false;
        }
        if (strlen($token) < 32) {
            Cookie::queue(Cookie::forget($name));

            return false;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT `id` FROM `remembered_devices` WHERE `token_hash` = :hash AND `expires_at` > NOW() LIMIT 1');
            $stmt->execute([':hash' => hash('sha256', $token)]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $request->session()->put(self::SESSION_FLAG, true);

                return true;
            }
            Cookie::queue(Cookie::forget($name));
        } catch (Throwable) {
            // Fail closed
        }

        return false;
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

    /**
     * The TOTP secret being set up in this session (generated on first view).
     */
    public function pendingSecret(Request $request): string
    {
        $secret = $request->session()->get(self::PENDING_SECRET);
        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret(20);
            $request->session()->put(self::PENDING_SECRET, $secret);
        }

        return $secret;
    }

    /**
     * @return array{secret: string, secret_display: string, qr: string}
     */
    public function setupScreen(Request $request): array
    {
        $secret = $this->pendingSecret($request);

        return [
            'secret' => $secret,
            'secret_display' => trim(chunk_split($secret, 4, ' ')),
            'qr' => Totp::getQrCodeDataUri(Totp::getProvisioningUri($secret, 'Admin', 'SSHhh')),
        ];
    }

    /**
     * Verifies the first code and permanently stores the encrypted secret.
     *
     * @return string|null error message, or null on success
     */
    public function completeSetup(Request $request, string $code, bool $remember): ?string
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        $ip = (string) $request->ip();

        if (($locked = $this->throttle->minutesRemaining($ip)) > 0) {
            return "Too many incorrect codes. Try again in {$locked} minute(s).";
        }
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            return 'Please enter a valid 6-digit verification code.';
        }

        $secret = $this->pendingSecret($request);
        if (! Totp::verifyCode($secret, $code, 1)) {
            $this->throttle->recordFailure($ip);
            usleep(300_000);

            return 'Incorrect verification code. Ensure your device clock is accurate and try again.';
        }

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO `auth_state` (`id`, `totp_secret_encrypted`, `is_configured`, `configured_at`)
            VALUES (1, :secret, 1, NOW())
            ON DUPLICATE KEY UPDATE `totp_secret_encrypted` = VALUES(`totp_secret_encrypted`), `is_configured` = 1, `configured_at` = NOW()
        SQL)->execute([':secret' => $this->crypto->encrypt($secret, 'totp-secret')]);

        $request->session()->forget(self::PENDING_SECRET);
        $this->signIn($request, $remember);

        return null;
    }

    /**
     * @return string|null error message, or null on success
     */
    public function attemptLogin(Request $request, string $code, bool $remember): ?string
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        $ip = (string) $request->ip();

        if (($locked = $this->throttle->minutesRemaining($ip)) > 0) {
            return "Too many incorrect codes. Try again in {$locked} minute(s).";
        }
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            return 'Please enter a valid 6-digit code.';
        }

        $stmt = $this->pdo->query('SELECT `totp_secret_encrypted` FROM `auth_state` WHERE `id` = 1 LIMIT 1');
        $encrypted = (string) ($stmt ? $stmt->fetchColumn() : '');
        try {
            $secret = $this->crypto->decrypt($encrypted, 'totp-secret');
        } catch (Throwable) {
            return 'Security secret decryption failed. Please check master encryption key.';
        }

        if ($secret === '' || ! Totp::verifyCode($secret, $code, 1)) {
            $this->throttle->recordFailure($ip);
            usleep(350_000);

            return 'Invalid verification code. Please check your Google Authenticator app.';
        }

        $this->throttle->clear($ip);
        $this->signIn($request, $remember);

        return null;
    }

    public function logout(Request $request): void
    {
        $name = $this->cookieName();
        $token = (string) $request->cookie($name, '');
        if ($token !== '') {
            try {
                $this->pdo->prepare('DELETE FROM `remembered_devices` WHERE `token_hash` = :hash')
                    ->execute([':hash' => hash('sha256', $token)]);
            } catch (Throwable) {
            }
        }
        Cookie::queue(Cookie::forget($name));

        $request->session()->forget(self::SESSION_FLAG);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function signIn(Request $request, bool $remember): void
    {
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $request->session()->put(self::SESSION_FLAG, true);

        if ($remember) {
            $this->rememberDevice($request);
        }
    }

    private function rememberDevice(Request $request): void
    {
        try {
            $days = (int) config('sshhh.auth.device_days', 30);
            $raw = bin2hex(random_bytes(32));
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO `remembered_devices` (`token_hash`, `user_agent`, `ip_address`, `expires_at`, `created_at`)
                VALUES (:token_hash, :ua, :ip, DATE_ADD(NOW(), INTERVAL :days DAY), NOW())
            SQL)->execute([
                ':token_hash' => hash('sha256', $raw),
                ':ua' => substr((string) $request->userAgent(), 0, 255),
                ':ip' => substr((string) $request->ip(), 0, 60),
                ':days' => $days,
            ]);

            Cookie::queue(cookie(
                name: $this->cookieName(),
                value: $raw,
                minutes: $days * 24 * 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: 'lax',
            ));
        } catch (Throwable) {
            // Never block a successful sign-in over the remember-me cookie
        }
    }

    private function cookieName(): string
    {
        return (string) config('sshhh.auth.device_cookie', 'sshhh_device_token');
    }
}
