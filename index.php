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
            <section class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Managed Projects</span>
                    <span class="stat-value indigo" id="statTotalProjects"><?= (int) $initialStats['total_projects'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Total Deployments</span>
                    <span class="stat-value" id="statTotalDeploys"><?= (int) $initialStats['total_deployments'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Successful Runs</span>
                    <span class="stat-value emerald" id="statSuccessDeploys"><?= (int) $initialStats['successful_deployments'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Failed Runs</span>
                    <span class="stat-value rose" id="statFailedDeploys"><?= (int) $initialStats['failed_deployments'] ?></span>
                </div>
            </section>

            <section class="toolbar">
                <div class="toolbar-left">
                    <input type="search" class="search-input" id="projectSearchInput" placeholder="Search projects, SSH hosts, paths...">
                    <div class="filter-pills" id="envFilterPills">
                        <button type="button" class="filter-pill active" data-env="all">All</button>
                        <button type="button" class="filter-pill" data-env="production">Production</button>
                        <button type="button" class="filter-pill" data-env="staging">Staging</button>
                        <button type="button" class="filter-pill" data-env="development">Development</button>
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

        <!-- Right Side: Recent Global Event Log -->
        <aside class="dashboard-aside">
            <section class="panel global-events-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <div>
                            <h2 class="panel-title">Recent Global Event Log</h2>
                            <span class="panel-subtitle">Latest deployments, SSH checks, and config updates</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" id="refreshGlobalEventsBtn">Refresh</button>
                </div>
                <div class="events-list global-events-scroll" id="globalEventsList"></div>
            </section>
        </aside>
    </div>
</main>

<?php require __DIR__ . '/src/Views/project_modal.php'; ?>

<script>
    window.INITIAL_DASHBOARD_DATA = <?= json_encode([
        'projects' => $initialProjects,
        'stats'    => $initialStats,
        'events'   => $recentEvents['items'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/common.js?v=1.1.3"></script>
<script src="assets/js/dashboard.js?v=1.1.3"></script>
</body>
</html>
