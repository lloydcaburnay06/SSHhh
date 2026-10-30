<?php

declare(strict_types=1);

use Deployer\Bootstrap;

require_once __DIR__ . '/vendor/autoload.php';
$app = Bootstrap::init();

$projectId = (int) ($_GET['id'] ?? 0);
$project = $projectId > 0 ? $app->projects()->find($projectId) : null;

if (!$project) {
    header('Location: index.php');
    exit;
}

$commands = $app->projects()->listCommands($projectId);
$allProjects = $app->projects()->listAll();
$eventsData = $app->logger()->query($projectId, ['limit' => 60]);

$pageTitle = (string) $project['name'];
$csrfToken = $app->csrfToken();

require __DIR__ . '/src/Views/header.php';
?>

<main class="container">
    <nav class="breadcrumb">
        <a href="index.php">&larr; All Projects</a>
        <span>/</span>
        <strong id="breadcrumbProjectName"><?= htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') ?></strong>
    </nav>

    <div class="project-layout">
        <!-- Left Independently Scrollable Pane: Project Hero + Card/Grid Commands + Complete Event Log -->
        <div class="project-left-pane">
            <section class="project-hero">
                <div class="hero-info">
                    <h1>
                        <span id="heroProjectName"><?= htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge env-<?= htmlspecialchars((string) $project['environment'], ENT_QUOTES, 'UTF-8') ?>" id="heroEnvBadge">
                            <?= htmlspecialchars((string) $project['environment'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span id="heroStatusBadge"></span>
                    </h1>
                    <div class="hero-chips" id="heroChips"></div>
                </div>
                <div class="hero-actions">
                    <button type="button" class="btn btn-secondary" id="duplicateHeroBtn" title="Duplicate this project and its command template">
                        <span>&#128203;</span>
                        <span>Duplicate</span>
                    </button>
                    <button type="button" class="btn btn-secondary" id="testSshHeroBtn">
                        <span>&#9889;</span>
                        <span>Test SSH</span>
                    </button>
                    <button type="button" class="btn btn-secondary" id="editSshHeroBtn">
                        <span>&#128274;</span>
                        <span>SSH &amp; Project Settings</span>
                    </button>
                    <button type="button" class="btn btn-deploy btn-lg" id="deployAllBtn">
                        <span>&#9654;</span>
                        <span>Deploy Now (Execute All)</span>
                    </button>
                </div>
            </section>

            <section class="panel commands-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <h2 class="panel-title">Deployment Commands</h2>
                        <span class="dirty-badge saved" id="commandsDirtyBadge">All changes saved</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" id="addCommandBtn">+ Add Command</button>
                        <button type="button" class="btn btn-primary btn-sm" id="saveCommandsBtn">Save Commands</button>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="commands-grid" id="commandsList"></div>

                    <div class="preset-bar" id="presetBar">
                        <span class="preset-label">Quick add step:</span>
                        <button type="button" class="preset-chip" data-name="Pull latest code" data-cmd="git pull origin $DEPLOY_BRANCH">+ git pull</button>
                        <button type="button" class="preset-chip" data-name="Check Git status" data-cmd="git status -sb">+ git status</button>
                        <button type="button" class="preset-chip" data-name="Install Composer dependencies" data-cmd="composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev">+ composer install</button>
                        <button type="button" class="preset-chip" data-name="Build frontend assets" data-cmd="npm ci && npm run build">+ npm build</button>
                        <button type="button" class="preset-chip" data-name="Run Laravel migrations" data-cmd="php artisan migrate --force">+ artisan migrate</button>
                        <button type="button" class="preset-chip" data-name="Optimize caches" data-cmd="php artisan optimize">+ artisan optimize</button>
                        <button type="button" class="preset-chip" data-name="Reload PM2" data-cmd="pm2 reload all">+ pm2 reload</button>
                    </div>

                    <form class="adhoc-box" id="adhocForm" autocomplete="off">
                        <input type="text" class="adhoc-input" id="adhocCommandInput" placeholder="Quick one-off SSH command (e.g. git log -n 3 --oneline or df -h)...">
                        <button type="submit" class="btn btn-secondary" id="runAdhocBtn">&#9654; Run Once</button>
                    </form>
                </div>
            </section>

            <section class="panel project-events-panel">
                <div class="panel-header event-log-header">
                    <div>
                        <h2 class="panel-title">Complete Event Log</h2>
                        <span class="panel-subtitle">Full audit &amp; execution history: deployments, individual commands, SSH tests, and settings updates</span>
                    </div>
                    <div class="event-filter-controls">
                        <select class="form-select" id="eventStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="success">Succeeded</option>
                            <option value="failed">Failed</option>
                            <option value="warning">Warning</option>
                            <option value="info">Info / Audit</option>
                        </select>
                        <input type="search" class="search-input event-search-input" id="eventSearchInput" placeholder="Search logs, commands, output...">
                        <div class="event-filter-btns">
                            <button type="button" class="btn btn-ghost btn-sm" id="refreshEventsBtn">Refresh</button>
                            <button type="button" class="btn btn-danger btn-sm" id="clearEventsBtn">Clear Logs</button>
                        </div>
                    </div>
                </div>
                <div class="event-tabs-bar">
                    <div class="event-tabs" id="eventCategoryTabs">
                        <button type="button" class="event-tab active" data-cat="all">All Events <span class="tab-count" id="cnt-all">0</span></button>
                        <button type="button" class="event-tab" data-cat="deployment">Deployments <span class="tab-count" id="cnt-deployment">0</span></button>
                        <button type="button" class="event-tab" data-cat="command">Commands <span class="tab-count" id="cnt-command">0</span></button>
                        <button type="button" class="event-tab" data-cat="ssh">SSH Tests <span class="tab-count" id="cnt-ssh">0</span></button>
                        <button type="button" class="event-tab" data-cat="audit">Config &amp; Audit <span class="tab-count" id="cnt-audit">0</span></button>
                    </div>
                </div>
                <div class="events-list" id="projectEventsList"></div>
            </section>
        </div>

        <!-- Right Independently Scrollable Pane: Live SSH Console -->
        <aside class="project-console-aside">
            <div class="panel terminal-panel" id="liveTerminalPanel">
                <div class="terminal-header">
                    <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                        <div class="terminal-dots">
                            <span class="t-dot red"></span>
                            <span class="t-dot yellow"></span>
                            <span class="t-dot green"></span>
                        </div>
                        <strong style="font-size:13px; white-space:nowrap;">Live SSH Console</strong>
                        <span id="consoleStateBadge"></span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                        <span id="consoleTimer" style="font-family:var(--font-mono); font-size:12px; color:var(--text-secondary);"></span>
                        <button type="button" class="btn btn-ghost btn-sm" id="copyConsoleBtn">Copy</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="clearConsoleBtn">Clear</button>
                    </div>
                </div>
                <pre class="terminal-output" id="terminalOutput">Ready. Click "Deploy Now (Execute All)" to run the full pipeline or "&#9654; Run" on any individual command card.</pre>
            </div>
        </aside>
    </div>
</main>

<?php require __DIR__ . '/src/Views/project_modal.php'; ?>

<script>
    window.INITIAL_PROJECT_PAGE = <?= json_encode([
        'project'      => $project,
        'commands'     => $commands,
        'all_projects' => $allProjects,
        'events'       => $eventsData,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/common.js?v=1.2.0"></script>
<script src="assets/js/project.js?v=1.2.0"></script>
</body>
</html>
