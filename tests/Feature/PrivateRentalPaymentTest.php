<?php

use App\Enums\Role;
use App\Exceptions\PrivateRentalRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\PrivateRentalPayment;
use App\Models\Register;
use App\Models\Room;
use App\Models\User;
use App\Services\PrivateRentalService;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 18:30:00');
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);

    // Both seeded by the payment_methods migration.
    PaymentMethod::where('code', 'cash')->update(['requires_register_shift' => true, 'available_at_desk' => true, 'active' => true, 'transaction_fee' => 0]);
    PaymentMethod::where('code', 'venmo')->update(['requires_register_shift' => false, 'available_at_desk' => true, 'active' => true, 'transaction_fee' => 1.50]);
    PaymentMethod::forgetCashCodes();

    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->door);

    $this->register = Register::factory()->create();
    $this->door->update(['default_register_id' => $this->register->id]);
    $this->shifts = app(RegisterShiftService::class);
    $this->shift = $this->shifts->openShift($this->register, $this->door, 100);

    $this->room = Room::factory()->create(['name' => 'Blue Room', 'hourly_rate' => 40, 'included_people' => 10, 'overage_fee' => 5, 'overage_per_hour' => true, 'min_hours' => 2, 'max_hours' => 6]);
    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular'])->id,
        'dob' => '1990-01-01',
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ]);
    $this->service = app(PrivateRentalService::class);
    // $150: $120 room + 2 extra people x $15.
    $this->rental = $this->service->book($this->member, $this->room, Carbon::parse('2026-11-14 19:00'), 3, 12, $this->door);
});

afterEach(fn () => Carbon::setTestNow());

test('collecting payment records the total on the box and locks the booking', function () {
    $payment = $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    expect($payment->amount)->toBe('150.00')
        ->and($payment->register_shift_id)->toBe($this->shift->id)
        ->and($this->service->isPaid($this->rental))->toBeTrue()
        ->and($this->service->canCollectPayment($this->rental))->toBeFalse()
        ->and(fn () => $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door))->toThrow(PrivateRentalRefused::class, 'already paid')
        ->and(fn () => $this->service->change($this->rental, Carbon::parse('2026-11-14 19:00'), 4, 12))->toThrow(PrivateRentalRefused::class, 'is paid')
        ->and(fn () => $this->service->cancel($this->rental, $this->door))->toThrow(PrivateRentalRefused::class, 'Manager can cancel it with a refund');
});

test('rental cash counts toward the box and shows as its own Rental line', function () {
    $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    expect($this->shifts->cashReceivedCents($this->shift))->toBe(15000)
        ->and($this->shifts->expectedClosingCountCents($this->shift))->toBe(25000)
        ->and($this->shifts->revenueBreakdown($this->shift)['rental'])->toBe(150.0)
        ->and($this->shifts->revenueBreakdown($this->shift)['other'])->toBe(0.0)
        ->and($this->shifts->cashRevenueBreakdownCents($this->shift)['rental'])->toBe(15000)
        ->and($this->shifts->varianceMathCents($this->shift)['rental'])->toBe(15000);
});

test('an electronic payment adds its fee, stays out of the box, and lists as electronic', function () {
    $payment = $this->service->collectPayment($this->rental, 'venmo', $this->shift, $this->door);

    expect($payment->amount)->toBe('151.50')
        ->and($payment->transaction_fee)->toBe('1.50')
        ->and($this->shifts->cashReceivedCents($this->shift))->toBe(0)
        ->and($this->shifts->revenueBreakdown($this->shift)['rental'])->toBe(151.5)
        ->and($this->shifts->electronicByMethodCents($this->shift))->toBe(['Venmo' => 15150]);
});

test('cash needs an open box, and only desk methods are accepted', function () {
    expect(fn () => $this->service->collectPayment($this->rental, 'cash', null, $this->door))->toThrow(HttpException::class)
        ->and(fn () => $this->service->collectPayment($this->rental, 'not-a-method', $this->shift, $this->door))->toThrow(HttpException::class);

    expect(PrivateRentalPayment::count())->toBe(0);
});

test('payment is taken on the day of the rental, not ahead of it', function () {
    $later = $this->service->book($this->member, $this->room, Carbon::parse('2026-11-20 19:00'), 2, 5, $this->door);

    expect($this->service->canCollectPayment($later))->toBeFalse()
        ->and(fn () => $this->service->collectPayment($later, 'cash', $this->shift, $this->door))->toThrow(PrivateRentalRefused::class, 'day of the rental');
});

test('a Manager cancels a paid booking with a refund out of the box', function () {
    $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    $refund = $this->service->refundAndCancel($this->rental, 'cash', $this->shift, 'Their event was called off', $this->manager);

    expect($refund->amount)->toBe('-150.00')
        ->and($refund->reason)->toBe('Their event was called off')
        ->and($this->service->netPaidCents($this->rental))->toBe(0)
        ->and($this->rental->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->rental->event->fresh()->isArchived())->toBeTrue()
        ->and($this->shifts->cashReceivedCents($this->shift))->toBe(0)
        ->and($this->shifts->revenueBreakdown($this->shift)['rental'])->toBe(0.0);
});

test('a refund needs a reason, a paid booking, and nobody checked in', function () {
    expect(fn () => $this->service->refundAndCancel($this->rental, 'cash', $this->shift, 'x', $this->manager))->toThrow(PrivateRentalRefused::class, 'Only a paid booking');

    $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);
    expect(fn () => $this->service->refundAndCancel($this->rental, 'cash', $this->shift, '  ', $this->manager))->toThrow(PrivateRentalRefused::class, 'reason');

    Attendance::factory()->create(['event_id' => $this->rental->event_id, 'member_id' => $this->member->id]);
    expect(fn () => $this->service->refundAndCancel($this->rental, 'cash', $this->shift, 'x', $this->manager))->toThrow(PrivateRentalRefused::class, 'already checked in');
});

test('the refund gate is Manager+ only', function () {
    expect(Gate::forUser($this->door)->allows('refund-private-rental'))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('refund-private-rental'))->toBeTrue();
});

test('payments are append-only', function () {
    $payment = $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    expect(Gate::forUser($this->manager)->allows('update', $payment))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('delete', $payment))->toBeFalse();
});

test('the desk collects payment from the renter\'s member card', function () {
    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionVisible('collectPrivateRentalPayment', arguments: ['rental' => $this->rental->id])
        ->assertActionHidden('refundPrivateRental', arguments: ['rental' => $this->rental->id])
        ->callAction('collectPrivateRentalPayment', arguments: ['rental' => $this->rental->id], data: ['payment_method' => 'cash'])
        ->assertHasNoActionErrors()
        ->assertNotified('Rental paid — $150.00');

    expect($this->service->isPaid($this->rental))->toBeTrue();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionHidden('collectPrivateRentalPayment', arguments: ['rental' => $this->rental->id])
        ->assertActionHidden('changePrivateRental', arguments: ['rental' => $this->rental->id])
        ->assertActionHidden('cancelPrivateRental', arguments: ['rental' => $this->rental->id])
        ->assertSee('Paid');
});

test('a Manager refunds from the desk; Door does not see the button', function () {
    $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    $this->actingAs($this->manager);
    $this->manager->update(['default_register_id' => $this->register->id]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('refundPrivateRental', arguments: ['rental' => $this->rental->id], data: ['payment_method' => 'cash', 'reason' => 'Double booked by mistake'])
        ->assertHasNoActionErrors()
        ->assertNotified('Booking cancelled — refunded $150.00');

    expect($this->rental->fresh()->cancelled_at)->not->toBeNull();
});

test('training mode records no payment', function () {
    session(['checkin.training_mode' => true]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('collectPrivateRentalPayment', arguments: ['rental' => $this->rental->id], data: ['payment_method' => 'cash'])
        ->assertNotified('Practice only — nothing saved');

    expect(PrivateRentalPayment::count())->toBe(0);
});

test('the close-box envelope names rental cash as its own amount', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    $this->service->collectPayment($this->rental, 'cash', $this->shift, $this->door);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 250])
        ->assertHasNoActionErrors();

    expect(sentNotifications()->last()?->getBody())
        ->toContain('Entry: $0.00 · Private rentals: $150.00 · Other: $0.00 (total $150.00)');
});
