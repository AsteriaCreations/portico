<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ShiftsWorked;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\ShiftSlotsRelationManager;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftWorkService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->manager = User::factory()->create(['name' => 'Morgan', 'role' => Role::Manager, 'active' => true]);
    $this->sam = User::factory()->create(['name' => 'Sam', 'role' => Role::Door, 'active' => true]);
    $this->jo = User::factory()->create(['name' => 'Jo', 'role' => Role::Door, 'active' => true]);

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->host = ShiftRole::factory()->create(['name' => 'Host', 'counts_toward_requirement' => false]);
    $this->desk->users()->attach([$this->sam->id, $this->jo->id]);
    $this->host->users()->attach([$this->sam->id]);

    $this->event = Event::factory()->create([
        'event_date' => '2026-11-20',
        'starts_at' => Carbon::parse('2026-11-20 21:00'),
        'ends_at' => Carbon::parse('2026-11-21 03:00'),
    ]);
});

function deskShift(object $test, string $startsAt, ?ShiftRole $role = null): ShiftSlot
{
    return ShiftSlot::factory()->create([
        'event_id' => $test->event->id,
        'shift_role_id' => ($role ?? $test->desk)->id,
        'kind' => 'open',
        'starts_at' => Carbon::parse($startsAt),
        'ends_at' => Carbon::parse($startsAt)->addHours(3),
        'headcount' => 2,
    ]);
}

function schedule(ShiftSlot $slot, User $user, User $by, ?string $workedAt = null): ShiftAssignment
{
    return ShiftAssignment::create(['shift_slot_id' => $slot->id, 'user_id' => $user->id, 'assigned_by' => $by->id, 'worked_at' => $workedAt]);
}

test('signing in during a scheduled shift marks it worked, from an hour before it starts', function (string $now, bool $worked) {
    $slot = deskShift($this, '2026-11-20 21:00');
    schedule($slot, $this->sam, $this->manager);
    $this->travelTo(Carbon::parse($now));

    event(new Login('web', $this->sam, false));

    $assignment = ShiftAssignment::sole();
    expect($assignment->worked_at !== null)->toBe($worked);

    if ($worked) {
        expect($assignment->worked_source)->toBe('sign_in');
    }
})->with([
    'well before' => ['2026-11-20 19:30', false],
    'an hour before' => ['2026-11-20 20:00', true],
    'during' => ['2026-11-20 23:00', true],
    'after it ended' => ['2026-11-21 00:30', false],
]);

test('signing in does nothing while staff scheduling is off', function () {
    schedule(deskShift($this, '2026-11-20 21:00'), $this->sam, $this->manager);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    $this->travelTo(Carbon::parse('2026-11-20 22:00'));

    event(new Login('web', $this->sam, false));

    expect(ShiftAssignment::sole()->worked_at)->toBeNull();
});

test('a Manager ticks who worked, unticks a no-show, and adds someone who covered', function () {
    $slot = deskShift($this, '2026-11-20 21:00');
    schedule($slot, $this->sam, $this->manager, '2026-11-20 21:05');
    $this->travelTo(Carbon::parse('2026-11-21 10:00'));

    // Sam didn't actually stay; Jo covered.
    expect(app(ShiftWorkService::class)->recordWorked($slot, $this->manager, [$this->jo->id]))->toBe(2);

    expect(ShiftAssignment::where('user_id', $this->sam->id)->sole()->worked_at)->toBeNull()
        ->and(ShiftAssignment::where('user_id', $this->jo->id)->sole()->only(['worked_source', 'worked_recorded_by']))
        ->toBe(['worked_source' => 'manager', 'worked_recorded_by' => $this->manager->id]);
});

test('who worked can\'t be recorded before a shift starts, by Door, or for someone who can\'t work the role', function () {
    $slot = deskShift($this, '2026-11-20 21:00');
    $outsider = User::factory()->create(['active' => true]);
    $this->travelTo(Carbon::parse('2026-11-20 12:00'));

    expect(fn () => app(ShiftWorkService::class)->recordWorked($slot, $this->manager, [$this->sam->id]))->toThrow(HttpException::class);

    $this->travelTo(Carbon::parse('2026-11-21 10:00'));

    expect(fn () => app(ShiftWorkService::class)->recordWorked($slot, $this->sam, [$this->sam->id]))->toThrow(HttpException::class)
        ->and(app(ShiftWorkService::class)->recordWorked($slot, $this->manager, [$outsider->id]))->toBe(0);
});

test('a Manager records who worked from the event\'s Staffing tab', function () {
    $slot = deskShift($this, '2026-11-20 21:00');
    schedule($slot, $this->sam, $this->manager);
    $this->travelTo(Carbon::parse('2026-11-21 10:00'));
    $this->actingAs($this->manager);

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
        ->assertTableActionVisible('whoWorked', $slot)
        ->callTableAction('whoWorked', $slot, data: ['worked' => [$this->sam->id]])
        ->assertHasNoTableActionErrors();

    expect(ShiftAssignment::sole()->worked_at)->not->toBeNull();
});

test('the monthly count only counts worked shifts of roles that count, in the month they start', function () {
    $deskA = deskShift($this, '2026-11-20 21:00');
    $deskB = deskShift($this, '2026-11-21 00:30');
    $hosting = deskShift($this, '2026-11-20 18:00', $this->host);
    $december = deskShift($this, '2026-12-01 21:00');

    schedule($deskA, $this->sam, $this->manager, '2026-11-20 21:00');
    schedule($deskB, $this->sam, $this->manager, '2026-11-21 00:30');
    schedule($hosting, $this->sam, $this->manager, '2026-11-20 18:00');
    schedule($december, $this->sam, $this->manager, '2026-12-01 21:00');
    schedule($deskA, $this->jo, $this->manager);

    expect(app(ShiftWorkService::class)->workedCountsFor(Carbon::parse('2026-11-15')))->toBe([$this->sam->id => 2]);
});

test('Shifts Worked shows each person against the requirement', function () {
    MembershipSetting::current()->update(['staff_monthly_shift_requirement' => 1]);
    $this->travelTo(Carbon::parse('2026-11-25 12:00'));
    $deskA = deskShift($this, '2026-11-20 21:00');
    $deskB = deskShift($this, '2026-11-21 00:30');
    schedule($deskA, $this->sam, $this->manager, '2026-11-20 21:00');
    schedule($deskB, $this->sam, $this->manager, '2026-11-21 00:30');
    $this->actingAs($this->manager);

    Livewire::test(ShiftsWorked::class)
        ->assertSee('Requirement: 1 shift a month')
        ->assertCanSeeTableRecords([$this->sam, $this->jo])
        ->assertTableColumnStateSet('worked', 2, $this->sam)
        ->assertTableColumnStateSet('beyond', 1, $this->sam)
        ->assertTableColumnStateSet('status', 'Met', $this->sam)
        ->assertTableColumnStateSet('status', 'Short by 1', $this->jo)
        ->filterTable('status', 'short')
        ->assertCanSeeTableRecords([$this->jo])
        ->assertCanNotSeeTableRecords([$this->sam])
        ->filterTable('status', 'beyond')
        ->assertCanSeeTableRecords([$this->sam])
        ->assertCanNotSeeTableRecords([$this->jo])
        ->callAction('previousMonth')
        ->assertSet('month', '2026-10');
});

test('recording and viewing shifts worked is Manager and up, with the flag on', function () {
    expect(Gate::forUser($this->sam)->allows('record-shifts-worked'))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('record-shifts-worked'))->toBeTrue();

    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    $this->actingAs($this->manager)->get(ShiftsWorked::getUrl())->assertForbidden();
});
