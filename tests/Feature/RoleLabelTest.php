<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\RoleLabels;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('getLabel returns a generic default, never club jargon, with no DB access required', function () {
    expect(Role::Showrunner->getLabel())->toBe('Event Lead')
        ->and(Role::DM->getLabel())->toBe('Monitor')
        ->and(Role::Door->getLabel())->toBe('Door')
        ->and(Role::Manager->getLabel())->toBe('Manager')
        ->and(Role::Admin->getLabel())->toBe('Admin')
        ->and(Role::Owner->getLabel())->toBe('Owner')
        ->and(Role::Volunteer->getLabel())->toBe('Volunteer');
});

test('displayLabel falls back to the generic default when no alias is set', function () {
    expect(Role::Showrunner->displayLabel())->toBe('Event Lead');
});

test('displayLabel prefers a club-set alias over the default', function () {
    MembershipSetting::current()->update(['role_labels' => ['showrunner' => 'Dungeon Monitor']]);

    expect(Role::Showrunner->displayLabel())->toBe('Dungeon Monitor')
        ->and(Role::DM->displayLabel())->toBe('Monitor'); // untouched, still the default
});

test('a manager can save role label overrides via the settings page', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(RoleLabels::class)
        ->fillForm(['role_labels' => ['showrunner' => 'Showrunner', 'dm' => 'DM']])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->role_labels)->toBe(['showrunner' => 'Showrunner', 'dm' => 'DM']);
});

test('a blank field clears the alias back to the default rather than saving an empty string', function () {
    MembershipSetting::current()->update(['role_labels' => ['showrunner' => 'Dungeon Monitor']]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(RoleLabels::class)
        ->fillForm(['role_labels' => ['showrunner' => '']])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->role_labels)->toBe([])
        ->and(Role::Showrunner->displayLabel())->toBe('Event Lead');
});

test('a door volunteer cannot access the role labels settings page', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/role-labels')->assertForbidden();
});

test('the users list page shows the aliased role label, not the generic default', function () {
    MembershipSetting::current()->update(['role_labels' => ['showrunner' => 'Dungeon Monitor']]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    User::factory()->create(['active' => true, 'role' => Role::Showrunner]);

    $this->get('/admin/users')
        ->assertSuccessful()
        ->assertSee('Dungeon Monitor')
        ->assertDontSee('Event Lead');
});
