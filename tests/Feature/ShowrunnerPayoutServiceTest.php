<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Services\ShowrunnerPayoutBand;
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

    // The house rule: a $25 voucher up to 35 attendees, then 10% of the
    // entry fee per attendee 36-100 and 15% per attendee from 101 on.
    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => 35, 'payout_type' => PayoutType::Voucher, 'payout_value' => 25.00]);
    ShowrunnerPayoutTier::create(['min_headcount' => 36, 'max_headcount' => 100, 'payout_type' => PayoutType::Percentage, 'payout_value' => 10.00]);
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

/**
 * @return list<array{int, int}> [attendees, cents] per paying band
 */
function bandSummary(array $bands): array
{
    return array_map(fn (ShowrunnerPayoutBand $band): array => [$band->attendees, $band->amountCents], $bands);
}

test('up to 35 attendees earns the flat voucher, including exactly 35', function (int $count) {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, $count);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe($count)
        ->and($result->voucherTier?->payout_type)->toBe(PayoutType::Voucher)
        ->and($result->bands)->toBe([])
        ->and($result->payoutAmountCents)->toBe(2500);
})->with([1, 34, 35]);

test('the 36th attendee is the first to earn 10% of the entry fee', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 20]);
    cashAttendance($event, 36, entryFee: 20.0);

    $result = $this->service->calculate($event);

    expect($result->voucherTier)->toBeNull()
        ->and(bandSummary($result->bands))->toBe([[1, 200]]) // 1 × $20 × 10%
        ->and($result->payoutAmountCents)->toBe(200);
});

test('100 attendees: 65 of them at 10%', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 20]);
    cashAttendance($event, 100, entryFee: 20.0);

    $result = $this->service->calculate($event);

    expect(bandSummary($result->bands))->toBe([[65, 13000]]) // 65 × $20 × 10%
        ->and($result->payoutAmountCents)->toBe(13000);
});

test('above 100 the 10% band stays capped at 65 attendees and the rest earn 15%', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 20]);
    cashAttendance($event, 150, entryFee: 20.0);

    $result = $this->service->calculate($event);

    expect(bandSummary($result->bands))->toBe([[65, 13000], [50, 15000]]) // 65 × $20 × 10%, 50 × $20 × 15%
        ->and($result->payoutAmountCents)->toBe(28000);
});

test("the payout uses the event's listed entry fee, not what each attendee actually paid", function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 30]); // > $25 credit, so SH counts
    cashAttendance($event, 30, entryFee: 30.0);
    Attendance::factory()->for($event)->recycle($this->category)->count(10)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
        'entry_fee' => 30,
        'entry_coverage' => 25, // paid only $5 each
    ]);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(40)
        ->and(bandSummary($result->bands))->toBe([[5, 1500]]); // attendees 36-40 × $30 × 10%
});

test('subscription-covered attendees count toward the headcount once the entry fee exceeds the subscription credit', function () {
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
        ->and($result->headcount)->toBe(20);
});

test('subscription-covered attendees are left out of the headcount when the entry fee does not exceed the subscription credit', function () {
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
        ->and($result->headcount)->toBe(0);
});

test('staff (a comped category), per-event comps and the host never count toward the headcount', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 40, entryFee: 10.0);
    foreach ([EntryCoverageSource::Comp, EntryCoverageSource::EventComp, EntryCoverageSource::Host] as $source) {
        Attendance::factory()->for($event)->recycle($this->category)->count(30)->create([
            'checked_in_at' => now(),
            'entry_covered_by' => $source,
            'entry_fee' => 10,
            'entry_coverage' => 10,
        ]);
    }

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(40)
        ->and(bandSummary($result->bands))->toBe([[5, 500]]);
});

test('prepaid attendees who never arrived do not count', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 36);
    Attendance::factory()->for($event)->recycle($this->category)->count(10)->create([
        'checked_in_at' => null,
        'entry_covered_by' => EntryCoverageSource::None,
        'entry_fee' => 10,
        'entry_coverage' => 0,
    ]);

    expect($this->service->calculate($event)->headcount)->toBe(36);
});

test('pool and add-on money play no part in the payout', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10, 'pool_fee' => 5]);
    cashAttendance($event, 36);
    AttendanceAddOn::factory()->for(Attendance::first(), 'attendance')->create(['price' => 300]);

    expect($this->service->calculate($event)->payoutAmountCents)->toBe(100); // 1 × $10 × 10%
});

test("each band's share is rounded to the cent, half up", function () {
    ShowrunnerPayoutTier::query()->delete();
    ShowrunnerPayoutTier::create(['min_headcount' => 1, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 15.00]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10.30]);
    cashAttendance($event, 1, entryFee: 10.30);

    expect($this->service->calculate($event)->payoutAmountCents)->toBe(155); // 15% of $10.30 is $1.545
});

test('a headcount that reaches no paying tier pays $0', function () {
    ShowrunnerPayoutTier::where('payout_type', PayoutType::Voucher)->delete();
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 20);

    $result = $this->service->calculate($event);

    expect($result->bands)->toBe([])
        ->and($result->payoutAmountCents)->toBe(0);
});

test('no tiers configured reports a null payout rather than guessing', function () {
    ShowrunnerPayoutTier::query()->delete();
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10]);
    cashAttendance($event, 5);

    expect($this->service->calculate($event)->payoutAmountCents)->toBeNull();
});

test('worked example from the club: 39 paid at the door, $975 door, pays $10', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 25]); // = the $25 credit, so SH wouldn't count
    cashAttendance($event, 39, entryFee: 25.0);

    $result = $this->service->calculate($event);

    expect($result->headcount)->toBe(39)
        ->and(bandSummary($result->bands))->toBe([[4, 1000]]) // (39 - 35) × $25 × 10%
        ->and($result->payoutAmountCents)->toBe(1000);
});

test('legacy-imported subscription visits count like live ones once the fee exceeds the credit', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 30]); // > $25 credit
    cashAttendance($event, 30, entryFee: 30.0);
    Attendance::factory()->for($event)->recycle($this->category)->count(10)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::LegacyImport,
        'entry_fee' => 30,
        'entry_coverage' => 30,
    ]);

    $result = $this->service->calculate($event);

    expect($result->shCount)->toBe(10)
        ->and($result->headcount)->toBe(40);
});

test('legacy 0.01 comps (imported as comped entries) never count', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 30]);
    cashAttendance($event, 36, entryFee: 30.0);
    Attendance::factory()->for($event)->recycle($this->category)->count(20)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::Comp,
        'entry_fee' => 30,
        'entry_coverage' => 30,
        'amount_paid' => 0,
    ]);

    expect($this->service->calculate($event)->headcount)->toBe(36);
});
