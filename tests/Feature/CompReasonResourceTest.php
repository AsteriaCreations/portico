<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\CompReasons\Pages\CreateCompReason;
use App\Filament\Admin\Resources\CompReasons\Pages\ListCompReasons;
use App\Models\CompReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
