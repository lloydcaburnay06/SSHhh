<?php

declare(strict_types=1);

use Deployer\Bootstrap;

require_once __DIR__ . '/vendor/autoload.php';
$app = Bootstrap::init();

$pageTitle = 'Projects Dashboard';
$csrfToken = $app->csrfToken();
$appTagline = (string) ($app->config()['app_tagline'] ?? 'Deployments so quiet, you can hear a server drop.');
$initialProjects = $app->projects()->listAll();
$initialStats = $app->projects()->getDashboardStats();
$recentEvents = $app->logger()->query(null, ['limit' => 30]);

require __DIR__ . '/src/Views/header.php';
?>

<main class="container">
    <div class="mobile-tagline-strip">
        <?= htmlspecialchars($appTagline, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div class="dashboard-layout">
        <!-- Left Side: Project Content Area -->
        <div class="dashboard-main">
            <section class="toolbar">
                <div class="toolbar-left">
                    <input type="search" class="search-input" id="projectSearchInput" placeholder="Search projects, SSH hosts, paths...">
                    <div class="filter-pills" id="envFilterPills">
                        <button type="button" class="filter-pill active" data-env="all">All</button>
                        <button type="button" class="filter-pill" data-env="production">Production</button>
                        <button type="button" class="filter-pill" data-env="staging">Staging</button>
                        <button type="button" class="filter-pill" data-env="development">Development</button>
                        <button type="button" class="filter-pill filter-pill-down <?= (($initialStats['down_projects'] ?? 0) > 0) ? 'has-down' : '' ?>" data-env="app-down">🔴 Down (<span id="downFilterCount"><?= (int) ($initialStats['down_projects'] ?? 0) ?></span>)</button>
                    </div>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="btn btn-primary btn-lg toolbar-add-btn" id="addProjectBtn">
                        <span>+</span>
                        <span>Add Project</span>
                    </button>
                </div>
            </section>

            <section id="projectsContainer"></section>
        </div>

        <!-- Right Side: Recent Global Event Log & Developer Command Directory Tabs -->
        <aside class="dashboard-aside">
            <section class="panel global-events-panel aside-tabbed-panel">
                <div class="panel-header aside-tab-header">
                    <div class="aside-tab-nav" role="tablist">
                        <button type="button" class="aside-tab-btn active" data-aside-tab="events" id="asideTabEventsBtn" role="tab" aria-selected="true" aria-controls="asidePaneEvents">
                            <span>📜 Event Logs</span>
                            <span class="aside-tab-count" id="asideEventsCount"><?= count($recentEvents['items']) ?></span>
                        </button>
                        <button type="button" class="aside-tab-btn" data-aside-tab="directory" id="asideTabDirectoryBtn" role="tab" aria-selected="false" aria-controls="asidePaneDirectory">
                            <span>⚡ Commands</span>
                            <span class="aside-tab-count" id="asideCommandsCount">0</span>
                        </button>
                    </div>
                    <div class="aside-header-actions" id="asideEventsActions">
                        <button type="button" class="btn btn-ghost btn-xs" id="refreshGlobalEventsBtn" title="Refresh recent events">Refresh</button>
                    </div>
                </div>

                <!-- Tab 1: Recent Global Event Log -->
                <div class="aside-tab-pane active" id="asidePaneEvents" role="tabpanel" aria-labelledby="asideTabEventsBtn">
                    <div class="events-list global-events-scroll" id="globalEventsList"></div>
                </div>

                <!-- Tab 2: Directory of Useful Laravel & Networking Commands -->
                <div class="aside-tab-pane" id="asidePaneDirectory" role="tabpanel" aria-labelledby="asideTabDirectoryBtn" style="display: none;">
                    <div class="cmddir-toolbar">
                        <div class="cmddir-search-wrap">
                            <input type="search" class="search-input cmddir-search-input" id="cmddirSearchInput" placeholder="Search commands, artisan, nginx, git, ports...">
                        </div>
                        <div class="cmddir-filters-bar" id="cmddirFilterPills"></div>
                    </div>
                    <div class="cmddir-scroll" id="cmddirList"></div>
                </div>
            </section>
        </aside>
    </div>
</main>

<?php require __DIR__ . '/src/Views/project_modal.php'; ?>
<?php require __DIR__ . '/src/Views/command_run_modal.php'; ?>

<script>
    window.INITIAL_DASHBOARD_DATA = <?= json_encode([
        'projects' => $initialProjects,
        'stats'    => $initialStats,
        'events'   => $recentEvents['items'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/common.js?v=1.3.0"></script>
<script src="assets/js/commands-data.js?v=1.3.0"></script>
<script src="assets/js/dashboard.js?v=1.3.2"></script>
</body>
</html>
