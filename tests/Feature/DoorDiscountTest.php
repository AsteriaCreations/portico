<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\DoorDiscounts\Pages\ListDoorDiscounts;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceDiscount;
use App\Models\Category;
use App\Models\DoorDiscount;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Services\EntryCorrectionService;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->door);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $this->food = DoorDiscount::factory()->create(['name' => 'Food donation', 'amount' => 5]);
});

function discountMember(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ], $overrides));
}

function discountEvent(int|float $entryFee): Event
{
    return Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => $entryFee, 'pool_fee' => 0]);
}

function checkInWithDiscount(Member $member, Event $event, ?int $discountId, array $pricing = []): void
{
    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['door_discount_id' => $discountId, ...$pricing], 'pricingForm')
        ->callAction('checkIn', data: ['payment_method' => 'comp', 'checked_in_at' => now()])
        ->assertHasNoActionErrors();
}

test('Door takes a food donation discount off entry, and the visit records its name and amount', function () {
    $member = discountMember($this->irregular);
    $event = discountEvent(20);

    checkInWithDiscount($member, $event, $this->food->id);

    $attendance = Attendance::where('member_id', $member->id)->sole();
    expect((float) $attendance->amount_paid)->toBe(15.0)
        ->and($attendance->discount->name)->toBe('Food donation')
        ->and((float) $attendance->discount->amount)->toBe(5.0)
        ->and($attendance->discount->door_discount_id)->toBe($this->food->id);
});

test('the Due line includes the discount before Check in is clicked', function () {
    $member = discountMember($this->irregular);
    $event = discountEvent(20);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertSee('Due: $20.00')
        ->fillForm(['door_discount_id' => $this->food->id], 'pricingForm');

    expect($component->instance()->getLiveDueTotal())->toBe(15.0);
    $component->assertSee('Due: $15.00');
});

test('it comes off the entry still owed after subscription credit, and a covered entry gets nothing off', function () {
    $member = discountMember($this->irregular, ['subscription_eligible' => true]);
    Subscription::create([
        'member_id' => $member->id,
        'add_on_id' => $this->entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
        'amount_paid' => 60,
        'paid_on' => now(),
    ]);

    // $40 less the $25 credit = $15 owed, less $5.
    $partial = discountEvent(40);
    checkInWithDiscount($member, $partial, $this->food->id);
    expect((float) Attendance::where('event_id', $partial->id)->sole()->amount_paid)->toBe(10.0);

    // $20 is fully covered: nothing off, and no discount recorded.
    $covered = discountEvent(20);
    checkInWithDiscount($member, $covered, $this->food->id);
    $attendance = Attendance::where('event_id', $covered->id)->sole();
    expect((float) $attendance->amount_paid)->toBe(0.0)
        ->and($attendance->discount)->toBeNull();
});

test('it never takes entry below zero: only what was owed is recorded', function () {
    $breakdown = new PriceBreakdown(
        entryFeeCents: 300,
        entryCoverageCents: 0,
        entryCoveredBy: EntryCoverageSource::None,
        addOnLines: [],
        voucherCoverageCents: 0,
        amountPaidCents: 1800, // $3 entry plus $15 pool
    );

    expect(app(PricingService::class)->entryDiscountCents($breakdown, 500))->toBe(300);
});

test('a voucher covers what is left after the discount', function () {
    $member = discountMember($this->irregular);
    Voucher::create(['member_id' => $member->id, 'amount' => 50, 'reason' => 'test', 'recorded_by' => $this->door->id]);
    $event = discountEvent(20);

    checkInWithDiscount($member, $event, $this->food->id, ['apply_voucher' => true, 'voucher_amount' => 15, 'voucher_reason' => 'test']);

    $attendance = Attendance::where('member_id', $member->id)->sole();
    expect((float) $attendance->amount_paid)->toBe(0.0)
        ->and((float) $attendance->voucher_coverage)->toBe(15.0)
        ->and((float) $attendance->discount->amount)->toBe(5.0)
        ->and((float) $member->fresh()->voucherBalance())->toBe(35.0);
});

test('an inactive discount is not offered and a forged one is ignored', function () {
    $this->food->update(['active' => false]);
    $member = discountMember($this->irregular);
    $event = discountEvent(20);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertFormFieldHidden('door_discount_id', 'pricingForm')
        ->set('pricingData.door_discount_id', $this->food->id)
        ->callAction('checkIn', data: ['payment_method' => 'comp', 'checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->sole();
    expect((float) $attendance->amount_paid)->toBe(20.0)
        ->and(AttendanceDiscount::count())->toBe(0);
});

test('renaming a discount later leaves past visits as they were', function () {
    $member = discountMember($this->irregular);
    checkInWithDiscount($member, discountEvent(20), $this->food->id);

    $this->food->update(['name' => 'Canned goods', 'amount' => 3]);

    $discount = Attendance::where('member_id', $member->id)->sole()->discount;
    expect($discount->name)->toBe('Food donation')
        ->and((float) $discount->amount)->toBe(5.0);
});

test('Door and above may apply a discount; Volunteer may not', function () {
    expect(Gate::forUser(User::factory()->create(['role' => Role::Door]))->allows('apply-door-discount'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create(['role' => Role::Owner]))->allows('apply-door-discount'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create(['role' => Role::Volunteer]))->allows('apply-door-discount'))->toBeFalse();
});

test('Manager+ manages the discounts list; Door cannot', function () {
    Livewire::test(ListDoorDiscounts::class)->assertForbidden();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    Livewire::test(ListDoorDiscounts::class)->assertOk()->assertCanSeeTableRecords([$this->food]);
});

test('a discounted entry cannot be converted to a subscription', function () {
    $member = discountMember($this->irregular, ['subscription_eligible' => true]);
    checkInWithDiscount($member, discountEvent(20), $this->food->id);

    expect(app(EntryCorrectionService::class)->refusalReason(Attendance::where('member_id', $member->id)->sole()))
        ->toBe('Only a plain paid entry can be converted.');
});
