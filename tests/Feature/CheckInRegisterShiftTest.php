<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterDrop;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RegisterShiftService;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->user);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $pool->id, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);

    $this->register = Register::factory()->create();
});

function cashClearMember(Category $category, array $overrides = []): Member
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

test('the register picker defaults from the user\'s saved default register', function () {
    $this->user->update(['default_register_id' => $this->register->id]);

    $instance = Livewire::test(CheckIn::class)->instance();

    expect($instance->registerId)->toBe($this->register->id);
});

test('changing the register picker persists it as the user\'s new default', function () {
    Livewire::test(CheckIn::class)->set('registerId', $this->register->id);

    expect($this->user->fresh()->default_register_id)->toBe($this->register->id);
});

test('openShift is visible with no shift open, and hidden once one is; recordDrop/closeShift are the reverse', function () {
    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionVisible('openShift')
        ->assertActionHidden('recordDrop')
        ->assertActionHidden('closeShift')
        ->assertActionHidden('recordMiscPayment')
        ->callAction('openShift', data: ['opening_count' => 100])
        ->assertHasNoActionErrors();

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('openShift')
        ->assertActionVisible('recordDrop')
        ->assertActionVisible('closeShift')
        ->assertActionVisible('recordMiscPayment');
});

test('a Door user can record a miscellaneous payment while a shift is open', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    $shift = app(RegisterShiftService::class)->openShift($this->register, $door, 100);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('recordMiscPayment', data: [
            'payment_method' => 'cash',
            'amount' => 40,
            'notation' => 'Private rental deposit',
        ])
        ->assertHasNoActionErrors();

    $payment = MiscellaneousPayment::where('register_shift_id', $shift->id)->firstOrFail();

    expect($payment->payment_method)->toBe('cash')
        ->and((float) $payment->amount)->toEqual(40.0)
        ->and($payment->notation)->toBe('Private rental deposit')
        ->and($payment->recorded_by)->toBe($door->id);
});

test('a check-in during an open shift attributes register_shift_id and the chosen payment method to the attendance row', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'cash',
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    expect($attendance->payment_method)->toBe('cash')
        ->and($attendance->register_shift_id)->toBe($shift->id);
});

test('a check-in with a non-cash payment method is still attributed to the open shift', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'venmo',
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    expect($attendance->payment_method)->toBe('venmo')
        ->and($attendance->register_shift_id)->toBe($shift->id);
});

test('"Other" is not offered at check-in, so a forged one is rejected and nothing is recorded', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    expect(PaymentMethod::where('code', 'other')->value('available_at_desk'))->toBeFalse();

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'other',
        ])
        ->assertHasActionErrors(['payment_method']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('a method switched back on for the desk is offered at check-in again', function () {
    PaymentMethod::where('code', 'other')->update(['available_at_desk' => true]);
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->value('payment_method'))->toBe('other');
});

test('"Record other payment" still offers a method that is not available at the desk', function () {
    $shift = app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('recordMiscPayment', data: [
            'payment_method' => 'other',
            'amount' => 25,
            'notation' => 'Vendor table fee',
        ])
        ->assertHasNoActionErrors();

    expect(MiscellaneousPayment::where('register_shift_id', $shift->id)->value('payment_method'))->toBe('other');
});

test('a forged cash payment method with no open shift is rejected by the field\'s own option validation', function () {
    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'cash',
        ])
        ->assertHasActionErrors(['payment_method']);

    expect(Attendance::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeFalse();
});

test('omitting a payment method with no open shift still checks the member in without one', function () {
    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();

    expect($attendance->payment_method)->toBeNull()
        ->and($attendance->register_shift_id)->toBeNull();
});

test('a subscription paid at check-in also gets register_shift_id and payment_method stamped', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '1'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
            'payment_method' => 'cash',
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $member->id)->where('add_on_id', $this->entry->id)->firstOrFail();
    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    expect($subscription->payment_method)->toBe('cash')
        ->and($subscription->register_shift_id)->toBe($shift->id);
});

test('a Door user can open a shift, take a cash check-in, record a drop, and close the shift end to end', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $livewire = Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('openShift', data: ['opening_count' => 100])
        ->assertHasNoActionErrors();

    $livewire->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    $livewire->callAction('recordDrop', data: ['amount' => 10])
        ->assertHasNoActionErrors();

    $livewire->callAction('closeShift', data: ['closing_count' => 110])
        ->assertHasNoActionErrors();

    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);
    expect($shift)->toBeNull();

    $closed = RegisterShift::where('register_id', $this->register->id)->firstOrFail();
    expect($closed->closed_by)->toBe($door->id)
        ->and(app(RegisterShiftService::class)->variance($closed))->toEqual(0.0);
});

test('closing a drawer that balances to the cent reports it as exact, not $0.00 over', function () {
    $livewire = Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('openShift', data: ['opening_count' => 50])
        ->assertHasNoActionErrors();

    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);
    app(RegisterShiftService::class)->recordMiscPayment($shift, auth()->user(), 0.05, 'cash', 'vendor');

    $livewire->callAction('recordDrop', data: ['amount' => 20])
        ->assertHasNoActionErrors()
        ->callAction('closeShift', data: ['closing_count' => 30.05])
        ->assertHasNoActionErrors()
        ->assertNotified(__('Box closed — :amount :label', ['amount' => '$0.00', 'label' => __('exact')]));
});

test('a register can be closed and reopened for a mid-shift changeover, and check-ins attribute to the new shift', function () {
    $livewire = Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionVisible('openShift')
        ->callAction('openShift', data: ['opening_count' => 100])
        ->assertHasNoActionErrors();

    $firstShift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $livewire->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    // Mid-shift changeover: close out the box for the outgoing staffer.
    $livewire->assertActionVisible('closeShift')
        ->callAction('closeShift', data: ['closing_count' => 120])
        ->assertHasNoActionErrors();

    expect(app(RegisterShiftService::class)->currentOpenShift($this->register))->toBeNull();

    // The incoming staffer opens a fresh shift on the very same register.
    $livewire->assertActionVisible('openShift')
        ->assertActionHidden('recordDrop')
        ->assertActionHidden('closeShift')
        ->callAction('openShift', data: ['opening_count' => 50])
        ->assertHasNoActionErrors();

    $secondShift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    expect($secondShift)->not->toBeNull()
        ->and($secondShift->id)->not->toBe($firstShift->id)
        ->and($secondShift->register_id)->toBe($this->register->id)
        ->and($secondShift->opening_count)->toEqual(50.0);

    $secondMember = cashClearMember($this->irregular, ['first_name' => 'Sam', 'last_name' => 'Smith', 'email' => 'sam@example.com']);

    $livewire->fillForm(['event_id' => $event->id, 'member_id' => $secondMember->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    $firstAttendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    $secondAttendance = Attendance::where('member_id', $secondMember->id)->where('event_id', $event->id)->firstOrFail();

    expect($firstAttendance->register_shift_id)->toBe($firstShift->id)
        ->and($secondAttendance->register_shift_id)->toBe($secondShift->id);
});

test('any Door+ user, not just the one who opened it, can record a drop or close the shift', function () {
    $opener = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($opener);
    app(RegisterShiftService::class)->openShift($this->register, $opener, 100);

    $closer = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($closer);

    $livewire = Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionVisible('recordDrop')
        ->assertActionVisible('closeShift')
        ->callAction('closeShift', data: ['closing_count' => 100])
        ->assertHasNoActionErrors();

    $shift = RegisterShift::where('register_id', $this->register->id)->firstOrFail();
    expect($shift->closed_by)->toBe($closer->id);
});

test('the register section is hidden from the check-in page once register_shifts_enabled is off', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    $this->get('/admin/check-in')->assertDontSee('id="registerId"', false);
});

test('the register section is visible again once register_shifts_enabled is restored', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    MembershipSetting::current()->update(['register_shifts_enabled' => true]);

    $this->get('/admin/check-in')->assertSee('id="registerId"', false);
});

test('openShift is rejected once register_shifts_enabled is off, even via a forged direct call', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('openShift', data: ['opening_count' => 100]);

    expect(RegisterShift::where('register_id', $this->register->id)->exists())->toBeFalse();
});

test('recordDrop is rejected once register_shifts_enabled is off, even via a forged direct call', function () {
    $shift = app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('recordDrop', data: ['amount' => 10]);

    expect(RegisterDrop::where('register_shift_id', $shift->id)->exists())->toBeFalse();
});

test('recordMiscPayment is rejected once register_shifts_enabled is off, even via a forged direct call', function () {
    $shift = app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('recordMiscPayment', data: [
            'payment_method' => 'cash',
            'amount' => 40,
            'notation' => 'Private rental deposit',
        ]);

    expect(MiscellaneousPayment::where('register_shift_id', $shift->id)->exists())->toBeFalse();
});

function lastNotificationBody(): ?string
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications->last()?->getBody();
}

test('closing a shift with cash collected includes an envelope reminder with the cash breakdown and event/date', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular, ['subscription_eligible' => true]);
    // A fixed past date keeps the envelope label assertable; door prepay lets
    // the desk accept it (since portico#81 the desk requires the event's own
    // opt-in for anything not happening tonight).
    $event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-07-10', 'entry_fee' => 20, 'pool_fee' => 0, 'door_prepay_enabled' => true]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 120])
        ->assertHasNoActionErrors();

    $body = lastNotificationBody();

    expect($body)->not->toBeNull()
        ->and($body)->toContain('Cash collected')
        ->and($body)->toContain('Entry: $20.00')
        ->and($body)->toContain('total $20.00')
        ->and($body)->toContain('Friday Social — Jul 10, 2026')
        ->and($body)->toContain('Make an envelope');
});

test('subscription cash is left out of the night\'s envelope and named as its own', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    $shift = app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);
    $event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => today()]);
    Attendance::factory()->create(['event_id' => $event->id, 'register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    Subscription::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 60]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 180])
        ->assertHasNoActionErrors();

    $body = lastNotificationBody();

    expect($body)->toContain('Entry: $20.00 · Other: $0.00 (total $20.00)')
        ->and($body)->toContain('Subscriptions — '.now()->isoFormat('ll').'": $60.00')
        ->and($body)->not->toContain('Subscription: ');
});

test('cash_envelope_reminder_enabled is off by default', function () {
    expect(MembershipSetting::current()->cash_envelope_reminder_enabled)->toBeFalse();
});

test('with the envelope reminder off, closing a shift with cash collected shows only the variance', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => today(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 120])
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $member->id)->value('payment_method'))->toBe('cash')
        ->and(lastNotificationBody())->toBeNull();
});

test('closing a shift with zero cash collected has no envelope reminder', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 100])
        ->assertHasNoActionErrors();

    expect(lastNotificationBody())->toBeNull();
});

test('a cash-only subscription purchase gets only its own subscription envelope, labelled by date', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $member = cashClearMember($this->irregular, ['subscription_eligible' => true]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->entry->id,
            'desired_start' => now()->startOfMonth(),
            'duration_months' => '1',
            'payment_method' => 'cash',
        ])
        ->assertHasNoActionErrors();

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 160])
        ->assertHasNoActionErrors();

    $body = lastNotificationBody();

    expect($body)->not->toBeNull()
        ->and($body)->toContain('Subscription cash goes in its own envelope')
        ->and($body)->toContain('Subscriptions — '.now()->isoFormat('ll').'": $60.00')
        ->and($body)->not->toContain('Cash collected');
});

test('closeShift is rejected once register_shifts_enabled is off, even via a forged direct call', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 100]);

    expect(app(RegisterShiftService::class)->currentOpenShift($this->register))->not->toBeNull();
});

test('the box summary and the close message list electronic payments apart from the cash', function () {
    $livewire = Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('openShift', data: ['opening_count' => 0])
        ->assertHasNoActionErrors();

    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);
    app(RegisterShiftService::class)->recordMiscPayment($shift, auth()->user(), 40, 'venmo', 'rental');

    Livewire::test('register-box-summary', ['registerId' => $this->register->id])
        ->assertSee('Electronic, included above but not in the box: Venmo $40.00');

    $livewire->callAction('closeShift', data: ['closing_count' => 0])->assertHasNoActionErrors();

    // Read the way Notification::assertNotified() does; it only matches on
    // the title or the whole notification.
    $sent = new Notifications;
    $sent->mount();

    expect($sent->notifications->map(fn ($notification) => $notification->getBody())->implode(' '))
        ->toContain('Taken electronically (not in the box or any envelope): Venmo $40.00 (total $40.00).');
});
