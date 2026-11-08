<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MyAvailability;
use App\Filament\Admin\Pages\StaffSchedule;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ScheduleWeek;
use App\Models\ShiftAssignment;
use App\Models\ShiftAvailability;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftAvailabilityService;
use App\Services\ShiftScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Wednesday 11 Nov 2026; the schedule week runs Thursday to Wednesday,
    // so the week ahead is Thursday 12 to Wednesday 18.
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);

    $this->owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->actingAs($this->owner);

    $this->door = ShiftRole::factory()->create(['name' => 'Door']);
    $this->bar = ShiftRole::factory()->create(['name' => 'Bar']);
    $this->alex = User::factory()->create(['name' => 'Alex', 'role' => Role::Volunteer, 'active' => true]);
    $this->sam = User::factory()->create(['name' => 'Sam', 'role' => Role::Door, 'active' => true]);
    $this->jo = User::factory()->create(['name' => 'Jo', 'role' => Role::Door, 'active' => true]);
    $this->door->users()->attach([$this->alex->id, $this->sam->id, $this->jo->id]);
    $this->bar->users()->attach([$this->alex->id]);

    $this->event = Event::factory()->create([
        'name' => 'Friday Social',
        'event_date' => '2026-11-13',
        'starts_at' => Carbon::parse('2026-11-13 21:00'),
        'ends_at' => Carbon::parse('2026-11-14 02:00'),
    ]);
    $this->doorSlot = ShiftSlot::factory()->create(['event_id' => $this->event->id, 'shift_role_id' => $this->door->id, 'starts_at' => '2026-11-13 20:30', 'ends_at' => '2026-11-14 00:30', 'headcount' => 2]);
    $this->barSlot = ShiftSlot::factory()->create(['event_id' => $this->event->id, 'shift_role_id' => $this->bar->id, 'starts_at' => '2026-11-13 22:00', 'ends_at' => '2026-11-14 02:00', 'headcount' => 1]);

    $this->week = Carbon::parse('2026-11-12');
});

test('the picker lists who can work the role, best answer first', function () {
    ShiftAvailability::create(['shift_slot_id' => $this->doorSlot->id, 'user_id' => $this->jo->id, 'preference' => 'preferred']);
    ShiftAvailability::create(['shift_slot_id' => $this->doorSlot->id, 'user_id' => $this->alex->id, 'preference' => 'unavailable']);

    $slot = app(ShiftScheduleService::class)->slotsFor($this->week)->firstWhere('id', $this->doorSlot->id);

    expect(app(ShiftScheduleService::class)->candidateOptions($slot))->toBe([
        $this->jo->id => 'Jo (Preferred)',
        $this->sam->id => 'Sam (no answer)',
        $this->alex->id => 'Alex (No)',
    ]);
});

test('assigning saves, swaps and removes people', function () {
    $service = app(ShiftScheduleService::class);

    expect($service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id, $this->jo->id]]))->toBe(2)
        ->and($service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id]]))->toBe(1)
        ->and(ShiftAssignment::sole()->only(['user_id', 'assigned_by']))->toBe(['user_id' => $this->sam->id, 'assigned_by' => $this->owner->id]);
});

test('a shift refuses someone who can\'t work its role, too many people, or a double booking', function (Closure $people, string $message) {
    expect(fn () => app(ShiftScheduleService::class)->assign($this->owner, $this->week, $people($this)))
        ->toThrow(ValidationException::class, $message);

    expect(ShiftAssignment::count())->toBe(0);
})->with([
    'not on the role' => [fn ($test) => [$test->barSlot->id => [$test->sam->id]], 'Only people who can work Bar'],
    'too many' => [fn ($test) => [$test->barSlot->id => [$test->alex->id, $test->alex->id + 1000]], 'Only people who can work Bar'],
    'over the headcount' => [function ($test) {
        $test->bar->users()->attach($test->sam);

        return [$test->barSlot->id => [$test->alex->id, $test->sam->id]];
    }, 'This shift needs 1 person.'],
    'overlapping shifts' => [fn ($test) => [$test->doorSlot->id => [$test->alex->id], $test->barSlot->id => [$test->alex->id]], 'Alex is already on'],
]);

test('a double booking against a shift saved earlier is refused too', function () {
    $service = app(ShiftScheduleService::class);
    $service->assign($this->owner, $this->week, [$this->barSlot->id => [$this->alex->id]]);

    expect(fn () => $service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->alex->id]]))
        ->toThrow(ValidationException::class, 'Alex is already on Bar');
});

test('publishing bells everyone scheduled with their shifts, once', function () {
    $service = app(ShiftScheduleService::class);
    $service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id], $this->barSlot->id => [$this->alex->id]]);

    $service->publish($this->owner, $this->week);
    $service->publish($this->owner, $this->week);

    expect(ScheduleWeek::count())->toBe(1)
        ->and($this->sam->notifications()->count())->toBe(1)
        ->and($this->sam->notifications()->first()->data['body'])->toContain('Door, Friday Social')
        ->and($this->alex->notifications()->first()->data['body'])->toContain('Bar, Friday Social')
        ->and($this->jo->notifications()->count())->toBe(0);
});

test('before publishing, changes notify no one; after, each change notifies that person', function () {
    $service = app(ShiftScheduleService::class);
    $service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id]]);
    expect($this->sam->notifications()->count())->toBe(0);

    $service->publish($this->owner, $this->week);
    $service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->jo->id]]);

    expect($this->jo->notifications()->sole()->data['title'])->toBe('You\'ve been added to a shift')
        ->and($this->sam->notifications->pluck('data.title')->sort()->values()->all())->toBe([
            'You\'ve been taken off a shift',
            'Your shifts for the week of Thu Nov 12, 2026',
        ]);
});

test('once a week is published, availability for it is locked', function () {
    app(ShiftScheduleService::class)->publish($this->owner, $this->week);

    expect(app(ShiftAvailabilityService::class)->isLocked($this->doorSlot))->toBeTrue()
        ->and(app(ShiftAvailabilityService::class)->save($this->sam, $this->week, [$this->doorSlot->id => 'preferred'], null))->toBe(0);

    $this->actingAs($this->sam);
    Livewire::test(MyAvailability::class)->assertFormFieldIsDisabled('slot_'.$this->doorSlot->id);
});

test('a started shift can\'t be changed', function () {
    $this->travelTo(Carbon::parse('2026-11-13 23:00'));

    expect(app(ShiftScheduleService::class)->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id]]))->toBe(0);
});

test('Admin builds and publishes the week from the Staff schedule page', function () {
    Livewire::test(StaffSchedule::class)
        ->assertSet('weekStart', '2026-11-12')
        ->assertSee('Not published')
        ->fillForm(['slot_'.$this->doorSlot->id => [$this->sam->id, $this->jo->id]])
        ->callAction('save')
        ->assertHasNoActionErrors()
        ->callAction('publish')
        ->assertSee('Published');

    expect(ShiftAssignment::count())->toBe(2)
        ->and(ScheduleWeek::count())->toBe(1);
});

test('the page shows a refused save against the shift', function () {
    Livewire::test(StaffSchedule::class)
        ->fillForm(['slot_'.$this->doorSlot->id => [$this->alex->id], 'slot_'.$this->barSlot->id => [$this->alex->id]])
        ->callAction('save')
        ->assertHasFormErrors(['slot_'.$this->barSlot->id]);

    expect(ShiftAssignment::count())->toBe(0);
});

test('the Staff schedule is Admin and up, with the flag on', function (Role $role, bool $allowed) {
    expect(Gate::forUser(User::factory()->create(['role' => $role, 'active' => true]))->allows('build-staff-schedule'))->toBe($allowed);
})->with([
    [Role::Manager, false],
    [Role::Admin, true],
    [Role::Owner, true],
]);

test('the Staff schedule is closed while the flag is off', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    $this->get(StaffSchedule::getUrl())->assertForbidden();
});
