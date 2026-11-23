<?php

// Old bookmarks and installed apps open /index.php; Laravel would then prefix every URL with it

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->superAdmin()->withTwoFactor()->create(); // past first-run setup
    $this->withServerVariables(['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => public_path('index.php')]);
});

it('redirects /index.php addresses to the clean URL with a relative location', function (string $from, string $to) {
    $this->get($from)->assertStatus(301)->assertHeader('Location', $to);
})->with([
    ['/index.php', '/'],
    ['/index.php?x=1', '/?x=1'],
    ['/index.php/admin/users', '/admin/users'],
]);

it('leaves clean addresses and non-GET requests alone', function () {
    $this->get('/login')->assertOk();
    $this->post('/index.php/logout')->assertStatus(302); // handled by the app, not this redirect
});

it('never puts index.php into the shared base URL', function () {
    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('app.base_url', 'http://localhost'));
});
