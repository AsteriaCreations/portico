<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the shipped icon and logo files exist and are not empty', function (string $file) {
    expect(filesize(public_path($file)))->toBeGreaterThan(0);
})->with([
    'favicon.ico',
    'apple-touch-icon.png',
    'images/portico-logo.png',
    'images/portico-logo-white.png',
    'images/portico-wheel-white.png',
]);

test('the login page links the favicon and touch icon and shows the Portico footer', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="'.asset('favicon.ico'), false)
        ->assertSee('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'">', false)
        ->assertSee('images/portico-logo.png')
        ->assertSee('alt="Powered by Portico"', false);
});

test('the panel footer shows the Portico logo for light and dark mode', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin')
        ->assertOk()
        ->assertSee('images/portico-logo.png')
        ->assertSee('images/portico-logo-white.png');
});
