<?php

use App\Enums\Capability;
use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\RelationManagers\CapabilitiesRelationManager;
use App\Models\User;
use App\Models\UserCapability;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('hasCapability and the gate reflect a granted capability', function () {
    $withCapability = User::factory()->create(['role' => Role::Showrunner]);
    UserCapability::factory()->create(['user_id' => $withCapability->id, 'capability' => Capability::CleaningCrew]);

    $withoutCapability = User::factory()->create(['role' => Role::Showrunner]);

    expect($withCapability->hasCapability(Capability::CleaningCrew))->toBeTrue()
        ->and(Gate::forUser($withCapability)->allows('access-cleaning-checklist'))->toBeTrue()
        ->and($withoutCapability->hasCapability(Capability::CleaningCrew))->toBeFalse()
        ->and(Gate::forUser($withoutCapability)->allows('access-cleaning-checklist'))->toBeFalse();
});

test('an admin can grant a capability via the Users relation manager', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);
    $target = User::factory()->create(['role' => Role::Door]);

    Livewire::test(CapabilitiesRelationManager::class, [
        'ownerRecord' => $target,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('create', data: [
            'capability' => Capability::CleaningCrew->value,
        ])
        ->assertHasNoTableActionErrors();

    $grant = UserCapability::where('user_id', $target->id)->where('capability', Capability::CleaningCrew)->firstOrFail();
    expect($grant->granted_by)->toBe($admin->id);
    expect($target->refresh()->hasCapability(Capability::CleaningCrew))->toBeTrue();
});

test('an admin can revoke a capability, and hasCapability flips back to false', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);
    $target = User::factory()->create(['role' => Role::Door]);
    $grant = UserCapability::factory()->create(['user_id' => $target->id, 'capability' => Capability::CleaningCrew]);

    Livewire::test(CapabilitiesRelationManager::class, [
        'ownerRecord' => $target,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction(DeleteAction::class, $grant)
        ->assertHasNoTableActionErrors();

    expect(UserCapability::find($grant->id))->toBeNull();
    expect($target->refresh()->hasCapability(Capability::CleaningCrew))->toBeFalse();
});

test('a manager, below the Admin floor, cannot manage capability grants on the Users resource', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $target = User::factory()->create(['role' => Role::Door]);

    $this->get("/admin/users/{$target->id}/edit")->assertForbidden();
});

test('a capability grants nothing role-related: a Showrunner with Cleaning Crew still cannot reach Door-gated check-in', function () {
    $showrunner = User::factory()->create(['role' => Role::Showrunner]);
    UserCapability::factory()->create(['user_id' => $showrunner->id, 'capability' => Capability::CleaningCrew]);
    $this->actingAs($showrunner);

    $this->get('/admin/check-in')->assertForbidden();
});

test('a capability does not leak upward into role-gated actions: a Door user with Cleaning Crew still cannot grant an event comp', function () {
    $door = User::factory()->create(['role' => Role::Door]);
    UserCapability::factory()->create(['user_id' => $door->id, 'capability' => Capability::CleaningCrew]);

    expect(Gate::forUser($door)->allows('grant-event-comp'))->toBeFalse();
});
