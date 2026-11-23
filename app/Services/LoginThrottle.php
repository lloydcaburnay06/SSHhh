<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Per-IP lockout shared by the authenticator sign-in and the token-authenticated webhook:
 * 5 failures within 15 minutes lock that IP out for the rest of the window.
 */
final class LoginThrottle
{
    public function __construct(
        private PDO $pdo,
        private int $maxAttempts = 5,
        private int $lockoutMinutes = 15,
    ) {
    }

    /**
     * Minutes left in this IP's lockout (0 = not locked).
     */
    public function minutesRemaining(string $ip): int
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT COUNT(*) AS `fails`, TIMESTAMPDIFF(SECOND, MIN(`attempted_at`), NOW()) AS `elapsed`
                FROM `login_attempts`
                WHERE `ip_address` = :ip AND `attempted_at` > DATE_SUB(NOW(), INTERVAL :mins MINUTE)
            SQL);
            $stmt->execute([':ip' => $this->clip($ip), ':mins' => $this->lockoutMinutes]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int) ($row['fails'] ?? 0) < $this->maxAttempts) {
                return 0;
            }
            $elapsed = max(0, (int) ($row['elapsed'] ?? 0));

            return max(1, (int) ceil(($this->lockoutMinutes * 60 - $elapsed) / 60));
        } catch (Throwable) {
            return 0;
        }
    }

    public function recordFailure(string $ip): void
    {
        try {
            $this->pdo->prepare('INSERT INTO `login_attempts` (`ip_address`, `attempted_at`) VALUES (:ip, NOW())')
                ->execute([':ip' => $this->clip($ip)]);
            $this->pdo->exec('DELETE FROM `login_attempts` WHERE `attempted_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        } catch (Throwable) {
        }
    }

    public function clear(string $ip): void
    {
        try {
            $this->pdo->prepare('DELETE FROM `login_attempts` WHERE `ip_address` = :ip')->execute([':ip' => $this->clip($ip)]);
        } catch (Throwable) {
        }
    }

    private function clip(string $ip): string
    {
        return substr($ip !== '' ? $ip : 'unknown', 0, 60);
    }
}
