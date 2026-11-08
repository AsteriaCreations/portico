<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Exceptions\PrivateRentalRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Models\Room;
use App\Models\User;
use App\Services\KioskCheckInService;
use App\Services\PrivateRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
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
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ]);
    $this->service = app(PrivateRentalService::class);
});

afterEach(fn () => Carbon::setTestNow());

function bookRental(array $overrides = []): PrivateRental
{
    $args = array_merge([
        'member' => test()->member,
        'room' => test()->room,
        'startsAt' => Carbon::parse('2026-11-14 19:00'),
        'hours' => 3,
        'headcount' => 12,
        'bookedBy' => test()->door,
    ], $overrides);

    return test()->service->book(...$args);
}

test('the quote charges the room per hour and each extra person per hour', function () {
    $quote = $this->service->quote($this->room, 3, 12);

    expect($quote->roomCostCents)->toBe(12000)
        ->and($quote->extraPeople)->toBe(2)
        ->and($quote->perExtraPersonCents)->toBe(1500)
        ->and($quote->extraPeopleCostCents)->toBe(3000)
        ->and($quote->totalCents)->toBe(15000);
});

test('a flat extra-person fee is charged once, and nobody extra costs nothing', function () {
    $this->room->update(['overage_per_hour' => false]);

    expect($this->service->quote($this->room, 3, 12)->extraPeopleCostCents)->toBe(1000)
        ->and($this->service->quote($this->room, 3, 8)->extraPeopleCostCents)->toBe(0)
        ->and($this->service->quote($this->room, 3, 8)->totalCents)->toBe(12000);
});

test('booking snapshots the price and creates the rental event with the renter as host', function () {
    $rental = bookRental();

    expect($rental->starts_at->toDateTimeString())->toBe('2026-11-14 19:00:00')
        ->and($rental->ends_at->toDateTimeString())->toBe('2026-11-14 22:00:00')
        ->and($rental->total)->toBe('150.00')
        ->and($rental->room_cost)->toBe('120.00')
        ->and($rental->extra_people_cost)->toBe('30.00')
        ->and($rental->hourly_rate)->toBe('40.00')
        ->and($rental->booked_by)->toBe($this->door->id);

    $event = $rental->event;
    expect($event->host_id)->toBe($this->member->id)
        ->and($event->event_date->toDateString())->toBe('2026-11-14')
        ->and($event->starts_at->toDateTimeString())->toBe('2026-11-14 19:00:00')
        ->and($event->ends_at->toDateTimeString())->toBe('2026-11-14 22:00:00')
        ->and($event->entry_fee)->toBe('15.00')
        ->and($event->name)->toBe('Private rental — Blue Room')
        ->and($event->privateRental->is($rental))->toBeTrue();
});

test('changing the room afterwards never changes a booking', function () {
    $rental = bookRental();
    $this->room->update(['hourly_rate' => 100]);

    expect($rental->fresh()->total)->toBe('150.00');
});

test('an overlapping booking for the same room is refused', function () {
    bookRental();

    expect(fn () => bookRental(['startsAt' => Carbon::parse('2026-11-14 21:00'), 'hours' => 2]))
        ->toThrow(PrivateRentalRefused::class, 'Blue Room is already booked');

    expect(PrivateRental::count())->toBe(1)
        ->and(Event::count())->toBe(1);
});

test('back-to-back bookings, another room, and a cancelled booking do not clash', function () {
    bookRental();

    bookRental(['startsAt' => Carbon::parse('2026-11-14 22:00'), 'hours' => 2]);
    bookRental(['room' => Room::factory()->create(['min_hours' => 1])]);

    PrivateRental::query()->update(['cancelled_at' => now()]);
    bookRental();

    expect(PrivateRental::count())->toBe(4);
});

test('a member flagged No private rentals cannot book', function () {
    $this->member->update(['rentals_blocked' => true, 'rentals_blocked_reason' => 'Damage last time']);

    expect(fn () => bookRental())->toThrow(PrivateRentalRefused::class, 'can\'t book private rentals');
});

test('a member the admission check blocks cannot book', function () {
    $this->member->update(['is_banned' => true, 'ban_reason' => 'Fight']);

    expect(fn () => bookRental())->toThrow(PrivateRentalRefused::class, 'Do not admit');
});

test('someone under the age of majority on the rental date cannot book', function () {
    $this->member->update(['dob' => '2009-01-01']);

    expect(fn () => bookRental())->toThrow(PrivateRentalRefused::class, 'no admittance');
});

test('a watchlisted member can still book', function () {
    $this->member->update(['on_watchlist' => true, 'watchlist_reason' => 'Keep an eye']);

    expect(bookRental())->toBeInstanceOf(PrivateRental::class);
});

test('hours outside the room limits, an inactive room, and a past date are refused', function () {
    expect(fn () => bookRental(['hours' => 1]))->toThrow(PrivateRentalRefused::class, '2 to 6 hours')
        ->and(fn () => bookRental(['hours' => 7]))->toThrow(PrivateRentalRefused::class, '2 to 6 hours')
        ->and(fn () => bookRental(['startsAt' => Carbon::parse('2026-11-13 19:00')]))->toThrow(PrivateRentalRefused::class, 'past date');

    $this->room->update(['active' => false]);
    expect(fn () => bookRental())->toThrow(PrivateRentalRefused::class, 'isn\'t taking bookings');
});

test('a future date can be booked', function () {
    $rental = bookRental(['startsAt' => Carbon::parse('2026-12-05 15:00')]);

    expect($rental->event->event_date->toDateString())->toBe('2026-12-05');
});

test('the gate is Door+ and needs the flag', function () {
    expect(Gate::allows('book-private-rental'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create(['active' => true, 'role' => Role::DM]))->allows('book-private-rental'))->toBeFalse();

    MembershipSetting::current()->update(['private_rentals_enabled' => false]);
    expect(Gate::allows('book-private-rental'))->toBeFalse();
});

test('the desk books a rental for the selected member', function () {
    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionVisible('bookPrivateRental')
        ->callAction('bookPrivateRental', data: [
            'room_id' => $this->room->id,
            'date' => '2026-11-14',
            'start_time' => '19:00',
            'hours' => 3,
            'headcount' => 12,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Private rental booked');

    $rental = PrivateRental::sole();
    expect($rental->member_id)->toBe($this->member->id)
        ->and($rental->total)->toBe('150.00');
});

test('the desk shows the refusal and books nothing when the room is taken', function () {
    bookRental();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('bookPrivateRental', data: [
            'room_id' => $this->room->id,
            'date' => '2026-11-14',
            'start_time' => '20:00',
            'hours' => 2,
            'headcount' => 5,
        ])
        ->assertNotified('Not booked');

    expect(PrivateRental::count())->toBe(1);
});

test('training mode books nothing', function () {
    session(['checkin.training_mode' => true]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('bookPrivateRental', data: [
            'room_id' => $this->room->id,
            'date' => '2026-11-14',
            'start_time' => '19:00',
            'hours' => 3,
            'headcount' => 12,
        ])
        ->assertNotified('Practice only — nothing saved');

    expect(PrivateRental::count())->toBe(0)
        ->and(Event::count())->toBe(0);
});

test('the desk button is hidden while private rentals are off', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionHidden('bookPrivateRental');
});

test('a rental running alongside the night\'s event leaves the kiosk and the desk default on that event', function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true, 'kiosk_subscribers_only' => false]);
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Carbon::setTestNow('2026-11-14 20:00:00');
    $night = Event::factory()->create([
        'event_date' => '2026-11-14',
        'starts_at' => '2026-11-14 19:00',
        'ends_at' => '2026-11-14 23:59',
        'entry_fee' => 0,
    ]);
    bookRental(['startsAt' => Carbon::parse('2026-11-14 19:00')]);

    expect((int) Livewire::test(CheckIn::class)->get('data.event_id'))->toBe($night->id);

    $guest = Member::factory()->create(['category_id' => $this->member->category_id, 'dob' => '1990-01-01', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com']);
    $result = app(KioskCheckInService::class)->scan($guest->ensureKioskToken());

    expect($result->message)->not->toContain('More than one event');
});

test('a room with bookings cannot be deleted, one without can', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    bookRental();
    $unused = Room::factory()->create();

    expect(Gate::forUser($manager)->allows('delete', $this->room))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('delete', $unused))->toBeTrue();
});

test('the dialog shows the breakdown, the end time and a clash before booking', function () {
    bookRental(['startsAt' => Carbon::parse('2026-11-14 21:00'), 'hours' => 2]);

    $desk = Livewire::test(CheckIn::class)->set('data.member_id', $this->member->id)->instance();
    // Filament renders a modal's schema only once opened in the browser, so
    // read the live total straight from the closure the Placeholder uses.
    $html = (fn () => $this->privateRentalQuoteSummary(test()->room->id, '2026-11-14', '19:00', 3, 12)->toHtml())->call($desk);

    expect($html)->toContain('role="status"')
        ->toContain('Room: $40.00 × 3 h = $120.00')
        ->toContain('Extra people: 2 × $15.00 = $30.00')
        ->toContain('Total: $150.00, paid at the start of the rental')
        ->toContain('Blue Room is already booked');

    $empty = (fn () => $this->privateRentalQuoteSummary(null, null, null, null, null)->toHtml())->call($desk);
    expect($empty)->toContain('Choose a room');
});
