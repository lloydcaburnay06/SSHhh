<?php

use App\Models\User;
use App\Services\ProjectRepository;
use App\Services\SettingsRepository;
use App\Services\SshProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->alice = User::factory()->regular()->withTwoFactor()->create(['name' => 'Alice']);
    $this->bob = User::factory()->regular()->withTwoFactor()->create(['name' => 'Bob']);
    $this->aliceProject = asWorkspace($this->alice, fn () => app(ProjectRepository::class)->create(projectPayload(['name' => 'Alice Shop'])));
});

it('lists only the signed-in user\'s projects, stats and events', function () {
    asWorkspace($this->bob, fn () => app(ProjectRepository::class)->create(projectPayload(['name' => 'Bob Blog'])));
    signIn($this->bob);

    $this->getJson('/api/projects')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Bob Blog')
        ->assertJsonPath('stats.total_projects', 1);

    $titles = collect($this->getJson('/api/events')->json('items'))->pluck('title');
    expect($titles->implode(' '))->toContain('Bob Blog')->not->toContain('Alice Shop');

    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.name', 'Bob Blog'));
});

it('hides another user\'s project behind 404s everywhere', function () {
    signIn($this->bob);
    $id = $this->aliceProject['id'];

    $this->getJson("/api/projects/{$id}")->assertNotFound();
    $this->putJson("/api/projects/{$id}", projectPayload(['name' => 'Hijacked']))->assertStatus(422);
    $this->deleteJson("/api/projects/{$id}")->assertStatus(422);
    $this->putJson("/api/projects/{$id}/commands", ['commands' => [['name' => 'x', 'command' => 'id']]])->assertStatus(422);
    $this->postJson("/api/projects/{$id}/execute", ['mode' => 'adhoc_command', 'adhoc_command' => 'id'])->assertStatus(422);
    $this->getJson("/api/projects/{$id}/vars")->assertStatus(422);
    $this->postJson("/api/projects/{$id}/webhook-token")->assertStatus(422);
    $this->get("/projects/{$id}")->assertRedirect(route('dashboard'));

    expect(DB::table('projects')->where('id', $id)->value('name'))->toBe('Alice Shop');
});

it('hides another user\'s event log entries', function () {
    $eventId = DB::table('event_logs')->where('user_id', $this->alice->id)->value('id');
    signIn($this->bob);

    $this->getJson("/api/events/{$eventId}")->assertNotFound();
    $this->get("/api/events/{$eventId}/download")->assertNotFound();
});

it('keeps shared SSH profiles per user', function () {
    $profile = asWorkspace($this->alice, fn () => app(SshProfileRepository::class)->create([
        'name' => 'Alice VPS', 'ssh_host' => 'vps.example.com', 'ssh_port' => 22,
        'ssh_username' => 'deploy', 'ssh_auth_type' => 'password', 'ssh_password' => 'pw',
    ]));
    signIn($this->bob);

    $this->getJson('/api/ssh-profiles')->assertJsonCount(0, 'profiles');
    $this->deleteJson("/api/ssh-profiles/{$profile['id']}")->assertStatus(422);
    // Bob can't borrow Alice's stored credentials by linking her profile
    $this->postJson('/api/projects', projectPayload(['ssh_profile_id' => $profile['id']]))
        ->assertStatus(422)
        ->assertJson(['error' => 'Selected SSH Server Profile was not found.']);
});

it('keeps settings per user', function () {
    asWorkspace($this->alice, fn () => app(SettingsRepository::class)->set('alert_gemini_key', 'alice-secret', true));

    expect(asWorkspace($this->bob, fn () => app(SettingsRepository::class)->get('alert_gemini_key')))->toBeNull()
        ->and(asWorkspace($this->alice, fn () => app(SettingsRepository::class)->get('alert_gemini_key')))->toBe('alice-secret');
});

it('runs a webhook deploy inside the project owner\'s workspace', function () {
    $project = asWorkspace($this->alice, fn () => app(ProjectRepository::class)->update($this->aliceProject['id'], projectPayload([
        'name' => 'Alice Shop', 'webhook_enabled' => 1, 'webhook_branch' => 'main', 'ssh_password' => '',
    ])));

    $this->postJson('/webhook/'.$project['webhook_token'], ['ref' => 'refs/heads/other'])
        ->assertOk()
        ->assertJson(['ignored' => true]);

    $this->alice->forceFill(['is_active' => false])->save();
    $this->postJson('/webhook/'.$project['webhook_token'])->assertForbidden();
});

it('refuses to run service queries with no workspace selected', function () {
    expect(fn () => app(ProjectRepository::class)->listAll())->toThrow(RuntimeException::class, 'No workspace selected');
});
