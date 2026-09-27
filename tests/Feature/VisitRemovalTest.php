<?php

use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\VisitRemovals\Pages\ListVisitRemovals;
use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Models\Category;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaymentCorrection;
use App\Models\PaymentMethod;
use App\Models\RegisterShift;
use App\Models\User;
use App\Models\VisitRemoval;
use App\Models\Voucher;
use App\Services\RegisterShiftService;
use App\Services\VisitRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['label' => 'Cash', 'requires_register_shift' => true, 'active' => true]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->manager);

    $this->shift = RegisterShift::factory()->create(['opening_count' => 100]);
    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 40]);
    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular', 'is_comped' => false])->id,
    ]);
});

function recordedVisit(object $test, array $overrides = []): Attendance
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

function closeVisitShift(RegisterShift $shift, User $by): void
{
    $service = app(RegisterShiftService::class);
    $service->closeShift($shift, $by, $service->expectedClosingCount($shift));
}

test('a $0 visit is removed by a Manager with no reason and no log', function () {
    $prepay = recordedVisit($this, ['checked_in_at' => null, 'amount_paid' => 0, 'payment_method' => null]);

    expect(app(VisitRemovalService::class)->remove($prepay, $this->manager))->toBeNull();

    expect(Attendance::find($prepay->id))->toBeNull()
        ->and(VisitRemoval::count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(0);
});

test('a paid visit on an open shift is refunded from that drawer, logged, and reported to the Owner', function () {
    $attendance = recordedVisit($this);
    $expectedBefore = app(RegisterShiftService::class)->expectedClosingCountCents($this->shift);

    $removal = app(VisitRemovalService::class)->remove($attendance, $this->manager, 'Duplicate check-in');

    expect(Attendance::find($attendance->id))->toBeNull()
        ->and($removal->member_id)->toBe($this->member->id)
        ->and($removal->event_id)->toBe($this->event->id)
        ->and((float) $removal->amount_paid)->toBe(40.0)
        ->and($removal->payment_method)->toBe('cash')
        ->and($removal->after_shift_closed)->toBeFalse()
        ->and($removal->reason)->toBe('Duplicate check-in')
        ->and($removal->removed_by)->toBe($this->manager->id)
        ->and($expectedBefore - app(RegisterShiftService::class)->expectedClosingCountCents($this->shift))->toBe(4000)
        ->and($this->owner->notifications()->count())->toBe(1);
});

test('a paid visit needs a reason', function () {
    $attendance = recordedVisit($this);

    expect(fn () => app(VisitRemovalService::class)->remove($attendance, $this->manager, ''))
        ->toThrow(HttpException::class);

    expect(Attendance::find($attendance->id))->not->toBeNull();
});

test('once the shift has closed only an Owner can remove a paid visit, and the closed shift does not move', function () {
    $attendance = recordedVisit($this);
    closeVisitShift($this->shift, $this->manager);
    $service = app(RegisterShiftService::class);
    $shift = $this->shift->fresh();
    $expectedBefore = $service->expectedClosingCountCents($shift);
    $varianceBefore = $service->varianceCents($shift);
    $eventRevenueBefore = $service->revenueBreakdown($shift)['event'];

    expect(app(VisitRemovalService::class)->refusalReason($attendance, $this->manager))->not->toBeNull();
    expect(fn () => app(VisitRemovalService::class)->remove($attendance, $this->manager, 'Late fix'))
        ->toThrow(HttpException::class);

    $removal = app(VisitRemovalService::class)->remove($attendance, $this->owner, 'Late fix');

    expect($removal->after_shift_closed)->toBeTrue()
        ->and(Attendance::find($attendance->id))->toBeNull()
        ->and($service->expectedClosingCountCents($shift))->toBe($expectedBefore)
        ->and($service->varianceCents($shift))->toBe($varianceBefore)
        ->and($service->revenueBreakdown($shift)['event'])->toBe($eventRevenueBefore);
});

test('a visit other records point at is refused with a message instead of crashing', function (Closure $attach) {
    $attendance = recordedVisit($this);
    $attach($this, $attendance);

    expect(app(VisitRemovalService::class)->refusalReason($attendance, $this->owner))->toContain('can\'t be removed');

    expect(fn () => app(VisitRemovalService::class)->remove($attendance, $this->owner, 'Try'))
        ->toThrow(HttpException::class);

    expect(Attendance::find($attendance->id))->not->toBeNull();
})->with([
    'voucher activity' => [fn ($test, $attendance) => Voucher::create(['member_id' => $test->member->id, 'amount' => -10, 'reason' => 'Spent', 'attendance_id' => $attendance->id, 'recorded_by' => $test->manager->id])],
    'behavior note' => [fn ($test, $attendance) => AttendanceBehaviorNote::create(['attendance_id' => $attendance->id, 'note' => 'Loud', 'created_by' => $test->manager->id])],
    'comp request' => [fn ($test, $attendance) => CompRequest::factory()->create(['event_id' => $test->event->id, 'member_id' => $test->member->id, 'attendance_id' => $attendance->id])],
    'payment correction' => [fn ($test, $attendance) => PaymentCorrection::factory()->create(['attendance_id' => $attendance->id])],
]);

test('Door cannot remove a visit', function () {
    $attendance = recordedVisit($this, ['amount_paid' => 0]);
    $door = User::factory()->create(['role' => Role::Door]);

    expect(app(VisitRemovalService::class)->refusalReason($attendance, $door))->not->toBeNull();
});

test('the generic delete policy only allows $0 visits', function () {
    $paid = recordedVisit($this);
    $unpaid = recordedVisit($this, ['event_id' => Event::factory()->create()->id, 'amount_paid' => 0]);

    expect($this->owner->can('delete', $paid))->toBeFalse()
        ->and($this->manager->can('delete', $unpaid))->toBeTrue();
});

test('the bulk delete on an attendance tab skips paid visits', function () {
    $paid = recordedVisit($this);
    $unpaid = recordedVisit($this, ['event_id' => Event::factory()->create()->id, 'amount_paid' => 0]);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->callTableBulkAction('delete', [$paid, $unpaid]);

    expect(Attendance::find($paid->id))->not->toBeNull()
        ->and(Attendance::find($unpaid->id))->toBeNull();
});

test('a Manager removes a paid visit from the member\'s Attendance tab with a reason', function () {
    $attendance = recordedVisit($this);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->callTableAction('removeVisit', $attendance, data: ['reason' => 'Rang up twice'])
        ->assertHasNoTableActionErrors();

    expect(VisitRemoval::sole()->reason)->toBe('Rang up twice');
});

test('the Remove action requires a reason for a paid visit', function () {
    $attendance = recordedVisit($this);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->callTableAction('removeVisit', $attendance, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect(Attendance::find($attendance->id))->not->toBeNull();
});

test('the Remove action is hidden for a Manager once the shift has closed', function () {
    $attendance = recordedVisit($this);
    closeVisitShift($this->shift, $this->manager);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
        ->assertTableActionHidden('removeVisit', $attendance);
});

test('a prepay is removed from the Prepay List with no reason', function () {
    $this->event->update(['door_prepay_enabled' => true, 'event_date' => today()->addWeek()->toDateString()]);
    $prepay = recordedVisit($this, ['checked_in_at' => null, 'amount_paid' => 0, 'payment_method' => null, 'register_shift_id' => null]);

    Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
        ->callTableAction('removeVisit', $prepay)
        ->assertHasNoTableActionErrors();

    expect(Attendance::find($prepay->id))->toBeNull();
});

test('an event with a removed paid visit can no longer be deleted', function () {
    $attendance = recordedVisit($this);
    app(VisitRemovalService::class)->remove($attendance, $this->manager, 'Duplicate');

    expect($this->event->fresh()->hasRecordedActivity())->toBeTrue();
});

test('a visit removal can never be created, updated or deleted by hand, even by an Owner', function () {
    $removal = VisitRemoval::factory()->create();

    expect($this->owner->can('create', VisitRemoval::class))->toBeFalse()
        ->and($this->owner->can('update', $removal))->toBeFalse()
        ->and($this->owner->can('delete', $removal))->toBeFalse()
        ->and($this->manager->can('viewAny', VisitRemoval::class))->toBeTrue();
});

test('the Visit removals list renders for a Manager', function () {
    app(VisitRemovalService::class)->remove(recordedVisit($this), $this->manager, 'Rang up twice');

    Livewire::test(ListVisitRemovals::class)
        ->assertSuccessful()
        ->assertSee('Rang up twice');
});
