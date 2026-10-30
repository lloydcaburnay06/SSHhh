<?php
/** @var string $pageTitle */
/** @var string $csrfToken */
/** @var \Deployer\Bootstrap $app */
$appName = (string) ($app->config()['app_name'] ?? 'SSH Deployer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=1.0.0">
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a href="index.php" class="brand">
                <span class="brand-icon">&gt;_</span>
                <span><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <div class="topbar-meta">
                <span class="security-pill" title="SSH passwords and private keys are encrypted at rest with AES-256-GCM authenticated encryption">
                    <span>&#128274;</span>
                    <span>AES-256-GCM Vault Active</span>
                </span>
                <?php if ($app->isAuthEnabled()): ?>
                    <a href="index.php?logout=1" class="btn btn-ghost btn-sm">Sign Out</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <script>
        window.DEPLOYER_CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
    </script>
