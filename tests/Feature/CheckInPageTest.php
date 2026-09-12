<?php

use App\Enums\AddOnCoverageSource;
use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Widgets\RecordDeparturesWidget;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\BanException;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\Register;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Services\RegisterShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->prospective = Category::factory()->create(['name' => 'Prospective', 'is_comped' => false]);
    $this->guest = Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->user);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->pool->id, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);
});

/**
 * Pool is the only subscribable add-on in these tests, so its
 * attendance_add_ons row (if any) is the whole relation.
 */
function poolAddOnRow(Attendance $attendance): ?AttendanceAddOn
{
    return $attendance->addOns()->first();
}

function clearMember(Category $category, array $overrides = []): Member
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

test('the check-in page renders for an active user', function () {
    Livewire::test(CheckIn::class)->assertSuccessful();
});

test('the member field renders before the event field', function () {
    $html = Livewire::test(CheckIn::class)->html();

    $memberPos = strpos($html, 'data.member_id');
    $eventPos = strpos($html, 'data.event_id');

    expect($memberPos)->not->toBeFalse()
        ->and($eventPos)->not->toBeFalse()
        ->and($memberPos)->toBeLessThan($eventPos);
});

test('the live price breakdown updates as a subscription is selected, without checking in or purchasing anything', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id]);

    expect($component->instance()->getLivePriceBreakdown()->amountPaid)->toEqual(40.0);

    $component->fillForm(['subscription_regular_duration' => '1'], 'pricingForm');

    expect($component->instance()->getLivePriceBreakdown()->amountPaid)->toEqual(15.0);

    expect(Attendance::where('member_id', $member->id)->exists())->toBeFalse()
        ->and(Subscription::where('member_id', $member->id)->exists())->toBeFalse();
});

test('add_on_ids is always seeded as a real array, never an absent key', function () {
    // Regression guard for a live bug: Alpine/Livewire's checkbox-group
    // binding (getInputValue() in livewire.js) only concats into an array
    // on the *first* click when the current bound value already
    // Array.isArray()'s true -- if add_on_ids is absent/null at that point,
    // it instead writes the click's own boolean `checked` state as the
    // whole property, which then forces every sibling checkbox sharing that
    // wire:model to render checked too. Pest's fillForm()/set() can't
    // reproduce the browser-side mechanism itself, but this at least locks
    // in the one thing that prevents it: the property must never start (or
    // reset to) a bare [].
    $freshComponent = Livewire::test(CheckIn::class);
    expect($freshComponent->instance()->pricingData)->toBe(['add_on_ids' => []]);

    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id]);

    expect($component->instance()->pricingData)->toBe(['add_on_ids' => []]);
});

test('pricingData resets when the member changes', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $otherMember = clearMember($this->irregular, ['username' => 'someone-else']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm');

    expect($component->instance()->pricingData['subscription_regular_duration'] ?? null)->toBe('1');

    $component->set('data.member_id', $otherMember->id);

    // Not a bare [] -- add_on_ids must always reset as a real array, never
    // an absent key, or the very next add-on click corrupts it to a scalar
    // via Alpine/Livewire's checkbox-group binding (see the property's own
    // comment in CheckIn.php).
    expect($component->instance()->pricingData)->toBe(['add_on_ids' => []]);
});

test('pricingData resets when the event changes', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    $otherEvent = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm');

    expect($component->instance()->pricingData['subscription_regular_duration'] ?? null)->toBe('1');

    $component->set('data.event_id', $otherEvent->id);

    // Not a bare [] -- add_on_ids must always reset as a real array, never
    // an absent key, or the very next add-on click corrupts it to a scalar
    // via Alpine/Livewire's checkbox-group binding (see the property's own
    // comment in CheckIn.php).
    expect($component->instance()->pricingData)->toBe(['add_on_ids' => []]);
});

test('pricingData resets after a successful check-in', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    Voucher::factory()->for($member)->create(['amount' => 10, 'recorded_by' => $this->user->id]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['apply_voucher' => true, 'voucher_amount' => 10, 'voucher_reason' => 'test'], 'pricingForm')
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    // Not a bare [] -- add_on_ids must always reset as a real array, never
    // an absent key, or the very next add-on click corrupts it to a scalar
    // via Alpine/Livewire's checkbox-group binding (see the property's own
    // comment in CheckIn.php).
    expect($component->instance()->pricingData)->toBe(['add_on_ids' => []]);
});

test('the action-modals placeholder renders even with no event selected, so action clicks actually show a modal', function () {
    // Regression test: Filament's own page layout skips <x-filament-actions::modals />
    // for any HasTable page, assuming the table's own view provides it instead. This
    // page's table only renders inside "@if ($event)", so with no event selected,
    // neither copy existed anywhere in the HTML -- every action (Open Box, checkIn, ...)
    // would mount successfully server-side but never visibly show a modal, since nothing
    // was listening for the open-modal event client-side. See check-in.blade.php's own
    // unconditional <x-filament-actions::modals /> near the top for the fix.
    $html = Livewire::test(CheckIn::class)->html();

    expect($html)->toContain('wire:partial="action-modals"');
});

test('member status shows immediately once a member is selected, with no event picked', function () {
    $member = clearMember($this->irregular, [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
        'on_watchlist' => true,
        'watchlist_reason' => 'Watchlist reason',
    ]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertSee($member->displayName())
        ->assertSee('Banned')
        ->assertSee('On watchlist')
        ->assertSee('Watchlist reason')
        ->assertSee('Not yet subscription-eligible');
});

test('the check-in desk display name setting controls what the member header shows', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'username']);
    $member = clearMember($this->irregular, ['username' => 'jsmith99', 'preferred_name' => 'Johnny']);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertSee('jsmith99')
        ->assertDontSee('Johnny');
});

test('a deceased member shows a status flag with no event picked', function () {
    $member = clearMember($this->irregular, ['is_deceased' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertSee('Deceased');
});

test('the watchlist reason on the member status card is hidden from a door volunteer', function () {
    $member = clearMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'See manager first']);
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertSee('On watchlist')
        ->assertDontSee('See manager first');
});

test('the status line reads "Ready to admit" for a clear member at an event', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id, 'event_id' => $event->id])
        ->assertSee('Ready to admit');
});

test('the status line reads "Check ID" for an under-21 member at an event', function () {
    $member = clearMember($this->irregular, ['dob' => now()->subYears(19)->toDateString()]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id, 'event_id' => $event->id])
        ->assertSee('Check ID');
});

test('the status line refreshes when the selected member changes, without any other action', function () {
    $clear = clearMember($this->irregular);
    $banned = clearMember($this->irregular, ['username' => 'banned-one', 'is_banned' => true, 'ban_reason' => 'x']);

    $component = Livewire::test(CheckIn::class)->set('data.member_id', $clear->id);
    $component->assertSee('No flags yet')->assertDontSee('Do not admit');

    // Just the member select changing -- no event picked, no check-in.
    $component->set('data.member_id', $banned->id);
    $component->assertSee('Do not admit')->assertDontSee('No flags yet');

    // The strip's wire:key must move with the member, or morphdom leaves the
    // previous verdict on screen (the bug this guards).
    expect($component->html())->toContain('status-strip-'.$banned->id.'-');
});

test('the save and promote action is visible for an incomplete Prospective member, with no event picked', function () {
    $member = clearMember($this->prospective, ['first_name' => null, 'last_name' => null, 'email' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertActionVisible('saveAndPromote');
});

test('the buy subscription action is visible once a member is subscription-eligible, with no event picked', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertActionVisible('purchaseSubscription');
});

test('the buy subscription action is hidden for a member who is not yet subscription-eligible', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertActionHidden('purchaseSubscription');
});

test('buying a subscription standalone creates a real subscription immediately, independent of any event', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->entry->id,
            'desired_start' => now()->startOfMonth(),
            'duration_months' => '1',
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    expect($subscription->amount_paid)->toEqual(60)
        ->and($subscription->recorded_by)->toBe($this->user->id)
        ->and($subscription->payment_method)->toBe('other');
});

test('a door volunteer can also buy a subscription standalone', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $member = clearMember($this->irregular, ['subscription_eligible' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->entry->id,
            'desired_start' => now()->startOfMonth(),
            'duration_months' => '1',
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->exists())->toBeTrue();
});

test('buying a pool subscription standalone is rejected once pool_enabled is off, even via a forged plan_type', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);

    // purchaseSubscriptionAction itself stays visible (a member could still
    // buy Regular) -- only the plan_type Select's own options are filtered.
    // A forged 'pool' value must still be rejected server-side by the
    // closure's own abort_unless(). Livewire's test harness converts the
    // resulting HttpException into a response rather than re-throwing it,
    // so the DB state -- not a caught exception -- is what proves the fix.
    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->pool->id,
            'desired_start' => now()->startOfMonth(),
            'duration_months' => '1',
            'payment_method' => 'other',
        ]);

    expect(Subscription::where('member_id', $member->id)->where('add_on_id', $this->pool->id)->exists())->toBeFalse();
});

test('buying a pool day pass is rejected once pool_enabled is off, even via a forged action call', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'pool_fee' => 15]);

    // purchaseAddOnDayPassAction is hidden when disabled -- callAction()
    // would refuse to call a hidden action itself (asserts visibility as a
    // test-authoring nicety, not a security boundary), so mountAction()/
    // callMountedAction() skip that assertion, the same way a forged
    // Livewire request would, proving the closure's own abort_unless is
    // what actually stops this.
    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->mountAction('purchaseAddOnDayPass')
        ->fillForm(['event_id' => $event->id, 'payment_method' => 'other'])
        ->callMountedAction();

    expect(AddOnDayPass::where('member_id', $member->id)->exists())->toBeFalse();
});

test('a forged pool subscription selection at check-in is silently ignored once pool_enabled is off', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 15]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->set("pricingData.subscription_addon_{$this->pool->id}_duration", '1')
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->where('add_on_id', $this->pool->id)->exists())->toBeFalse();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    // pool_enabled only stops NEW Pool commitments -- the event still has a
    // nonzero pool_fee, so it's still priced (and uncovered, since no
    // subscription/day-pass exists) exactly as before Pool was an add-on.
    expect(poolAddOnRow($attendance)->coverage)->toEqual(0)
        ->and($attendance->amount_paid)->toEqual(35); // 20 entry + 15 pool, unbought
});

test('a member with a real, pre-existing pool subscription still gets pool coverage after pool_enabled is turned off', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 0, 'pool_fee' => 15]);
    $member->subscriptions()->create([
        'add_on_id' => $this->pool->id,
        'covered_month' => now()->startOfMonth(),
        'amount_paid' => 15,
    ]);

    MembershipSetting::current()->update(['pool_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect(poolAddOnRow($attendance)->covered_by)->toBe(AddOnCoverageSource::Subscription)
        ->and($attendance->amount_paid)->toEqual(0);
});

test('buying a subscription standalone, then checking in that night, shows the entry as already covered', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->entry->id,
            'desired_start' => now()->startOfMonth(),
            'duration_months' => '1',
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    $component->fillForm(['event_id' => $event->id]);

    expect($component->instance()->getPriceBreakdown()->amountPaid)->toEqual(15.0);

    $component->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->count())->toBe(1);

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($attendance->amount_paid)->toEqual(15.0);
});

test('a 1-month subscription purchase at check-in is priced as of today, not the event\'s date', function () {
    // Regression: checkInAction's subscription loop used to price the 1-month case
    // off the event's date (Plan::currentFor($planType, $event->event_date))
    // instead of today, unlike SubscriptionBundleService::purchase(), which
    // is explicitly always priced as of today since it's a payment happening
    // now regardless of which (possibly future, door-prepay) event is
    // selected.
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 75, 'credit' => 25, 'effective_from' => now()->toDateString()]);

    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => '2026-01-05', 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    expect($subscription->amount_paid)->toEqual(75.0);
});

test('checking in a clear member creates a priced attendance row', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    $register = Register::factory()->create();
    app(RegisterShiftService::class)->openShift($register, $this->user, 0);

    Livewire::test(CheckIn::class)
        ->set('registerId', $register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'cash',
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();

    expect($attendance->entry_fee)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(40)
        ->and($attendance->payment_method)->toBe('cash')
        ->and($attendance->checked_in_by)->toBe($this->user->id)
        ->and($attendance->checked_in_at)->not->toBeNull();
});

test('a banned member cannot be checked in', function () {
    $member = clearMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'Banned reason']);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn');

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('a deceased member cannot be checked in', function () {
    $member = clearMember($this->irregular, ['is_deceased' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn');

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('a banned member with a granted exception can check in after acknowledging the warning', function () {
    $member = clearMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'Banned reason']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $event->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasActionErrors(['acknowledged']);

    expect(Attendance::where('member_id', $member->id)->exists())->toBeFalse();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'acknowledged' => true,
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeTrue();
});

test('a banned member without a granted exception for this event is still fully blocked', function () {
    $member = clearMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'Banned reason']);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);
    $otherEvent = Event::factory()->create(['event_date' => '2026-08-01']);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $otherEvent->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn');

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('a watchlisted member requires acknowledgement before checking in, and the reason is role-gated', function () {
    $member = clearMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'See manager first']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasActionErrors(['acknowledged']);

    expect(Attendance::where('member_id', $member->id)->exists())->toBeFalse();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'acknowledged' => true,
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $member->id)->exists())->toBeTrue();
});

test('the watchlist reason is visible to a manager but hidden from a door volunteer', function () {
    $member = clearMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'See manager first']);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);

    $this->actingAs($this->user);
    $managerInstance = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id]);
    expect($managerInstance->instance()->canSeeReason())->toBeTrue();

    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    $doorInstance = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id]);
    expect($doorInstance->instance()->canSeeReason())->toBeFalse();
});

test('a prospective member with incomplete identity must be captured before checking in', function () {
    $member = clearMember($this->prospective, ['first_name' => null, 'last_name' => null, 'email' => null]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn')
        ->assertActionVisible('saveAndPromote')
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Newb',
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new@example.com',
        ])
        ->assertHasNoActionErrors()
        ->assertActionVisible('checkIn')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $member->refresh();
    expect($member->category_id)->toBe($this->irregular->id)
        ->and($member->first_name)->toBe('New')
        ->and($member->preferred_name)->toBe('Newb')
        ->and(Attendance::where('member_id', $member->id)->exists())->toBeTrue();
});

test('a member missing paperwork must be captured before checking in', function () {
    $member = clearMember($this->irregular, ['missing_paperwork' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn')
        ->assertActionVisible('confirmPaperwork')
        ->callAction('confirmPaperwork')
        ->assertHasNoActionErrors()
        ->assertActionVisible('checkIn')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $member->refresh();
    expect($member->missing_paperwork)->toBeFalse()
        ->and(Attendance::where('member_id', $member->id)->exists())->toBeTrue();
});

test('confirmPaperwork is hidden once paperwork is already on file', function () {
    $member = clearMember($this->irregular, ['missing_paperwork' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertActionHidden('confirmPaperwork');
});

test('save and promote requires dob once "appears to be under 21" is checked', function () {
    $member = clearMember($this->prospective, ['first_name' => null, 'last_name' => null, 'email' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Newb',
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new@example.com',
            'appears_under_21' => true,
        ])
        ->assertHasActionErrors(['dob']);

    expect($member->fresh()->category_id)->toBe($this->prospective->id);
});

test('save and promote captures dob when "appears to be under 21" is checked and dob provided', function () {
    $member = clearMember($this->prospective, ['first_name' => null, 'last_name' => null, 'email' => null, 'dob' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Newb',
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new@example.com',
            'appears_under_21' => true,
            'dob' => '2010-01-01',
        ])
        ->assertHasNoActionErrors();

    expect($member->fresh()->dob->toDateString())->toBe('2010-01-01');
});

test('save and promote does not require dob when "appears to be under 21" is left unchecked', function () {
    $member = clearMember($this->prospective, ['first_name' => null, 'last_name' => null, 'email' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Newb',
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new@example.com',
        ])
        ->assertHasNoActionErrors();
});

test('a member already checked in for an event cannot be checked in again', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);
    Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('checkIn');

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->count())->toBe(1);
});

test('a check-in raced by another register is rejected without duplicating the row or crashing', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);

    // The action was still visible when this register's staff opened it —
    // mountAction() (unlike callAction()) doesn't assert visibility, so this
    // mirrors a real stale-page submission rather than something the test
    // harness would otherwise refuse to send.
    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->mountAction('checkIn');

    Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    $livewire->setActionData(['checked_in_at' => now()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->count())->toBe(1);
});

test('checking in for a future event without a check-in time records a prepayment', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'entry_fee' => 40]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => null,
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();

    expect($attendance->checked_in_at)->toBeNull()
        ->and($attendance->entry_fee)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(40);
});

test('a forged check-in time for a future prepay event is ignored server-side, still recording a prepayment', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'entry_fee' => 40, 'door_prepay_enabled' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            // The field itself is hidden for a non-live event -- this forges
            // a value as if it had been submitted anyway.
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();

    expect($attendance->checked_in_at)->toBeNull();
});

test('marking a prepaid member arrived sets the check-in time without re-pricing', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);
    $attendance = Attendance::factory()->for($member)->for($event)->create([
        'checked_in_at' => null,
        'entry_fee' => 40,
        'amount_paid' => 40,
    ]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('markArrived');

    $attendance->refresh();
    expect($attendance->checked_in_at)->not->toBeNull()
        ->and($attendance->amount_paid)->toEqual(40);
});

test('marking arrived refuses if the member became banned after prepaying', function () {
    $member = clearMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'Banned after prepay']);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('markArrived');

    $attendance->refresh();
    expect($attendance->checked_in_at)->toBeNull();
});

test('paying regular subscription at check-in creates the subscription and applies the credit to entry', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    expect($subscription->covered_month->toDateString())->toBe(now()->startOfMonth()->toDateString())
        ->and($subscription->amount_paid)->toEqual(60)
        ->and($subscription->recorded_by)->toBe($this->user->id);

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($attendance->entry_coverage)->toEqual(25)
        ->and($attendance->amount_paid)->toEqual(15);
});

test('paying pool subscription at check-in creates the subscription and covers the pool fee in full', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 0, 'pool_fee' => 15]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(["subscription_addon_{$this->pool->id}_duration" => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->pool->id)->firstOrFail();
    expect($subscription->amount_paid)->toEqual(15);

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect(poolAddOnRow($attendance)->covered_by)->toBe(AddOnCoverageSource::Subscription)
        ->and(poolAddOnRow($attendance)->coverage)->toEqual(15)
        ->and($attendance->amount_paid)->toEqual(0);
});

test('the subscription checkbox is not offered once the member already has an active subscription for the month', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);
    Subscription::create([
        'member_id' => $member->id,
        'add_on_id' => $this->entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
        'amount_paid' => 60,
    ]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->count())->toBe(1);
});

test('the subscription checkbox is not offered for a member who is not yet eligible', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => false]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->exists())->toBeFalse();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(40);
});

test('a member becomes subscription-eligible purely from live attendance count', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => false]);
    for ($i = 0; $i < 5; $i++) {
        $priorEvent = Event::factory()->create();
        $member->attendance()->create(['event_id' => $priorEvent->id, 'checked_in_at' => now()]);
    }

    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->exists())->toBeTrue();
});

test('a door volunteer can also collect a subscription payment at check-in', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    expect($subscription->recorded_by)->toBe($door->id);
});

test('applying voucher credit at check-in reduces amount due and writes a ledger row', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    Voucher::factory()->for($member)->create(['amount' => 25, 'recorded_by' => $this->user->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'apply_voucher' => true,
            'voucher_amount' => 25,
            'voucher_reason' => 'earned voucher redeemed',
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->voucher_coverage)->toEqual(25)
        ->and($attendance->amount_paid)->toEqual(15);

    $redemption = Voucher::where('attendance_id', $attendance->id)->firstOrFail();
    expect($redemption->member_id)->toBe($member->id)
        ->and($redemption->amount)->toEqual(-25)
        ->and($redemption->reason)->toBe('earned voucher redeemed')
        ->and($redemption->recorded_by)->toBe($this->user->id)
        ->and($member->voucherBalance())->toEqual(0.0);
});

test('applying voucher credit is capped at the balance available, never overspends', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    Voucher::factory()->for($member)->create(['amount' => 10, 'recorded_by' => $this->user->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'apply_voucher' => true,
            'voucher_amount' => 40,
            'voucher_reason' => 'trying to overspend',
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->voucher_coverage)->toEqual(10)
        ->and($attendance->amount_paid)->toEqual(30);

    expect($member->voucherBalance())->toEqual(0.0);
});

test('applying voucher credit does nothing when vouchers are disabled, even via a forged payload', function () {
    MembershipSetting::current()->update(['vouchers_enabled' => false]);

    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    Voucher::factory()->for($member)->create(['amount' => 25, 'recorded_by' => $this->user->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'apply_voucher' => true,
            'voucher_amount' => 25,
            'voucher_reason' => 'should be ignored',
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->voucher_coverage)->toEqual(0)
        ->and($attendance->amount_paid)->toEqual(40);

    expect(Voucher::where('attendance_id', $attendance->id)->exists())->toBeFalse()
        ->and($member->voucherBalance())->toEqual(25.0);
});

test('a voucher can be redeemed on behalf of a different member than the one checking in', function () {
    $payer = clearMember($this->irregular, ['username' => 'payer1']);
    $attendee = clearMember($this->irregular, ['username' => 'attendee1']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    Voucher::factory()->for($payer)->create(['amount' => 50, 'recorded_by' => $this->user->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $attendee->id])
        ->fillForm([
            'apply_voucher' => true,
            'voucher_payer_id' => $payer->id,
            'voucher_amount' => 20,
            'voucher_reason' => "covering {$attendee->username}'s entry",
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $attendee->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(0);

    $redemption = Voucher::where('attendance_id', $attendance->id)->firstOrFail();
    expect($redemption->member_id)->toBe($payer->id)
        ->and($payer->voucherBalance())->toEqual(30.0)
        ->and($attendee->voucherBalance())->toEqual(0.0);
});

test('not applying a voucher at check-in leaves the balance untouched', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
    Voucher::factory()->for($member)->create(['amount' => 25, 'recorded_by' => $this->user->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->voucher_coverage)->toEqual(0)
        ->and($attendance->amount_paid)->toEqual(40)
        ->and($member->voucherBalance())->toEqual(25.0);
});

test('not paying a subscription at check-in leaves subscription behavior unaffected', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Subscription::where('member_id', $member->id)->exists())->toBeFalse();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(40);
});

test('a manager can comp an entry, waiving the fee and recording the reason', function () {
    $reason = CompReason::factory()->create(['name' => 'House Sub']);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 5]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'comp_entry' => true,
            'comp_reason_id' => $reason->id,
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->entry_covered_by)->toBe(EntryCoverageSource::EventComp)
        ->and($attendance->entry_coverage)->toEqual(40)
        ->and($attendance->comp_reason_id)->toBe($reason->id)
        ->and($attendance->amount_paid)->toEqual(5); // pool fee still owed — entry only
});

test('a door volunteer cannot comp an entry even via a forged payload', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $reason = CompReason::factory()->create();
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'comp_entry' => true,
            'comp_reason_id' => $reason->id,
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->entry_covered_by)->toBe(EntryCoverageSource::None)
        ->and($attendance->comp_reason_id)->toBeNull()
        ->and($attendance->amount_paid)->toEqual(40);
});

test('comping an entry without picking a reason is rejected', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['comp_entry' => true], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasErrors(['pricingData.comp_reason_id' => 'required']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('selecting an add-on adds its price to amount_paid and records a snapshotted AttendanceAddOn row', function () {
    $addOn = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['add_on_ids' => [$addOn->id]], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(70); // 20 entry + 50 add-on

    $addOnRow = $attendance->addOns()->firstOrFail();
    expect($addOnRow->add_on_id)->toBe($addOn->id)
        ->and($addOnRow->name)->toBe('Private room rental')
        ->and($addOnRow->price)->toEqual(50);
});

test('selecting an add-on does nothing when add-ons are disabled, even via a forged payload', function () {
    MembershipSetting::current()->update(['add_ons_enabled' => false]);

    $addOn = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['add_on_ids' => [$addOn->id]], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(20); // add-on ignored — entry only
    expect($attendance->addOns()->exists())->toBeFalse();
});

test('comping an entry does not comp an add-on charge', function () {
    $reason = CompReason::factory()->create();
    $addOn = AddOn::factory()->create(['price' => 50]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm([
            'comp_entry' => true,
            'comp_reason_id' => $reason->id,
            'add_on_ids' => [$addOn->id],
        ], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->entry_covered_by)->toBe(EntryCoverageSource::EventComp)
        ->and($attendance->entry_coverage)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(50); // entry fully comped — only the add-on is owed
});

test('a door volunteer can select an add-on at check-in, unlike per-event comp', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $addOn = AddOn::factory()->create(['price' => 25]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['add_on_ids' => [$addOn->id]], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(45); // 20 entry + 25 add-on
});

test('forcing an inactive add-on id is rejected by pricingForm validation, creating no attendance row', function () {
    $addOn = AddOn::factory()->create(['price' => 50, 'active' => false]);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->set('pricingData.add_on_ids', [$addOn->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasErrors(['pricingData.add_on_ids.0']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('a non-array add_on_ids state (observed live as a bare true) is treated as no add-ons selected, not a crash', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->set('pricingData.add_on_ids', true);

    expect($livewire->instance()->getLiveAddOnTotal())->toEqual(0.0);

    $livewire->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->amount_paid)->toEqual(20)
        ->and($attendance->addOns()->count())->toBe(0);
});

test('forcing an add-on past its nightly cap is rejected by pricingForm validation, creating no attendance row', function () {
    $addOn = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50, 'max_per_night' => 1]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $earlierAttendance = Attendance::factory()->create(['event_id' => $event->id]);
    AttendanceAddOn::factory()->create(['attendance_id' => $earlierAttendance->id, 'add_on_id' => $addOn->id]);

    $member = clearMember($this->irregular);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->set('pricingData.add_on_ids', [$addOn->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasErrors(['pricingData.add_on_ids.0']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('card is no longer offered as a check-in payment method', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'card',
        ])
        ->assertHasActionErrors(['payment_method']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('venmo can be used once per member; a second attempt is rejected and the first is noted on hospitality_note', function () {
    $member = clearMember($this->irregular);
    $eventOne = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $eventTwo = Event::factory()->create(['event_date' => '2026-07-26', 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $eventOne->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'venmo',
        ])
        ->assertHasNoActionErrors();

    $member->refresh();
    expect($member->hasUsedOneTimeMethod())->toBeTrue()
        ->and($member->hospitality_note)->toContain('Venmo used '.now()->toDateString());

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $eventTwo->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'venmo',
        ])
        ->assertHasActionErrors(['payment_method']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $eventTwo->id)->exists())->toBeFalse();
});

test('using one one-time method (venmo) also locks out the others (paypal)', function () {
    $member = clearMember($this->irregular);
    $eventOne = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $eventTwo = Event::factory()->create(['event_date' => '2026-07-26', 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $eventOne->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'venmo',
        ])
        ->assertHasNoActionErrors();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $eventTwo->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'paypal',
        ])
        ->assertHasActionErrors(['payment_method']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $eventTwo->id)->exists())->toBeFalse();
});

test('a non-one-time method (cash) never locks anything out', function () {
    $member = clearMember($this->irregular);
    $eventOne = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $eventTwo = Event::factory()->create(['event_date' => '2026-07-26', 'entry_fee' => 20, 'pool_fee' => 0]);

    foreach ([$eventOne, $eventTwo] as $event) {
        Livewire::test(CheckIn::class)
            ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
            ->callAction('checkIn', data: [
                'checked_in_at' => now(),
                'payment_method' => 'other',
            ])
            ->assertHasNoActionErrors();
    }

    $member->refresh();
    expect($member->hasUsedOneTimeMethod())->toBeFalse()
        ->and(Attendance::where('member_id', $member->id)->count())->toBe(2);
});

test('the register-guest action is hidden until the host has been checked in tonight', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('registerGuest');
});

test('the register-guest action is hidden for a prepaid host who has not yet arrived', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'entry_fee' => 40]);
    Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->assertActionHidden('registerGuest');
});

test('the register-guest action becomes visible once the host is checked in', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->assertActionVisible('registerGuest');
});

test('the register-guest action is hidden when the checked-in host is on probation', function () {
    MembershipSetting::current()->update(['probation_period_days' => 90]);
    $member = clearMember($this->irregular, ['date_vetted' => now()->subDays(10)]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->assertActionHidden('registerGuest');
});

test('registering a guest creates a Guest-category member linked to the sponsor', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
        ])
        ->assertHasNoActionErrors();

    $guest = Member::where('first_name', 'Jamie')->where('last_name', 'Guestly')->firstOrFail();
    expect($guest->category->name)->toBe('Guest')
        ->and($guest->sponsor_id)->toBe($member->id)
        ->and($guest->username)->toBe('jamieguestly')
        ->and($guest->preferred_name)->toBe('Jamie')
        ->and($guest->email)->toBe('jamie.guestly@example.com')
        ->and($guest->notes)->toContain('Guest of');
});

test('registering a guest requires username, preferred name, first/last name, and email', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => '',
            'preferred_name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
        ])
        ->assertHasActionErrors(['username', 'preferred_name', 'first_name', 'last_name', 'email']);
});

test('registering a guest requires dob once "appears to be under 21" is checked', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
            'appears_under_21' => true,
        ])
        ->assertHasActionErrors(['dob']);

    expect(Member::where('username', 'jamieguestly')->exists())->toBeFalse();
});

test('registering a guest captures dob when "appears to be under 21" is checked and dob provided', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
            'appears_under_21' => true,
            'dob' => '2010-01-01',
        ])
        ->assertHasNoActionErrors();

    $guest = Member::where('username', 'jamieguestly')->firstOrFail();
    expect($guest->dob->toDateString())->toBe('2010-01-01');
});

test('registering a guest with a username already taken by another member is rejected', function () {
    Member::factory()->create(['username' => 'jamieguestly']);
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
        ])
        ->assertHasActionErrors(['username']);
});

test('a registered guest is auto-assigned the next member_number, same as any other staff-created member', function () {
    $member = clearMember($this->irregular, ['member_number' => 41]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
        ])
        ->assertHasNoActionErrors();

    $guest = Member::where('first_name', 'Jamie')->where('last_name', 'Guestly')->firstOrFail();
    expect($guest->member_number)->toBe(42);
});

test('the newly registered guest is auto-selected and can be checked in for the event', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
        ])
        ->assertHasNoActionErrors()
        ->assertActionVisible('checkIn')
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $guest = Member::where('first_name', 'Jamie')->firstOrFail();
    expect(Attendance::where('member_id', $guest->id)->where('event_id', $event->id)->exists())->toBeTrue();
});

test('a door volunteer can register a guest once their host is checked in and not on probation', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie.guestly@example.com',
        ])
        ->assertHasNoActionErrors();

    expect(Member::where('first_name', 'Jamie')->where('sponsor_id', $member->id)->exists())->toBeTrue();
});

test('the event picker only includes today\'s events and door-prepay-enabled events', function () {
    $today = Event::factory()->create(['event_date' => today()->toDateString(), 'door_prepay_enabled' => false]);
    $futureFlagged = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'door_prepay_enabled' => true]);
    $futureUnflagged = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'door_prepay_enabled' => false]);

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);
    $ids = $options->invoke(null)->pluck('id');

    expect($ids)->toContain($today->id)
        ->toContain($futureFlagged->id)
        ->not->toContain($futureUnflagged->id);
});

test('once prepay_enabled is off, the event picker stops including door-prepay-enabled future events, restores once re-enabled', function () {
    MembershipSetting::current()->update(['prepay_enabled' => false]);
    $today = Event::factory()->create(['event_date' => today()->toDateString(), 'door_prepay_enabled' => false]);
    $futureFlagged = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'door_prepay_enabled' => true]);

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);
    $ids = $options->invoke(null)->pluck('id');

    expect($ids)->toContain($today->id)
        ->not->toContain($futureFlagged->id);

    MembershipSetting::current()->update(['prepay_enabled' => true]);

    $ids = $options->invoke(null)->pluck('id');
    expect($ids)->toContain($futureFlagged->id);
});

test('a forged event_id for a future door-prepay event is rejected once prepay_enabled is off', function () {
    MembershipSetting::current()->update(['prepay_enabled' => false]);
    $member = clearMember($this->irregular);
    $futureEvent = Event::factory()->create([
        'event_date' => now()->addMonth()->toDateString(),
        'starts_at' => now()->addMonth()->setTime(20, 0)->toDateTimeString(),
        'ends_at' => now()->addMonth()->setTime(23, 0)->toDateTimeString(),
        'door_prepay_enabled' => true,
        'entry_fee' => 20,
    ]);

    // event_id is forged directly via fillForm, bypassing the picker's own
    // (already-correct) options entirely -- proves checkInAction's own
    // server-side re-check is what actually stops this. No
    // ->assertHasNoActionErrors() chained here: the abort_unless(...,403)
    // firing leaves Filament's test harness unable to inspect the action's
    // post-call state cleanly, the same Livewire/abort_unless interaction
    // already seen in the Manager-perk and Pool rounds -- the resulting DB
    // state is what actually proves the fix.
    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $futureEvent->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()]);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $futureEvent->id)->exists())->toBeFalse();
});

test('a pre-existing prepay attendance row still works via markArrivedAction after prepay_enabled is turned off', function () {
    $member = clearMember($this->irregular);
    $futureEvent = Event::factory()->create([
        'event_date' => now()->addMonth()->toDateString(),
        'door_prepay_enabled' => true,
        'entry_fee' => 20,
    ]);
    $attendance = Attendance::factory()->for($member)->for($futureEvent)->create(['checked_in_at' => null, 'amount_paid' => 20]);

    MembershipSetting::current()->update(['prepay_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $futureEvent->id, 'member_id' => $member->id])
        ->callAction('markArrived')
        ->assertHasNoActionErrors();

    expect($attendance->refresh()->checked_in_at)->not->toBeNull();
});

test('the event picker includes an event that spans midnight, once event_date alone no longer says "today"', function () {
    // event_date is "yesterday" from the perspective of 1am -- without the
    // starts_at/ends_at window, this event would vanish from the picker the
    // moment the clock passed midnight, even though it's still running.
    $spansMidnight = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 22:00:00',
        'ends_at' => '2026-07-19 02:00:00',
        'door_prepay_enabled' => false,
    ]);

    $this->travelTo(Carbon::parse('2026-07-19 01:00:00'));

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);
    $ids = $options->invoke(null)->pluck('id');

    expect($ids)->toContain($spansMidnight->id);
});

test('the event picker respects the configured buffer around starts_at/ends_at, and excludes an event outside it', function () {
    $spansMidnight = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 22:00:00',
        'ends_at' => '2026-07-19 02:00:00',
        'door_prepay_enabled' => false,
    ]);

    // 20 minutes past ends_at -- outside the default 15-minute buffer, and
    // event_date ("2026-07-18") no longer matches "today" either.
    $this->travelTo(Carbon::parse('2026-07-19 02:20:00'));

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);

    expect($options->invoke(null)->pluck('id'))->not->toContain($spansMidnight->id);

    MembershipSetting::current()->update(['event_window_buffer_minutes' => 30]);

    expect($options->invoke(null)->pluck('id'))->toContain($spansMidnight->id);
});

test('defaultEventId auto-selects an event that spans midnight when it\'s the only one running', function () {
    Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 22:00:00',
        'ends_at' => '2026-07-19 02:00:00',
        'door_prepay_enabled' => false,
    ]);

    $this->travelTo(Carbon::parse('2026-07-19 01:00:00'));

    $method = (new ReflectionClass(CheckIn::class))->getMethod('defaultEventId');
    $method->setAccessible(true);

    Livewire::test(CheckIn::class)
        ->assertSet('data.event_id', $method->invoke(null));
});

test('a door-prepay event with a future starts_at/ends_at stays selectable regardless of the window', function () {
    $prepay = Event::factory()->create([
        'event_date' => now()->addMonth()->toDateString(),
        'starts_at' => now()->addMonth()->setTime(20, 0),
        'ends_at' => now()->addMonth()->setTime(23, 0),
        'door_prepay_enabled' => true,
    ]);

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);

    expect($options->invoke(null)->pluck('id'))->toContain($prepay->id);
});

test('Event::isCurrentlyActive is true for a same-day event and false for a future door-prepay event', function () {
    $today = Event::factory()->create(['event_date' => today()->toDateString()]);
    $future = Event::factory()->create(['event_date' => now()->addMonth()->toDateString(), 'door_prepay_enabled' => true]);

    expect($today->isCurrentlyActive())->toBeTrue()
        ->and($future->isCurrentlyActive())->toBeFalse();
});

test('Event::isCurrentlyActive respects the starts_at/ends_at window (and its buffer) for an event spanning midnight', function () {
    $spansMidnight = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 22:00:00',
        'ends_at' => '2026-07-19 02:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-19 01:00:00'));
    expect($spansMidnight->fresh()->isCurrentlyActive())->toBeTrue();

    $this->travelTo(Carbon::parse('2026-07-19 02:20:00'));
    expect($spansMidnight->fresh()->isCurrentlyActive())->toBeFalse();
});

test('the event picker still works for a legacy event with no starts_at/ends_at set', function () {
    $legacy = Event::factory()->create([
        'event_date' => today()->toDateString(),
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $options = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $options->setAccessible(true);

    expect($options->invoke(null)->pluck('id'))->toContain($legacy->id);
});

test('a prepay for a future-month event creates a subscription covering that event\'s month, not today\'s', function () {
    $member = clearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => '2026-09-19', 'entry_fee' => 40, 'door_prepay_enabled' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => null,
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    expect($subscription->covered_month->toDateString())->toBe('2026-09-01');
});

test('checkIn is hidden once the building is at capacity, and a recorded departure restores it', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $existingMember = clearMember($this->irregular, ['username' => 'already-here']);
    $event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20]);
    Attendance::factory()->for($existingMember)->for($event)->create(['checked_in_at' => now()]);

    $newMember = clearMember($this->irregular, ['username' => 'walk-in']);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $newMember->id])
        ->assertActionHidden('checkIn');

    // "Record departures" moved to its own Dashboard widget — see
    // tests/Feature/RecordDeparturesWidgetTest.php for its own coverage.
    // This test only needs to confirm checkIn's own capacity gate reacts to
    // a departure recorded elsewhere.
    $this->actingAs($this->user);
    Livewire::test(RecordDeparturesWidget::class)
        ->callAction('recordDepartures', data: ['count' => 1]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $newMember->id])
        ->assertActionVisible('checkIn');
});

test('markArrivedAction is never blocked by capacity', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $existingMember = clearMember($this->irregular, ['username' => 'already-here']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);
    Attendance::factory()->for($existingMember)->for($event)->create(['checked_in_at' => now()]);

    $prepaidMember = clearMember($this->irregular, ['username' => 'prepaid-guest']);
    $attendance = Attendance::factory()->for($prepaidMember)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $prepaidMember->id])
        ->assertActionVisible('markArrived')
        ->callAction('markArrived')
        ->assertHasNoActionErrors();

    expect($attendance->refresh()->checked_in_at)->not->toBeNull();
});

test('the back check-in table lists unarrived attendance for the selected event and marks arrived per row', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);
    $attendance = Attendance::factory()->for($member)->for($event)->create([
        'checked_in_at' => null,
        'entry_fee' => 40,
        'amount_paid' => 40,
    ]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id])
        ->assertCanSeeTableRecords([$attendance])
        ->callTableAction('markArrived', $attendance)
        ->assertHasNoTableActionErrors();

    expect($attendance->refresh()->checked_in_at)->not->toBeNull();
});

test('the back check-in table renders as a responsive stacked layout, not one wide row of columns', function () {
    $member = clearMember($this->irregular, ['username' => 'layout-check']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40]);
    Attendance::factory()->for($member)->for($event)->create([
        'checked_in_at' => null,
        'entry_fee' => 40,
        'amount_paid' => 40,
    ]);

    // Split::make() emits a `fi-ta-split` wrapper; a plain ->columns([TextColumn, ...])
    // table never does. This is what keeps the phone view off a horizontal scroll.
    expect(Livewire::test(CheckIn::class)->fillForm(['event_id' => $event->id])->html())
        ->toContain('fi-ta-split');
});

test('the back check-in table requires acknowledgement for a watchlisted row and blocks a banned one', function () {
    $watchlisted = clearMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'See manager first']);
    $banned = clearMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'Banned reason']);
    $event = Event::factory()->create(['event_date' => now()->toDateString()]);
    $watchlistedAttendance = Attendance::factory()->for($watchlisted)->for($event)->create(['checked_in_at' => null]);
    $bannedAttendance = Attendance::factory()->for($banned)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id])
        ->callTableAction('markArrived', $watchlistedAttendance)
        ->assertHasTableActionErrors(['acknowledged']);

    expect($watchlistedAttendance->refresh()->checked_in_at)->toBeNull();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id])
        ->callTableAction('markArrived', $watchlistedAttendance, data: ['acknowledged' => true])
        ->assertHasNoTableActionErrors();

    expect($watchlistedAttendance->refresh()->checked_in_at)->not->toBeNull();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id])
        ->callTableAction('markArrived', $bannedAttendance);

    expect($bannedAttendance->refresh()->checked_in_at)->toBeNull();
});

test('two guests registered with the same name but different usernames both register fine', function () {
    $member = clearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $livewire->callAction('registerGuest', data: [
        'username' => 'jamieguestly1',
        'preferred_name' => 'Jamie',
        'first_name' => 'Jamie',
        'last_name' => 'Guestly',
        'email' => 'jamie1@example.com',
    ])
        ->assertHasNoActionErrors();

    // registerGuest auto-selected the first guest — re-select the host before registering a second one.
    $livewire->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('registerGuest', data: [
            'username' => 'jamieguestly2',
            'preferred_name' => 'Jamie',
            'first_name' => 'Jamie',
            'last_name' => 'Guestly',
            'email' => 'jamie2@example.com',
        ])
        ->assertHasNoActionErrors();

    $usernames = Member::where('first_name', 'Jamie')->where('last_name', 'Guestly')->pluck('username');
    expect($usernames->sort()->values()->all())->toBe(['jamieguestly1', 'jamieguestly2']);
});

test('member search matches on username before falling back to name', function () {
    // Name search is off by default — enable it so the fallback tier has something to do.
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name']]);

    $usernameMatch = clearMember($this->irregular, ['username' => 'nightowl', 'first_name' => 'Alex', 'last_name' => 'Fox']);
    $nameMatch = clearMember($this->irregular, ['username' => 'jsmith99', 'first_name' => 'Owlvia', 'last_name' => 'Nightengale']);
    $unrelated = clearMember($this->irregular, ['username' => 'someoneelse', 'first_name' => 'No', 'last_name' => 'Match']);

    $method = (new ReflectionClass(CheckIn::class))->getMethod('searchMembers');
    $method->setAccessible(true);
    $results = $method->invoke(null, 'owl');

    expect($results->pluck('id'))
        ->toContain($usernameMatch->id)
        ->toContain($nameMatch->id)
        ->not->toContain($unrelated->id)
        ->and($results->first()->id)->toBe($usernameMatch->id);
});

test('member search caps total results and still fills remaining slots from name matches', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name']]);

    clearMember($this->irregular, ['username' => 'zzzmatch1']);
    clearMember($this->irregular, ['username' => 'someoneelse', 'first_name' => 'Zzz']);

    $method = (new ReflectionClass(CheckIn::class))->getMethod('searchMembers');
    $method->setAccessible(true);

    // Limit 1: the username match alone fills it, the name match is capped out.
    expect($method->invoke(null, 'zzz', 1))->toHaveCount(1);

    // Limit 2: the remaining slot is filled from the name-match tier.
    $both = $method->invoke(null, 'zzz', 2);
    expect($both)->toHaveCount(2)
        ->and($both->first()->username)->toBe('zzzmatch1');
});
