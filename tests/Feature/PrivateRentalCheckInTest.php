<?php

use App\Enums\AddOnKind;
use App\Enums\AdmissionOutcome;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Register;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdmissionPolicy;
use App\Services\CheckInRequest;
use App\Services\CheckInService;
use App\Services\EntryCorrectionService;
use App\Services\GuestRegistrationService;
use App\Services\PricingService;
use App\Services\PrivateRentalService;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 19:30:00');
    MembershipSetting::current()->update(['private_rentals_enabled' => true, 'max_guests_per_night' => 1, 'probation_period_days' => 0]);

    PaymentMethod::where('code', 'cash')->update(['requires_register_shift' => true, 'available_at_desk' => true, 'active' => true, 'transaction_fee' => 0]);
    PaymentMethod::forgetCashCodes();

    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->door);
    $this->register = Register::factory()->create();
    $this->door->update(['default_register_id' => $this->register->id]);
    $this->shifts = app(RegisterShiftService::class);
    $this->shift = $this->shifts->openShift($this->register, $this->door, 100);

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->irregular = Category::firstOrCreate(['name' => 'Irregular']);
    $this->guestCategory = Category::firstOrCreate(['name' => 'Guest']);
    $this->makeMember = fn (array $overrides = []): Member => Member::factory()->create([
        'category_id' => $this->irregular->id,
        'dob' => '1990-01-01',
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => fake()->unique()->safeEmail(),
        'is_banned' => false,
        'on_watchlist' => false,
        'missing_paperwork' => false,
        ...$overrides,
    ]);

    // 2 included people, 3 booked: 3 covered spots, then $15 each (5 x 3h).
    $this->room = Room::factory()->create(['name' => 'Blue Room', 'hourly_rate' => 40, 'included_people' => 2, 'overage_fee' => 5, 'overage_per_hour' => true, 'min_hours' => 2, 'max_hours' => 6]);
    $this->renter = ($this->makeMember)();
    $this->rental = app(PrivateRentalService::class)->book($this->renter, $this->room, Carbon::parse('2026-11-14 19:00'), 3, 3, $this->door);
    $this->event = $this->rental->event;

    $this->checkIn = fn (Member $member, string $method = 'cash'): Attendance => app(CheckInService::class)
        ->record($member, Event::find($this->event->id), $this->door, new CheckInRequest(checkedInAt: now(), paymentMethod: $method), $this->shift)
        ->attendance;
});

afterEach(fn () => Carbon::setTestNow());

test('the paid-for spots are free, first come, then each person pays the extra-person charge', function () {
    $renter = ($this->checkIn)($this->renter, 'comp');
    $first = ($this->checkIn)(($this->makeMember)());
    $second = ($this->checkIn)(($this->makeMember)());
    $third = ($this->checkIn)(($this->makeMember)());

    expect($renter->entry_covered_by)->toBe(EntryCoverageSource::Host)
        ->and($first->entry_covered_by)->toBe(EntryCoverageSource::PrivateRental)
        ->and($first->amount_paid)->toBe('0.00')
        ->and($second->entry_covered_by)->toBe(EntryCoverageSource::PrivateRental)
        ->and($third->entry_covered_by)->toBe(EntryCoverageSource::None)
        ->and($third->amount_paid)->toBe('15.00');
});

test('the renter counts as one of the covered people', function () {
    ($this->checkIn)(($this->makeMember)());
    ($this->checkIn)(($this->makeMember)());
    ($this->checkIn)(($this->makeMember)());
    $late = ($this->checkIn)($this->renter, 'comp');

    expect($late->entry_covered_by)->toBe(EntryCoverageSource::Host)
        ->and(Attendance::where('event_id', $this->event->id)->where('entry_covered_by', EntryCoverageSource::PrivateRental)->count())->toBe(3);

    $past = ($this->checkIn)(($this->makeMember)());
    expect($past->amount_paid)->toBe('15.00');
});

test('a subscription does not cover the extra-person charge', function () {
    foreach (range(1, 3) as $ignored) {
        ($this->checkIn)(($this->makeMember)());
    }
    $subscriber = ($this->makeMember)();
    Plan::factory()->create(['add_on_id' => AddOn::entry()->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Subscription::factory()->create(['member_id' => $subscriber->id, 'add_on_id' => AddOn::entry()->id, 'covered_month' => '2026-11-01']);

    $breakdown = app(PricingService::class)->price($subscriber->fresh(), Event::find($this->event->id));

    expect($subscriber->hasActiveSubscriptionFor(AddOn::entry(), Carbon::parse('2026-11-01')))->toBeTrue()
        ->and($breakdown->entryCoveredBy)->toBe(EntryCoverageSource::None)
        ->and($breakdown->amountPaidCents)->toBe(1500);
});

test('the extra-person charge paid at the door is Rental revenue, not Event', function () {
    foreach (range(1, 4) as $ignored) {
        ($this->checkIn)(($this->makeMember)());
    }

    expect($this->shifts->revenueBreakdown($this->shift)['rental'])->toBe(15.0)
        ->and($this->shifts->revenueBreakdown($this->shift)['event'])->toBe(0.0)
        ->and($this->shifts->cashRevenueBreakdownCents($this->shift)['rental'])->toBe(1500)
        ->and($this->shifts->cashReceivedCents($this->shift))->toBe(1500);
});

test('the paid-entry correction is refused at a rental', function () {
    foreach (range(1, 4) as $ignored) {
        $last = ($this->checkIn)(($this->makeMember)());
    }

    expect(app(EntryCorrectionService::class)->refusalReason($last))->toContain('private rental');
});

test('the renter registers guests past the nightly limit when the club allows them', function () {
    $attendance = ($this->checkIn)($this->renter, 'comp');
    $guests = app(GuestRegistrationService::class);

    foreach (['g1', 'g2'] as $username) {
        $guests->register($this->renter, $attendance, ['username' => $username, 'preferred_name' => $username, 'first_name' => 'G', 'last_name' => 'Uest', 'email' => "{$username}@example.com"]);
    }

    expect(Member::where('sponsor_id', $this->renter->id)->count())->toBe(2)
        ->and($guests->canRegister($this->renter, $attendance))->toBeTrue();
});

test('only the renter registers guests at their rental', function () {
    $member = ($this->makeMember)();
    $attendance = ($this->checkIn)($member);

    expect(app(GuestRegistrationService::class)->canRegister($member, $attendance))->toBeFalse()
        ->and(fn () => app(GuestRegistrationService::class)->register($member, $attendance, ['username' => 'g1', 'preferred_name' => 'G', 'first_name' => 'G', 'last_name' => 'Uest', 'email' => 'g1@example.com']))
        ->toThrow(HttpException::class);
});

test('with guests off, no guests are registered and existing guests are turned away', function () {
    MembershipSetting::current()->update(['private_rental_guests_allowed' => false]);
    $attendance = ($this->checkIn)($this->renter, 'comp');
    $guest = ($this->makeMember)(['category_id' => $this->guestCategory->id]);

    $decision = app(AdmissionPolicy::class)->decide($guest, Event::find($this->event->id));

    expect(app(GuestRegistrationService::class)->canRegister($this->renter, $attendance))->toBeFalse()
        ->and($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Private rentals are for members only')
        ->and(app(AdmissionPolicy::class)->decide($guest, Event::factory()->create(['event_date' => today()->toDateString()]))->outcome)->toBe(AdmissionOutcome::Ok);
});

test('the desk shows the members-only bar and offers the renter Register a guest', function () {
    $guest = ($this->makeMember)(['category_id' => $this->guestCategory->id]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $this->event->id, 'member_id' => $this->renter->id])
        ->callAction('checkIn', data: ['payment_method' => 'comp', 'checked_in_at' => now()])
        ->assertHasNoActionErrors()
        ->assertActionVisible('registerGuest');

    MembershipSetting::current()->update(['private_rental_guests_allowed' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $this->event->id, 'member_id' => $guest->id])
        ->assertSee('Private rentals are for members only')
        ->assertActionHidden('checkIn');
});

test('the desk says how many covered spots are used and what the next person pays', function () {
    $page = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $this->event->id, 'member_id' => $this->renter->id])
        ->assertSee('Private rental: 0 of 3 covered spots used. The next person is covered by the rental.');

    foreach (range(1, 3) as $ignored) {
        ($this->checkIn)(($this->makeMember)());
    }

    $page->fillForm(['event_id' => $this->event->id, 'member_id' => ($this->makeMember)()->id])
        ->assertSee('Private rental: all 3 covered spots used. Each extra person pays $15.00.');

    $other = Event::factory()->create(['event_date' => today()->toDateString()]);
    $page->fillForm(['event_id' => $other->id])->assertDontSee('covered spots');
});
