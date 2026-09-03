<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\AddOns\Pages\CreateAddOn;
use App\Filament\Admin\Resources\AddOns\Pages\ListAddOns;
use App\Models\AddOn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add-ons list page renders for a manager', function () {
    AddOn::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListAddOns::class)->assertSuccessful();
});

test('a manager can create an add-on', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateAddOn::class)
        ->fillForm([
            'name' => 'Private room rental',
            'price' => 50,
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AddOn::where('name', 'Private room rental')->exists())->toBeTrue();
});

test('a manager can set a nightly cap on an add-on, or leave it unlimited', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateAddOn::class)
        ->fillForm([
            'name' => 'Private room rental',
            'price' => 50,
            'max_per_night' => 1,
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AddOn::where('name', 'Private room rental')->firstOrFail()->max_per_night)->toBe(1);
});

test('a manager can mark an add-on as an overnight stay', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateAddOn::class)
        ->fillForm([
            'name' => 'Sleepover',
            'price' => 25,
            'is_overnight' => true,
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AddOn::where('name', 'Sleepover')->firstOrFail()->is_overnight)->toBeTrue();
});

test('is_overnight defaults to false when omitted', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateAddOn::class)
        ->fillForm([
            'name' => 'Coat check',
            'price' => 5,
            'sort_order' => 20,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AddOn::where('name', 'Coat check')->firstOrFail()->is_overnight)->toBeFalse();
});

test('a door volunteer has no access to the add-ons resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/add-ons')->assertForbidden();
});
