<?php

declare(strict_types=1);

use Deployer\Env;

$baseConfig = [
    'app_name'    => Env::get('DEPLOYER_APP_NAME', 'SSHhh'),
    'app_tagline' => Env::get('DEPLOYER_APP_TAGLINE', 'Deployments so quiet, you can hear a server drop.'),
    'timezone'    => Env::get('DEPLOYER_TIMEZONE', 'UTC'),
    'db' => [
        'host'     => Env::get('DEPLOYER_DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DEPLOYER_DB_PORT', '3306'),
        'database' => Env::get('DEPLOYER_DB_NAME', 'ssh_deployer'),
        'username' => Env::get('DEPLOYER_DB_USER', 'root'),
        'password' => Env::get('DEPLOYER_DB_PASS', '') ?? '',
        'charset'  => 'utf8mb4',
    ],
    'security' => [
        // Master key file used to derive the AES-256-GCM encryption key.
        // Can also be overridden via DEPLOYER_SECRET_KEY environment variable.
        'key_file' => dirname(__DIR__) . '/storage/secret.key',
    ],
    'auth' => [
        'app_password'      => Env::get('DEPLOYER_APP_PASSWORD', '') ?? '',
        'app_password_hash' => Env::get('DEPLOYER_APP_PASSWORD_HASH', '') ?? '',
        'allow_remote'      => Env::bool('DEPLOYER_ALLOW_REMOTE', false),
    ],
];

$localOverride = __DIR__ . '/config.local.php';
if (is_file($localOverride)) {
    $custom = require $localOverride;
    if (is_array($custom)) {
        $baseConfig = array_replace_recursive($baseConfig, $custom);
    }
}

return $baseConfig;
