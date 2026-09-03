<?php

use App\Enums\PlanType;
use App\Filament\Admin\Resources\Plans\Pages\CreatePlan;
use App\Filament\Admin\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
});

test('plans list page renders', function () {
    Plan::factory()->count(2)->create();

    Livewire::test(ListPlans::class)->assertSuccessful();
});

test('a new effective-dated plan can be created', function () {
    Livewire::test(CreatePlan::class)
        ->fillForm([
            'code' => PlanType::Regular->value,
            'price' => 65,
            'credit' => 25,
            'effective_from' => '2027-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::whereDate('effective_from', '2027-01-01')->firstOrFail();
    expect($plan->code)->toBe(PlanType::Regular)
        ->and($plan->price)->toEqual(65)
        ->and($plan->credit)->toEqual(25);
});
