<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ChangePassword;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $this->actingAs($this->admin);
    // phpunit.xml runs on the array session driver; ending sessions only
    // applies to the database driver the app defaults to.
    config(['session.driver' => 'database']);
});

function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

test('a temporary password always passes the password rule and varies', function () {
    $passwords = collect(range(1, 200))->map(fn () => TemporaryPassword::generate());

    $passwords->each(fn (string $password) => expect(Validator::make(['p' => $password], ['p' => Password::default()])->passes())->toBeTrue()
        ->and(strlen($password))->toBeGreaterThanOrEqual(15));

    expect($passwords->unique()->count())->toBeGreaterThan(190);
});

test('resetting sets a working temporary password, forces a change, and ends only their sessions', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true, 'password' => Hash::make('Original-Passw0rd!')]);
    fakeSession($door, 'door-session');
    fakeSession($this->admin, 'admin-session');

    $password = $door->resetToTemporaryPassword();
    $door->refresh();

    expect($door->must_change_password)->toBeTrue()
        ->and(Hash::check($password, $door->password))->toBeTrue()
        ->and(Hash::check('Original-Passw0rd!', $door->password))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $door->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Signing in with it lands on the forced change-password page.
    auth()->logout();
    $this->actingAs($door)->get('/admin')->assertRedirect(ChangePassword::getUrl());
});

test('the reset button shows the temporary password once, in a notification', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true, 'name' => 'Dana Door']);

    Livewire::test(ListUsers::class)
        ->callTableAction('resetPassword', $door)
        ->assertHasNoTableActionErrors()
        ->assertNotified('Temporary password for Dana Door');

    expect($door->refresh()->must_change_password)->toBeTrue();
});

test('the reset button is on the edit page too', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);

    Livewire::test(EditUser::class, ['record' => $door->getRouteKey()])
        ->callAction('resetPassword')
        ->assertNotified();

    expect($door->refresh()->must_change_password)->toBeTrue();
});

test('you cannot reset your own password this way', function () {
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('resetPassword', $this->admin);
});

test('a manager cannot reset anyone', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);

    expect(Gate::forUser($manager)->allows('resetPassword', $door))->toBeFalse();
});

test('an admin cannot see the reset button on an owner', function () {
    auth()->logout();
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('resetPassword', $owner)
        ->assertTableActionHidden('edit', $owner);
});

test('a new account requires a password change by default', function () {
    Livewire::test(CreateUser::class)
        ->assertFormSet(['must_change_password' => true]);
});

test('changing your password rejects reusing the current one, and keeps only this session', function () {
    $this->admin->update(['password' => 'Current-Passw0rd!']);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'Current-Passw0rd!',
            'new_password' => 'Current-Passw0rd!',
            'new_password_confirmation' => 'Current-Passw0rd!',
        ])
        ->callAction('save')
        ->assertHasFormErrors(['new_password']);

    fakeSession($this->admin, 'other-browser');
    fakeSession($this->admin, session()->getId());

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'Current-Passw0rd!',
            'new_password' => 'Brand-New-Passw0rd!',
            'new_password_confirmation' => 'Brand-New-Passw0rd!',
        ])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect(DB::table('sessions')->where('id', 'other-browser')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', session()->getId())->exists())->toBeTrue();
});

test('the users list can be filtered by active', function () {
    $inactive = User::factory()->create(['role' => Role::Door, 'active' => false]);

    Livewire::test(ListUsers::class)
        ->filterTable('active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$this->admin]);
});
