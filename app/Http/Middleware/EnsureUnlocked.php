<?php

namespace App\Http\Middleware;

use App\Services\Authenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only when SSHhh is unlocked with Google Authenticator.
 * Before the authenticator is configured, the setup screen is offered on localhost only.
 */
class EnsureUnlocked
{
    public function __construct(private Authenticator $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->isAuthenticated($request) || $this->auth->restoreFromDeviceCookie($request)) {
            return $next($request);
        }

        $wantsJson = $request->expectsJson() || $request->is('api/*');

        if (! $this->auth->isConfigured()) {
            if ($wantsJson) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Google Authenticator setup is required before accessing the API. Please open SSHhh in your browser to complete initial setup.',
                ], 428);
            }
            if (! $this->auth->isLocalRequest($request)) {
                return response("SSHhh has not been set up yet. Open it on the computer running it (http://localhost/...) to configure Google Authenticator.\n", 403)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }

            return redirect()->route('setup');
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => false,
                'error' => 'Authentication required. Please unlock SSHhh with your Google Authenticator code.',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}
