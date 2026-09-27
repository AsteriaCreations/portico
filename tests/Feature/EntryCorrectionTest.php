<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\PaymentCorrectionsRelationManager;
use App\Filament\Admin\Resources\PaymentCorrections\Pages\ListPaymentCorrections;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaymentCorrection;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntryCorrectionService;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->plan = Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['label' => 'Cash', 'requires_register_shift' => true, 'active' => true]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->manager);

    $this->shift = RegisterShift::factory()->create();
    $this->event = Event::factory()->create([
        'event_date' => today()->toDateString(),
        'starts_at' => today()->setTime(20, 0),
        'ends_at' => today()->setTime(23, 0),
        'entry_fee' => 40,
    ]);
    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular', 'is_comped' => false])->id,
        'subscription_eligible' => true,
    ]);
});

/**
 * A plain $40 cash entry on tonight's event, on the open shift.
 */
function paidEntry(object $test, array $overrides = []): Attendance
{
    return Attendance::create([
        'member_id' => $test->member->id,
        'event_id' => $test->event->id,
        'checked_in_by' => $test->manager->id,
        'checked_in_at' => now(),
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

test('converting a $40 cash entry records the subscription, re-prices the visit, and nets +$35 in the same drawer', function () {
    $attendance = paidEntry($this);
    $cashBefore = app(RegisterShiftService::class)->cashReceivedCents($this->shift);

    $correction = app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Qualified, paid entry by mistake');

    $attendance->refresh();
    $subscription = Subscription::sole();
    expect($subscription->member_id)->toBe($this->member->id)
        ->and($subscription->add_on_id)->toBe($this->entry->id)
        ->and((float) $subscription->amount_paid)->toBe(60.0)
        ->and($subscription->payment_method)->toBe('cash')
        ->and($subscription->register_shift_id)->toBe($this->shift->id)
        ->and($attendance->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and((float) $attendance->entry_coverage)->toBe(25.0)
        ->and((float) $attendance->amount_paid)->toBe(15.0)
        ->and((float) $correction->net_amount)->toBe(35.0)
        ->and((float) $correction->old_amount_paid)->toBe(40.0)
        ->and((float) $correction->new_amount_paid)->toBe(15.0)
        ->and($correction->reason)->toBe('Qualified, paid entry by mistake')
        ->and($correction->corrected_by)->toBe($this->manager->id)
        ->and(app(RegisterShiftService::class)->cashReceivedCents($this->shift) - $cashBefore)->toBe(3500);

    expect($this->owner->notifications()->count())->toBe(1)
        ->and($this->manager->notifications()->count())->toBe(0);
});

test('a conversion that comes out cheaper records a refund and lowers the drawer by that amount', function () {
    $this->plan->update(['price' => 10]);
    $attendance = paidEntry($this);
    $cashBefore = app(RegisterShiftService::class)->cashReceivedCents($this->shift);

    $correction = app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Swap');

    // $10 subscription + $15 entry = $25 against $40 paid.
    expect((float) $correction->net_amount)->toBe(-15.0)
        ->and(app(RegisterShiftService::class)->cashReceivedCents($this->shift) - $cashBefore)->toBe(-1500)
        ->and(EntryCorrectionService::settlementLabel(-1500))->toContain('Refund');
});

test('add-ons and a transaction fee already folded into amount_paid are preserved', function () {
    // $40 entry + $12 add-on + $1 fee.
    $attendance = paidEntry($this, ['amount_paid' => 53]);

    app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Swap');

    expect((float) $attendance->fresh()->amount_paid)->toBe(28.0);
});

test('a visit that is not a plain paid entry on tonight\'s event cannot be converted', function (Closure $setUp) {
    $attendance = $setUp($this);
    $subscriptionsBefore = Subscription::count();

    expect(app(EntryCorrectionService::class)->refusalReason($attendance))->not->toBeNull();

    expect(fn () => app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Swap'))
        ->toThrow(HttpException::class);

    expect(Subscription::count())->toBe($subscriptionsBefore)
        ->and(PaymentCorrection::count())->toBe(0);
})->with([
    'the event is over' => [function ($test) {
        $test->event->update(['event_date' => today()->subDay()->toDateString(), 'starts_at' => today()->subDay()->setTime(20, 0), 'ends_at' => today()->subDay()->setTime(23, 0)]);

        return paidEntry($test);
    }],
    'not arrived yet' => [fn ($test) => paidEntry($test, ['checked_in_at' => null])],
    'comped entry' => [fn ($test) => paidEntry($test, ['entry_coverage' => 40, 'entry_covered_by' => EntryCoverageSource::Comp, 'amount_paid' => 0])],
    'event comp' => [fn ($test) => paidEntry($test, ['comp_reason_id' => CompReason::factory()->create()->id])],
    'voucher used' => [fn ($test) => paidEntry($test, ['voucher_coverage' => 10, 'amount_paid' => 30])],
    'member not eligible' => [function ($test) {
        $test->member->update(['subscription_eligible' => false]);

        return paidEntry($test);
    }],
    'already subscribed this month' => [function ($test) {
        Subscription::create(['member_id' => $test->member->id, 'add_on_id' => $test->entry->id, 'covered_month' => today()->startOfMonth()->toDateString(), 'amount_paid' => 60, 'paid_on' => now()]);

        return paidEntry($test);
    }],
    'shift already closed' => [function ($test) {
        $test->shift->update(['closed_at' => now(), 'closing_count' => 100]);

        return paidEntry($test);
    }],
]);

test('a visit can only be converted once', function () {
    $attendance = paidEntry($this);
    app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Swap');

    expect(app(EntryCorrectionService::class)->refusalReason($attendance->fresh()))->not->toBeNull()
        ->and(PaymentCorrection::count())->toBe(1);
});

test('the correct-entry-payment gate is Manager+', function (Role $role, bool $allowed) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->allows('correct-entry-payment'))->toBe($allowed);
})->with([
    [Role::Door, false],
    [Role::Manager, true],
    [Role::Owner, true],
]);

test('a Manager converts from the Check-In Desk with a reason', function () {
    $attendance = paidEntry($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionVisible('convertEntryToSubscription')
        ->callAction('convertEntryToSubscription', data: ['reason' => 'Came back to subscribe'])
        ->assertHasNoActionErrors();

    expect(PaymentCorrection::sole()->reason)->toBe('Came back to subscribe')
        ->and((float) $attendance->fresh()->amount_paid)->toBe(15.0);
});

test('the desk requires a reason', function () {
    paidEntry($this);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->callAction('convertEntryToSubscription', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect(PaymentCorrection::count())->toBe(0);
});

test('Door does not get the desk action', function () {
    paidEntry($this);
    $this->actingAs(User::factory()->create(['role' => Role::Door, 'active' => true]));

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionHidden('convertEntryToSubscription');

    expect(PaymentCorrection::count())->toBe(0);
});

test('the desk action is hidden for a visit that cannot be converted', function () {
    paidEntry($this, ['entry_coverage' => 40, 'entry_covered_by' => EntryCoverageSource::Comp, 'amount_paid' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id, 'event_id' => $this->event->id])
        ->assertActionHidden('convertEntryToSubscription');
});

test('a payment correction can never be created, updated or deleted by hand, even by an Owner', function () {
    $correction = PaymentCorrection::factory()->create();

    expect($this->owner->can('create', PaymentCorrection::class))->toBeFalse()
        ->and($this->owner->can('update', $correction))->toBeFalse()
        ->and($this->owner->can('delete', $correction))->toBeFalse()
        ->and($this->manager->can('viewAny', PaymentCorrection::class))->toBeTrue();
});

test('the attendance edit form no longer changes amount paid or payment method', function () {
    PaymentMethod::query()->updateOrCreate(['code' => 'card'], ['label' => 'Card', 'active' => true]);
    $attendance = paidEntry($this);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->callTableAction('edit', $attendance, data: ['amount_paid' => 0, 'payment_method' => 'card', 'notes' => 'Edited'])
        ->assertHasNoTableActionErrors();

    $attendance->refresh();
    expect((float) $attendance->amount_paid)->toBe(40.0)
        ->and($attendance->payment_method)->toBe('cash')
        ->and($attendance->notes)->toBe('Edited');
});

test('the member page lists the member\'s payment corrections', function () {
    $attendance = paidEntry($this);
    app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Swap');

    expect($this->member->paymentCorrections()->count())->toBe(1);

    Livewire::test(PaymentCorrectionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->assertSuccessful()
        ->assertSee('Swap');
});

test('the Payment corrections list renders for a Manager', function () {
    $attendance = paidEntry($this);
    app(EntryCorrectionService::class)->convertToSubscription($attendance, $this->manager, 'Came back to subscribe');

    Livewire::test(ListPaymentCorrections::class)
        ->assertSuccessful()
        ->assertSee('Came back to subscribe');
});
