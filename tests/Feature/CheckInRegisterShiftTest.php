<?php

use App\Enums\PlanType;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterDrop;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->user);

    Plan::create(['code' => PlanType::Regular, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Pool, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);

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
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    $shift = app(RegisterShiftService::class)->currentOpenShift($this->register);

    expect($attendance->payment_method)->toBe('other')
        ->and($attendance->register_shift_id)->toBe($shift->id);
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

    $subscription = Subscription::where('member_id', $member->id)->where('plan_type', PlanType::Regular)->firstOrFail();
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

test('closeShift is rejected once register_shifts_enabled is off, even via a forged direct call', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 100]);

    expect(app(RegisterShiftService::class)->currentOpenShift($this->register))->not->toBeNull();
});
