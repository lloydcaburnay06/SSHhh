<?php

use App\Models\User;
use App\Services\Crypto;
use App\Services\Totp;
use App\Support\Workspace;

pest()->extend(Tests\TestCase::class)->in('Feature');

/**
 * A fully onboarded user (2FA linked, password changed), signed in for the test.
 */
function signIn(?User $user = null, bool $superAdmin = false): User
{
    $user ??= ($superAdmin ? User::factory()->superAdmin() : User::factory()->regular())->withTwoFactor()->create();
    test()->actingAs($user->fresh());

    return $user;
}

/** The current TOTP code for a user's linked authenticator. */
function totpCodeFor(User $user): string
{
    $secret = app(Crypto::class)->decrypt((string) $user->fresh()->two_factor_secret, 'user-totp:'.$user->id);

    return Totp::calculateCode($secret);
}

/** Runs service code (repositories) inside a user's workspace, outside any request. */
function asWorkspace(User $user, callable $fn): mixed
{
    return app(Workspace::class)->runAs($user->id, $fn);
}

function projectPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Shop',
        'environment' => 'production',
        'repo_branch' => 'main',
        'remote_path' => '/var/www/shop',
        'ssh_host' => 'shop.example.com',
        'ssh_port' => 22,
        'ssh_username' => 'deploy',
        'ssh_auth_type' => 'password',
        'ssh_password' => 's3cret-pass',
        'preset' => 'none',
    ], $overrides);
}
