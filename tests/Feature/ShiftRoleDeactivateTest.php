<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\ShiftRoles\Pages\EditShiftRole;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftAssignment;
use App\Models\ShiftAvailability;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);
    $this->admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $this->actingAs($this->admin);

    $this->bar = ShiftRole::factory()->create(['name' => 'Bar']);
    $this->staff = User::factory()->create(['active' => true]);

    $upcoming = Event::factory()->create(['event_date' => '2026-11-13']);
    $past = Event::factory()->create(['event_date' => '2026-11-06']);
    $archived = Event::factory()->create(['event_date' => '2026-11-14', 'archived_at' => now()]);

    $shift = fn (Event $event, string $startsAt): ShiftSlot => ShiftSlot::factory()->create([
        'event_id' => $event->id, 'shift_role_id' => $this->bar->id,
        'starts_at' => Carbon::parse($startsAt), 'ends_at' => Carbon::parse($startsAt)->addHours(3),
    ]);

    $this->unstaffed = $shift($upcoming, '2026-11-13 20:00');
    $this->alsoUnstaffed = $shift($upcoming, '2026-11-13 23:00');
    $this->staffed = $shift($upcoming, '2026-11-13 21:00');
    $this->past = $shift($past, '2026-11-06 20:00');
    $this->archived = $shift($archived, '2026-11-14 20:00');

    ShiftAssignment::create(['shift_slot_id' => $this->staffed->id, 'user_id' => $this->staff->id, 'assigned_by' => $this->admin->id]);
    ShiftAvailability::create(['shift_slot_id' => $this->unstaffed->id, 'user_id' => $this->staff->id, 'preference' => 'available']);
});

test('a role\'s upcoming shifts are counted, leaving out past and archived events', function () {
    expect(app(ShiftSlotService::class)->upcomingShiftsFor($this->bar))->toBe(['shifts' => 3, 'staffed' => 1]);
});

test('turning a role off warns how many upcoming shifts it has before saving', function () {
    Livewire::test(EditShiftRole::class, ['record' => $this->bar->getRouteKey()])
        ->assertDontSee('shifts on upcoming events')
        ->fillForm(['active' => false])
        ->assertSee('Bar has 3 shifts on upcoming events (1 with people scheduled)');
});

test('once off, the unstaffed upcoming shifts can be removed; staffed, past and archived ones stay', function () {
    $this->bar->update(['active' => false]);

    Livewire::test(EditShiftRole::class, ['record' => $this->bar->getRouteKey()])
        ->assertActionVisible('removeUnstaffedUpcomingShifts')
        ->callAction('removeUnstaffedUpcomingShifts')
        ->assertActionHidden('removeUnstaffedUpcomingShifts');

    expect(ShiftSlot::pluck('id')->sort()->values()->all())->toBe(collect([$this->staffed->id, $this->past->id, $this->archived->id])->sort()->values()->all())
        ->and(ShiftAvailability::count())->toBe(0)
        ->and(ShiftAssignment::count())->toBe(1);
});

test('the remove button only shows for a role that is off and has unstaffed upcoming shifts', function () {
    Livewire::test(EditShiftRole::class, ['record' => $this->bar->getRouteKey()])
        ->assertActionHidden('removeUnstaffedUpcomingShifts');

    $this->bar->update(['active' => false]);
    app(ShiftSlotService::class)->removeUnstaffedUpcomingShifts($this->bar);

    Livewire::test(EditShiftRole::class, ['record' => $this->bar->getRouteKey()])
        ->assertActionHidden('removeUnstaffedUpcomingShifts');
});
