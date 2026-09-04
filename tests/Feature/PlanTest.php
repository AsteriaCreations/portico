<?php

use App\Enums\AddOnKind;
use App\Models\AddOn;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
});

test('currentFor picks the plan effective on the given date', function () {
    Plan::create([
        'add_on_id' => $this->entry->id,
        'price' => 50,
        'credit' => 20,
        'effective_from' => '2025-01-01',
        'effective_to' => '2025-12-31',
    ]);
    $current = Plan::create([
        'add_on_id' => $this->entry->id,
        'price' => 60,
        'credit' => 25,
        'effective_from' => '2026-01-01',
    ]);

    $plan = Plan::currentFor($this->entry, now()->parse('2026-07-19'));

    expect($plan->id)->toBe($current->id)
        ->and($plan->price)->toEqual(60);
});

test('currentFor ignores a plan that has not started yet', function () {
    Plan::create([
        'add_on_id' => $this->entry->id,
        'price' => 60,
        'credit' => 25,
        'effective_from' => '2027-01-01',
    ]);

    expect(Plan::currentFor($this->entry, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor ignores a plan that has already ended', function () {
    Plan::create([
        'add_on_id' => $this->entry->id,
        'price' => 50,
        'credit' => 20,
        'effective_from' => '2020-01-01',
        'effective_to' => '2021-12-31',
    ]);

    expect(Plan::currentFor($this->entry, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor returns null when no plan of that add-on exists', function () {
    expect(Plan::currentFor($this->pool, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor(durationMonths: 1) is unaffected by a duration-3 plan for the same add-on', function () {
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);

    $plan = Plan::currentFor($this->entry, now()->parse('2026-07-19'));

    expect($plan->duration_months)->toBe(1)
        ->and($plan->price)->toEqual(60);
});

test('currentOptionsFor returns one row per distinct duration, latest effective_from within each', function () {
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 50, 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);
    $currentMonthly = Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    $bundle = Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->pool->id, 'duration_months' => 1, 'price' => 15, 'effective_from' => '2026-01-01']);

    $options = Plan::currentOptionsFor($this->entry, now()->parse('2026-07-19'));

    expect($options)->toHaveCount(2)
        ->and($options->pluck('id')->all())->toBe([$currentMonthly->id, $bundle->id])
        ->and($options->pluck('duration_months')->all())->toBe([1, 3]);
});

test('currentOptionsFor excludes a duration whose only row has not started yet or has expired', function () {
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2027-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 6, 'price' => 300, 'effective_from' => '2020-01-01', 'effective_to' => '2021-01-01']);

    $options = Plan::currentOptionsFor($this->entry, now()->parse('2026-07-19'));

    expect($options->pluck('duration_months')->all())->toBe([1]);
});
