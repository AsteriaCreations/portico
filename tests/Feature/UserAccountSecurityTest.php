<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $this->actingAs($this->admin);
});

// --- A1: password strength -------------------------------------------------

test('a weak password is rejected when creating a user', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'password',
            'role' => Role::Door->value,
            'active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});

test('a strong password is accepted and stored hashed', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Strong Password',
            'email' => 'strong@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Door->value,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'strong@example.com')->firstOrFail();

    expect($user->password)->not->toBe('Sup3r-Secret-Passw0rd')
        ->and(Hash::check('Sup3r-Secret-Passw0rd', $user->password))->toBeTrue();
});

test('an unchanged blank password on edit does not trip the strength rule', function () {
    $door = User::factory()->create([
        'role' => Role::Door,
        'active' => true,
        'password' => Hash::make('Original-Passw0rd!'),
    ]);
    $originalHash = $door->password;

    Livewire::test(EditUser::class, ['record' => $door->getRouteKey()])
        ->fillForm([
            'name' => $door->name,
            'email' => $door->email,
            'password' => '',
            'role' => $door->role->value,
            'active' => $door->active,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($door->refresh()->password)->toBe($originalHash);
});

// --- A2: self-lockout guard ----------------------------------------------

test('an admin cannot deactivate their own account', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->fillForm([
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role' => $this->admin->role->value,
            'active' => false,
        ])
        ->call('save');

    expect($this->admin->refresh()->active)->toBeTrue();
});

test('an admin cannot lower their own role below Admin', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->fillForm([
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role' => Role::Door->value,
            'active' => true,
        ])
        ->call('save');

    expect($this->admin->refresh()->role)->toBe(Role::Admin);
});

test('an admin can still edit their own name', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed Admin',
            'email' => $this->admin->email,
            'role' => $this->admin->role->value,
            'active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->admin->refresh()->name)->toBe('Renamed Admin');
});

test('an admin cannot delete their own account', function () {
    expect(Gate::forUser($this->admin)->denies('delete', $this->admin))->toBeTrue();

    expect(fn () => $this->admin->delete())->toThrow(ValidationException::class);
    expect(User::whereKey($this->admin->getKey())->exists())->toBeTrue();
});

// --- A2: last active owner guard ---------------------------------------

test('the last active owner cannot be deactivated or demoted', function () {
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    // The sole Owner acting on their own account: an Admin would be stopped
    // by the rank rule first, which isn't what this test is about.
    $this->actingAs($owner);

    expect(fn () => $owner->update(['active' => false]))->toThrow(ValidationException::class);
    expect($owner->refresh()->active)->toBeTrue();

    expect(fn () => $owner->update(['role' => Role::Admin]))->toThrow(ValidationException::class, 'last active Owner');
    expect($owner->refresh()->role)->toBe(Role::Owner);
});

test('the last active owner cannot be deleted', function () {
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    expect(Gate::forUser($this->admin)->denies('delete', $owner))->toBeTrue();
    expect(fn () => $owner->delete())->toThrow(ValidationException::class);
    expect(User::whereKey($owner->getKey())->exists())->toBeTrue();
});

test('an owner can be changed once a second active owner exists', function () {
    $first = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $second = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    // Only an Owner can change an Owner account (see the rank tests below).
    $this->actingAs($second);
    $first->update(['role' => Role::Admin]);

    expect($first->refresh()->role)->toBe(Role::Admin);
    expect(Gate::forUser($this->admin)->allows('delete', $first))->toBeTrue();
});

test('an inactive owner does not count as the last active owner', function () {
    $activeOwner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $inactive = User::factory()->create(['role' => Role::Owner, 'active' => false]);

    // Only one *active* owner (the first) — but this row isn't it, so the
    // guard doesn't apply to changing this one. Acting as that Owner, since
    // only an Owner can change an Owner account.
    $this->actingAs($activeOwner);
    $inactive->update(['role' => Role::Admin]);

    expect($inactive->refresh()->role)->toBe(Role::Admin);
});

// --- Rank: nobody manages an account above their own ----------------------

test('an admin cannot create an owner or promote anyone, themselves included, to owner', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Would-be Owner',
            'email' => 'owner2@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Owner->value,
            'active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['role']);
    expect(User::where('email', 'owner2@example.com')->exists())->toBeFalse();

    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);
    expect(fn () => $door->update(['role' => Role::Owner]))->toThrow(ValidationException::class);
    expect(fn () => $this->admin->update(['role' => Role::Owner]))->toThrow(ValidationException::class);

    expect($door->refresh()->role)->toBe(Role::Door)
        ->and($this->admin->refresh()->role)->toBe(Role::Admin);
});

test('an admin cannot edit, deactivate, delete or reset an owner, even directly', function () {
    auth()->logout();
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->admin);

    expect(Gate::allows('update', $owner))->toBeFalse()
        ->and(Gate::allows('delete', $owner))->toBeFalse()
        ->and(Gate::allows('resetPassword', $owner))->toBeFalse();

    expect(fn () => $owner->update(['name' => 'Hijacked']))->toThrow(ValidationException::class, 'outranks you');
    expect(fn () => $owner->update(['active' => false]))->toThrow(ValidationException::class);
    expect(fn () => $owner->resetToTemporaryPassword())->toThrow(ValidationException::class);
    expect(fn () => $owner->delete())->toThrow(ValidationException::class);

    expect($owner->refresh()->name)->not->toBe('Hijacked')
        ->and($owner->must_change_password)->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])->assertForbidden();
});

test('the role picker never offers a role above your own', function () {
    $options = Livewire::test(CreateUser::class)
        ->instance()
        ->form
        ->getComponent('role')
        ->getOptions();

    expect(array_keys($options))->not->toContain(Role::Owner->value)
        ->toContain(Role::Admin->value);
});

test('an owner can manage owners, and an admin can still manage admins and below', function () {
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $otherAdmin = User::factory()->create(['role' => Role::Admin, 'active' => true]);

    expect(Gate::allows('update', $otherAdmin))->toBeTrue();
    $otherAdmin->update(['role' => Role::Manager]);
    expect($otherAdmin->refresh()->role)->toBe(Role::Manager);

    $this->actingAs($owner);
    $promoted = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    expect(Gate::allows('update', $promoted))->toBeTrue()
        ->and(Gate::allows('resetPassword', $promoted))->toBeTrue();
});

// --- A2: console context is exempt -----------------------------------------

test('a console-driven save is not subject to the self or owner guards', function () {
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    auth()->logout();

    $owner->update(['active' => false]);

    expect($owner->refresh()->active)->toBeFalse();
});
