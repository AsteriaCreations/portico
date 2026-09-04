<?php

use App\Enums\AddOnCoverageSource;
use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\AddOnPriceLine;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    Plan::create([
        'add_on_id' => $this->entry->id,
        'price' => 60.00,
        'credit' => 25.00,
        'effective_from' => '2026-01-01',
    ]);

    Plan::create([
        'add_on_id' => $this->pool->id,
        'price' => 15.00,
        'credit' => null,
        'effective_from' => '2026-01-01',
    ]);

    $this->pricing = new PricingService;
});

function subscribe(Member $member, AddOn $addOn, string $eventDate): void
{
    $member->subscriptions()->create([
        'add_on_id' => $addOn->id,
        'covered_month' => Carbon::parse($eventDate)->startOfMonth()->toDateString(),
        'amount_paid' => 0,
    ]);
}

/**
 * Pool is the only subscribable add-on in these tests, so its line (if any)
 * is the whole addOnLines array.
 */
function poolLine(PriceBreakdown $breakdown): ?AddOnPriceLine
{
    return $breakdown->addOnLines[0] ?? null;
}

test('a comped category owes nothing on any event', function () {
    $category = Category::factory()->create(['is_comped' => true]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 8, 'pool_fee' => 5]);

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->amountPaid)->toBe(0.0)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::Comp)
        ->and($breakdown->entryCoverage)->toBe(8.0)
        ->and(poolLine($breakdown)->coveredBy)->toBe(AddOnCoverageSource::Comp)
        ->and(poolLine($breakdown)->coverage)->toBe(5.0);
});

test('entry-only event, regular subscription covers up to the credit', function (float $entryFee, float $expectedDue) {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => $entryFee, 'pool_fee' => 0]);
    subscribe($member, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->amountPaid)->toBe($expectedDue)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::RegularSubscription);
})->with([
    'on $20' => [20.0, 0.0],
    'on $40' => [40.0, 15.0],
    'on $100' => [100.0, 75.0],
]);

test('a member covered by a 3-month regular bundle still only gets the ordinary monthly credit per visit, not full coverage', function () {
    // The bundle row itself: higher price, no credit of its own — present in
    // the DB purely to prove PricingService ignores it and keeps sourcing
    // the per-visit credit from the duration-1 row below.
    Plan::create([
        'add_on_id' => $this->entry->id,
        'duration_months' => 3,
        'price' => 175.00,
        'credit' => null,
        'effective_from' => '2026-01-01',
    ]);

    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);

    // Materializes the same as SubscriptionBundleService::purchase() would
    // leave behind for July, whether the member bought 1 month or 3.
    subscribe($member, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($breakdown->entryCoverage)->toBe(25.0)
        ->and($breakdown->amountPaid)->toBe(15.0);
});

test('entry-only event, no subscription owes the base fee', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->amountPaid)->toBe(40.0)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::None);
});

test('entry plus pool event pricing matrix', function (bool $hasRegular, bool $hasPool, float $expectedDue) {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 8, 'pool_fee' => 5]);

    if ($hasRegular) {
        subscribe($member, $this->entry, '2026-07-19');
    }
    if ($hasPool) {
        subscribe($member, $this->pool, '2026-07-19');
    }

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->amountPaid)->toBe($expectedDue);
})->with([
    'both subs' => [true, true, 0.0],
    'regular only' => [true, false, 5.0],
    'pool only' => [false, true, 8.0],
    'nothing' => [false, false, 13.0],
]);

test('pool-only event pricing matrix', function (bool $hasRegular, bool $hasPool, float $expectedDue) {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 0, 'pool_fee' => 5]);

    if ($hasRegular) {
        subscribe($member, $this->entry, '2026-07-19');
    }
    if ($hasPool) {
        subscribe($member, $this->pool, '2026-07-19');
    }

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->amountPaid)->toBe($expectedDue)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::None);
})->with([
    'pool subscription' => [false, true, 0.0],
    'no sub' => [false, false, 5.0],
    'regular subscription only does nothing' => [true, false, 5.0],
]);

test('a pool day pass fully covers pool at the event it was bought for, leaving entry priced independently', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 8, 'pool_fee' => 5]);
    $member->addOnDayPasses()->create(['event_id' => $event->id, 'add_on_id' => $this->pool->id, 'amount_paid' => 5, 'payment_method' => 'cash', 'recorded_by' => User::factory()->create()->id]);

    $breakdown = $this->pricing->price($member, $event);

    expect(poolLine($breakdown)->coveredBy)->toBe(AddOnCoverageSource::DayPass)
        ->and(poolLine($breakdown)->coverage)->toBe(5.0)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::None)
        ->and($breakdown->amountPaid)->toBe(8.0);
});

test('a pool day pass does not cover a different event for the same member', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $paidForEvent = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 0, 'pool_fee' => 5]);
    $otherEvent = Event::factory()->create(['event_date' => '2026-07-20', 'entry_fee' => 0, 'pool_fee' => 5]);
    $member->addOnDayPasses()->create(['event_id' => $paidForEvent->id, 'add_on_id' => $this->pool->id, 'amount_paid' => 5, 'payment_method' => 'cash', 'recorded_by' => User::factory()->create()->id]);

    $breakdown = $this->pricing->price($member, $otherEvent);

    expect(poolLine($breakdown)->coveredBy)->toBe(AddOnCoverageSource::None)
        ->and($breakdown->amountPaid)->toBe(5.0);
});

test('a pool day pass takes precedence over an active pool subscription in the reported coverage source', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 0, 'pool_fee' => 5]);
    subscribe($member, $this->pool, '2026-07-19');
    $member->addOnDayPasses()->create(['event_id' => $event->id, 'add_on_id' => $this->pool->id, 'amount_paid' => 5, 'payment_method' => 'cash', 'recorded_by' => User::factory()->create()->id]);

    $breakdown = $this->pricing->price($member, $event);

    expect(poolLine($breakdown)->coveredBy)->toBe(AddOnCoverageSource::DayPass)
        ->and($breakdown->amountPaid)->toBe(0.0);
});

test('breakdown maps cleanly onto attendance snapshot columns, entry and add-on lines separately', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 8, 'pool_fee' => 5]);
    subscribe($member, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->toAttendanceAttributes())->toBe([
        'entry_fee' => 8.0,
        'entry_coverage' => 8.0,
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
        'voucher_coverage' => 0.0,
        'amount_paid' => 5.0,
    ]);

    expect($breakdown->addOnAttendanceRows())->toBe([
        [
            'add_on_id' => $this->pool->id,
            'name' => AddOn::POOL_NAME,
            'price' => 5.0,
            'fee' => 5.0,
            'coverage' => 0.0,
            'covered_by' => AddOnCoverageSource::None,
            'is_overnight' => false,
        ],
    ]);
});

test('an event with no pool fee produces no add-on line at all', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 8, 'pool_fee' => 0]);

    $breakdown = $this->pricing->price($member, $event);

    expect($breakdown->addOnLines)->toBe([]);
});

test('the event host owes nothing on entry, pool priced independently', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $host = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 5, 'host_id' => $host->id]);

    $breakdown = $this->pricing->price($host, $event);

    expect($breakdown->entryCoverage)->toBe(40.0)
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::Host)
        ->and(poolLine($breakdown)->coverage)->toBe(0.0)
        ->and(poolLine($breakdown)->coveredBy)->toBe(AddOnCoverageSource::None)
        ->and($breakdown->amountPaid)->toBe(5.0);
});

test('being hosted for one event does not comp entry at a different event', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $host = Member::factory()->create(['category_id' => $category->id]);
    Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'host_id' => $host->id]);
    $otherEvent = Event::factory()->create(['event_date' => '2026-07-20', 'entry_fee' => 40]);

    $breakdown = $this->pricing->price($host, $otherEvent);

    expect($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::None)
        ->and($breakdown->amountPaid)->toBe(40.0);
});

test('host coverage is reported even when the host also has an active regular subscription', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $host = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'host_id' => $host->id]);
    subscribe($host, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($host, $event);

    expect($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::Host)
        ->and($breakdown->amountPaid)->toBe(0.0);
});

test('applyVoucher covers the remainder up to what is still due', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);
    subscribe($member, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($member, $event); // $15 due after the $25 subscription credit
    $result = $this->pricing->applyVoucher($breakdown, availableBalance: 100.0, requestedAmount: 15.0);

    expect($result->voucherCoverage)->toBe(15.0)
        ->and($result->amountPaid)->toBe(0.0);
});

test('applyVoucher is capped at the available balance', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);

    $breakdown = $this->pricing->price($member, $event); // $40 due, no subscription
    $result = $this->pricing->applyVoucher($breakdown, availableBalance: 25.0, requestedAmount: 40.0);

    expect($result->voucherCoverage)->toBe(25.0)
        ->and($result->amountPaid)->toBe(15.0);
});

test('applyVoucher is capped at what is due, never overpays', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10, 'pool_fee' => 0]);

    $breakdown = $this->pricing->price($member, $event); // $10 due
    $result = $this->pricing->applyVoucher($breakdown, availableBalance: 100.0, requestedAmount: 50.0);

    expect($result->voucherCoverage)->toBe(10.0)
        ->and($result->amountPaid)->toBe(0.0);
});

test('applyEventComp waives entry regardless of what price() already covered, leaving pool untouched', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 5]);

    $breakdown = $this->pricing->price($member, $event); // $45 due, no subscription
    $result = $this->pricing->applyEventComp($breakdown);

    expect($result->entryCoverage)->toBe(40.0)
        ->and($result->entryCoveredBy)->toBe(EntryCoverageSource::EventComp)
        ->and(poolLine($result)->fee)->toBe(5.0)
        ->and(poolLine($result)->coverage)->toBe(0.0)
        ->and(poolLine($result)->coveredBy)->toBe(AddOnCoverageSource::None)
        ->and($result->amountPaid)->toBe(5.0);
});

test('applyEventComp overrides an existing regular subscription entry coverage', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);
    subscribe($member, $this->entry, '2026-07-19');

    $breakdown = $this->pricing->price($member, $event); // $15 due after the $25 subscription credit
    $result = $this->pricing->applyEventComp($breakdown);

    expect($result->entryCoverage)->toBe(40.0)
        ->and($result->entryCoveredBy)->toBe(EntryCoverageSource::EventComp)
        ->and($result->amountPaid)->toBe(0.0);
});

test('applyVoucher allows a partial amount, leaving the remainder due', function () {
    $category = Category::factory()->create(['is_comped' => false]);
    $member = Member::factory()->create(['category_id' => $category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);

    $breakdown = $this->pricing->price($member, $event); // $40 due
    $result = $this->pricing->applyVoucher($breakdown, availableBalance: 100.0, requestedAmount: 10.0);

    expect($result->voucherCoverage)->toBe(10.0)
        ->and($result->amountPaid)->toBe(30.0);
});
