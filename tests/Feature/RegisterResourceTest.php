<?php

use App\Filament\Admin\Resources\Registers\Pages\CreateRegister;
use App\Filament\Admin\Resources\Registers\Pages\ListRegisters;
use App\Models\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
});

test('registers list page renders', function () {
    Register::factory()->count(2)->create();

    Livewire::test(ListRegisters::class)->assertSuccessful();
});

test('a register can be created', function () {
    Livewire::test(CreateRegister::class)
        ->fillForm([
            'name' => 'Front Desk',
            'sort_order' => 0,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Register::where('name', 'Front Desk')->exists())->toBeTrue();
});
