<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUnlocked;
use App\Models\User;
use App\Services\Authenticator;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Super admin: user accounts (spatie roles) and stepping into a user's workspace.
 * Every route here requires the users.manage permission (see routes/web.php).
 */
class UserController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('admin/users', [
            'users' => $this->listing(),
            'roles' => [Roles::USER, Roles::SUPER_ADMIN],
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['ok' => true, 'users' => $this->listing()]);
    }

    /**
     * New account with a temporary password (generated unless given); the user must
     * change it and link Google Authenticator on first sign-in.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in([Roles::USER, Roles::SUPER_ADMIN])],
            'password' => ['nullable', 'string', Password::min(10)],
        ]);
        $temporary = ($data['password'] ?? null) ?: self::temporaryPassword();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $temporary,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->assignRole($data['role']);

        return response()->json([
            'ok' => true,
            'user' => $user->toPublicArray(),
            // Shown once to the admin, who passes it on; never stored in plain text
            'temporary_password' => $temporary,
            'users' => $this->listing(),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in([Roles::USER, Roles::SUPER_ADMIN])],
            'is_active' => ['required', 'boolean'],
        ]);

        $losesAdmin = $user->isSuperAdmin() && ($data['role'] !== Roles::SUPER_ADMIN || ! $data['is_active']);
        if ($losesAdmin) {
            $this->guardLastSuperAdmin($user);
        }
        if ($user->is($request->user()) && ! $data['is_active']) {
            throw new InvalidArgumentException('You cannot disable your own account.');
        }

        $user->fill([
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'is_active' => $data['is_active'],
        ]);
        if (! $data['is_active']) {
            $user->setRememberToken(Str::random(60)); // sign out remembered browsers
        }
        $user->save();
        $user->syncRoles([$data['role']]);

        return response()->json(['ok' => true, 'user' => $user->fresh()?->toPublicArray(), 'users' => $this->listing()]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $temporary = self::temporaryPassword();
        $user->forceFill(['password' => $temporary, 'must_change_password' => true])->setRememberToken(Str::random(60));
        $user->save();

        return response()->json(['ok' => true, 'temporary_password' => $temporary, 'users' => $this->listing()]);
    }

    public function resetTwoFactor(User $user, Authenticator $auth): JsonResponse
    {
        $auth->resetTwoFactor($user);

        return response()->json(['ok' => true, 'users' => $this->listing()]);
    }

    /**
     * Deletes the account and everything in its workspace (projects, SSH profiles,
     * logs, settings, push devices) — the foreign keys cascade.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw new InvalidArgumentException('You cannot delete your own account.');
        }
        if ($user->isSuperAdmin()) {
            $this->guardLastSuperAdmin($user);
        }

        DB::transaction(function () use ($user): void {
            DB::table('app_settings')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(['ok' => true, 'users' => $this->listing()]);
    }

    /* ------------------------------------------------------------------ */
    /* Workspaces                                                          */
    /* ------------------------------------------------------------------ */

    public function enterWorkspace(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            $request->session()->forget(EnsureUnlocked::WORKSPACE_SESSION);
        } else {
            $request->session()->put(EnsureUnlocked::WORKSPACE_SESSION, $user->id);
        }

        return redirect()->route('dashboard');
    }

    public function leaveWorkspace(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureUnlocked::WORKSPACE_SESSION);

        return redirect()->route('admin.users');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(): array
    {
        $counts = DB::table('projects')->selectRaw('`user_id`, COUNT(*) AS `n`')->groupBy('user_id')->pluck('n', 'user_id');

        return User::query()->with('roles')->orderBy('name')->get()
            ->map(fn (User $u) => $u->toPublicArray() + ['project_count' => (int) ($counts[$u->id] ?? 0)])
            ->values()->all();
    }

    private function guardLastSuperAdmin(User $user): void
    {
        $others = User::role(Roles::SUPER_ADMIN)->whereKeyNot($user->id)->where('is_active', true)->count();
        if ($others === 0) {
            throw new InvalidArgumentException('SSHhh needs at least one active super admin. Make someone else a super admin first.');
        }
    }

    private static function temporaryPassword(): string
    {
        // 4 groups of 4 from an unambiguous alphabet, e.g. "k7Qm-xT3p-…"
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }
}
