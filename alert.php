<?php

declare(strict_types=1);

/**
 * Error alert decision page (opened from the push notification). Works without logging in:
 * the random token in the link is the permission, it is single-use and expires after 24 hours.
 */

use Deployer\Bootstrap;

require_once __DIR__ . '/vendor/autoload.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$token = (string) ($_GET['t'] ?? '');
$view = null;
$error = null;
try {
    $app = Bootstrap::init();
    $app->releaseSessionLock();
    $view = $app->alerts()->viewByToken($token);
    if ($view === null) {
        $error = 'This alert link is invalid or older than 24 hours.';
    }
} catch (Throwable $e) {
    $error = 'SSHhh could not load this alert: ' . $e->getMessage();
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$css = 'assets/css/app.css?v=' . (int) @filemtime(__DIR__ . '/assets/css/app.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error alert — SSHhh</title>
    <link rel="stylesheet" href="<?= $h($css) ?>">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px; }
        .alert-card { max-width:460px; width:100%; background:var(--bg-elevated, #111827); border:1px solid var(--border-color, #334155); border-radius:14px; padding:20px; }
        .alert-card h1 { font-size:17px; margin:0 0 4px; }
        .alert-card .meta { font-size:12px; color:var(--text-muted, #94a3b8); margin-bottom:14px; }
        .alert-card .summary { font-size:15px; line-height:1.5; padding:12px; border-radius:10px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.3); margin-bottom:14px; }
        .alert-card .actions { display:flex; gap:10px; }
        .alert-card .actions .btn { flex:1; justify-content:center; padding:12px; font-size:15px; }
        .alert-card .status { font-size:13px; margin-top:12px; color:var(--text-secondary, #cbd5e1); }
    </style>
</head>
<body>
<div class="alert-card">
<?php if ($error !== null): ?>
    <h1>⚠️ Alert unavailable</h1>
    <p class="status"><?= $h($error) ?></p>
<?php else: ?>
    <h1>⚠️ <?= $h($view['project_name']) ?>: new error</h1>
    <div class="meta">Found <?= $h($view['created_at']) ?> · <?= (int) $view['issue_count'] ?> new issue<?= (int) $view['issue_count'] === 1 ? '' : 's' ?></div>
    <div class="summary"><?= $h($view['summary']) ?></div>
    <?php if ($view['status'] === 'pending'): ?>
        <div class="actions" id="actions">
            <button type="button" class="btn btn-deploy" data-choice="proceed">🤖 Proceed — send to Claude</button>
            <button type="button" class="btn btn-ghost" data-choice="ignore">Ignore</button>
        </div>
    <?php endif; ?>
    <p class="status" id="status"><?= $h($view['status_text']) ?></p>
<?php endif; ?>
</div>
<?php if ($error === null && $view['status'] === 'pending'): ?>
<script>
(function () {
    const token = <?= json_encode($token) ?>;
    const status = document.getElementById('status');
    document.querySelectorAll('#actions button').forEach((btn) => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('#actions button').forEach((b) => { b.disabled = true; });
            status.textContent = btn.dataset.choice === 'proceed' ? 'Sending to Claude Code…' : 'Saving…';
            try {
                const res = await fetch('api.php?action=alert_action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token, choice: btn.dataset.choice, via: 'page' })
                });
                const data = await res.json();
                status.innerHTML = '';
                status.textContent = data.message || data.error || 'Done.';
                if (data.session_url) {
                    const a = document.createElement('a');
                    a.href = data.session_url; a.target = '_blank'; a.rel = 'noopener';
                    a.textContent = ' Open the Claude Code session';
                    status.appendChild(a);
                }
                document.getElementById('actions').remove();
            } catch (e) {
                status.textContent = 'Could not reach SSHhh: ' + e.message;
                document.querySelectorAll('#actions button').forEach((b) => { b.disabled = false; });
            }
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
