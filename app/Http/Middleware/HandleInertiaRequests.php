<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Roles;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name', 'SSHhh'),
                'tagline' => config('sshhh.tagline'),
                // Base URL of the app (it may live in a sub-folder such as /deployer). Built from the
                // base path, not url('/'), which would include /index.php when the address had it.
                'base_url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
            ],
            'auth' => fn () => [
                'user' => $request->user()?->toPublicArray(),
                'can_manage_users' => (bool) $request->user()?->can(Roles::MANAGE_USERS),
            ],
            // Set when a super admin has stepped into another user's workspace
            'workspace' => fn () => $this->workspace(),
            'flash' => fn () => $request->session()->get('flash'),
            'csrf_token' => fn () => csrf_token(),
        ];
    }

    /**
     * @return array{owner_id: int, owner_name: string, owner_email: string, impersonating: bool}|null
     */
    private function workspace(): ?array
    {
        $ws = app(Workspace::class);
        if (! $ws->isSet()) {
            return null;
        }
        $owner = User::query()->find($ws->owner());

        return $owner === null ? null : [
            'owner_id' => $owner->id,
            'owner_name' => $owner->name,
            'owner_email' => $owner->email,
            'impersonating' => $ws->isImpersonating(),
        ];
    }
}
