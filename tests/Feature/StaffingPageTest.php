<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Staffing;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\RelationManagers\ShiftSlotsRelationManager;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\User;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Wednesday 11 Nov 2026; the schedule week runs Thursday to Wednesday.
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);
    $this->actingAs(User::factory()->create(['role' => Role::Manager, 'active' => true]));

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk', 'sort_order' => 1]);
    $this->dm = ShiftRole::factory()->create(['name' => 'DM', 'sort_order' => 2]);

    $this->friday = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 03:00')]);
    $this->nextWeek = Event::factory()->create(['name' => 'Later Social', 'event_date' => '2026-11-20', 'starts_at' => Carbon::parse('2026-11-20 21:00'), 'ends_at' => Carbon::parse('2026-11-21 03:00')]);

    app(ShiftSlotService::class)->setStandardShifts($this->friday, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-14 00:00'), [
        'open' => [$this->desk->id => 2, $this->dm->id => 1],
        'close' => [$this->desk->id => 1],
    ]);
});

test('Staffing lists the week\'s events with a summary of their shifts', function () {
    Livewire::test(Staffing::class)
        ->assertSet('weekStart', '2026-11-12')
        ->assertCanSeeTableRecords([$this->friday])
        ->assertCanNotSeeTableRecords([$this->nextWeek])
        ->assertTableColumnStateSet('shifts', 'Open: Desk 2, DM 1 · Close: Desk 1', $this->friday)
        ->assertTableColumnStateSet('filled', '0 of 4', $this->friday)
        ->callAction('nextWeek')
        ->assertCanSeeTableRecords([$this->nextWeek])
        ->assertTableColumnStateSet('shifts', 'No shifts yet', $this->nextWeek);
});

test('picking an event opens its shifts below', function () {
    // On an update the child is only a placeholder in the test's HTML, so
    // check the page's own section heading for it.
    Livewire::test(Staffing::class)
        ->assertDontSee('Friday Social, Fri Nov 13, 2026')
        ->callTableAction('staff', $this->friday)
        ->assertSet('eventId', $this->friday->id)
        ->assertSee('Friday Social, Fri Nov 13, 2026');
});

test('a link to an event opens its week, and an event from another week can\'t be opened', function () {
    Livewire::withQueryParams(['event' => $this->nextWeek->id])
        ->test(Staffing::class)
        ->assertSet('weekStart', '2026-11-19')
        ->assertSeeLivewire(ShiftSlotsRelationManager::class);

    Livewire::test(Staffing::class)
        ->set('eventId', $this->nextWeek->id)
        ->assertDontSeeLivewire(ShiftSlotsRelationManager::class);
});

test('the embedded shifts table works from the Staffing page', function () {
    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $this->nextWeek, 'pageClass' => Staffing::class])
        ->callTableAction('setStandardShifts', data: ['shape' => ShiftSlotService::SHAPE_SINGLE, 'counts' => ['single' => [$this->desk->id => 1]]])
        ->assertHasNoTableActionErrors();

    expect($this->nextWeek->shiftSlots()->count())->toBe(1);
});

test('events no longer carry a staffing tab', function () {
    expect(EventResource::getRelations())->not->toContain(ShiftSlotsRelationManager::class);
});

test('Staffing is Manager and up, with the flag on', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Door, 'active' => true]))
        ->get(Staffing::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => Role::Manager, 'active' => true]))
        ->get(Staffing::getUrl())->assertSuccessful()->assertSee('Staff');

    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    $this->get(Staffing::getUrl())->assertForbidden();
});
