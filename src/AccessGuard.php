<?php

declare(strict_types=1);

namespace Deployer;

final class AccessGuard
{
    /**
     * @param array{app_password:string,app_password_hash:string,allow_remote:bool} $authConfig
     */
    public static function enforce(array $authConfig, string $csrfToken): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $plainPassword = (string) ($authConfig['app_password'] ?? '');
        $passwordHash = (string) ($authConfig['app_password_hash'] ?? '');
        $allowRemote = !empty($authConfig['allow_remote']);
        $authEnabled = ($plainPassword !== '' || $passwordHash !== '');

        $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isApi = ($scriptName === 'api.php');

        // 1. If no password is configured, enforce localhost-only access unless allow_remote=true
        if (!$authEnabled) {
            if (!$allowRemote && !self::isLocalhostRequest()) {
                if ($isApi) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok'    => false,
                        'error' => 'Remote access is disabled by default. Configure DEPLOYER_APP_PASSWORD in .env to enable authenticated remote access.',
                    ]);
                    exit;
                }
                self::renderBlockedScreen();
            }
            return;
        }

        // 2. Handle logout when password authentication is enabled
        if (isset($_GET['logout']) && $_GET['logout'] === '1') {
            unset($_SESSION['deployer_authenticated']);
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }

        if (!empty($_SESSION['deployer_authenticated'])) {
            return;
        }

        // 3. Handle login submission
        $loginError = '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['deployer_login_password'])) {
            $submittedToken = (string) ($_POST['csrf_token'] ?? '');
            $submittedPassword = (string) $_POST['deployer_login_password'];

            if ($csrfToken === '' || !hash_equals($csrfToken, $submittedToken)) {
                $loginError = 'Invalid security token. Please try again.';
            } elseif (self::verifyPassword($submittedPassword, $plainPassword, $passwordHash)) {
                session_regenerate_id(true);
                $_SESSION['deployer_authenticated'] = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $redirect = $isApi ? 'index.php' : ((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'));
                header('Location: ' . $redirect);
                exit;
            } else {
                usleep(350_000);
                $loginError = 'Incorrect password.';
            }
        }

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'Authentication required. Please reload the page and sign in.',
            ]);
            exit;
        }

        self::renderLoginScreen($csrfToken, $loginError);
    }

    public static function isAuthEnabled(array $authConfig): bool
    {
        return ((string) ($authConfig['app_password'] ?? '') !== '')
            || ((string) ($authConfig['app_password_hash'] ?? '') !== '');
    }

    private static function isLocalhostRequest(): bool
    {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return in_array($remoteAddr, ['127.0.0.1', '::1'], true);
    }

    private static function verifyPassword(string $input, string $expectedPlain, string $expectedHash): bool
    {
        if ($expectedHash !== '') {
            return password_verify($input, $expectedHash);
        }
        return $expectedPlain !== '' && hash_equals($expectedPlain, $input);
    }

    private static function renderBlockedScreen(): never
    {
        http_response_code(403);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#090d16">
    <title>Remote Access Restricted — SSHhh</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/app.css?v=1.1.6">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100dvh; padding:20px;">
    <div class="panel" style="max-width:480px; width:100%; padding:28px;">
        <div class="brand" style="margin-bottom:16px;">
            <img src="assets/img/icon-192.png" alt="SSHhh" class="brand-logo-img" width="40" height="40">
            <div class="brand-text-wrap">
                <span class="brand-wordmark">SSH<span class="brand-hush">hh</span></span>
                <span class="brand-tagline" style="display:block;">Deployments so quiet, you can hear a server drop.</span>
            </div>
        </div>
        <h2 style="margin:0 0 10px 0; font-size:18px;">Localhost-Only Mode Active</h2>
        <p style="color:var(--text-secondary); margin:0 0 14px 0;">
            For your security, unauthenticated access is restricted to <code>127.0.0.1</code> / <code>::1</code>.
        </p>
        <p style="color:var(--text-secondary); margin:0; font-size:13px;">
            To access SSHhh from another device or mobile phone on your network, set <code>DEPLOYER_APP_PASSWORD</code> (or <code>DEPLOYER_APP_PASSWORD_HASH</code>) in your <code>.env</code> file.
        </p>
    </div>
</body>
</html>
        <?php
        exit;
    }

    private static function renderLoginScreen(string $csrfToken, string $error): never
    {
        http_response_code(401);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#090d16">
    <title>Sign In — SSHhh</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/app.css?v=1.1.6">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100dvh; padding:20px;">
    <div class="panel" style="max-width:420px; width:100%; padding:28px;">
        <div class="brand" style="margin-bottom:18px;">
            <img src="assets/img/icon-192.png" alt="SSHhh" class="brand-logo-img" width="42" height="42">
            <div class="brand-text-wrap">
                <span class="brand-wordmark">SSH<span class="brand-hush">hh</span></span>
                <span class="brand-tagline" style="display:block;">Deployments so quiet, you can hear a server drop.</span>
            </div>
        </div>
        <h2 style="margin:0 0 6px 0; font-size:18px;">Unlock Vault</h2>
        <p style="color:var(--text-secondary); margin:0 0 18px 0; font-size:13px;">
            Enter your configured application password to manage projects and execute SSH deployments.
        </p>
        <?php if ($error !== ''): ?>
            <div class="ssh-test-banner show err" style="margin:0 0 14px 0; font-family:var(--font-sans);">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group" style="margin-bottom:16px;">
                <label for="deployer_login_password">Application Password</label>
                <input type="password" class="form-control" id="deployer_login_password" name="deployer_login_password" required autofocus placeholder="Enter password">
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Sign In</button>
        </form>
    </div>
</body>
</html>
        <?php
        exit;
    }
}
