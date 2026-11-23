<?php

namespace App\Http\Controllers;

use App\Services\Authenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthController extends Controller
{
    public function __construct(private Authenticator $auth)
    {
    }

    public function showSetup(Request $request): Response|RedirectResponse|HttpResponse
    {
        if ($blocked = $this->guardSetup($request)) {
            return $blocked;
        }

        return Inertia::render('auth/setup', $this->auth->setupScreen($request));
    }

    public function setup(Request $request): RedirectResponse|HttpResponse
    {
        if ($blocked = $this->guardSetup($request)) {
            return $blocked;
        }

        $error = $this->auth->completeSetup(
            $request,
            (string) $request->input('code', ''),
            $request->boolean('remember_device'),
        );

        return $error === null
            ? redirect()->route('dashboard')
            : back()->withErrors(['code' => $error]);
    }

    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (! $this->auth->isConfigured()) {
            return redirect()->route('setup');
        }
        if ($this->auth->isAuthenticated($request) || $this->auth->restoreFromDeviceCookie($request)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/login');
    }

    public function login(Request $request): RedirectResponse
    {
        $error = $this->auth->attemptLogin(
            $request,
            (string) $request->input('code', ''),
            $request->boolean('remember_device'),
        );

        return $error === null
            ? redirect()->intended(route('dashboard'))
            : back()->withErrors(['code' => $error]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout($request);

        return redirect()->route('login');
    }

    /**
     * The one-time setup screen exists only until the authenticator is configured,
     * and only for requests made on the machine running SSHhh.
     */
    private function guardSetup(Request $request): RedirectResponse|HttpResponse|null
    {
        if ($this->auth->isConfigured()) {
            return redirect()->route('login');
        }
        if (! $this->auth->isLocalRequest($request)) {
            return response("SSHhh has not been set up yet. Open it on the computer running it (http://localhost/...) to configure Google Authenticator.\n", 403)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return null;
    }
}
