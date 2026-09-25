<?php

use App\Enums\AddOnCoverageSource;
use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Services\ShowrunnerPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    Plan::create([
        'add_on_id' => $entry->id,
        'price' => 60.00,
        'credit' => 25.00,
        'effective_from' => '2026-01-01',
    ]);

    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => 34, 'payout_type' => PayoutType::Voucher, 'payout_value' => 25.00]);
    ShowrunnerPayoutTier::create(['min_headcount' => 35, 'max_headcount' => 100, 'payout_type' => PayoutType::Percentage, 'payout_value' => 10.00]);
    ShowrunnerPayoutTier::create(['min_headcount' => 101, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 15.00]);

    // Shared across every bulk-created member below so tests creating
    // hundreds of attendance rows don't each spin up their own Category via
    // fake()->unique()->word() — that pool is far smaller than 9999 and
    // exhausts quickly under bulk creation.
    $this->category = Category::factory()->create();

    $this->service = new ShowrunnerPayoutService;
});

function cashAttendance(Event $event, int $count, float $entryFee = 10.0): void
{
    Attendance::factory()->for($event)->recycle(test()->category)->count($count)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::None,
        'entry_fee' => $entryFee,
        'entry_coverage' => 0,
    ]);
}

test('under 35 attendees lands in the flat voucher tier', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 34);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(34)
        ->and($result->tier->payout_type)->toBe(PayoutType::Voucher)
        ->and($result->payoutAmountCents)->toBe(2500);
});

test('35 to 100 attendees lands in the 10% tier, computed off entry revenue', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 35, entryFee: 10.0);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(35)
        ->and($result->doorTotalCents)->toBe(35000)
        ->and($result->tier->payout_value)->toEqual('10.00')
        ->and($result->payoutAmountCents)->toBe(3500);
});

test('101 attendees lands in the 15% tier', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 101, entryFee: 10.0);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(101)
        ->and($result->payoutAmountCents)->toBe(15150); // 15% of $1010
});

test('above every configured tier, the top tier rate keeps applying with no cap', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 250, entryFee: 10.0);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(250)
        ->and($result->tier->min_headcount)->toBe(101)
        ->and($result->payoutAmountCents)->toBe(37500); // 15% of $2500
});

test('subscription-covered attendees count toward headcount and door total once the entry fee exceeds the subscription credit', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 30]); // > $25 credit
    Attendance::factory()->for($event)->recycle($this->category)->count(20)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
        'entry_fee' => 30,
        'entry_coverage' => 25,
    ]);

    $result = $this->service->calculate($event);

    expect($result->includeSh)->toBeTrue()
        ->and($result->shCount)->toBe(20)
        ->and($result->headcount)->toBe(20)
        ->and($result->doorTotalCents)->toBe(10000); // 20 * (30 - 25)
});

test('subscription-covered attendees are excluded from headcount and door total when the entry fee does not exceed the subscription credit', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 20]); // <= $25 credit
    Attendance::factory()->for($event)->recycle($this->category)->count(20)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
        'entry_fee' => 20,
        'entry_coverage' => 20,
    ]);

    $result = $this->service->calculate($event);

    expect($result->includeSh)->toBeFalse()
        ->and($result->shCount)->toBe(20) // still reported for display
        ->and($result->headcount)->toBe(0)
        ->and($result->doorTotalCents)->toBe(0);
});

test('comped, event-comp, and host entries never count toward headcount or door revenue', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 40, entryFee: 10.0);
    Attendance::factory()->for($event)->recycle($this->category)->count(100)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_fee' => 10,
        'entry_coverage' => 10,
    ]);
    Attendance::factory()->for($event)->recycle($this->category)->count(5)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::Host,
        'entry_fee' => 10,
        'entry_coverage' => 10,
    ]);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(40)
        ->and($result->doorTotalCents)->toBe(40000);
});

test('pool and add-on revenue only count toward the door total when their settings toggle is on', function () {
    $pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10, 'pool_fee' => 5]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::None,
        'entry_fee' => 10,
        'entry_coverage' => 0,
    ]);
    AttendanceAddOn::factory()->for($attendance, 'attendance')->create([
        'add_on_id' => $pool->id,
        'name' => AddOn::POOL_NAME,
        'price' => 5,
        'fee' => 5,
        'coverage' => 0,
        'covered_by' => AddOnCoverageSource::None,
    ]);
    AttendanceAddOn::factory()->for($attendance, 'attendance')->create(['price' => 30]);

    $offResult = $this->service->calculate($event);
    expect($offResult->doorTotalCents)->toBe(1000)
        ->and($offResult->poolRevenueCents)->toBe(500)
        ->and($offResult->addonRevenueCents)->toBe(3000);

    MembershipSetting::current()->update(['showrunner_door_includes_pool' => true, 'showrunner_door_includes_addons' => true]);

    $onResult = $this->service->calculate($event);
    expect($onResult->doorTotalCents)->toBe(4500); // 10 entry + 5 pool + 30 add-on
});

test('a percentage payout that lands on a fraction of a cent is rounded to the cent', function () {
    ShowrunnerPayoutTier::query()->delete();
    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 33.33]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 100.10]);
    cashAttendance($event, 1, entryFee: 100.10);

    $result = $this->service->calculate($event);

    expect($result->doorTotalCents)->toBe(10010)
        ->and($result->payoutAmountCents)->toBe(3336); // 33.33% of $100.10 is $33.363333
});

test('a percentage payout on an exact half cent rounds up', function () {
    ShowrunnerPayoutTier::query()->delete();
    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 15.00]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10.30]);
    cashAttendance($event, 1, entryFee: 10.30);

    $result = $this->service->calculate($event);

    expect($result->payoutAmountCents)->toBe(155); // 15% of $10.30 is $1.545; half-even would pay $1.54
});

test('no matching tier reports a null payout rather than guessing', function () {
    ShowrunnerPayoutTier::query()->delete();
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 5);

    $result = $this->service->calculate($event);

    expect($result->tier)->toBeNull()
        ->and($result->payoutAmountCents)->toBeNull();
});
