<?php

use App\Enums\Role;
use App\Enums\ShiftPreference;
use App\Filament\Admin\Pages\MyAvailability;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftAvailability;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\ShiftWeekNote;
use App\Models\User;
use App\Services\ShiftAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Wednesday 11 Nov 2026; the schedule week runs Thursday to Wednesday.
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);

    $this->staff = User::factory()->create(['role' => Role::Volunteer, 'active' => true]);
    $this->actingAs($this->staff);

    $this->door = ShiftRole::factory()->create(['name' => 'Door']);
    $this->bar = ShiftRole::factory()->create(['name' => 'Bar']);
    $this->door->users()->attach($this->staff);

    $this->event = Event::factory()->create([
        'name' => 'Friday Social',
        'event_date' => '2026-11-13',
        'starts_at' => Carbon::parse('2026-11-13 21:00'),
        'ends_at' => Carbon::parse('2026-11-14 02:00'),
    ]);
    $this->doorSlot = shiftSlotAt($this, $this->door, '2026-11-13 20:30');
    $this->barSlot = shiftSlotAt($this, $this->bar, '2026-11-13 21:00');
});

function shiftSlotAt(object $test, ShiftRole $role, string $startsAt): ShiftSlot
{
    return ShiftSlot::factory()->create([
        'event_id' => $test->event->id,
        'shift_role_id' => $role->id,
        'starts_at' => Carbon::parse($startsAt),
        'ends_at' => Carbon::parse($startsAt)->addHours(4),
    ]);
}

test('only someone with an active shift role, with the flag on, can give availability', function () {
    $noRole = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    expect(Gate::forUser($this->staff)->allows('give-shift-availability'))->toBeTrue()
        ->and(Gate::forUser($noRole)->allows('give-shift-availability'))->toBeFalse();

    $this->door->update(['active' => false]);
    expect(Gate::forUser($this->staff)->allows('give-shift-availability'))->toBeFalse();

    $this->door->update(['active' => true]);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    expect(Gate::forUser($this->staff)->allows('give-shift-availability'))->toBeFalse();
});

test('the week shows only shifts of roles they can work, in that week, on unarchived events', function () {
    $nextWeek = shiftSlotAt($this, $this->door, '2026-11-19 20:00');
    $archived = Event::factory()->create(['event_date' => '2026-11-14', 'archived_at' => now()]);
    ShiftSlot::factory()->create(['event_id' => $archived->id, 'shift_role_id' => $this->door->id, 'starts_at' => '2026-11-14 20:00', 'ends_at' => '2026-11-14 23:00']);

    $slots = app(ShiftAvailabilityService::class)->slotsFor($this->staff, Carbon::parse('2026-11-12'));

    expect($slots->modelKeys())->toBe([$this->doorSlot->id])
        ->and(app(ShiftAvailabilityService::class)->slotsFor($this->staff, Carbon::parse('2026-11-21'))->modelKeys())->toBe([$nextWeek->id]);
});

test('saving records, changes and clears answers and the week note', function () {
    $service = app(ShiftAvailabilityService::class);
    $week = Carbon::parse('2026-11-12');

    expect($service->save($this->staff, $week, [$this->doorSlot->id => 'preferred'], 'Leaving early Saturday'))->toBe(1)
        ->and(ShiftAvailability::sole()->preference)->toBe(ShiftPreference::Preferred)
        ->and($service->noteFor($this->staff, $week))->toBe('Leaving early Saturday');

    expect($service->save($this->staff, $week, [$this->doorSlot->id => 'preferred'], 'Leaving early Saturday'))->toBe(0)
        ->and($service->save($this->staff, $week, [$this->doorSlot->id => 'unavailable'], 'Leaving early Sunday'))->toBe(1)
        ->and(ShiftAvailability::sole()->preference)->toBe(ShiftPreference::Unavailable)
        ->and(ShiftWeekNote::sole()->note)->toBe('Leaving early Sunday');

    $service->save($this->staff, $week, [$this->doorSlot->id => null], '');

    expect(ShiftAvailability::count())->toBe(0)
        ->and(ShiftWeekNote::count())->toBe(0);
});

test('a forged answer for another role\'s shift, or a shift that has started, is ignored', function () {
    $started = shiftSlotAt($this, $this->door, '2026-11-11 10:00');

    $changed = app(ShiftAvailabilityService::class)->save($this->staff, Carbon::parse('2026-11-12'), [
        $this->barSlot->id => 'preferred',
        $started->id => 'available',
        $this->doorSlot->id => 'available',
    ], null);

    expect($changed)->toBe(1)
        ->and(ShiftAvailability::pluck('shift_slot_id')->all())->toBe([$this->doorSlot->id]);
});

test('staff answer from My availability, which opens on the week ahead', function () {
    $nextWeek = shiftSlotAt($this, $this->door, '2026-11-20 20:00');

    // Wednesday 11 Nov: the week ahead is Thursday 12 to Wednesday 18, with Friday Social.
    Livewire::test(MyAvailability::class)
        ->assertSet('weekStart', '2026-11-12')
        ->assertSee('Door, Friday Social')
        ->assertDontSee('Bar, Friday Social')
        ->fillForm(['slot_'.$this->doorSlot->id => 'preferred', 'note' => 'Can stay late'])
        ->callAction('save')
        ->assertHasNoActionErrors()
        ->callAction('nextWeek')
        ->assertFormFieldExists('slot_'.$nextWeek->id)
        ->assertFormFieldDoesNotExist('slot_'.$this->doorSlot->id)
        ->callAction('previousWeek')
        ->assertFormSet(['slot_'.$this->doorSlot->id => ShiftPreference::Preferred, 'note' => 'Can stay late']);

    expect(ShiftAvailability::sole()->only(['shift_slot_id', 'user_id']))->toBe(['shift_slot_id' => $this->doorSlot->id, 'user_id' => $this->staff->id])
        ->and(ShiftWeekNote::sole()->note)->toBe('Can stay late');
});

test('a week with no shifts for their roles says so', function () {
    Livewire::test(MyAvailability::class)
        ->callAction('previousWeek')
        ->assertSee('There are no shifts for your roles this week yet.');
});

test('My availability can\'t go back before the current week', function () {
    Livewire::test(MyAvailability::class)
        ->callAction('previousWeek')
        ->assertSet('weekStart', '2026-11-05')
        ->assertActionDisabled('previousWeek');
});

test('below Manager, someone without a shift role can\'t open My availability; a Manager can', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Door, 'active' => true]))
        ->get(MyAvailability::getUrl())
        ->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => Role::Manager, 'active' => true]))
        ->get(MyAvailability::getUrl())
        ->assertSuccessful();
});

test('a Manager enters availability for someone, and it records who entered it', function () {
    $manager = User::factory()->create(['name' => 'Morgan', 'role' => Role::Manager, 'active' => true]);
    $this->actingAs($manager);

    Livewire::test(MyAvailability::class)
        ->assertSee('Pick a person to enter their availability.')
        ->set('personData.person_id', $this->staff->id)
        ->assertSet('personId', $this->staff->id)
        ->assertSee('Door, Friday Social')
        ->fillForm(['slot_'.$this->doorSlot->id => 'available', 'note' => 'Called in'])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(ShiftAvailability::sole()->only(['user_id', 'entered_by']))->toBe(['user_id' => $this->staff->id, 'entered_by' => $manager->id])
        ->and(ShiftWeekNote::sole()->only(['user_id', 'entered_by', 'note']))->toBe(['user_id' => $this->staff->id, 'entered_by' => $manager->id, 'note' => 'Called in']);

    // The staff member sees who entered it.
    $this->actingAs($this->staff);
    Livewire::test(MyAvailability::class)->assertSee('Entered by Morgan.');
});

test('staff answering for themselves record themselves, with no "Entered by"', function () {
    Livewire::test(MyAvailability::class)
        ->fillForm(['slot_'.$this->doorSlot->id => 'preferred'])
        ->callAction('save');

    expect(ShiftAvailability::sole()->entered_by)->toBe($this->staff->id);

    Livewire::test(MyAvailability::class)->assertDontSee('Entered by');
});

test('below Manager, nobody can answer for someone else', function () {
    $other = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $this->door->users()->attach($other);

    expect(fn () => app(ShiftAvailabilityService::class)->save($other, Carbon::parse('2026-11-12'), [$this->doorSlot->id => 'available'], null, $this->staff))
        ->toThrow(HttpException::class);

    // A forged person on the page falls back to their own availability.
    Livewire::test(MyAvailability::class)
        ->assertDontSee('Person')
        ->set('personId', $other->id)
        ->fillForm(['slot_'.$this->doorSlot->id => 'available'])
        ->callAction('save');

    expect(ShiftAvailability::sole()->user_id)->toBe($this->staff->id);
});

test('a Manager can only pick someone on the roster', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Manager, 'active' => true]));
    $notOnRoster = User::factory()->create(['active' => true]);

    Livewire::test(MyAvailability::class)
        ->set('personId', $notOnRoster->id)
        ->assertSee('Pick a person to enter their availability.')
        ->assertActionDisabled('save');
});
