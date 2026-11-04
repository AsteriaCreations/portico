<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\CompReasons\Pages\CreateCompReason;
use App\Filament\Admin\Resources\CompReasons\Pages\EditCompReason;
use App\Filament\Admin\Resources\CompReasons\Pages\ListCompReasons;
use App\Models\CompReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('comp reasons list page renders for a manager', function () {
    CompReason::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListCompReasons::class)->assertSuccessful();
});

test('a manager can create a comp reason', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateCompReason::class)
        ->fillForm([
            'name' => 'DM',
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CompReason::where('name', 'DM')->exists())->toBeTrue();
});

test('a door volunteer has no access to the comp reasons resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/comp-reasons')->assertForbidden();
});

test('an admin can mark a comp reason door-allowed', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    $reason = CompReason::factory()->create(['name' => 'Brought donation items']);

    Livewire::test(EditCompReason::class, ['record' => $reason->getRouteKey()])
        ->fillForm(['door_allowed' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($reason->fresh()->door_allowed)->toBeTrue();
});

test('a manager cannot mark a comp reason door-allowed, from the form or any other save path', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $reason = CompReason::factory()->create(['name' => 'Brought donation items']);

    Livewire::test(EditCompReason::class, ['record' => $reason->getRouteKey()])
        ->assertFormFieldDisabled('door_allowed')
        ->fillForm(['door_allowed' => true, 'description' => 'Canned food drive'])
        ->call('save');

    expect($reason->fresh()->door_allowed)->toBeFalse()
        ->and($reason->fresh()->description)->toBe('Canned food drive');

    expect(fn () => $reason->update(['door_allowed' => true]))->toThrow(ValidationException::class)
        ->and(fn () => CompReason::create(['name' => 'Sneaky', 'door_allowed' => true]))->toThrow(ValidationException::class);
});
