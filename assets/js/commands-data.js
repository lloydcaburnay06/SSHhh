(function () {
    'use strict';

    window.COMMAND_DIRECTORY_CATEGORIES = [
        { id: 'all', label: 'All Commands', icon: '⚡' },
        { id: 'maintenance', label: 'Cache & Maintenance', icon: '🧹', badgeClass: 'cat-maintenance' },
        { id: 'database', label: 'Database & Migrations', icon: '🗄️', badgeClass: 'cat-database' },
        { id: 'queue', label: 'Queues & Cron', icon: '⏱️', badgeClass: 'cat-queue' },
        { id: 'storage', label: 'Storage & Permissions', icon: '📁', badgeClass: 'cat-storage' },
        { id: 'composer', label: 'Composer & PHP', icon: '🐘', badgeClass: 'cat-composer' },
        { id: 'assets', label: 'Frontend & Assets', icon: '📦', badgeClass: 'cat-assets' },
        { id: 'services', label: 'Web Server & PHP-FPM', icon: '🌐', badgeClass: 'cat-services' },
        { id: 'docker', label: 'Docker & Containers', icon: '🐳', badgeClass: 'cat-docker' },
        { id: 'git', label: 'Git & Repo', icon: '🌿', badgeClass: 'cat-git' },
        { id: 'network', label: 'Network & SSL', icon: '📡', badgeClass: 'cat-network' },
        { id: 'server', label: 'Server Diagnostics', icon: '📊', badgeClass: 'cat-server' }
    ];

    window.COMMAND_DIRECTORY = [
        // ==========================================
        // 1. LARAVEL MAINTENANCE & CACHE
        // ==========================================
        {
            id: 'artisan-down',
            category: 'maintenance',
            title: 'Enable Maintenance Mode',
            command: 'php artisan down || true',
            desc: 'Puts the Laravel application into maintenance mode (HTTP 503). Continues even if already down.',
            suggestSudo: false,
            tags: ['down', 'maintenance', '503', 'artisan', 'offline']
        },
        {
            id: 'artisan-down-secret',
            category: 'maintenance',
            title: 'Maintenance Mode with Secret Bypass',
            command: 'php artisan down --secret="deployer-bypass-token" --render="errors::503"',
            desc: 'Puts app in maintenance mode but allows developers with the secret cookie /deployer-bypass-token to browse.',
            suggestSudo: false,
            tags: ['down', 'secret', 'bypass', 'cookie', 'maintenance', '503']
        },
        {
            id: 'artisan-up',
            category: 'maintenance',
            title: 'Bring Application Live',
            command: 'php artisan up',
            desc: 'Takes the application out of maintenance mode and restores normal traffic to users.',
            suggestSudo: false,
            tags: ['up', 'live', 'online', 'artisan', 'resume']
        },
        {
            id: 'optimize-clear',
            category: 'maintenance',
            title: 'Clear All Caches (Optimize Clear)',
            command: 'php artisan optimize:clear',
            desc: 'Flushes config, route, view, event, and compiled cache in a single command. Essential after deploying code.',
            suggestSudo: false,
            tags: ['cache', 'optimize', 'clear', 'flush', 'views', 'routes', 'config']
        },
        {
            id: 'optimize-cache',
            category: 'maintenance',
            title: 'Optimize All (Re-cache for Production)',
            command: 'php artisan optimize',
            desc: 'Caches config, routes, and compiled classes for maximum production response speeds.',
            suggestSudo: false,
            tags: ['cache', 'optimize', 'production', 'speed', 'performance']
        },
        {
            id: 'config-cache',
            category: 'maintenance',
            title: 'Cache Configuration',
            command: 'php artisan config:cache',
            desc: 'Combines all of your configuration options into a single cached file for rapid bootstrapping.',
            suggestSudo: false,
            tags: ['config', 'cache', 'artisan', 'env']
        },
        {
            id: 'config-clear',
            category: 'maintenance',
            title: 'Clear Configuration Cache',
            command: 'php artisan config:clear',
            desc: 'Removes the configuration cache file so fresh .env values take effect immediately.',
            suggestSudo: false,
            tags: ['config', 'clear', 'env', 'artisan']
        },
        {
            id: 'route-cache',
            category: 'maintenance',
            title: 'Cache Routes',
            command: 'php artisan route:cache',
            desc: 'Compiles all registered routes into a single fast cache file. Greatly reduces route registration overhead.',
            suggestSudo: false,
            tags: ['route', 'cache', 'artisan', 'routing']
        },
        {
            id: 'route-clear',
            category: 'maintenance',
            title: 'Clear Route Cache',
            command: 'php artisan route:clear',
            desc: 'Removes the cached route file so newly added or modified routes are recognized.',
            suggestSudo: false,
            tags: ['route', 'clear', 'routing', 'artisan']
        },
        {
            id: 'view-cache',
            category: 'maintenance',
            title: 'Cache Blade Views',
            command: 'php artisan view:cache',
            desc: 'Precompiles all Blade templates so views do not have to be compiled just-in-time on first request.',
            suggestSudo: false,
            tags: ['view', 'blade', 'cache', 'templates']
        },
        {
            id: 'view-clear',
            category: 'maintenance',
            title: 'Clear Blade View Cache',
            command: 'php artisan view:clear',
            desc: 'Clears all compiled Blade template view files from storage/framework/views.',
            suggestSudo: false,
            tags: ['view', 'blade', 'clear', 'templates']
        },
        {
            id: 'event-cache',
            category: 'maintenance',
            title: 'Cache Events & Listeners',
            command: 'php artisan event:cache',
            desc: 'Discovers and caches all application event listeners to bypass dynamic event discovery.',
            suggestSudo: false,
            tags: ['event', 'listeners', 'cache', 'artisan']
        },
        {
            id: 'event-clear',
            category: 'maintenance',
            title: 'Clear Event Cache',
            command: 'php artisan event:clear',
            desc: 'Clears cached event listener mappings.',
            suggestSudo: false,
            tags: ['event', 'listeners', 'clear']
        },
        {
            id: 'cache-clear',
            category: 'maintenance',
            title: 'Flush Application Data Cache',
            command: 'php artisan cache:clear',
            desc: 'Flushes the default application data cache store (Redis, Memcached, Database, or File).',
            suggestSudo: false,
            tags: ['cache', 'flush', 'redis', 'memcached', 'data']
        },

        // ==========================================
        // 2. LARAVEL DATABASE & MIGRATIONS
        // ==========================================
        {
            id: 'migrate-force',
            category: 'database',
            title: 'Run Pending Migrations',
            command: 'php artisan migrate --force',
            desc: 'Executes outstanding database migrations without interactive confirmation prompts (safe for CI/CD).',
            suggestSudo: false,
            tags: ['migrate', 'database', 'schema', 'sql', 'tables']
        },
        {
            id: 'migrate-status',
            category: 'database',
            title: 'Check Migration Status',
            command: 'php artisan migrate:status',
            desc: 'Shows each migration batch, filename, and whether it has already run or is pending.',
            suggestSudo: false,
            tags: ['migrate', 'status', 'batches', 'schema']
        },
        {
            id: 'migrate-rollback-step',
            category: 'database',
            title: 'Rollback Last Migration Batch',
            command: 'php artisan migrate:rollback --step=1 --force',
            desc: 'Rolls back the most recent migration operation by 1 step.',
            suggestSudo: false,
            tags: ['migrate', 'rollback', 'undo', 'step']
        },
        {
            id: 'db-seed-force',
            category: 'database',
            title: 'Run Database Seeds',
            command: 'php artisan db:seed --force',
            desc: 'Populates database tables using defined seeders without prompt.',
            suggestSudo: false,
            tags: ['seed', 'seeder', 'database', 'records']
        },
        {
            id: 'db-show',
            category: 'database',
            title: 'Inspect Database Connection & Stats',
            command: 'php artisan db:show',
            desc: 'Displays database server type, host, open connections, total size, and table row counts.',
            suggestSudo: false,
            tags: ['db', 'show', 'stats', 'size', 'connection', 'mysql', 'postgres']
        },
        {
            id: 'db-table-inspect',
            category: 'database',
            title: 'Inspect Database Table Structure',
            command: 'php artisan db:table users',
            desc: 'Outputs detailed table information: columns, data types, indexes, and total rows.',
            suggestSudo: false,
            tags: ['db', 'table', 'columns', 'indexes', 'users']
        },
        {
            id: 'db-monitor',
            category: 'database',
            title: 'Monitor Database Active Connections',
            command: 'php artisan db:monitor --databases=mysql --max=100',
            desc: 'Checks active database connection counts and alerts if approaching connection limits.',
            suggestSudo: false,
            tags: ['db', 'monitor', 'connections', 'pool']
        },
        {
            id: 'schema-dump',
            category: 'database',
            title: 'Dump Database Schema File',
            command: 'php artisan schema:dump',
            desc: 'Dumps database schema to a squashed SQL file in database/schema.',
            suggestSudo: false,
            tags: ['schema', 'dump', 'sql', 'squash']
        },

        // ==========================================
        // 3. LARAVEL QUEUE & TASKS
        // ==========================================
        {
            id: 'queue-restart',
            category: 'queue',
            title: 'Restart Queue Workers',
            command: 'php artisan queue:restart',
            desc: 'Instructs all active background queue workers to gracefully terminate after finishing their current job.',
            suggestSudo: false,
            tags: ['queue', 'restart', 'workers', 'jobs', 'deploy']
        },
        {
            id: 'queue-work-once',
            category: 'queue',
            title: 'Process Queues Until Empty',
            command: 'php artisan queue:work --stop-when-empty',
            desc: 'Processes all pending jobs on the default queue and exits when done. Perfect for debugging.',
            suggestSudo: false,
            tags: ['queue', 'work', 'jobs', 'once', 'debug']
        },
        {
            id: 'queue-failed',
            category: 'queue',
            title: 'List Failed Queue Jobs',
            command: 'php artisan queue:failed',
            desc: 'Lists IDs, connection, queue name, and exception messages for failed jobs.',
            suggestSudo: false,
            tags: ['queue', 'failed', 'errors', 'exceptions']
        },
        {
            id: 'queue-retry-all',
            category: 'queue',
            title: 'Retry All Failed Queue Jobs',
            command: 'php artisan queue:retry all',
            desc: 'Pushes all failed queue jobs back into their respective queues for re-processing.',
            suggestSudo: false,
            tags: ['queue', 'retry', 'all', 'failed']
        },
        {
            id: 'queue-flush',
            category: 'queue',
            title: 'Flush (Delete) Failed Jobs',
            command: 'php artisan queue:flush',
            desc: 'Permanently deletes all records from the failed_jobs table.',
            suggestSudo: false,
            tags: ['queue', 'flush', 'delete', 'failed_jobs']
        },
        {
            id: 'schedule-list',
            category: 'queue',
            title: 'List Scheduled Cron Tasks',
            command: 'php artisan schedule:list',
            desc: 'Displays all scheduled tasks, their cron expressions, and when they will run next.',
            suggestSudo: false,
            tags: ['schedule', 'cron', 'tasks', 'schedule:list']
        },
        {
            id: 'schedule-run',
            category: 'queue',
            title: 'Run Due Scheduled Tasks Now',
            command: 'php artisan schedule:run',
            desc: 'Evaluates and executes any background tasks scheduled for the current minute.',
            suggestSudo: false,
            tags: ['schedule', 'cron', 'run', 'artisan']
        },

        // ==========================================
        // 4. STORAGE, PERMISSIONS & LINKS
        // ==========================================
        {
            id: 'storage-link',
            category: 'storage',
            title: 'Create Public Storage Symlink',
            command: 'php artisan storage:link',
            desc: 'Creates a symbolic link from public/storage to storage/app/public for publicly accessible uploads.',
            suggestSudo: false,
            tags: ['storage', 'symlink', 'link', 'public', 'uploads']
        },
        {
            id: 'storage-unlink',
            category: 'storage',
            title: 'Remove Public Storage Symlink',
            command: 'php artisan storage:unlink',
            desc: 'Deletes the public/storage symbolic link so it can be cleanly recreated.',
            suggestSudo: false,
            tags: ['storage', 'unlink', 'symlink']
        },
        {
            id: 'chmod-storage-cache',
            category: 'storage',
            title: 'Fix Storage & Cache Permissions',
            command: 'chmod -R 775 storage bootstrap/cache',
            desc: 'Ensures the application has read/write permissions for file sessions, logs, and compiled views.',
            suggestSudo: false,
            tags: ['chmod', 'permissions', '775', 'storage', 'cache']
        },
        {
            id: 'chown-web-user',
            category: 'storage',
            title: 'Set Web Server Ownership (www-data)',
            command: 'sudo chown -R www-data:www-data storage bootstrap/cache',
            desc: 'Assigns storage directory ownership to the Nginx/Apache web server user.',
            suggestSudo: true,
            tags: ['chown', 'www-data', 'ownership', 'sudo', 'permissions']
        },
        {
            id: 'model-prune',
            category: 'storage',
            title: 'Prune Stale Database Records (Model Prune)',
            command: 'php artisan model:prune',
            desc: 'Prunes Eloquent models that implement the Prunable or MassPrunable traits.',
            suggestSudo: false,
            tags: ['prune', 'models', 'cleanup', 'database']
        },

        // ==========================================
        // 5. COMPOSER & PHP ENVIRONMENT
        // ==========================================
        {
            id: 'composer-prod',
            category: 'composer',
            title: 'Composer Install (Production)',
            command: 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev',
            desc: 'Installs dependencies from composer.lock, excludes dev packages, and optimizes autoloader map.',
            suggestSudo: false,
            tags: ['composer', 'install', 'production', 'no-dev', 'autoload']
        },
        {
            id: 'composer-dump-autoload',
            category: 'composer',
            title: 'Regenerate Optimized Autoloader',
            command: 'composer dump-autoload -o',
            desc: 'Scans all project classes and rebuilds a high-speed static classmap.',
            suggestSudo: false,
            tags: ['composer', 'dump-autoload', 'classmap', 'optimize']
        },
        {
            id: 'composer-outdated',
            category: 'composer',
            title: 'Check Outdated Packages',
            command: 'composer outdated --direct',
            desc: 'Lists directly-required packages that have newer versions available on Packagist.',
            suggestSudo: false,
            tags: ['composer', 'outdated', 'updates', 'dependencies']
        },
        {
            id: 'composer-audit',
            category: 'composer',
            title: 'Security Vulnerability Audit',
            command: 'composer audit',
            desc: 'Scans all installed PHP packages against the security advisory database for reported CVEs.',
            suggestSudo: false,
            tags: ['composer', 'audit', 'security', 'cve', 'vulnerabilities']
        },
        {
            id: 'php-version-binary',
            category: 'composer',
            title: 'Check PHP Version & Binary',
            command: 'php -v && which php',
            desc: 'Outputs active PHP CLI version, compiler flags, and the path of the executing PHP binary.',
            suggestSudo: false,
            tags: ['php', 'version', 'binary', 'cli']
        },
        {
            id: 'php-modules',
            category: 'composer',
            title: 'List Loaded PHP Extensions',
            command: 'php -m',
            desc: 'Lists all compiled and dynamic PHP extensions currently active (curl, mbstring, pdo, redis, etc.).',
            suggestSudo: false,
            tags: ['php', 'modules', 'extensions', 'redis', 'pdo', 'curl']
        },
        {
            id: 'php-ini-path',
            category: 'composer',
            title: 'Display Active php.ini Paths',
            command: 'php --ini',
            desc: 'Shows the path to the loaded php.ini configuration file and additional scanned directories.',
            suggestSudo: false,
            tags: ['php', 'ini', 'configuration', 'settings']
        },

        // ==========================================
        // 6. FRONTEND ASSETS & NPM
        // ==========================================
        {
            id: 'npm-ci',
            category: 'assets',
            title: 'Clean NPM Install (npm ci)',
            command: 'npm ci',
            desc: 'Installs dependencies directly from package-lock.json with strictly reproducible builds.',
            suggestSudo: false,
            tags: ['npm', 'ci', 'install', 'node', 'package-lock']
        },
        {
            id: 'npm-build',
            category: 'assets',
            title: 'Compile Production Assets (Vite / Mix)',
            command: 'npm run build',
            desc: 'Runs the Vite or Laravel Mix production build script to compile JS, CSS, and hashed assets.',
            suggestSudo: false,
            tags: ['npm', 'build', 'vite', 'mix', 'css', 'javascript']
        },
        {
            id: 'npm-audit',
            category: 'assets',
            title: 'NPM Security Audit',
            command: 'npm audit',
            desc: 'Scans Node dependencies for known vulnerabilities.',
            suggestSudo: false,
            tags: ['npm', 'audit', 'security', 'vulnerabilities']
        },
        {
            id: 'yarn-build',
            category: 'assets',
            title: 'Yarn Production Build',
            command: 'yarn build',
            desc: 'Compiles frontend assets using Yarn package manager.',
            suggestSudo: false,
            tags: ['yarn', 'build', 'frontend']
        },
        {
            id: 'pnpm-build',
            category: 'assets',
            title: 'PNPM Production Build',
            command: 'pnpm build',
            desc: 'Compiles frontend assets using fast, disk-efficient PNPM.',
            suggestSudo: false,
            tags: ['pnpm', 'build', 'frontend']
        },

        // ==========================================
        // 7. WEB SERVERS, SERVICES & SUPERVISOR
        // ==========================================
        {
            id: 'nginx-test',
            category: 'services',
            title: 'Test Nginx Config Syntax',
            command: 'sudo nginx -t',
            desc: 'Validates Nginx configuration files and virtual hosts for syntax errors before reloading.',
            suggestSudo: true,
            tags: ['nginx', 'test', 'syntax', 'config', 'sudo']
        },
        {
            id: 'nginx-reload',
            category: 'services',
            title: 'Reload Nginx Web Server',
            command: 'sudo systemctl reload nginx',
            desc: 'Zero-downtime reload of Nginx configuration without dropping active HTTP connections.',
            suggestSudo: true,
            tags: ['nginx', 'reload', 'web', 'systemctl', 'sudo']
        },
        {
            id: 'nginx-status',
            category: 'services',
            title: 'Check Nginx Service Status',
            command: 'sudo systemctl status nginx --no-pager',
            desc: 'Displays active/running status, worker process counts, and recent error logs.',
            suggestSudo: true,
            tags: ['nginx', 'status', 'systemctl', 'service']
        },
        {
            id: 'php-fpm-reload',
            category: 'services',
            title: 'Reload PHP-FPM Service',
            command: 'sudo systemctl reload php8.3-fpm || sudo systemctl reload php-fpm',
            desc: 'Gracefully reloads PHP-FPM worker pools to clear OPcache and apply php.ini updates.',
            suggestSudo: true,
            tags: ['php-fpm', 'fpm', 'reload', 'opcache', 'sudo']
        },
        {
            id: 'php-fpm-restart',
            category: 'services',
            title: 'Restart PHP-FPM Service',
            command: 'sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm',
            desc: 'Fully stops and restarts PHP-FPM process manager.',
            suggestSudo: true,
            tags: ['php-fpm', 'restart', 'service', 'sudo']
        },
        {
            id: 'apache-test',
            category: 'services',
            title: 'Test Apache Config Syntax',
            command: 'sudo apachectl configtest',
            desc: 'Validates Apache virtual host configuration and returns Syntax OK or line errors.',
            suggestSudo: true,
            tags: ['apache', 'configtest', 'syntax', 'httpd', 'sudo']
        },
        {
            id: 'apache-reload',
            category: 'services',
            title: 'Reload Apache Web Server',
            command: 'sudo systemctl reload apache2 || sudo systemctl reload httpd',
            desc: 'Reloads Apache without restarting worker child processes.',
            suggestSudo: true,
            tags: ['apache', 'reload', 'apache2', 'httpd', 'sudo']
        },
        {
            id: 'supervisor-status',
            category: 'services',
            title: 'Supervisor Worker Status',
            command: 'sudo supervisorctl status',
            desc: 'Checks the state of background queue worker processes managed by Supervisor.',
            suggestSudo: true,
            tags: ['supervisor', 'workers', 'status', 'queue', 'sudo']
        },
        {
            id: 'supervisor-reload',
            category: 'services',
            title: 'Reread & Restart Supervisor Workers',
            command: 'sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart all',
            desc: 'Picks up new worker configuration files and restarts all managed worker pools.',
            suggestSudo: true,
            tags: ['supervisor', 'reread', 'update', 'restart', 'sudo']
        },
        {
            id: 'pm2-list',
            category: 'services',
            title: 'List PM2 Processes',
            command: 'pm2 list',
            desc: 'Displays all Node.js / SSR / queue worker processes managed by PM2 with memory and CPU.',
            suggestSudo: false,
            tags: ['pm2', 'list', 'node', 'ssr', 'processes']
        },
        {
            id: 'pm2-reload-all',
            category: 'services',
            title: 'Reload All PM2 Processes',
            command: 'pm2 reload all',
            desc: 'Performs a zero-downtime cluster reload of all PM2 managed processes.',
            suggestSudo: false,
            tags: ['pm2', 'reload', 'zero-downtime', 'node']
        },

        // ==========================================
        // 8. DOCKER & CONTAINERS
        // ==========================================
        {
            id: 'docker-ps',
            category: 'docker',
            title: 'Docker Compose Container Status',
            command: 'docker compose ps',
            desc: 'Lists status, healthy checks, and exposed port mappings for all project containers.',
            suggestSudo: false,
            tags: ['docker', 'compose', 'ps', 'containers', 'ports']
        },
        {
            id: 'docker-up-d',
            category: 'docker',
            title: 'Start Docker Containers (Detached)',
            command: 'docker compose up -d',
            desc: 'Builds, recreates, and starts all containers in the background.',
            suggestSudo: false,
            tags: ['docker', 'up', 'start', 'compose']
        },
        {
            id: 'docker-down',
            category: 'docker',
            title: 'Stop Docker Containers',
            command: 'docker compose down',
            desc: 'Stops running containers and removes internal networks.',
            suggestSudo: false,
            tags: ['docker', 'down', 'stop', 'compose']
        },
        {
            id: 'docker-logs-tail',
            category: 'docker',
            title: 'Tail Docker Container Logs',
            command: 'docker compose logs --tail=100',
            desc: 'Prints the last 100 log lines from all containers in the compose file.',
            suggestSudo: false,
            tags: ['docker', 'logs', 'tail', 'output']
        },
        {
            id: 'docker-exec-artisan',
            category: 'docker',
            title: 'Run Artisan in App Container',
            command: 'docker compose exec -T app php artisan optimize:clear',
            desc: 'Executes artisan optimize:clear inside the running app container.',
            suggestSudo: false,
            tags: ['docker', 'exec', 'artisan', 'app']
        },
        {
            id: 'docker-prune',
            category: 'docker',
            title: 'Prune Unused Docker Cache',
            command: 'docker system prune -f',
            desc: 'Removes stopped containers, unused networks, and dangling image layers to free disk space.',
            suggestSudo: false,
            tags: ['docker', 'prune', 'cleanup', 'disk', 'images']
        },

        // ==========================================
        // 9. GIT & REPOSITORY
        // ==========================================
        {
            id: 'git-status-sb',
            category: 'git',
            title: 'Git Short Status',
            command: 'git status -sb',
            desc: 'Displays the current branch, ahead/behind commits count, and uncommitted modified files.',
            suggestSudo: false,
            tags: ['git', 'status', 'branch', 'modified']
        },
        {
            id: 'git-pull-origin',
            category: 'git',
            title: 'Pull Latest Remote Code',
            command: 'git pull origin $DEPLOY_BRANCH',
            desc: 'Pulls commits from the remote tracked deployment branch into the local repository.',
            suggestSudo: false,
            tags: ['git', 'pull', 'origin', 'branch', 'update']
        },
        {
            id: 'git-log-oneline',
            category: 'git',
            title: 'Recent 5 Git Commits',
            command: 'git log -n 5 --oneline --graph',
            desc: 'Displays the last 5 commits with commit hashes, authors, and compact branch graph.',
            suggestSudo: false,
            tags: ['git', 'log', 'commits', 'history']
        },
        {
            id: 'git-fetch-prune',
            category: 'git',
            title: 'Fetch & Prune Stale Remote Branches',
            command: 'git fetch --all --prune',
            desc: 'Fetches references from all remotes and deletes references to branches deleted on the remote.',
            suggestSudo: false,
            tags: ['git', 'fetch', 'prune', 'remotes']
        },
        {
            id: 'git-head-hash',
            category: 'git',
            title: 'Print Current Commit Hash (HEAD)',
            command: 'git rev-parse HEAD',
            desc: 'Outputs full 40-character SHA-1 hash of the currently deployed commit.',
            suggestSudo: false,
            tags: ['git', 'head', 'sha', 'commit', 'hash']
        },
        {
            id: 'git-diff-stat',
            category: 'git',
            title: 'Git Diff Summary (Changed Files)',
            command: 'git diff --stat',
            desc: 'Displays summary of modified files and inserted/deleted line counts.',
            suggestSudo: false,
            tags: ['git', 'diff', 'stat', 'changes']
        },

        // ==========================================
        // 10. NETWORKING, PORTS & SSL
        // ==========================================
        {
            id: 'net-ports-listening',
            category: 'network',
            title: 'Active Listening Ports (ss / netstat)',
            command: 'sudo ss -tulpn || sudo netstat -tulpen',
            desc: 'Lists all open TCP/UDP listening ports (80, 443, 3306, 6379, 22) and their process names.',
            suggestSudo: true,
            tags: ['ports', 'ss', 'netstat', 'listening', 'tcp', 'network', 'sudo']
        },
        {
            id: 'net-curl-local',
            category: 'network',
            title: 'Test Local HTTP Response Headers',
            command: 'curl -I -sS http://127.0.0.1',
            desc: 'Fetches HTTP response headers from local web server to verify it is serving requests.',
            suggestSudo: false,
            tags: ['curl', 'http', 'localhost', 'headers', 'response']
        },
        {
            id: 'net-ping-gateway',
            category: 'network',
            title: 'Ping Public DNS (Outbound Check)',
            command: 'ping -c 4 1.1.1.1',
            desc: 'Sends 4 ICMP packets to Cloudflare 1.1.1.1 to confirm server has outbound internet connectivity.',
            suggestSudo: false,
            tags: ['ping', 'network', 'internet', 'outbound', 'dns']
        },
        {
            id: 'net-ssl-dates',
            category: 'network',
            title: 'Check SSL Certificate Expiry Dates',
            command: 'echo | openssl s_client -servername localhost -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -dates',
            desc: 'Connects via TLS to port 443 and inspects certificate notBefore and notAfter validity dates.',
            suggestSudo: false,
            tags: ['ssl', 'tls', 'certificate', 'expiry', 'https', 'openssl']
        },
        {
            id: 'net-ufw-status',
            category: 'network',
            title: 'Check UFW Firewall Rules',
            command: 'sudo ufw status verbose',
            desc: 'Displays active Linux UFW firewall rules, allowed ports (SSH, HTTP, HTTPS), and default policy.',
            suggestSudo: true,
            tags: ['ufw', 'firewall', 'security', 'ports', 'sudo']
        },

        // ==========================================
        // 11. SERVER HEALTH, DISK, MEMORY & LOGS
        // ==========================================
        {
            id: 'server-df-h',
            category: 'server',
            title: 'Disk Space Usage (df -h)',
            command: 'df -h / /var /home 2>/dev/null || df -h',
            desc: 'Displays total, used, and available disk space across root and data mount points.',
            suggestSudo: false,
            tags: ['disk', 'storage', 'df', 'space', 'free']
        },
        {
            id: 'server-free-m',
            category: 'server',
            title: 'RAM & Swap Usage (free -h)',
            command: 'free -h',
            desc: 'Shows used and available physical RAM, buffer/cache, and swap memory.',
            suggestSudo: false,
            tags: ['memory', 'ram', 'swap', 'free', 'performance']
        },
        {
            id: 'server-uptime',
            category: 'server',
            title: 'Server Uptime & Load Averages',
            command: 'uptime',
            desc: 'Prints current system time, days online, logged in users, and 1, 5, and 15 minute CPU load averages.',
            suggestSudo: false,
            tags: ['uptime', 'load', 'cpu', 'server']
        },
        {
            id: 'server-top-memory',
            category: 'server',
            title: 'Top 10 Processes by Memory Usage',
            command: 'ps aux --sort=-%mem | head -n 11',
            desc: 'Lists the top 10 processes consuming the highest percentage of system RAM.',
            suggestSudo: false,
            tags: ['ps', 'processes', 'memory', 'ram', 'top']
        },
        {
            id: 'server-tail-laravel-log',
            category: 'server',
            title: 'Tail Recent Laravel Logs',
            command: 'tail -n 50 storage/logs/laravel.log',
            desc: 'Outputs the most recent 50 lines from the primary Laravel log file.',
            suggestSudo: false,
            tags: ['log', 'laravel', 'tail', 'storage', 'errors']
        },
        {
            id: 'server-tail-nginx-err',
            category: 'server',
            title: 'Tail Nginx Error Log',
            command: 'sudo tail -n 50 /var/log/nginx/error.log',
            desc: 'Displays the last 50 error entries from Nginx server error logs.',
            suggestSudo: true,
            tags: ['nginx', 'error', 'log', 'tail', 'sudo']
        }
    ];
})();

