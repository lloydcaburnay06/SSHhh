<?php

use App\Models\User;
use App\Services\Crypto;
use App\Services\Totp;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/* ------------------------------------------------------------------ */
/* First run                                                           */
/* ------------------------------------------------------------------ */

it('sends a fresh install to the local-only super admin setup', function () {
    $this->get('/')->assertRedirect(route('setup'));
    $this->get('/login')->assertRedirect(route('setup'));
    $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/setup'));

    $this->get('/setup', ['X-Forwarded-For' => '203.0.113.9'])->assertForbidden();
    $this->getJson('/api/projects')->assertStatus(428);
});

it('creates the super admin, who takes over pre-users data and the old authenticator', function () {
    // State left by the single-user version
    $secret = Totp::generateSecret();
    DB::table('auth_state')->where('id', 1)->update([
        'totp_secret_encrypted' => app(Crypto::class)->encrypt($secret, 'totp-secret'),
        'is_configured' => 1,
    ]);
    DB::table('projects')->insert(['name' => 'Legacy', 'ssh_host' => 'h.example.com', 'ssh_username' => 'u']);
    DB::table('app_settings')->insert([
        ['user_id' => 0, 'setting_key' => 'notify_webhook_url', 'setting_value_encrypted' => 'x', 'is_encrypted' => 0],
        ['user_id' => 0, 'setting_key' => 'push_vapid_public', 'setting_value_encrypted' => 'k', 'is_encrypted' => 0],
    ]);

    $this->get('/setup')->assertInertia(fn (Assert $page) => $page->where('keeps_authenticator', true));
    $this->post('/setup', [
        'name' => 'Owner',
        'email' => 'Owner@Example.com',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ])->assertRedirect(route('dashboard'));

    $admin = User::query()->sole();
    expect($admin->email)->toBe('owner@example.com')
        ->and($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->hasTwoFactor())->toBeTrue()
        ->and(totpCodeFor($admin))->toBe(Totp::calculateCode($secret))
        ->and(DB::table('projects')->value('user_id'))->toBe($admin->id)
        ->and(DB::table('app_settings')->where('setting_key', 'notify_webhook_url')->value('user_id'))->toBe($admin->id)
        ->and(DB::table('app_settings')->where('setting_key', 'push_vapid_public')->value('user_id'))->toBe(0);

    $this->assertAuthenticatedAs($admin);
    $this->get('/setup')->assertRedirect(route('login'));
});

it('sends a fresh super admin to link an authenticator', function () {
    $this->post('/setup', [
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
    ])->assertRedirect(route('two-factor.setup'));

    $this->get('/')->assertRedirect(route('two-factor.setup'));
    $this->get('/two-factor/setup')->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-setup')->has('qr'));

    $secret = session('two_factor.pending_secret');
    $this->post('/two-factor/setup', ['code' => Totp::calculateCode($secret)])->assertRedirect(route('dashboard'));
    expect(User::query()->sole()->hasTwoFactor())->toBeTrue();
    $this->get('/')->assertOk();
});

/* ------------------------------------------------------------------ */
/* Sign-in                                                             */
/* ------------------------------------------------------------------ */

it('signs in with password then authenticator code, back to the intended page', function () {
    $user = User::factory()->regular()->withTwoFactor()->create(['email' => 'dev@example.com']);

    $this->get('/projects/1')->assertRedirect(route('login'));
    $this->post('/login', ['email' => 'dev@example.com', 'password' => UserFactory::PASSWORD])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    $this->get('/two-factor/challenge')->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
    $this->post('/two-factor/challenge', ['code' => totpCodeFor($user)])->assertRedirect('/projects/1');
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password and a wrong code', function () {
    $user = User::factory()->regular()->withTwoFactor()->create(['email' => 'dev@example.com']);

    $this->post('/login', ['email' => 'dev@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => 'dev@example.com', 'password' => UserFactory::PASSWORD]);
    $this->post('/two-factor/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('locks an IP out after five failures', function () {
    User::factory()->regular()->withTwoFactor()->create(['email' => 'dev@example.com']);

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => 'dev@example.com', 'password' => 'wrong']);
    }
    $this->post('/login', ['email' => 'dev@example.com', 'password' => UserFactory::PASSWORD])
        ->assertSessionHasErrors(['email' => 'Too many failed attempts. Try again in 15 minute(s).']);
    $this->assertGuest();
});

it('refuses disabled accounts', function () {
    User::factory()->regular()->withTwoFactor()->create(['email' => 'gone@example.com', 'is_active' => false]);

    $this->post('/login', ['email' => 'gone@example.com', 'password' => UserFactory::PASSWORD])
        ->assertSessionHasErrors(['email' => 'This account is disabled. Ask the super admin to enable it.']);

    // An account disabled while signed in is signed out on its next request
    $signedIn = signIn();
    $signedIn->forceFill(['is_active' => false])->save();
    $this->actingAs($signedIn->fresh());
    $this->get('/')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('remembers the device with a 30-day remember-me cookie', function () {
    $user = User::factory()->regular()->withTwoFactor()->create(['email' => 'dev@example.com']);

    $this->post('/login', ['email' => 'dev@example.com', 'password' => UserFactory::PASSWORD, 'remember' => true]);
    $response = $this->post('/two-factor/challenge', ['code' => totpCodeFor($user)]);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThan(now()->addDays(31)->getTimestamp());
});

it('makes admin-created users change their temporary password', function () {
    $user = User::factory()->regular()->withTwoFactor()->create(['must_change_password' => true]);
    signIn($user);

    $this->get('/')->assertRedirect(route('password.change'));
    $this->getJson('/api/projects')->assertForbidden();

    $this->put('/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'my-own-new-password',
        'password_confirmation' => 'my-own-new-password',
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->get('/')->assertOk();
});

it('needs the password before replacing a working authenticator', function () {
    signIn();

    $this->get('/two-factor/setup')->assertRedirect(route('profile'));
    $this->post('/profile/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->post('/profile/two-factor', ['current_password' => UserFactory::PASSWORD])->assertRedirect(route('two-factor.setup'));
    $this->get('/two-factor/setup')->assertOk();
});

it('signs out', function () {
    signIn();

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->getJson('/api/projects')->assertUnauthorized();
});

it('gives the super admin role every permission', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = User::factory()->regular()->create();

    expect($admin->can(Roles::MANAGE_USERS))->toBeTrue()
        ->and($admin->can(Roles::ACCESS_ALL_WORKSPACES))->toBeTrue()
        ->and($user->can(Roles::MANAGE_USERS))->toBeFalse();
});
