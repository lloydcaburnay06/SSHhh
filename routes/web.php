<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LegacyController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (no session)
|--------------------------------------------------------------------------
*/

// First run (localhost only, until the super admin exists)
Route::get('/setup', [AuthController::class, 'showSetup'])->name('setup');
Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:20,1');

// Sign-in: email + password, then the user's Google Authenticator code
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/two-factor/challenge', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
Route::post('/two-factor/challenge', [AuthController::class, 'challenge'])->middleware('throttle:20,1');

// Git push webhooks: authenticated by the per-project token
Route::match(['get', 'post'], '/webhook/{token?}', WebhookController::class)->name('webhook');

// Error alert decisions from a push notification: authenticated by the single-use alert token
Route::get('/alert', [AlertController::class, 'show'])->name('alert');
Route::post('/alert/decide', [AlertController::class, 'decide'])->name('alert.decide');

// Pre-Laravel URLs that live outside the app: webhook URLs saved in GitHub/GitLab,
// links in push notifications already delivered, and old bookmarks.
Route::any('/api.php', LegacyController::class);
Route::get('/alert.php', fn () => redirect()->route('alert', ['t' => request()->query('t')]));
Route::get('/project.php', fn () => redirect()->route('projects.show', ['project' => (int) request()->query('id')]));

/*
|--------------------------------------------------------------------------
| Unlocked with Google Authenticator
|--------------------------------------------------------------------------
*/

Route::middleware('unlocked')->group(function () {
    // Reachable before onboarding is finished (EnsureUnlocked::ONBOARDING_ROUTES)
    Route::get('/two-factor/setup', [AuthController::class, 'showTwoFactorSetup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [AuthController::class, 'confirmTwoFactor'])->name('two-factor.confirm');
    Route::get('/password/change', [AuthController::class, 'showPasswordChange'])->name('password.change');
    Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Own account
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/two-factor', [ProfileController::class, 'replaceTwoFactor'])->name('profile.two-factor');

    // Super admin: user accounts and other users' workspaces
    Route::middleware('permission:'.Roles::MANAGE_USERS)->prefix('admin')->group(function () {
        Route::get('users', [UserController::class, 'page'])->name('admin.users');
        Route::post('workspace/{user}', [UserController::class, 'enterWorkspace'])->whereNumber('user')->name('admin.workspace.enter');
        Route::delete('workspace', [UserController::class, 'leaveWorkspace'])->name('admin.workspace.leave');
    });
    Route::middleware('permission:'.Roles::MANAGE_USERS)->prefix('api/admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user');
        Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->whereNumber('user');
    });

    Route::get('/', [PageController::class, 'dashboard'])->name('dashboard');
    Route::get('/projects/{project}', [PageController::class, 'project'])->whereNumber('project')->name('projects.show');

    Route::prefix('api')->name('api.')->group(function () {
        // Projects
        Route::get('projects', [Api\ProjectController::class, 'index']);
        Route::post('projects', [Api\ProjectController::class, 'store']);
        Route::post('projects/reorder', [Api\ProjectController::class, 'reorder']);

        Route::prefix('projects/{project}')->whereNumber('project')->group(function () {
            Route::get('/', [Api\ProjectController::class, 'show']);
            Route::put('/', [Api\ProjectController::class, 'update']);
            Route::delete('/', [Api\ProjectController::class, 'destroy']);
            Route::put('commands', [Api\ProjectController::class, 'saveCommands']);
            Route::post('app-state', [Api\ProjectController::class, 'setAppState']);
            Route::get('vars', [Api\ProjectController::class, 'vars']);
            Route::post('webhook-token', [Api\ProjectController::class, 'regenerateWebhookToken']);
            Route::get('live-run', [Api\ProjectController::class, 'liveRun']);

            // Running commands
            Route::post('execute', [Api\ExecutionController::class, 'execute']);
            Route::get('remote-dirs', [Api\ExecutionController::class, 'remoteDirs']);

            // Event log
            Route::get('events/download', [Api\EventController::class, 'downloadForProject']);
            Route::delete('events', [Api\EventController::class, 'clearForProject']);

            // Live server (SSH)
            Route::get('pulse', [Api\RemoteController::class, 'pulse']);
            Route::get('env', [Api\RemoteController::class, 'env']);
            Route::put('env', [Api\RemoteController::class, 'saveEnv']);
            Route::post('logs', [Api\RemoteController::class, 'logs']);
            Route::get('logs/download', [Api\RemoteController::class, 'downloadLog']);
            Route::delete('logs', [Api\RemoteController::class, 'clearLog']);
            Route::post('logs/claude', [Api\RemoteController::class, 'sendLogToClaude']);
            Route::get('releases', [Api\RemoteController::class, 'releases']);
            Route::post('rollback', [Api\RemoteController::class, 'rollback']);

            // Claude Code link
            Route::get('claude-link', [Api\EscalationController::class, 'claudeLink']);
            Route::put('claude-link', [Api\EscalationController::class, 'saveClaudeLink']);
        });

        Route::post('ssh/test', [Api\ExecutionController::class, 'testSsh']);

        // Events
        Route::get('events', [Api\EventController::class, 'index']);
        Route::get('events/{event}', [Api\EventController::class, 'show'])->whereNumber('event');
        Route::get('events/{event}/download', [Api\EventController::class, 'download'])->whereNumber('event');

        // Health checks
        Route::post('health/check', [Api\HealthController::class, 'check']);
        Route::post('health/check-all', [Api\HealthController::class, 'checkAll']);

        // Shared SSH profiles
        Route::get('ssh-profiles', [Api\SshProfileController::class, 'index']);
        Route::post('ssh-profiles', [Api\SshProfileController::class, 'store']);
        Route::put('ssh-profiles/{profile}', [Api\SshProfileController::class, 'update'])->whereNumber('profile');
        Route::delete('ssh-profiles/{profile}', [Api\SshProfileController::class, 'destroy'])->whereNumber('profile');

        // Deploy notifications & vault backup
        Route::get('settings/notifications', [Api\SettingsController::class, 'notifications']);
        Route::put('settings/notifications', [Api\SettingsController::class, 'saveNotifications']);
        Route::post('settings/notifications/test', [Api\SettingsController::class, 'testNotification']);
        Route::post('vault/export', [Api\SettingsController::class, 'exportVault']);
        Route::post('vault/import', [Api\SettingsController::class, 'importVault']);

        // Error escalation, push devices & log checker
        Route::get('escalation', [Api\EscalationController::class, 'settings']);
        Route::put('escalation', [Api\EscalationController::class, 'saveSettings']);
        Route::post('escalation/test-push', [Api\EscalationController::class, 'testPush']);
        Route::post('escalation/test-summary', [Api\EscalationController::class, 'testSummary']);
        Route::post('push/subscribe', [Api\EscalationController::class, 'subscribe']);
        Route::delete('push/devices/{device}', [Api\EscalationController::class, 'removeDevice'])->whereNumber('device');
        Route::get('log-checker', [Api\EscalationController::class, 'logChecker']);
        Route::post('log-checker/run', [Api\EscalationController::class, 'runLogCheck']);
    });
});
