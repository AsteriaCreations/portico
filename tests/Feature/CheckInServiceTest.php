<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CheckInRequest;
use App\Services\CheckInResult;
use App\Services\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * CheckInService called directly, with no Livewire page and nobody logged
 * in: the staff member is always the one passed in, never auth(). The desk's
 * own behavior is covered end to end in CheckInPageTest.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    $this->member = serviceMember($this->category, 'walk-in');
});

function serviceMember(Category $category, string $username, array $overrides = []): Member
{
    return Member::factory()->create([
        'category_id' => $category->id,
        'username' => $username,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        ...$overrides,
    ]);
}

function recordCheckIn(Member $member, Event $event, User $staff, CheckInRequest $request = new CheckInRequest): CheckInResult
{
    return app(CheckInService::class)->record($member, $event, $staff, $request, null);
}

test('records the entry fee plus a flat add-on, attributed to the staff member passed in', function () {
    $room = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50]);
    $this->event->addOns()->attach($room->id);

    expect(auth()->check())->toBeFalse();

    $result = recordCheckIn($this->member, $this->event, $this->manager, new CheckInRequest(addOnIds: [$room->id]));

    expect($result->addOnTotal)->toEqual(50.0)
        ->and($result->attendance->amount_paid)->toEqual(90)
        ->and($result->attendance->checked_in_by)->toBe($this->manager->id)
        ->and($result->attendance->addOns()->pluck('name')->all())->toBe(['Private room rental']);
});

test('ignores an add-on the event does not offer', function () {
    $sleepover = AddOn::factory()->create(['name' => 'Sleepover', 'price' => 25]);

    $result = recordCheckIn($this->member, $this->event, $this->manager, new CheckInRequest(addOnIds: [$sleepover->id]));

    expect($result->addOnTotal)->toEqual(0.0)
        ->and($result->attendance->amount_paid)->toEqual(40)
        ->and($result->attendance->addOns()->exists())->toBeFalse();
});

test('applies a comp only when the staff member passed in may grant one', function () {
    $reason = CompReason::factory()->create();
    $request = new CheckInRequest(compEntry: true, compReasonId: $reason->id);
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);

    $byDoor = recordCheckIn($this->member, $this->event, $door, $request);
    $byManager = recordCheckIn(serviceMember($this->category, 'house-sub'), $this->event, $this->manager, $request);

    expect($byDoor->attendance->amount_paid)->toEqual(40)
        ->and($byDoor->attendance->comp_reason_id)->toBeNull()
        ->and($byManager->attendance->amount_paid)->toEqual(0)
        ->and($byManager->attendance->comp_reason_id)->toBe($reason->id);
});

test('buys a one-month subscription that covers tonight, recorded by the staff member', function () {
    $member = serviceMember($this->category, 'subscriber', ['subscription_eligible' => true]);

    $result = recordCheckIn($member, $this->event, $this->manager, new CheckInRequest(subscriptionMonths: [$this->entry->id => 1]));

    $subscription = Subscription::where('member_id', $member->id)->sole();
    expect($result->subscriptionTotal)->toEqual(60.0)
        ->and($subscription->recorded_by)->toBe($this->manager->id)
        ->and($subscription->covered_month->toDateString())->toBe(today()->startOfMonth()->toDateString())
        ->and($result->attendance->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($result->breakdown->amountPaid)->toEqual(15.0);
});

test('ignores a subscription selection for a member who is not eligible', function () {
    $result = recordCheckIn($this->member, $this->event, $this->manager, new CheckInRequest(subscriptionMonths: [$this->entry->id => 1]));

    expect($result->subscriptionTotal)->toEqual(0.0)
        ->and(Subscription::where('member_id', $this->member->id)->exists())->toBeFalse();
});

test("draws a voucher from another member's balance, capped at the fee", function () {
    $payer = serviceMember($this->category, 'payer');
    Voucher::factory()->create(['member_id' => $payer->id, 'amount' => 100]);

    $result = recordCheckIn($this->member, $this->event, $this->manager, new CheckInRequest(
        applyVoucher: true,
        voucherPayerId: $payer->id,
        voucherAmount: 75,
        voucherReason: 'gift',
    ));

    $draw = Voucher::where('attendance_id', $result->attendance->id)->sole();
    expect($result->voucherApplied)->toEqual(40.0)
        ->and($result->attendance->amount_paid)->toEqual(0)
        ->and($draw->member_id)->toBe($payer->id)
        ->and($draw->amount)->toEqual(-40)
        ->and($draw->recorded_by)->toBe($this->manager->id);
});

test('refuses once the building is full, writing nothing', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    Attendance::factory()->for(serviceMember($this->category, 'already-in'))->for($this->event)->create(['checked_in_at' => now()]);
    $member = serviceMember($this->category, 'subscriber', ['subscription_eligible' => true]);

    expect(fn () => recordCheckIn($member, $this->event, $this->manager, new CheckInRequest(subscriptionMonths: [$this->entry->id => 1])))
        ->toThrow(CheckInRefused::class);

    expect(Attendance::where('member_id', $member->id)->exists())->toBeFalse()
        ->and(Subscription::where('member_id', $member->id)->exists())->toBeFalse();
});

test('refuses a sold-out add-on rather than dropping it, writing nothing', function () {
    $room = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50, 'max_per_night' => 1]);
    $this->event->addOns()->attach($room->id);
    recordCheckIn(serviceMember($this->category, 'first'), $this->event, $this->manager, new CheckInRequest(addOnIds: [$room->id]));

    expect(fn () => recordCheckIn($this->member, $this->event, $this->manager, new CheckInRequest(addOnIds: [$room->id])))
        ->toThrow(CheckInRefused::class, 'Private room rental sold out');

    expect(Attendance::where('member_id', $this->member->id)->exists())->toBeFalse();
});

test('ignores a check-in time for an event that is not live yet, recording a prepayment', function () {
    $future = Event::factory()->create(['event_date' => today()->addMonth()->toDateString(), 'entry_fee' => 40]);

    $result = recordCheckIn($this->member, $future, $this->manager, new CheckInRequest(checkedInAt: now()));

    expect($result->attendance->checked_in_at)->toBeNull();
});
