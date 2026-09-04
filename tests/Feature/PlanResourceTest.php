<?php

use App\Enums\AddOnKind;
use App\Filament\Admin\Resources\Plans\Pages\CreatePlan;
use App\Filament\Admin\Resources\Plans\Pages\ListPlans;
use App\Models\AddOn;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

test('plans list page renders', function () {
    Plan::factory()->count(2)->create();

    Livewire::test(ListPlans::class)->assertSuccessful();
});

test('a new effective-dated plan can be created', function () {
    Livewire::test(CreatePlan::class)
        ->fillForm([
            'add_on_id' => $this->entry->id,
            'price' => 65,
            'credit' => 25,
            'effective_from' => '2027-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::whereDate('effective_from', '2027-01-01')->firstOrFail();
    expect($plan->add_on_id)->toBe($this->entry->id)
        ->and($plan->price)->toEqual(65)
        ->and($plan->credit)->toEqual(25);
});
