<?php

use App\Services\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function webhookProject(bool $enabled = true): array
{
    $project = app(ProjectRepository::class)->create([
        'name' => 'Blog',
        'ssh_host' => 'blog.example.com',
        'ssh_port' => 22,
        'ssh_username' => 'deploy',
        'ssh_auth_type' => 'password',
        'ssh_password' => 'pw',
        'webhook_enabled' => $enabled ? 1 : 0,
        'webhook_branch' => 'main',
        'preset' => 'none',
    ]);

    return app(ProjectRepository::class)->find($project['id']);
}

it('rejects webhooks without a valid token', function () {
    $this->postJson('/webhook')->assertUnauthorized();
    $this->postJson('/webhook/not-a-real-token')->assertForbidden()->assertJson(['ok' => false, 'error' => 'Invalid webhook token.']);
});

it('rejects webhooks for projects with webhooks disabled', function () {
    $project = webhookProject(enabled: false);

    $this->postJson('/webhook/'.$project['webhook_token'])->assertForbidden();
});

it('skips pushes to other branches, on the new and the legacy URL', function () {
    $project = webhookProject();

    $this->postJson('/webhook/'.$project['webhook_token'], ['ref' => 'refs/heads/feature-x'])
        ->assertOk()
        ->assertJson(['ok' => true, 'ignored' => true]);

    // Webhook URLs saved in GitHub before the Laravel port keep working
    $this->postJson('/api.php?action=webhook&token='.$project['webhook_token'], ['ref' => 'refs/heads/feature-x'])
        ->assertOk()
        ->assertJson(['ok' => true, 'ignored' => true]);
});

it('locks out an IP that keeps guessing webhook tokens', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/webhook/guess-'.bin2hex(random_bytes(4)))->assertForbidden();
    }

    $this->postJson('/webhook/another-guess')->assertStatus(429);
    expect(DB::table('login_attempts')->count())->toBe(5);
});

it('shows an unavailable alert for an unknown token without signing in', function () {
    $this->get('/alert?t=nope')
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page
            ->component('alert')
            ->where('alert', null)
            ->where('error', 'This alert link is invalid or older than 24 hours.'));

    $this->postJson('/alert/decide', ['token' => 'nope', 'choice' => 'ignore'])->assertStatus(400)->assertJson(['ok' => false]);
    $this->postJson('/api.php?action=alert_action', ['token' => 'nope', 'choice' => 'ignore'])->assertStatus(400);
});

it('redirects pre-Laravel page URLs', function () {
    $this->get('/alert.php?t=abc')->assertRedirect('/alert?t=abc');
    $this->get('/project.php?id=7')->assertRedirect('/projects/7');
});
