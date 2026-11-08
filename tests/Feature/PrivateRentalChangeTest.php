<?php

use App\Enums\Role;
use App\Exceptions\PrivateRentalRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\PrivateRentals\Pages\ListPrivateRentals;
use App\Filament\Admin\Resources\PrivateRentals\PrivateRentalResource;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Models\Room;
use App\Models\User;
use App\Services\PrivateRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 12:00:00');
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);

    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->door);

    $this->room = Room::factory()->create([
        'name' => 'Blue Room',
        'hourly_rate' => 40,
        'included_people' => 10,
        'overage_fee' => 5,
        'overage_per_hour' => true,
        'min_hours' => 2,
        'max_hours' => 6,
    ]);
    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular'])->id,
        'dob' => '1990-01-01',
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ]);
    $this->service = app(PrivateRentalService::class);
    $this->rental = $this->service->book($this->member, $this->room, Carbon::parse('2026-11-14 19:00'), 3, 12, $this->door);
});

afterEach(fn () => Carbon::setTestNow());

test('changing a booking moves it and its event, re-priced at the rates it was booked at', function () {
    $this->room->update(['hourly_rate' => 100, 'overage_fee' => 50]);

    $rental = $this->service->change($this->rental, Carbon::parse('2026-11-15 18:00'), 4, 14);

    expect($rental->starts_at->toDateTimeString())->toBe('2026-11-15 18:00:00')
        ->and($rental->ends_at->toDateTimeString())->toBe('2026-11-15 22:00:00')
        ->and($rental->room_cost)->toBe('160.00')
        ->and($rental->extra_people_cost)->toBe('80.00')
        ->and($rental->total)->toBe('240.00');

    $event = $rental->event->fresh();
    expect($event->event_date->toDateString())->toBe('2026-11-15')
        ->and($event->starts_at->toDateTimeString())->toBe('2026-11-15 18:00:00')
        ->and($event->ends_at->toDateTimeString())->toBe('2026-11-15 22:00:00')
        ->and($event->entry_fee)->toBe('20.00')
        ->and($event->checkin_closes_at->lt($event->ends_at))->toBeTrue();
});

test('a change that would clash with another booking is refused, but overlapping its own old slot is fine', function () {
    $this->service->book($this->member, $this->room, Carbon::parse('2026-11-14 22:00'), 2, 5, $this->door);

    expect(fn () => $this->service->change($this->rental, Carbon::parse('2026-11-14 20:00'), 3, 12))
        ->toThrow(PrivateRentalRefused::class, 'already booked');

    expect($this->service->change($this->rental, Carbon::parse('2026-11-14 18:00'), 4, 12)->ends_at->toDateTimeString())
        ->toBe('2026-11-14 22:00:00');
});

test('a change still respects the room\'s hours, the member\'s block and past dates', function () {
    expect(fn () => $this->service->change($this->rental, Carbon::parse('2026-11-14 19:00'), 7, 12))->toThrow(PrivateRentalRefused::class, '2 to 6 hours')
        ->and(fn () => $this->service->change($this->rental, Carbon::parse('2026-11-13 19:00'), 3, 12))->toThrow(PrivateRentalRefused::class, 'past date');

    $this->member->update(['rentals_blocked' => true, 'rentals_blocked_reason' => 'x']);
    expect(fn () => $this->service->change($this->rental->fresh(), Carbon::parse('2026-11-14 19:00'), 3, 12))->toThrow(PrivateRentalRefused::class, 'can\'t book');
});

test('a booking in a room since turned off can still be changed', function () {
    $this->room->update(['active' => false]);

    expect($this->service->change($this->rental->fresh(), Carbon::parse('2026-11-14 19:00'), 2, 12)->hours)->toBe(2);
});

test('cancelling frees the room and archives the event', function () {
    $this->service->cancel($this->rental, $this->door);

    expect($this->rental->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->rental->fresh()->cancelled_by)->toBe($this->door->id)
        ->and($this->rental->event->fresh()->isArchived())->toBeTrue();

    expect($this->service->book($this->member, $this->room, Carbon::parse('2026-11-14 19:00'), 3, 12, $this->door))
        ->toBeInstanceOf(PrivateRental::class);
});

test('a booking with someone checked in cannot be cancelled', function () {
    Attendance::factory()->create(['event_id' => $this->rental->event_id, 'member_id' => $this->member->id]);

    expect(fn () => $this->service->cancel($this->rental, $this->door))->toThrow(PrivateRentalRefused::class, 'already checked in');
});

test('a cancelled or finished booking can no longer be changed or cancelled', function () {
    $this->service->cancel($this->rental, $this->door);

    expect(fn () => $this->service->change($this->rental->fresh(), Carbon::parse('2026-11-14 19:00'), 3, 12))->toThrow(PrivateRentalRefused::class, 'no longer');

    $other = $this->service->book($this->member, $this->room, Carbon::parse('2026-11-14 13:00'), 2, 5, $this->door);
    Carbon::setTestNow('2026-11-14 15:30:00');
    expect($this->service->isChangeable($other))->toBeFalse()
        ->and(fn () => $this->service->cancel($other, $this->door))->toThrow(PrivateRentalRefused::class, 'no longer');
});

test('the renter\'s member card lists their upcoming bookings and changes one', function () {
    $component = Livewire::test(CheckIn::class)->set('data.member_id', $this->member->id);

    expect($component->instance()->upcomingPrivateRentals()->pluck('id')->all())->toBe([$this->rental->id]);
    $component->assertSee('Upcoming private rentals');

    $component->callAction('changePrivateRental', arguments: ['rental' => $this->rental->id], data: [
        'date' => '2026-11-14',
        'start_time' => '20:00',
        'hours' => 2,
        'headcount' => 12,
    ])->assertHasNoActionErrors()->assertNotified('Booking changed');

    expect($this->rental->fresh()->starts_at->toDateTimeString())->toBe('2026-11-14 20:00:00');
});

test('the member card cancels a booking, and can\'t reach another member\'s', function () {
    $other = Member::factory()->create(['category_id' => $this->member->category_id, 'dob' => '1990-01-01', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com']);
    $theirs = $this->service->book($other, $this->room, Carbon::parse('2026-11-15 19:00'), 2, 5, $this->door);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('cancelPrivateRental', arguments: ['rental' => $this->rental->id])
        ->assertNotified('Booking cancelled');

    expect($this->rental->fresh()->cancelled_at)->not->toBeNull();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionHidden('cancelPrivateRental', arguments: ['rental' => $theirs->id]);

    expect($theirs->fresh()->cancelled_at)->toBeNull();
});

test('training mode changes and cancels nothing', function () {
    session(['checkin.training_mode' => true]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('cancelPrivateRental', arguments: ['rental' => $this->rental->id])
        ->assertNotified('Practice only — nothing saved');

    expect($this->rental->fresh()->cancelled_at)->toBeNull();
});

test('the Private Rentals list is Door+ and needs the flag', function () {
    $this->get(PrivateRentalResource::getUrl())->assertSuccessful();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::DM]))
        ->get(PrivateRentalResource::getUrl())->assertForbidden();

    MembershipSetting::current()->update(['private_rentals_enabled' => false]);
    $this->actingAs($this->door)->get(PrivateRentalResource::getUrl())->assertForbidden();
});

test('the list shows upcoming bookings by default and changes or cancels from a row', function () {
    $cancelled = $this->service->book($this->member, $this->room, Carbon::parse('2026-11-16 19:00'), 2, 5, $this->door);
    $this->service->cancel($cancelled, $this->door);

    Livewire::test(ListPrivateRentals::class)
        ->assertCanSeeTableRecords([$this->rental])
        ->assertCanNotSeeTableRecords([$cancelled])
        ->callTableAction('change', $this->rental, data: [
            'date' => '2026-11-14',
            'start_time' => '19:00',
            'hours' => 4,
            'headcount' => 12,
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('cancel', $this->rental);

    expect($this->rental->fresh()->hours)->toBe(4)
        ->and($this->rental->fresh()->cancelled_at)->not->toBeNull();
});
