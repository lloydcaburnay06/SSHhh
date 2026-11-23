<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = signIn();
});

it('requires an unlocked session', function () {
    $this->post('/logout');

    $this->getJson('/api/projects')
        ->assertUnauthorized()
        ->assertJson(['ok' => false]);
});

it('creates, lists, updates and deletes a project with encrypted credentials', function () {
    $created = $this->postJson('/api/projects', projectPayload())
        ->assertCreated()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('project.name', 'Shop')
        ->assertJsonPath('project.has_password', true)
        ->assertJsonMissingPath('project.ssh_password_encrypted')
        ->json('project');

    $stored = DB::table('projects')->where('id', $created['id'])->value('ssh_password_encrypted');
    expect($stored)->not->toBeEmpty()->not->toContain('s3cret-pass');

    $this->getJson('/api/projects')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('stats.total_projects', 1);

    $this->putJson("/api/projects/{$created['id']}", projectPayload(['name' => 'Shop v2', 'ssh_password' => '']))
        ->assertOk()
        ->assertJsonPath('project.name', 'Shop v2')
        ->assertJsonPath('project.has_password', true);

    $this->deleteJson("/api/projects/{$created['id']}")->assertOk()->assertJsonPath('stats.total_projects', 0);
    $this->getJson("/api/projects/{$created['id']}")->assertNotFound()->assertJson(['ok' => false, 'error' => 'Project not found.']);
});

it('returns service validation errors in the legacy envelope', function () {
    $this->postJson('/api/projects', projectPayload(['name' => '']))
        ->assertStatus(422)
        ->assertExactJson(['ok' => false, 'error' => 'Project name is required.']);
});

it('saves deployment commands', function () {
    $id = $this->postJson('/api/projects', projectPayload())->json('project.id');

    $this->putJson("/api/projects/{$id}/commands", ['commands' => [
        ['name' => 'Pull', 'command' => 'git pull', 'is_enabled' => true, 'stop_on_error' => true],
        ['name' => 'Migrate', 'command' => 'php artisan migrate --force', 'is_enabled' => true, 'stop_on_error' => true],
    ]])
        ->assertOk()
        ->assertJsonCount(2, 'commands')
        ->assertJsonPath('commands.1.name', 'Migrate');

    $this->getJson("/api/projects/{$id}")->assertJsonCount(2, 'commands');
});

it('lists and downloads event logs', function () {
    $id = $this->postJson('/api/projects', projectPayload())->json('project.id');

    $events = $this->getJson("/api/events?project_id={$id}")->assertOk()->json('items');
    expect($events)->not->toBeEmpty();

    $this->get("/api/events/{$events[0]['id']}/download")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('SSHhh EVENT LOG #'.$events[0]['id']);

    $this->get("/api/projects/{$id}/events/download")->assertOk()->assertSee('SSHhh DEPLOYER EVENT-LOGS');

    $this->deleteJson("/api/projects/{$id}/events")->assertOk()->assertJsonPath('ok', true);
});

it('manages shared SSH profiles', function () {
    $profile = $this->postJson('/api/ssh-profiles', [
        'name' => 'Main VPS',
        'ssh_host' => 'vps.example.com',
        'ssh_port' => 22,
        'ssh_username' => 'deploy',
        'ssh_auth_type' => 'password',
        'ssh_password' => 'pw',
    ])->assertCreated()->json('profile');

    $this->getJson('/api/ssh-profiles')->assertJsonCount(1, 'profiles');
    $this->deleteJson("/api/ssh-profiles/{$profile['id']}")->assertOk()->assertJsonCount(0, 'profiles');
});

it('renders the dashboard and project pages', function () {
    $id = $this->postJson('/api/projects', projectPayload())->json('project.id');

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('projects', 1)
        ->has('ssh_profiles')
        ->has('stats')
        ->has('events'));

    $this->get("/projects/{$id}")->assertInertia(fn (Assert $page) => $page
        ->component('project')
        ->where('project.id', $id)
        ->has('commands')
        ->has('events.items'));

    $this->get('/projects/999999')->assertRedirect(route('dashboard'));
});

it('exports and re-imports the encrypted vault', function () {
    $this->postJson('/api/projects', projectPayload());

    $bundle = $this->postJson('/api/vault/export', ['passphrase' => 'correct horse battery'])
        ->assertOk()
        ->json('bundle');

    $this->postJson('/api/vault/import', ['passphrase' => 'correct horse battery', 'bundle' => $bundle])
        ->assertOk()
        ->assertJsonPath('ok', true);
});
