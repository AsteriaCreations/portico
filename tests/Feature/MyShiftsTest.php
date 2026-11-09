<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MyShifts;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftScheduleService;
use Filament\Schemas\Components\Grid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Friday 13 Nov 2026, in the Thursday 12 to Wednesday 18 schedule week.
    $this->travelTo(Carbon::parse('2026-11-13 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);

    $this->owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $this->sam = User::factory()->create(['name' => 'Sam', 'role' => Role::Door, 'active' => true]);
    $this->jo = User::factory()->create(['name' => 'Jo', 'role' => Role::Door, 'active' => true]);

    $this->door = ShiftRole::factory()->create(['name' => 'Door']);
    $this->bar = ShiftRole::factory()->create(['name' => 'Bar']);
    $this->door->users()->attach([$this->sam->id, $this->jo->id]);
    $this->bar->users()->attach([$this->jo->id]);

    $event = Event::factory()->create([
        'name' => 'Friday Social',
        'event_date' => '2026-11-13',
        'starts_at' => Carbon::parse('2026-11-13 21:00'),
        'ends_at' => Carbon::parse('2026-11-14 02:00'),
    ]);
    $this->doorSlot = ShiftSlot::factory()->create(['event_id' => $event->id, 'shift_role_id' => $this->door->id, 'starts_at' => '2026-11-13 20:30', 'ends_at' => '2026-11-14 00:30', 'headcount' => 2]);
    $this->barSlot = ShiftSlot::factory()->create(['event_id' => $event->id, 'shift_role_id' => $this->bar->id, 'starts_at' => '2026-11-13 22:00', 'ends_at' => '2026-11-14 02:00', 'headcount' => 1]);

    $this->week = Carbon::parse('2026-11-12');
    app(ShiftScheduleService::class)->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id], $this->barSlot->id => [$this->jo->id]]);
});

test('a draft week shows nothing to staff', function () {
    $this->actingAs($this->sam);

    Livewire::test(MyShifts::class)
        ->assertSee('This week\'s schedule isn\'t out yet')
        ->assertDontSee('Friday Social');
});

test('a published week shows every shift, who is on it, and marks your own', function () {
    app(ShiftScheduleService::class)->publish($this->owner, $this->week);
    $this->actingAs($this->sam);

    Livewire::test(MyShifts::class)
        ->assertSet('weekStart', '2026-11-12')
        ->assertSee('Your shifts this week')
        ->assertSee('Door, Friday Social: Fri Nov 13, 2026')
        ->assertSee('Bar, Friday Social')
        ->assertSee('Jo')
        ->assertSee('1 spot open')
        ->assertSee('You');
});

test('someone not on any shift that week is told so', function () {
    app(ShiftScheduleService::class)->publish($this->owner, $this->week);
    $this->actingAs($this->owner);

    Livewire::test(MyShifts::class)
        ->assertSee('You\'re not on any shifts this week.');
});

test('a link to a week opens that week, and the page moves between weeks', function () {
    app(ShiftScheduleService::class)->publish($this->owner, $this->week);
    $this->actingAs($this->sam);

    Livewire::withQueryParams(['week' => '2026-11-15'])
        ->test(MyShifts::class)
        ->assertSet('weekStart', '2026-11-12')
        ->assertSee('Friday Social')
        ->callAction('nextWeek')
        ->assertSet('weekStart', '2026-11-19')
        ->assertSee('This week\'s schedule isn\'t out yet')
        ->assertDontSee('Friday Social');

    Livewire::withQueryParams(['week' => 'not a date'])
        ->test(MyShifts::class)
        ->assertSet('weekStart', '2026-11-12');
});

test('publish and change notifications link to the week on My shifts', function () {
    $service = app(ShiftScheduleService::class);
    $service->publish($this->owner, $this->week);
    // Jo moves from Bar to Door: one removal, one addition.
    $service->assign($this->owner, $this->week, [$this->doorSlot->id => [$this->sam->id, $this->jo->id], $this->barSlot->id => []]);

    $url = MyShifts::getUrl(['week' => '2026-11-12']);

    expect($this->sam->notifications()->sole()->data['actions'][0]['url'])->toBe($url)
        ->and($this->jo->notifications->pluck('data.actions.0.url')->unique()->all())->toBe([$url]);
});

test('My shifts is for people with a shift role, on a shift, or Admin and up, with the flag on', function () {
    $volunteer = User::factory()->create(['role' => Role::Volunteer, 'active' => true]);
    $exStaff = User::factory()->create(['role' => Role::Volunteer, 'active' => true]);
    $this->doorSlot->assignments()->create(['user_id' => $exStaff->id, 'assigned_by' => $this->owner->id]);

    expect(Gate::forUser($this->sam)->allows('view-staff-schedule'))->toBeTrue()
        ->and(Gate::forUser($exStaff)->allows('view-staff-schedule'))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('view-staff-schedule'))->toBeTrue()
        ->and(Gate::forUser($volunteer)->allows('view-staff-schedule'))->toBeFalse();

    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    $this->actingAs($this->sam)->get(MyShifts::getUrl())->assertForbidden();
});

test('every shift row has the same four columns, with or without a badge, so a day lines up', function () {
    app(ShiftScheduleService::class)->publish($this->owner, $this->week);
    $this->actingAs($this->jo);
    $page = Livewire::test(MyShifts::class)->instance();

    // The Door shift has a spot open; the Bar shift is full and is Jo's own.
    foreach ([$this->doorSlot, $this->barSlot] as $slot) {
        $row = (fn () => $this->shiftRow($slot->fresh(['assignments.user', 'event', 'shiftRole'])))->call($page);

        expect($row)->toBeInstanceOf(Grid::class)
            ->and($row->getDefaultChildComponents())->toHaveCount(4);
    }
});
