<?php

use App\Filament\Admin\Resources\EventTypes\Pages\CreateEventType;
use App\Filament\Admin\Resources\EventTypes\Pages\ListEventTypes;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
});

test('event types list page renders', function () {
    EventType::factory()->count(2)->create();

    Livewire::test(ListEventTypes::class)->assertSuccessful();
});

test('an event type can be created', function () {
    Livewire::test(CreateEventType::class)
        ->fillForm([
            'name' => 'Fundraiser',
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EventType::where('name', 'Fundraiser')->exists())->toBeTrue();
});
