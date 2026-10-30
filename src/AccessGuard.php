<?php

declare(strict_types=1);

namespace Deployer;

use PDO;
use Throwable;

final class AccessGuard
{
    private const COOKIE_NAME = 'sshhh_device_token';
    private const DEVICE_COOKIE_DAYS = 30;

    /**
     * Check if Google Authenticator has already been configured in the database.
     */
    public static function isConfigured(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query('SELECT `is_configured` FROM `auth_state` WHERE `id` = 1 LIMIT 1');
            return (bool) ($stmt ? $stmt->fetchColumn() : false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Enforce single-user Google Authenticator authentication.
     */
    public static function enforce(PDO $pdo, Crypto $crypto, string $csrfToken): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isApi = ($scriptName === 'api.php');

        // 1. Handle Logout
        if (isset($_GET['logout']) && $_GET['logout'] === '1') {
            self::handleLogout($pdo);
            header('Location: index.php');
            exit;
        }

        // 2. Check Remembered Device Cookie if session is not already authenticated
        if (empty($_SESSION['deployer_authenticated'])) {
            self::checkRememberedDevice($pdo);
        }

        // 3. Query current auth state
        $stmt = $pdo->query('SELECT `id`, `totp_secret_encrypted`, `is_configured`, `configured_at` FROM `auth_state` WHERE `id` = 1 LIMIT 1');
        $authState = $stmt ? $stmt->fetch() : null;
        $isConfigured = !empty($authState['is_configured']);

        // 4. FLOW A: Initial Setup (when not configured yet)
        if (!$isConfigured) {
            if ($isApi) {
                http_response_code(428); // Precondition Required
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Google Authenticator setup is required before accessing the API. Please open SSHhh in your browser to complete initial setup.',
                ]);
                exit;
            }

            self::handleInitialSetup($pdo, $crypto, $csrfToken);
            return;
        }

        // 5. FLOW B: Authenticator is configured.
        // If already authenticated via session or valid device cookie:
        if (!empty($_SESSION['deployer_authenticated'])) {
            return;
        }

        // If not authenticated:
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'Authentication required. Please unlock SSHhh with your Google Authenticator code.',
            ]);
            exit;
        }

        self::handleLogin($pdo, $crypto, $csrfToken, (string) ($authState['totp_secret_encrypted'] ?? ''));
    }

    private static function handleLogout(PDO $pdo): void
    {
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $token = (string) $_COOKIE[self::COOKIE_NAME];
            $tokenHash = hash('sha256', $token);
            try {
                $stmt = $pdo->prepare('DELETE FROM `remembered_devices` WHERE `token_hash` = :hash');
                $stmt->execute([':hash' => $tokenHash]);
            } catch (Throwable) {
                // Ignore DB deletion errors during logout
            }
        }

        self::clearDeviceCookie();
        unset($_SESSION['deployer_authenticated']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function checkRememberedDevice(PDO $pdo): void
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $token = (string) $_COOKIE[self::COOKIE_NAME];
        if (strlen($token) < 32) {
            self::clearDeviceCookie();
            return;
        }

        $tokenHash = hash('sha256', $token);
        try {
            $stmt = $pdo->prepare('SELECT `id`, `expires_at` FROM `remembered_devices` WHERE `token_hash` = :hash AND `expires_at` > NOW() LIMIT 1');
            $stmt->execute([':hash' => $tokenHash]);
            $device = $stmt->fetch();

            if ($device) {
                $_SESSION['deployer_authenticated'] = true;
            } else {
                self::clearDeviceCookie();
            }
        } catch (Throwable) {
            // DB query failure, fail closed
        }
    }

    private static function issueRememberDevice(PDO $pdo): void
    {
        try {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 60);

            $stmt = $pdo->prepare(<<<'SQL'
                INSERT INTO `remembered_devices` (`token_hash`, `user_agent`, `ip_address`, `expires_at`, `created_at`)
                VALUES (:token_hash, :ua, :ip, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())
            SQL);
            $stmt->execute([
                ':token_hash' => $tokenHash,
                ':ua'         => $ua,
                ':ip'         => $ip,
            ]);

            setcookie(self::COOKIE_NAME, $rawToken, [
                'expires'  => time() + (self::DEVICE_COOKIE_DAYS * 86400),
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => self::isHttps(),
            ]);
        } catch (Throwable) {
            // Silently continue rather than blocking login
        }
    }

    private static function clearDeviceCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    private static function handleInitialSetup(PDO $pdo, Crypto $crypto, string $csrfToken): never
    {
        if (empty($_SESSION['pending_totp_secret']) || !is_string($_SESSION['pending_totp_secret'])) {
            $_SESSION['pending_totp_secret'] = Totp::generateSecret(20);
        }
        $secret = $_SESSION['pending_totp_secret'];

        $error = '';
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'));

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['setup_code'])) {
            $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
            $submittedCode = preg_replace('/\s+/', '', (string) $_POST['setup_code']);
            $rememberDevice = !empty($_POST['remember_device']);

            if ($csrfToken === '' || !hash_equals($csrfToken, $submittedCsrf)) {
                $error = 'Invalid security token. Please refresh and try again.';
            } elseif (!preg_match('/^[0-9]{6}$/', $submittedCode)) {
                $error = 'Please enter a valid 6-digit verification code.';
            } elseif (Totp::verifyCode($secret, $submittedCode, 1)) {
                // SUCCESS: Encrypt TOTP secret and permanently lock setup
                $encryptedSecret = $crypto->encrypt($secret, 'totp-secret');
                $stmt = $pdo->prepare(<<<'SQL'
                    UPDATE `auth_state`
                    SET `totp_secret_encrypted` = :secret,
                        `is_configured` = 1,
                        `configured_at` = NOW()
                    WHERE `id` = 1
                SQL);
                $stmt->execute([':secret' => $encryptedSecret]);

                unset($_SESSION['pending_totp_secret']);
                session_regenerate_id(true);
                $_SESSION['deployer_authenticated'] = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if ($rememberDevice) {
                    self::issueRememberDevice($pdo);
                }

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                    exit;
                }

                header('Location: index.php');
                exit;
            } else {
                usleep(300_000);
                $error = 'Incorrect verification code. Ensure your device clock is accurate and try again.';
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }

        $provisioningUri = Totp::getProvisioningUri($secret, 'Admin', 'SSHhh');
        $qrCodeDataUri = Totp::getQrCodeDataUri($provisioningUri);

        self::renderSetupScreen($csrfToken, $secret, $qrCodeDataUri, $error);
    }

    private static function handleLogin(PDO $pdo, Crypto $crypto, string $csrfToken, string $encryptedSecret): never
    {
        $error = '';
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'));

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['login_code'])) {
            $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
            $submittedCode = preg_replace('/\s+/', '', (string) $_POST['login_code']);
            $rememberDevice = !empty($_POST['remember_device']);

            if ($csrfToken === '' || !hash_equals($csrfToken, $submittedCsrf)) {
                $error = 'Invalid security token. Please try again.';
            } elseif (!preg_match('/^[0-9]{6}$/', $submittedCode)) {
                $error = 'Please enter a valid 6-digit code.';
            } else {
                $totpSecret = '';
                try {
                    $totpSecret = $crypto->decrypt($encryptedSecret, 'totp-secret');
                } catch (Throwable) {
                    $error = 'Security secret decryption failed. Please check master encryption key.';
                }

                if ($totpSecret !== '' && Totp::verifyCode($totpSecret, $submittedCode, 1)) {
                    session_regenerate_id(true);
                    $_SESSION['deployer_authenticated'] = true;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    if ($rememberDevice) {
                        self::issueRememberDevice($pdo);
                    }

                    $redirect = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
                    if (str_contains($redirect, 'logout=1') || empty($redirect)) {
                        $redirect = 'index.php';
                    }

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => true, 'redirect' => $redirect]);
                        exit;
                    }

                    header('Location: ' . $redirect);
                    exit;
                } else {
                    usleep(350_000);
                    $error = 'Invalid verification code. Please check your Google Authenticator app.';
                }
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }

        self::renderLoginScreen($csrfToken, $error);
    }

    private static function renderSetupScreen(string $csrfToken, string $secret, string $qrCodeDataUri, string $error): never
    {
        http_response_code(200);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#090d16">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SSHhh">
    <title>Authenticator Setup — SSHhh</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/app.css?v=1.2.0">
</head>
<body class="totp-auth-page">
    <div class="totp-auth-container">
        <div class="brand" style="justify-content:center; margin-bottom:16px;">
            <img src="assets/img/icon-192.png" alt="SSHhh" class="brand-logo-img" width="44" height="44">
            <div class="brand-text-wrap" style="text-align:left;">
                <span class="brand-wordmark">SSH<span class="brand-hush">hh</span></span>
                <span class="brand-tagline">Deployments so quiet, you can hear a server drop.</span>
            </div>
        </div>

        <div class="panel totp-card">
            <div class="totp-card-header">
                <span class="badge badge-totp-setup">Initial Setup</span>
                <h1 class="totp-heading">Google Authenticator</h1>
                <p class="totp-subheading">
                    Link your mobile phone to unlock SSHhh securely without needing passwords.
                </p>
            </div>

            <div id="authAlertBanner" class="ssh-test-banner show err" style="<?= $error !== '' ? '' : 'display:none;' ?> margin-bottom:16px; font-family:var(--font-sans);">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="totp-step-box">
                <div class="totp-step-title">
                    <span class="totp-step-num">1</span>
                    <span>Scan this QR code with Google Authenticator</span>
                </div>
                <div class="totp-qr-wrapper">
                    <img src="<?= $qrCodeDataUri ?>" alt="Scan with Google Authenticator" class="totp-qr-img" width="180" height="180">
                </div>
                <div class="totp-manual-fallback">
                    <span class="totp-manual-label">Can't scan? Enter this key manually:</span>
                    <div class="totp-secret-row">
                        <code class="totp-secret-code" id="secretKeyText"><?= htmlspecialchars(chunk_split($secret, 4, ' '), ENT_QUOTES, 'UTF-8') ?></code>
                        <button type="button" class="btn btn-secondary btn-sm" id="copyKeyBtn" onclick="copySecretKey()">Copy</button>
                    </div>
                </div>
            </div>

            <form method="post" autocomplete="off" class="totp-form" id="setupTotpForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="totp-step-title" style="margin-top:16px; margin-bottom:8px;">
                    <span class="totp-step-num">2</span>
                    <span>Enter the 6-digit code from your phone</span>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <input type="text"
                           name="setup_code"
                           id="setup_code"
                           class="form-control totp-code-input"
                           pattern="[0-9 ]*"
                           inputmode="numeric"
                           maxlength="7"
                           placeholder="000 000"
                           autofocus
                           required
                           autocomplete="one-time-code">
                </div>

                <label class="remember-device-toggle">
                    <input type="checkbox" name="remember_device" value="1" checked>
                    <span>Remember this device for 30 days</span>
                </label>

                <button type="submit" class="btn btn-primary btn-lg" style="width:100%; justify-content:center; margin-top:14px;">
                    Verify &amp; Activate Authenticator
                </button>
            </form>

            <div class="totp-lockout-notice">
                <span class="totp-lockout-icon">&#128274;</span>
                <span><strong>One-Time Setup:</strong> Once verified, this setup screen and secret key will never be shown again.</span>
            </div>
        </div>
    </div>

    <script>
    function copySecretKey() {
        const raw = <?= json_encode($secret) ?>;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(raw).then(() => {
                const btn = document.getElementById('copyKeyBtn');
                if (btn) {
                    btn.textContent = 'Copied!';
                    setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
                }
            });
        }
    }

    document.querySelectorAll('.totp-code-input').forEach(input => {
        input.addEventListener('input', (e) => {
            let val = e.target.value.replace(/\D/g, '').slice(0, 6);
            if (val.length > 3) {
                val = val.slice(0, 3) + ' ' + val.slice(3);
            }
            e.target.value = val;
        });
    });

    const setupForm = document.getElementById('setupTotpForm');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = setupForm.querySelector('button[type="submit"]');
            const alertBanner = document.getElementById('authAlertBanner');
            const origText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Activating...';
            }
            if (alertBanner) alertBanner.style.display = 'none';

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(setupForm)
                });
                const data = await res.json();
                if (data.ok) {
                    if (submitBtn) submitBtn.textContent = 'Activated! Opening...';
                    window.location.replace(data.redirect || 'index.php');
                    return;
                }
                if (alertBanner) {
                    alertBanner.textContent = data.error || 'Verification failed.';
                    alertBanner.style.display = 'block';
                }
            } catch {
                setupForm.submit();
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
        });
    }
    </script>
</body>
</html>
        <?php
        exit;
    }

    private static function renderLoginScreen(string $csrfToken, string $error): never
    {
        http_response_code(200);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#090d16">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SSHhh">
    <title>Unlock — SSHhh</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/app.css?v=1.2.0">
</head>
<body class="totp-auth-page">
    <div class="totp-auth-container" style="max-width:420px;">
        <div class="brand" style="justify-content:center; margin-bottom:20px;">
            <img src="assets/img/icon-192.png" alt="SSHhh" class="brand-logo-img" width="44" height="44">
            <div class="brand-text-wrap" style="text-align:left;">
                <span class="brand-wordmark">SSH<span class="brand-hush">hh</span></span>
                <span class="brand-tagline">Deployments so quiet, you can hear a server drop.</span>
            </div>
        </div>

        <div class="panel totp-card">
            <div class="totp-card-header">
                <h1 class="totp-heading">Unlock SSHhh</h1>
                <p class="totp-subheading">
                    Enter the 6-digit code from Google Authenticator on your phone.
                </p>
            </div>

            <div id="authAlertBanner" class="ssh-test-banner show err" style="<?= $error !== '' ? '' : 'display:none;' ?> margin-bottom:16px; font-family:var(--font-sans);">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <form method="post" autocomplete="off" class="totp-form" id="loginTotpForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group" style="text-align:center; margin-bottom:16px;">
                    <label for="login_code" style="font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:8px; display:block;">
                        Authenticator Code
                    </label>
                    <input type="text"
                           name="login_code"
                           id="login_code"
                           class="form-control totp-code-input"
                           pattern="[0-9 ]*"
                           inputmode="numeric"
                           maxlength="7"
                           placeholder="000 000"
                           autofocus
                           required
                           autocomplete="one-time-code">
                </div>

                <label class="remember-device-toggle">
                    <input type="checkbox" name="remember_device" value="1" checked>
                    <span>Remember this device for 30 days</span>
                </label>

                <button type="submit" class="btn btn-primary btn-lg" style="width:100%; justify-content:center; margin-top:16px;">
                    Unlock SSHhh
                </button>
            </form>
        </div>
    </div>

    <script>
    document.querySelectorAll('.totp-code-input').forEach(input => {
        input.addEventListener('input', (e) => {
            let val = e.target.value.replace(/\D/g, '').slice(0, 6);
            if (val.length > 3) {
                val = val.slice(0, 3) + ' ' + val.slice(3);
            }
            e.target.value = val;
        });
    });

    const loginForm = document.getElementById('loginTotpForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = loginForm.querySelector('button[type="submit"]');
            const alertBanner = document.getElementById('authAlertBanner');
            const origText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Unlocking...';
            }
            if (alertBanner) alertBanner.style.display = 'none';

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(loginForm)
                });
                const data = await res.json();
                if (data.ok) {
                    if (submitBtn) submitBtn.textContent = 'Unlocked! Opening...';
                    window.location.replace(data.redirect || 'index.php');
                    return;
                }
                if (alertBanner) {
                    alertBanner.textContent = data.error || 'Invalid code. Please try again.';
                    alertBanner.style.display = 'block';
                }
            } catch {
                loginForm.submit();
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
        });
    }
    </script>
</body>
</html>
        <?php
        exit;
    }
}
