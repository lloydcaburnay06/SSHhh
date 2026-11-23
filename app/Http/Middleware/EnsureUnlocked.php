<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Authenticator;
use App\Services\InstallationSetup;
use App\Support\Roles;
use App\Support\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only for a signed-in, active user who has finished setting up
 * Google Authenticator and changed any temporary password, then selects the workspace:
 * the user's own, or (super admin only) the one they stepped into.
 */
class EnsureUnlocked
{
    /** Session key holding the workspace a super admin has stepped into. */
    public const WORKSPACE_SESSION = 'workspace_user_id';

    /** Routes a signed-in user may reach before finishing 2FA setup / password change. */
    private const ONBOARDING_ROUTES = ['two-factor.setup', 'two-factor.confirm', 'password.change', 'password.update', 'logout'];

    public function __construct(
        private Authenticator $auth,
        private InstallationSetup $setup,
        private Workspace $workspace,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $wantsJson = $request->expectsJson() || $request->is('api/*');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            if ($this->setup->needed()) {
                if ($wantsJson) {
                    return response()->json(['ok' => false, 'error' => 'SSHhh setup is required. Open SSHhh in a browser on the computer running it.'], 428);
                }
                if (! $this->auth->isLocalRequest($request)) {
                    return response("SSHhh has not been set up yet. Open it on the computer running it (http://localhost/...) to create the super admin.\n", 403)
                        ->header('Content-Type', 'text/plain; charset=utf-8');
                }

                return redirect()->route('setup');
            }

            return $wantsJson
                ? response()->json(['ok' => false, 'error' => 'Authentication required. Please sign in to SSHhh.'], 401)
                : redirect()->guest(route('login'));
        }

        if (! $user->is_active) {
            $this->auth->logout($request);

            return $wantsJson
                ? response()->json(['ok' => false, 'error' => 'This account is disabled.'], 403)
                : redirect()->route('login')->withErrors(['email' => 'This account is disabled. Ask the super admin to enable it.']);
        }

        $onboarding = in_array($request->route()?->getName(), self::ONBOARDING_ROUTES, true);
        if (! $onboarding) {
            if (! $user->hasTwoFactor()) {
                return $wantsJson
                    ? response()->json(['ok' => false, 'error' => 'Set up Google Authenticator first.'], 403)
                    : redirect()->route('two-factor.setup');
            }
            if ($user->must_change_password) {
                return $wantsJson
                    ? response()->json(['ok' => false, 'error' => 'Change your temporary password first.'], 403)
                    : redirect()->route('password.change');
            }
        }

        $this->workspace->set($this->workspaceOwner($request, $user), $user->id);

        return $next($request);
    }

    private function workspaceOwner(Request $request, User $user): int
    {
        $selected = (int) $request->session()->get(self::WORKSPACE_SESSION, 0);
        if ($selected > 0 && $selected !== $user->id) {
            if ($user->can(Roles::ACCESS_ALL_WORKSPACES) && User::query()->whereKey($selected)->exists()) {
                return $selected;
            }
            $request->session()->forget(self::WORKSPACE_SESSION);
        }

        return $user->id;
    }
}
