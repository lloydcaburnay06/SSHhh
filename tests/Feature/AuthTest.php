<?php

use App\Services\Authenticator;
use App\Services\Crypto;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function configureAuthenticator(string $secret): void
{
    DB::table('auth_state')->where('id', 1)->update([
        'totp_secret_encrypted' => app(Crypto::class)->encrypt($secret, 'totp-secret'),
        'is_configured' => 1,
        'configured_at' => now(),
    ]);
}

it('sends a fresh install to the one-time setup screen', function () {
    $this->get('/')->assertRedirect(route('setup'));

    $this->get('/setup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/setup')->has('secret')->has('qr'));
});

it('refuses the setup screen to remote visitors', function () {
    $this->get('/setup', ['X-Forwarded-For' => '203.0.113.9'])->assertForbidden();
    $this->getJson('/api/projects')->assertStatus(428);
});

it('activates the authenticator with a valid first code', function () {
    $this->get('/setup');
    $secret = session('pending_totp_secret');

    $this->post('/setup', ['code' => Totp::calculateCode($secret), 'remember_device' => true])
        ->assertRedirect(route('dashboard'))
        ->assertCookie('sshhh_device_token');

    expect(app(Authenticator::class)->isConfigured())->toBeTrue()
        ->and(session(Authenticator::SESSION_FLAG))->toBeTrue()
        ->and(DB::table('remembered_devices')->count())->toBe(1);

    $this->get('/setup')->assertRedirect(route('login'));
});

it('rejects a wrong setup code', function () {
    $this->get('/setup');

    $this->from('/setup')->post('/setup', ['code' => '000000'])
        ->assertRedirect('/setup')
        ->assertSessionHasErrors('code');

    expect(app(Authenticator::class)->isConfigured())->toBeFalse();
});

it('unlocks with a valid code and remembers the intended page', function () {
    $secret = Totp::generateSecret();
    configureAuthenticator($secret);

    $this->get('/projects/1')->assertRedirect(route('login'));
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('auth/login'));

    $this->post('/login', ['code' => Totp::calculateCode($secret)])
        ->assertRedirect('/projects/1')
        ->assertCookieMissing('sshhh_device_token');
});

it('locks an IP out after five wrong codes', function () {
    configureAuthenticator(Totp::generateSecret());

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post('/login', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Too many incorrect codes. Try again in 15 minute(s).']);
});

it('unlocks from a remembered device cookie', function () {
    configureAuthenticator(Totp::generateSecret());
    $token = bin2hex(random_bytes(32));
    DB::table('remembered_devices')->insert([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
        'created_at' => now(),
    ]);

    $this->withUnencryptedCookie('sshhh_device_token', $token)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
});

it('signs out and forgets the device', function () {
    configureAuthenticator(Totp::generateSecret());
    $token = bin2hex(random_bytes(32));
    DB::table('remembered_devices')->insert([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
        'created_at' => now(),
    ]);

    $this->withSession([Authenticator::SESSION_FLAG => true])
        ->withUnencryptedCookie('sshhh_device_token', $token)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(DB::table('remembered_devices')->count())->toBe(0);
    $this->getJson('/api/projects')->assertUnauthorized();
});
