<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Authenticator;
use App\Services\InstallationSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthController extends Controller
{
    public function __construct(private Authenticator $auth, private InstallationSetup $setup)
    {
    }

    /* ------------------------------------------------------------------ */
    /* First run: create the super admin (localhost only)                  */
    /* ------------------------------------------------------------------ */

    public function showSetup(Request $request): Response|RedirectResponse|HttpResponse
    {
        if ($blocked = $this->guardSetup($request)) {
            return $blocked;
        }

        return Inertia::render('auth/setup', ['keeps_authenticator' => $this->setup->hasLegacyAuthenticator()]);
    }

    public function setup(Request $request): RedirectResponse|HttpResponse
    {
        if ($blocked = $this->guardSetup($request)) {
            return $blocked;
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $user = $this->setup->createSuperAdmin($data);
        $this->auth->completeLogin($request, $user, false);

        // Upgraded installs keep their existing authenticator; fresh ones set it up next
        return redirect()->route($user->hasTwoFactor() ? 'dashboard' : 'two-factor.setup');
    }

    /* ------------------------------------------------------------------ */
    /* Sign-in: password, then authenticator code                          */
    /* ------------------------------------------------------------------ */

    public function showLogin(): Response|RedirectResponse
    {
        if ($this->setup->needed()) {
            return redirect()->route('setup');
        }
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $error = $this->auth->attemptPassword($request, $data['email'], $data['password'], $request->boolean('remember'));
        if ($error !== null) {
            return back()->withErrors(['email' => $error])->onlyInput('email');
        }

        return $this->auth->pendingUser($request) !== null
            ? redirect()->route('two-factor.challenge')
            : redirect()->intended(route('dashboard'));
    }

    public function showChallenge(Request $request): Response|RedirectResponse
    {
        $user = $this->auth->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/two-factor-challenge', ['email' => $user->email]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $error = $this->auth->attemptCode($request, (string) $request->input('code', ''));

        return $error === null
            ? redirect()->intended(route('dashboard'))
            : back()->withErrors(['code' => $error]);
    }

    /* ------------------------------------------------------------------ */
    /* Signed in, onboarding: link an authenticator, replace a password    */
    /* ------------------------------------------------------------------ */

    public function showTwoFactorSetup(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Replacing a working authenticator needs the password first (Profile page)
        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor.may_replace')) {
            return redirect()->route('profile');
        }

        return Inertia::render('auth/two-factor-setup', [
            ...$this->auth->setupScreen($request, $user),
            'replacing' => $user->hasTwoFactor(),
        ]);
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor.may_replace')) {
            return redirect()->route('profile');
        }
        $error = $this->auth->confirmSetup($request, $user, (string) $request->input('code', ''));
        if ($error !== null) {
            return back()->withErrors(['code' => $error]);
        }

        $request->session()->forget('two_factor.may_replace');

        return redirect()->route($user->must_change_password ? 'password.change' : 'dashboard');
    }

    public function showPasswordChange(Request $request): Response
    {
        return Inertia::render('auth/change-password', ['forced' => (bool) $request->user()?->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10), 'different:current_password'],
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();
        Auth::logoutOtherDevices($data['password']);

        return redirect()->route('dashboard')->with('flash', 'Password changed.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout($request);

        return redirect()->route('login');
    }

    /**
     * The setup screen exists only until the first user is created, and only for
     * requests made on the machine running SSHhh.
     */
    private function guardSetup(Request $request): RedirectResponse|HttpResponse|null
    {
        if (! $this->setup->needed()) {
            return redirect()->route('login');
        }
        if (! $this->auth->isLocalRequest($request)) {
            return response("SSHhh has not been set up yet. Open it on the computer running it (http://localhost/...) to create the super admin.\n", 403)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return null;
    }
}
