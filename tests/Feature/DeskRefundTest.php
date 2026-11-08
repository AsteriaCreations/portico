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
use App\Models\MembershipSetting;
use App\Models\PaymentCorrection;
use App\Models\PaymentMethod;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\CapacityService;
use App\Services\DeskRefundService;
use App\Services\RegisterShiftService;
use App\Services\ShowrunnerPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['label' => 'Cash', 'requires_register_shift' => true, 'active' => true]);
    MembershipSetting::current()->update(['desk_refunds_enabled' => true]);

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
 * A $40 cash entry on tonight's event, checked in 10 minutes ago on the open shift.
 */
function refundableVisit(object $test, array $overrides = []): Attendance
{
    return Attendance::create([
        'member_id' => $test->member->id,
        'event_id' => $test->event->id,
        'checked_in_by' => $test->door->id,
        'checked_in_at' => now()->subMinutes(10),
        'entry_fee' => 40,
        'entry_coverage' => 0,
        'entry_covered_by' => EntryCoverageSource::None,
        'voucher_coverage' => 0,
        'amount_paid' => 40,
        'payment_method' => 'cash',
        'register_shift_id' => $test->shift->id,
        ...$overrides,
    ]);
}

test('a desk refund zeroes the visit, marks it departed, logs it, notes it and drops the drawer', function () {
    $attendance = refundableVisit($this);
    $cashBefore = app(RegisterShiftService::class)->cashReceivedCents($this->shift);

    $correction = app(DeskRefundService::class)->refund($attendance, $this->door, 'Changed their mind');

    $attendance->refresh();
    expect((float) $attendance->amount_paid)->toBe(0.0)
        ->and($attendance->departed_at)->not->toBeNull()
        ->and($attendance->isDeskRefunded())->toBeTrue()
        ->and($correction->subscription_id)->toBeNull()
        ->and((float) $correction->old_amount_paid)->toBe(40.0)
        ->and((float) $correction->net_amount)->toBe(-40.0)
        ->and($correction->corrected_by)->toBe($this->door->id)
        ->and($attendance->behaviorNotes()->sole()->note)->toBe('Refunded at the desk: Changed their mind')
        ->and(app(RegisterShiftService::class)->cashReceivedCents($this->shift))->toBe($cashBefore - 4000)
        ->and(app(CapacityService::class)->occupancy(today()))->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(1);
});

test('no behavior note is written while behavior notes are off', function () {
    MembershipSetting::current()->update(['behavior_notes_enabled' => false]);
    $attendance = refundableVisit($this);

    app(DeskRefundService::class)->refund($attendance, $this->door, 'Left');

    expect($attendance->behaviorNotes()->count())->toBe(0)
        ->and(PaymentCorrection::count())->toBe(1);
});

test('the desk refund is refused when :dataset', function (Closure $setUp, string $message) {
    $attendance = $setUp($this);

    $before = PaymentCorrection::count();

    expect(app(DeskRefundService::class)->refusalReason($attendance, $this->door))->toContain($message)
        ->and(fn () => app(DeskRefundService::class)->refund($attendance, $this->door, 'Left'))->toThrow(HttpException::class)
        ->and(PaymentCorrection::count())->toBe($before)
        ->and($attendance->fresh()->departed_at)->toBeNull();
})->with([
    'the flag is off' => [function ($test) {
        MembershipSetting::current()->update(['desk_refunds_enabled' => false]);

        return refundableVisit($test);
    }, 'turned off'],
    'past the window' => [fn ($test) => refundableVisit($test, ['checked_in_at' => now()->subMinutes(31)]), 'more than 30 minutes'],
    'nothing was paid' => [fn ($test) => refundableVisit($test, ['entry_coverage' => 40, 'entry_covered_by' => EntryCoverageSource::Comp, 'amount_paid' => 0]), 'Nothing was paid'],
    'paid ahead' => [fn ($test) => refundableVisit($test, ['prepaid_ahead' => true]), 'paid ahead'],
    'voucher credit was used' => [fn ($test) => refundableVisit($test, ['voucher_coverage' => 10, 'amount_paid' => 30]), 'voucher'],
    'the shift has closed' => [function ($test) {
        $test->shift->update(['closed_at' => now()]);

        return refundableVisit($test);
    }, 'shift has closed'],
    'it was already corrected' => [function ($test) {
        $attendance = refundableVisit($test);
        PaymentCorrection::factory()->create(['attendance_id' => $attendance->id]);

        return $attendance;
    }, 'already been corrected'],
]);

test('the window follows Membership Settings', function () {
    MembershipSetting::current()->update(['desk_refund_window_minutes' => 60]);
    $attendance = refundableVisit($this, ['checked_in_at' => now()->subMinutes(45)]);

    expect(app(DeskRefundService::class)->refusalReason($attendance, $this->door))->toBeNull();
});

test('the gate is Door and up, and only with the flag on', function (Role $role, bool $allowed) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->allows('refund-visit-at-desk'))->toBe($allowed);
})->with([
    [Role::Volunteer, false],
    [Role::DM, false],
    [Role::Door, true],
    [Role::Manager, true],
]);

test('Door refunds from the Check-In Desk with a reason, and the visit can\'t be marked returned', function () {
    $attendance = refundableVisit($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionVisible('refundVisit')
        ->callAction('refundVisit', data: ['reason' => 'Didn\'t like the music'])
        ->assertHasNoActionErrors()
        ->assertSee('Refunded at the desk.')
        ->assertActionHidden('markAsReturned')
        ->assertActionHidden('refundVisit');

    expect(PaymentCorrection::sole()->reason)->toBe('Didn\'t like the music')
        ->and((float) $attendance->fresh()->amount_paid)->toBe(0.0);
});

test('the desk requires a reason', function () {
    refundableVisit($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->callAction('refundVisit', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect(PaymentCorrection::count())->toBe(0);
});

test('the desk button is hidden past the window and with the flag off', function () {
    refundableVisit($this, ['checked_in_at' => now()->subMinutes(40)]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionHidden('refundVisit');

    MembershipSetting::current()->update(['desk_refunds_enabled' => false]);
    Attendance::query()->update(['checked_in_at' => now()]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionHidden('refundVisit');
});

test('a refunded visit doesn\'t count toward showrunner or instructor pay', function () {
    $refunded = refundableVisit($this);
    $other = Member::factory()->create(['category_id' => $this->member->category_id]);
    refundableVisit($this, ['member_id' => $other->id]);

    app(DeskRefundService::class)->refund($refunded, $this->door, 'Left');

    expect(Attendance::query()->where('event_id', $this->event->id)->notDeskRefunded()->count())->toBe(1);

    expect(app(ShowrunnerPayoutService::class)->calculate($this->event)->cashCount)->toBe(1);
});
