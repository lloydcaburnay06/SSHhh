<?php

declare(strict_types=1);

namespace Deployer;

use RuntimeException;

final class Bootstrap
{
    /** @var array<string, mixed> */
    private array $config;
    private Database $database;
    private Crypto $crypto;
    private EventLogger $logger;
    private ProjectRepository $projects;
    private SshRunner $runner;

    private function __construct()
    {
        $rootDir = dirname(__DIR__);
        $autoload = $rootDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Composer dependencies are missing. Run "composer install" in the project root.');
        }
        require_once $autoload;

        Env::load($rootDir . '/.env');

        $this->config = require $rootDir . '/config/config.php';
        date_default_timezone_set((string) ($this->config['timezone'] ?? 'UTC'));

        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        AccessGuard::enforce($this->config['auth'] ?? [], $this->csrfToken());

        $storageDir = $rootDir . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }

        $this->database = new Database($this->config['db'], $storageDir);
        $this->crypto = new Crypto((string) $this->config['security']['key_file']);
        $this->logger = new EventLogger($this->database->pdo());
        $this->projects = new ProjectRepository($this->database->pdo(), $this->crypto, $this->logger);
        $this->runner = new SshRunner($this->projects, $this->logger);
    }

    public static function init(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }

    public function csrfToken(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    public function isAuthEnabled(): bool
    {
        return AccessGuard::isAuthEnabled($this->config['auth'] ?? []);
    }

    public function verifyCsrf(?string $token): void
    {
        $expected = $this->csrfToken();
        if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
            throw new RuntimeException('Invalid or expired security token (CSRF). Please refresh the page.');
        }
    }

    public function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function projects(): ProjectRepository
    {
        return $this->projects;
    }

    public function logger(): EventLogger
    {
        return $this->logger;
    }

    public function runner(): SshRunner
    {
        return $this->runner;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
