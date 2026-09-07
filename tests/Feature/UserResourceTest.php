<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($this->admin);
});

test('an admin can create a volunteer account with a hashed password', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Volunteer',
            'email' => 'volunteer@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Door->value,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'volunteer@example.com')->firstOrFail();

    expect($user->name)->toBe('New Volunteer')
        ->and($user->role)->toBe(Role::Door)
        ->and($user->active)->toBeTrue()
        ->and($user->password)->not->toBe('Sup3r-Secret-Passw0rd')
        ->and(Hash::check('Sup3r-Secret-Passw0rd', $user->password))->toBeTrue();
});

test('leaving the password blank on edit keeps the existing password', function () {
    $user = User::factory()->create(['password' => Hash::make('original-password'), 'role' => Role::Door, 'active' => true]);
    $originalHash = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'password' => '',
            'role' => $user->role->value,
            'active' => $user->active,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->password)->toBe($originalHash);
});

test('an admin can link a volunteer account to a member record', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Staff Volunteer',
            'email' => 'staff@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Door->value,
            'member_id' => $member->id,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'staff@example.com')->firstOrFail();

    expect($user->member_id)->toBe($member->id)
        ->and($user->member->is($member))->toBeTrue()
        ->and($member->user->is($user))->toBeTrue();
});

test('a volunteer account can be created without a linked member', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'No Member Link',
            'email' => 'nolink@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Door->value,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'nolink@example.com')->firstOrFail();

    expect($user->member_id)->toBeNull();
});

test('a member cannot be linked to two different volunteer accounts', function () {
    $member = Member::factory()->create();
    User::factory()->create(['member_id' => $member->id]);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Duplicate Link',
            'email' => 'duplicate@example.com',
            'password' => 'Sup3r-Secret-Passw0rd',
            'role' => Role::Door->value,
            'member_id' => $member->id,
            'active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['member_id']);

    expect(User::where('email', 'duplicate@example.com')->exists())->toBeFalse();
});
