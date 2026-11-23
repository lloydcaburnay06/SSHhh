<?php

use App\Models\User;
use App\Services\ProjectRepository;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->withTwoFactor()->create(['name' => 'Root']);
});

it('keeps user management away from regular users', function () {
    $user = signIn();

    $this->get('/admin/users')->assertForbidden();
    $this->getJson('/api/admin/users')->assertForbidden();
    $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => Roles::SUPER_ADMIN])->assertForbidden();
    $this->post("/admin/workspace/{$this->admin->id}")->assertForbidden();
    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});

it('creates a user with a one-time temporary password', function () {
    signIn($this->admin);

    $this->get('/admin/users')->assertInertia(fn (Assert $page) => $page->component('admin/users')->has('users', 1));

    $res = $this->postJson('/api/admin/users', ['name' => 'Dana', 'email' => 'Dana@Example.com', 'role' => Roles::USER])
        ->assertCreated()
        ->assertJsonPath('user.email', 'dana@example.com')
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonCount(2, 'users');

    $temporary = $res->json('temporary_password');
    expect($temporary)->toMatch('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}$/');

    $dana = User::query()->where('email', 'dana@example.com')->sole();
    expect($dana->hasRole(Roles::USER))->toBeTrue()
        ->and(password_verify($temporary, $dana->password))->toBeTrue()
        ->and($dana->hasTwoFactor())->toBeFalse();

    // Dana's first sign-in: password → link authenticator (then change password)
    $this->post('/logout');
    $this->post('/login', ['email' => 'dana@example.com', 'password' => $temporary])->assertRedirect(route('dashboard'));
    $this->get('/')->assertRedirect(route('two-factor.setup'));
});

it('edits roles and status but never removes the last super admin', function () {
    signIn($this->admin);
    $user = User::factory()->regular()->create();

    $this->putJson("/api/admin/users/{$user->id}", ['name' => 'Promoted', 'email' => $user->email, 'role' => Roles::SUPER_ADMIN, 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('user.name', 'Promoted');
    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    // With two super admins, one can be demoted
    $this->putJson("/api/admin/users/{$user->id}", ['name' => 'Promoted', 'email' => $user->email, 'role' => Roles::USER, 'is_active' => true])
        ->assertOk();
    expect($user->fresh()->isSuperAdmin())->toBeFalse();

    // The last one can't be demoted or disabled, not even by themselves
    $this->putJson("/api/admin/users/{$this->admin->id}", ['name' => 'Root', 'email' => $this->admin->email, 'role' => Roles::USER, 'is_active' => true])
        ->assertStatus(422)
        ->assertJson(['error' => 'SSHhh needs at least one active super admin. Make someone else a super admin first.']);
    expect($this->admin->fresh()->isSuperAdmin())->toBeTrue();

    $this->putJson("/api/admin/users/{$user->id}", ['name' => 'Promoted', 'email' => $user->email, 'role' => Roles::USER, 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('user.is_active', false);
});

it('resets passwords and authenticators', function () {
    signIn($this->admin);
    $user = User::factory()->regular()->withTwoFactor()->create();

    $temporary = $this->postJson("/api/admin/users/{$user->id}/reset-password")->assertOk()->json('temporary_password');
    expect(password_verify($temporary, $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->must_change_password)->toBeTrue();

    $this->postJson("/api/admin/users/{$user->id}/reset-two-factor")->assertOk();
    expect($user->fresh()->hasTwoFactor())->toBeFalse();
});

it('deletes a user together with their whole workspace', function () {
    signIn($this->admin);
    $user = User::factory()->regular()->create();
    $project = asWorkspace($user, fn () => app(ProjectRepository::class)->create(projectPayload()));

    $this->deleteJson("/api/admin/users/{$this->admin->id}")->assertStatus(422);
    $this->deleteJson("/api/admin/users/{$user->id}")->assertOk()->assertJsonCount(1, 'users');

    expect(User::query()->find($user->id))->toBeNull()
        ->and(DB::table('projects')->where('id', $project['id'])->exists())->toBeFalse()
        ->and(DB::table('event_logs')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('lets the super admin step into a workspace and act there, logged under their name', function () {
    $user = User::factory()->regular()->withTwoFactor()->create(['name' => 'Erin']);
    $project = asWorkspace($user, fn () => app(ProjectRepository::class)->create(projectPayload(['name' => 'Erin App'])));
    signIn($this->admin);

    $this->getJson('/api/projects')->assertJsonCount(0, 'projects');

    $this->post("/admin/workspace/{$user->id}")->assertRedirect(route('dashboard'));
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->has('projects', 1)
        ->where('workspace.owner_name', 'Erin')
        ->where('workspace.impersonating', true));

    $this->postJson("/api/projects/{$project['id']}/app-state", ['app_state' => 'down'])->assertOk();
    $event = DB::table('event_logs')->where('event_type', 'app_state_changed')->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->actor_user_id)->toBe($this->admin->id)
        ->and($event->actor_name)->toBe('Root');

    $this->delete('/admin/workspace')->assertRedirect(route('admin.users'));
    $this->getJson('/api/projects')->assertJsonCount(0, 'projects');
});

it('ignores a stale workspace selection for users without the permission', function () {
    $user = signIn();
    $other = User::factory()->regular()->create();
    asWorkspace($other, fn () => app(ProjectRepository::class)->create(projectPayload()));

    $this->withSession(['workspace_user_id' => $other->id])
        ->getJson('/api/projects')
        ->assertJsonCount(0, 'projects');
    expect($user->can(Roles::ACCESS_ALL_WORKSPACES))->toBeFalse();
});

it('lets users edit their own profile', function () {
    $user = signIn();

    $this->put('/profile', ['name' => 'New Name', 'email' => 'new@example.com'])->assertRedirect();
    expect($user->fresh()->only(['name', 'email']))->toBe(['name' => 'New Name', 'email' => 'new@example.com']);

    $this->put('/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'another-long-password',
        'password_confirmation' => 'another-long-password',
    ])->assertRedirect(route('dashboard'));
});
