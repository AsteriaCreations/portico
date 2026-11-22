<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodCorrection;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\PaymentMethodCorrectionService;
use App\Services\RegisterShiftService;
use App\Services\VisitRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['label' => 'Cash', 'requires_register_shift' => true, 'available_at_desk' => true, 'active' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'venmo'], ['label' => 'Venmo', 'requires_register_shift' => false, 'one_time_only' => true, 'available_at_desk' => true, 'active' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'card'], ['label' => 'Card', 'requires_register_shift' => false, 'available_at_desk' => true, 'active' => true]);
    PaymentMethod::forgetCashCodes();

    $this->door = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $this->owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->door);

    $this->shift = RegisterShift::factory()->create();
    $this->event = Event::factory()->create([
        'event_date' => today()->toDateString(),
        'starts_at' => today()->setTime(0, 0),
        'ends_at' => today()->setTime(23, 59),
        'entry_fee' => 40,
    ]);
    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular', 'is_comped' => false])->id,
    ]);
});

/**
 * A $40 entry on tonight's event recorded as Venmo, on the open shift.
 */
function misrecordedVisit(object $test, array $overrides = []): Attendance
{
    return Attendance::create([
        'member_id' => $test->member->id,
        'event_id' => $test->event->id,
        'checked_in_by' => $test->door->id,
        'checked_in_at' => now()->subHours(2),
        'entry_fee' => 40,
        'entry_coverage' => 0,
        'entry_covered_by' => EntryCoverageSource::None,
        'voucher_coverage' => 0,
        'amount_paid' => 40,
        'payment_method' => 'venmo',
        'register_shift_id' => $test->shift->id,
        ...$overrides,
    ]);
}

test('correcting Venmo to cash moves the money into the drawer, keeps the amount, logs it and notifies the Owner', function () {
    $attendance = misrecordedVisit($this);
    $registers = app(RegisterShiftService::class);
    $cashBefore = $registers->cashReceivedCents($this->shift);

    $correction = app(PaymentMethodCorrectionService::class)->correct($attendance, $this->door, 'cash', 'Paid cash, desk picked Venmo');

    $attendance->refresh();
    expect($attendance->payment_method)->toBe('cash')
        ->and((float) $attendance->amount_paid)->toBe(40.0)
        ->and($attendance->departed_at)->toBeNull()
        ->and($correction->old_payment_method)->toBe('venmo')
        ->and($correction->new_payment_method)->toBe('cash')
        ->and((float) $correction->amount)->toBe(40.0)
        ->and($correction->register_shift_id)->toBe($this->shift->id)
        ->and($correction->corrected_by)->toBe($this->door->id)
        ->and($registers->cashReceivedCents($this->shift))->toBe($cashBefore + 4000)
        ->and($registers->electronicByMethodCents($this->shift))->toBe([])
        ->and($this->owner->notifications()->count())->toBe(1);
});

test('moving a visit off a one-time method frees it, and onto one spends it', function () {
    $attendance = misrecordedVisit($this);
    $service = app(PaymentMethodCorrectionService::class);

    expect($this->member->isOneTimeMethodLocked())->toBeTrue();

    $service->correct($attendance, $this->door, 'cash', 'Paid cash');
    expect($this->member->fresh()->isOneTimeMethodLocked())->toBeFalse();

    $service->correct($attendance->fresh(), $this->door, 'venmo', 'Actually Venmo after all');
    expect($this->member->fresh()->isOneTimeMethodLocked())->toBeTrue()
        ->and($this->member->fresh()->hospitality_note)->toContain('Venmo used');
});

test('a one-time method already spent on another visit isn\'t offered', function () {
    $otherEvent = Event::factory()->create(['event_date' => today()->subMonth()->toDateString()]);
    misrecordedVisit($this, ['event_id' => $otherEvent->id, 'register_shift_id' => null]);
    $attendance = misrecordedVisit($this, ['payment_method' => 'cash']);

    $service = app(PaymentMethodCorrectionService::class);

    expect($service->methodOptions($attendance))->toHaveKey('card')->not->toHaveKey('venmo')->not->toHaveKey('cash')
        ->and(fn () => $service->correct($attendance, $this->door, 'venmo', 'x'))->toThrow(HttpException::class)
        ->and($attendance->fresh()->payment_method)->toBe('cash');
});

test('a cash method isn\'t offered for a visit with no register shift', function () {
    $attendance = misrecordedVisit($this, ['register_shift_id' => null]);

    expect(app(PaymentMethodCorrectionService::class)->methodOptions($attendance))->toHaveKey('card')->not->toHaveKey('cash');
});

test('the correction is refused when :dataset', function (Closure $setUp, string $message) {
    $attendance = $setUp($this);

    expect(app(PaymentMethodCorrectionService::class)->refusalReason($attendance, $this->door))->toContain($message)
        ->and(fn () => app(PaymentMethodCorrectionService::class)->correct($attendance, $this->door, 'card', 'x'))->toThrow(HttpException::class)
        ->and(PaymentMethodCorrection::count())->toBe(0);
})->with([
    'nothing was paid' => [fn ($test) => misrecordedVisit($test, ['entry_coverage' => 40, 'entry_covered_by' => EntryCoverageSource::Comp, 'amount_paid' => 0]), 'Nothing was paid'],
    'paid ahead' => [fn ($test) => misrecordedVisit($test, ['prepaid_ahead' => true]), 'paid ahead'],
    'not arrived' => [fn ($test) => misrecordedVisit($test, ['checked_in_at' => null]), 'hasn\'t arrived'],
    'the shift has closed' => [function ($test) {
        $test->shift->update(['closed_at' => now()]);

        return misrecordedVisit($test);
    }, 'shift has closed'],
    'the event isn\'t tonight' => [function ($test) {
        $test->event->update(['event_date' => today()->subWeek(), 'starts_at' => today()->subWeek(), 'ends_at' => today()->subWeek()->setTime(23, 0)]);

        return misrecordedVisit($test);
    }, 'tonight'],
]);

test('the gate is Door and up', function (Role $role, bool $allowed) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->allows('correct-visit-payment-method'))->toBe($allowed);
})->with([
    [Role::Volunteer, false],
    [Role::DM, false],
    [Role::Door, true],
    [Role::Manager, true],
]);

test('Door corrects the method from the Check-In Desk with a reason', function () {
    $attendance = misrecordedVisit($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionVisible('correctPaymentMethod')
        ->callAction('correctPaymentMethod', data: ['payment_method' => 'cash', 'reason' => 'Paid cash'])
        ->assertHasNoActionErrors();

    expect($attendance->fresh()->payment_method)->toBe('cash')
        ->and(PaymentMethodCorrection::sole()->reason)->toBe('Paid cash');
});

test('the desk requires a method and a reason', function () {
    misrecordedVisit($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->callAction('correctPaymentMethod', data: ['payment_method' => null, 'reason' => ''])
        ->assertHasActionErrors(['payment_method' => 'required', 'reason' => 'required']);

    expect(PaymentMethodCorrection::count())->toBe(0);
});

test('the desk button is hidden for a $0 visit', function () {
    misrecordedVisit($this, ['entry_coverage' => 40, 'entry_covered_by' => EntryCoverageSource::Comp, 'amount_paid' => 0, 'payment_method' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionHidden('correctPaymentMethod');
});

test('a corrected visit can\'t be removed and its correction can\'t be edited or deleted', function () {
    $attendance = misrecordedVisit($this);
    $correction = app(PaymentMethodCorrectionService::class)->correct($attendance, $this->door, 'cash', 'Paid cash');
    $manager = User::factory()->create(['role' => Role::Manager]);

    expect(app(VisitRemovalService::class)->refusalReason($attendance->fresh(), $manager))->toContain('payment method correction')
        ->and(Gate::forUser($this->owner)->allows('update', $correction))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('delete', $correction))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('viewAny', PaymentMethodCorrection::class))->toBeTrue()
        ->and(Gate::forUser($this->door)->allows('viewAny', PaymentMethodCorrection::class))->toBeFalse();
});
