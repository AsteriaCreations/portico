<?php

use App\Enums\PlanType;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('currentFor picks the plan effective on the given date', function () {
    Plan::create([
        'code' => PlanType::Regular,
        'price' => 50,
        'credit' => 20,
        'effective_from' => '2025-01-01',
        'effective_to' => '2025-12-31',
    ]);
    $current = Plan::create([
        'code' => PlanType::Regular,
        'price' => 60,
        'credit' => 25,
        'effective_from' => '2026-01-01',
    ]);

    $plan = Plan::currentFor(PlanType::Regular, now()->parse('2026-07-19'));

    expect($plan->id)->toBe($current->id)
        ->and($plan->price)->toEqual(60);
});

test('currentFor ignores a plan that has not started yet', function () {
    Plan::create([
        'code' => PlanType::Regular,
        'price' => 60,
        'credit' => 25,
        'effective_from' => '2027-01-01',
    ]);

    expect(Plan::currentFor(PlanType::Regular, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor ignores a plan that has already ended', function () {
    Plan::create([
        'code' => PlanType::Regular,
        'price' => 50,
        'credit' => 20,
        'effective_from' => '2020-01-01',
        'effective_to' => '2021-12-31',
    ]);

    expect(Plan::currentFor(PlanType::Regular, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor returns null when no plan of that type exists', function () {
    expect(Plan::currentFor(PlanType::Pool, now()->parse('2026-07-19')))->toBeNull();
});

test('currentFor(durationMonths: 1) is unaffected by a duration-3 plan for the same code', function () {
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 1, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);

    $plan = Plan::currentFor(PlanType::Regular, now()->parse('2026-07-19'));

    expect($plan->duration_months)->toBe(1)
        ->and($plan->price)->toEqual(60);
});

test('currentOptionsFor returns one row per distinct duration, latest effective_from within each', function () {
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 1, 'price' => 50, 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);
    $currentMonthly = Plan::create(['code' => PlanType::Regular, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    $bundle = Plan::create(['code' => PlanType::Regular, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Pool, 'duration_months' => 1, 'price' => 15, 'effective_from' => '2026-01-01']);

    $options = Plan::currentOptionsFor(PlanType::Regular, now()->parse('2026-07-19'));

    expect($options)->toHaveCount(2)
        ->and($options->pluck('id')->all())->toBe([$currentMonthly->id, $bundle->id])
        ->and($options->pluck('duration_months')->all())->toBe([1, 3]);
});

test('currentOptionsFor excludes a duration whose only row has not started yet or has expired', function () {
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2027-01-01']);
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 6, 'price' => 300, 'effective_from' => '2020-01-01', 'effective_to' => '2021-01-01']);

    $options = Plan::currentOptionsFor(PlanType::Regular, now()->parse('2026-07-19'));

    expect($options->pluck('duration_months')->all())->toBe([1]);
});
