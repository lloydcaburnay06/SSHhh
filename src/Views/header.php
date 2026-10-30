<?php
/** @var string $pageTitle */
/** @var string $csrfToken */
/** @var \Deployer\Bootstrap $app */
$appName = (string) ($app->config()['app_name'] ?? 'SSHhh');
$appTagline = (string) ($app->config()['app_tagline'] ?? 'Deployments so quiet, you can hear a server drop.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?= htmlspecialchars($appTagline, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#090d16">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/app.css?v=1.3.0">
</head>
<body class="app-shell">
    <header class="topbar">
        <div class="topbar-inner">
            <a href="index.php" class="brand" aria-label="<?= htmlspecialchars($appName . ' — ' . $appTagline, ENT_QUOTES, 'UTF-8') ?>">
                <img src="assets/img/icon-192.png" alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>" class="brand-logo-img" width="38" height="38">
                <div class="brand-text-wrap">
                    <span class="brand-wordmark">
                        <?php if ($appName === 'SSHhh'): ?>
                            SSH<span class="brand-hush">hh</span>
                        <?php else: ?>
                            <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </span>
                    <span class="brand-tagline"><?= htmlspecialchars($appTagline, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </a>
            <div class="topbar-meta">
                <button type="button" class="btn btn-secondary btn-sm pwa-install-btn" id="pwaInstallBtn" style="display:none;" title="Install SSHhh as an app">
                    <span>&#11015;</span>
                    <span class="hide-xs">Install App</span>
                </button>
                <span class="security-pill" title="SSH passwords and private keys are encrypted at rest with AES-256-GCM authenticated encryption">
                    <span>&#128274;</span>
                    <span class="security-pill-full">AES-256-GCM Vault</span>
                    <span class="security-pill-short">AES-256</span>
                </span>
                <?php if ($app->isAuthEnabled()): ?>
                    <a href="index.php?logout=1" class="btn btn-ghost btn-sm">Sign Out</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <script>
        window.DEPLOYER_CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
        window.DEPLOYER_APP_NAME = <?= json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <!-- Global In-App Confirmation Modal -->
    <div class="modal-backdrop confirm-modal-backdrop" id="confirmActionModal" aria-hidden="true" style="display:none;">
        <div class="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <div class="confirm-modal-header">
                <div class="confirm-icon-bubble" id="confirmModalIcon">⚡</div>
                <div style="min-width:0; flex:1;">
                    <h3 id="confirmModalTitle" class="confirm-modal-title">Confirm Action</h3>
                    <p id="confirmModalSubtitle" class="confirm-modal-subtitle" style="display:none;"></p>
                </div>
                <button type="button" class="icon-btn" id="confirmModalCloseBtn" title="Close">&times;</button>
            </div>
            <div class="confirm-modal-body">
                <div id="confirmModalMessage" class="confirm-modal-message"></div>
                <div id="confirmModalWarning" class="confirm-modal-warning" style="display:none;"></div>
                <pre id="confirmModalCode" class="confirm-modal-code" style="display:none;"></pre>
            </div>
            <div class="confirm-modal-footer">
                <button type="button" class="btn btn-ghost" id="confirmModalCancelBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
