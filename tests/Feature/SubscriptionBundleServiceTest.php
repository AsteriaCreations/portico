<?php

use App\Enums\AddOnKind;
use App\Models\AddOn;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\SubscriptionBundleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SubscriptionBundleService;
    $this->member = Member::factory()->create(['subscription_eligible' => true]);
    $this->user = User::factory()->create();
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'credit' => null, 'effective_from' => '2026-01-01']);
});

test('resolveStart returns the desired start unchanged when nothing conflicts', function () {
    $resolution = $this->service->resolveStart($this->member, $this->entry, now()->parse('2026-07-01'), 3);

    expect($resolution->start->toDateString())->toBe('2026-07-01')
        ->and($resolution->skippedMonths)->toBeEmpty();
});

test('resolveStart shifts the whole window past a conflicting month', function () {
    // Member already has August separately covered — a July-desired 3-month
    // window (Jul-Aug-Sep) collides with it, so the whole block shifts to
    // the next fully-free window: Sep-Oct-Nov.
    $this->member->subscriptions()->create([
        'add_on_id' => $this->entry->id,
        'covered_month' => '2026-08-01',
        'amount_paid' => 60,
    ]);

    $resolution = $this->service->resolveStart($this->member, $this->entry, now()->parse('2026-07-01'), 3);

    expect($resolution->start->toDateString())->toBe('2026-09-01')
        ->and(collect($resolution->skippedMonths)->map->toDateString()->all())->toBe(['2026-08-01']);
});

test('resolveStart aborts once no free block is found within the lookahead', function () {
    for ($i = 0; $i < 40; $i++) {
        $this->member->subscriptions()->create([
            'add_on_id' => $this->entry->id,
            'covered_month' => now()->parse('2026-07-01')->addMonthsNoOverflow($i)->toDateString(),
            'amount_paid' => 60,
        ]);
    }

    expect(fn () => $this->service->resolveStart($this->member, $this->entry, now()->parse('2026-07-01'), 3))
        ->toThrow(HttpException::class);
});

test('purchase creates exactly N rows whose amount_paid sums to the plan price to the cent', function () {
    $rows = $this->service->purchase($this->member, $this->entry, 3, now()->parse('2026-07-01'), $this->user);

    expect($rows)->toHaveCount(3)
        ->and((float) $rows->sum('amount_paid'))->toEqual(175.0);

    // 175 / 3 = 58.33 repeating — the remainder cent must land somewhere, not be dropped.
    expect($rows->pluck('amount_paid')->map(fn ($a) => (float) $a)->sort()->values()->all())
        ->toBe([58.33, 58.33, 58.34]);
});

test('purchase uses the resolved start, not the desired one, when they differ', function () {
    $this->member->subscriptions()->create([
        'add_on_id' => $this->entry->id,
        'covered_month' => '2026-08-01',
        'amount_paid' => 60,
    ]);

    $rows = $this->service->purchase($this->member, $this->entry, 3, now()->parse('2026-07-01'), $this->user);

    expect($rows->pluck('covered_month')->map->toDateString()->all())
        ->toBe(['2026-09-01', '2026-10-01', '2026-11-01']);
});

test('purchase rejects a member who is not subscription-eligible', function () {
    $ineligible = Member::factory()->create(['subscription_eligible' => false]);

    expect(fn () => $this->service->purchase($ineligible, $this->entry, 3, now()->parse('2026-07-01'), $this->user))
        ->toThrow(HttpException::class);
});

test('each created row records which part of the bundle it is', function () {
    $rows = $this->service->purchase($this->member, $this->entry, 3, now()->parse('2026-07-01'), $this->user);

    expect($rows->get(0)->notes)->toContain('1 of 3')
        ->and($rows->get(1)->notes)->toContain('2 of 3')
        ->and($rows->get(2)->notes)->toContain('3 of 3')
        ->and($rows->get(0)->notes)->toContain('Jul');
});

test('purchase prices the bundle as of today, not the floored coverage month — a plan effective mid-month still prices correctly', function () {
    // Regression: a bundle plan whose effective_from falls after the 1st of
    // the current month used to fail to price at all, because purchase()
    // looked the plan up as of the (always-floored) resolved coverage
    // month rather than today, even though both resolveStart() and the
    // check-in option label already treated the plan as available.
    $this->travelTo(Carbon::parse('2026-07-15'));
    $pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
    Plan::create(['add_on_id' => $pool->id, 'duration_months' => 3, 'price' => 40, 'effective_from' => '2026-07-15']);
    $member = Member::factory()->create(['subscription_eligible' => true]);

    $rows = $this->service->purchase($member, $pool, 3, now()->startOfMonth(), $this->user);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('covered_month')->map->toDateString()->all())->toBe(['2026-07-01', '2026-08-01', '2026-09-01'])
        ->and((float) $rows->sum('amount_paid'))->toEqual(40.0);
});

test('payment_method and register_shift_id land on every created row', function () {
    $register = Register::factory()->create();
    $shift = RegisterShift::factory()->create(['register_id' => $register->id]);

    $rows = $this->service->purchase($this->member, $this->entry, 3, now()->parse('2026-07-01'), $this->user, 'cash', $shift);

    expect($rows->every(fn ($row) => $row->payment_method === 'cash' && $row->register_shift_id === $shift->id))->toBeTrue();
});
