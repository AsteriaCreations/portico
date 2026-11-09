<?php

use App\Enums\Role;
use App\Enums\ShiftPresence;
use App\Filament\Admin\Pages\MyAvailability;
use App\Filament\Admin\Pages\MyShifts;
use App\Filament\Admin\Pages\StaffSchedule;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftPresenceAnswer;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftAvailabilityService;
use App\Services\ShiftGridService;
use App\Services\ShiftScheduleService;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Wednesday 11 Nov 2026; the week ahead runs Thursday 12 to Wednesday 18.
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);

    $this->owner = User::factory()->create(['name' => 'Owner', 'role' => Role::Owner, 'active' => true]);
    $this->desk = ShiftRole::factory()->create(['name' => 'Desk', 'sort_order' => 1]);
    $this->dm = ShiftRole::factory()->create(['name' => 'DM', 'sort_order' => 2]);
    $this->setup = ShiftRole::factory()->create(['name' => 'Setup', 'open_to_all' => true, 'sort_order' => 3]);

    $person = fn (string $name, array $roles): User => tap(User::factory()->create(['name' => $name, 'role' => Role::Door, 'active' => true]), fn (User $user) => $user->shiftRoles()->attach($roles));
    $this->sam = $person('Sam', [$this->desk->id]);
    $this->jo = $person('Jo', [$this->desk->id]);
    $this->lee = $person('Lee', [$this->dm->id]);
    $this->riley = $person('Riley', [$this->desk->id]);
    $this->drew = $person('Drew', [$this->desk->id]);

    $event = fn (string $name, string $start, string $end): Event => Event::factory()->create(['name' => $name, 'event_date' => substr($start, 0, 10), 'starts_at' => Carbon::parse($start), 'ends_at' => Carbon::parse($end)]);
    $this->friday = $event('Friday Social', '2026-11-13 21:00', '2026-11-14 03:00');
    $this->saturday = $event('Saturday Social', '2026-11-14 21:00', '2026-11-15 03:00');
    $this->sunday = $event('Sunday Social', '2026-11-15 19:00', '2026-11-15 23:00');

    $staffing = app(ShiftSlotService::class);
    foreach ([$this->friday, $this->saturday] as $night) {
        $staffing->setStandardShifts($night, ShiftSlotService::SHAPE_OPEN_CLOSE, $night->starts_at->copy()->addHours(3), [
            'open' => [$this->desk->id => 2, $this->dm->id => 1],
            'close' => [$this->desk->id => 1, $this->dm->id => 1],
        ]);
    }
    $staffing->setStandardShifts($this->sunday, ShiftSlotService::SHAPE_SINGLE, null, ['single' => [$this->desk->id => 1]]);

    $this->reset = ShiftSlot::factory()->create(['event_id' => $this->saturday->id, 'shift_role_id' => $this->setup->id, 'kind' => 'extra', 'label' => 'Reset', 'starts_at' => '2026-11-15 10:00', 'ends_at' => '2026-11-15 12:00', 'headcount' => 2]);

    $slot = fn (Event $event, string $kind, ShiftRole $role): ShiftSlot => $event->shiftSlots()->where('kind', $kind)->where('shift_role_id', $role->id)->sole();
    $this->week = Carbon::parse('2026-11-12');
    app(ShiftScheduleService::class)->assign($this->owner, $this->week, [
        $slot($this->friday, 'open', $this->desk)->id => [$this->sam->id, $this->jo->id],
        $slot($this->friday, 'open', $this->dm)->id => [$this->lee->id],
        $slot($this->saturday, 'close', $this->desk)->id => [$this->sam->id],
        $slot($this->sunday, 'single', $this->desk)->id => [$this->jo->id],
        $this->reset->id => [$this->lee->id],
    ]);
});

test('the grid lays out Open/Close columns per night, roles as rows, side shifts and weekday shifts', function () {
    $grid = app(ShiftGridService::class)->build($this->week, publishedOnly: false);

    expect(collect($grid['columns'])->map(fn (array $column): string => $column['day'].' '.$column['kind'])->all())
        ->toBe(['Fri Open', 'Fri Close', 'Sat Open', 'Sat Close'])
        ->and(collect($grid['rows'])->pluck('role')->all())->toBe(['Desk', 'DM']);

    $desk = $grid['rows'][0]['cells'];
    expect($desk[$this->friday->id.'-open'])->toBe(['names' => ['Jo', 'Sam'], 'open' => 0])
        ->and($desk[$this->saturday->id.'-open'])->toBe(['names' => [], 'open' => 2])
        ->and($desk[$this->saturday->id.'-close'])->toBe(['names' => ['Sam'], 'open' => 0]);

    expect($grid['side'])->toHaveCount(1)
        ->and($grid['side'][0]['shift'])->toBe('Setup – Reset')
        ->and($grid['side'][0]['names'])->toBe(['Lee'])
        ->and($grid['side'][0]['open'])->toBe(1)
        ->and($grid['weekday'])->toBe([[
            'day' => 'Sun',
            'event' => 'Sunday Social',
            'shifts' => [['shift' => 'Desk', 'names' => ['Jo'], 'open' => 0]],
        ]]);
});

test('a week with Open and Close on Thursday and Friday gets those columns', function () {
    $thursday = Event::factory()->create(['name' => 'NYE Eve', 'event_date' => '2026-11-12', 'starts_at' => Carbon::parse('2026-11-12 21:00'), 'ends_at' => Carbon::parse('2026-11-13 03:00')]);
    app(ShiftSlotService::class)->setStandardShifts($thursday, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-13 00:00'), ['open' => [$this->desk->id => 1]]);

    $days = collect(app(ShiftGridService::class)->build($this->week, publishedOnly: false)['columns'])->pluck('day')->unique()->values()->all();

    expect($days)->toBe(['Thu', 'Fri', 'Sat']);
});

test('Off and Out list the people not on a shift that night', function () {
    $service = app(ShiftAvailabilityService::class);
    $service->save($this->riley, $this->week, [], null, null, ['2026-11-13' => 'off', '2026-11-14' => 'out']);
    $service->save($this->drew, $this->week, [], null, null, ['2026-11-13' => 'out']);
    // Sam is working Friday, so saying Off doesn't list him.
    $service->save($this->sam, $this->week, [], null, null, ['2026-11-13' => 'off']);

    expect(app(ShiftGridService::class)->build($this->week, publishedOnly: false)['nights'])->toBe([
        ['day' => 'Fri', 'off' => ['Riley'], 'out' => ['Drew']],
        ['day' => 'Sat', 'off' => [], 'out' => ['Riley']],
    ]);
});

test('Off and Out are given per night on My availability, and lock with the week', function () {
    $this->actingAs($this->riley);

    Livewire::test(MyAvailability::class)
        ->assertFormFieldExists('presence_2026-11-13')
        ->assertFormFieldExists('presence_2026-11-15')
        ->fillForm(['presence_2026-11-13' => 'off'])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(ShiftPresenceAnswer::sole()->only(['night', 'status']))->toMatchArray(['status' => ShiftPresence::Off]);

    app(ShiftScheduleService::class)->publish($this->owner, $this->week);

    expect(app(ShiftAvailabilityService::class)->isNightLocked('2026-11-13'))->toBeTrue();
    app(ShiftAvailabilityService::class)->save($this->riley, $this->week, [], null, null, ['2026-11-13' => 'out']);
    expect(ShiftPresenceAnswer::sole()->status)->toBe(ShiftPresence::Off);
});

test('a night outside the week, or with no event, is ignored', function () {
    app(ShiftAvailabilityService::class)->save($this->riley, $this->week, [], null, null, ['2026-11-16' => 'off', '2026-11-30' => 'out']);

    expect(ShiftPresenceAnswer::count())->toBe(0);
});

test('My shifts shows the grid for a published week only; the Staff schedule shows drafts', function () {
    $this->actingAs($this->sam);

    Livewire::withQueryParams(['week' => '2026-11-12', 'view' => 'grid'])
        ->test(MyShifts::class)
        ->assertDontSee('Fri Open')
        ->assertSee('This week\'s schedule isn\'t out yet');

    $this->actingAs($this->owner);
    Livewire::test(StaffSchedule::class)
        ->callAction('toggleLayout')
        ->assertSee('Fri Open')
        ->assertSee('Setup – Reset')
        ->assertActionVisible('print');

    app(ShiftScheduleService::class)->publish($this->owner, $this->week);
    $this->actingAs($this->sam);

    Livewire::withQueryParams(['week' => '2026-11-12'])
        ->test(MyShifts::class)
        ->callAction('toggleLayout')
        ->assertSet('display', 'grid')
        ->assertSee('Fri Open')
        ->assertSee('Weekday shifts')
        ->assertSee('Sunday Social');
});
