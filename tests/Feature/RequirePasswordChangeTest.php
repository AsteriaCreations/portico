<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ChangePassword;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// --- Setting the flag -------------------------------------------------------

test('an admin can flag another user to require a password change at next login', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $door->getRouteKey()])
        ->fillForm([
            'name' => $door->name,
            'email' => $door->email,
            'role' => $door->role->value,
            'active' => true,
            'must_change_password' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($door->refresh()->must_change_password)->toBeTrue();
});

// --- Enforcement --------------------------------------------------------

test('a flagged user is redirected to the change-password page from any panel route', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user)->get('/admin')->assertRedirect('/admin/change-password');
    $this->actingAs($user)->get('/admin/check-in')->assertRedirect('/admin/change-password');
});

test('a non-flagged user is not redirected', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => false]);

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

test('the change-password page itself renders for a flagged user with no redirect loop', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user)->get('/admin/change-password')->assertSuccessful();
});

test('a flagged user can still reach the logout route', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user)->post('/admin/logout')->assertRedirect('/admin/login');
});

// --- The change-password form ---------------------------------------------

test('a weak new password is rejected', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'password',
            'new_password' => 'weak',
            'new_password_confirmation' => 'weak',
        ])
        ->callAction('save')
        ->assertHasFormErrors(['new_password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});

test('the wrong current password is rejected', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'not-the-real-password',
            'new_password' => 'Sup3r-Secret-Passw0rd!',
            'new_password_confirmation' => 'Sup3r-Secret-Passw0rd!',
        ])
        ->callAction('save')
        ->assertHasFormErrors(['current_password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});

test('a mismatched confirmation is rejected', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'password',
            'new_password' => 'Sup3r-Secret-Passw0rd!',
            'new_password_confirmation' => 'Different-Passw0rd!',
        ])
        ->callAction('save')
        ->assertHasFormErrors(['new_password_confirmation']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});

test('a correct submission updates the password, clears the flag, and lifts the redirect', function () {
    $user = User::factory()->create(['active' => true, 'must_change_password' => true]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'password',
            'new_password' => 'Sup3r-Secret-Passw0rd!',
            'new_password_confirmation' => 'Sup3r-Secret-Passw0rd!',
        ])
        ->callAction('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('Sup3r-Secret-Passw0rd!', $user->password))->toBeTrue();

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});
