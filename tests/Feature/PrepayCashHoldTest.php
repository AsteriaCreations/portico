<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Register;
use App\Models\User;
use App\Models\VisitRemoval;
use App\Services\PrepayCashService;
use App\Services\RegisterShiftService;
use App\Services\VisitRemovalService;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->register = Register::factory()->create();
    $this->service = app(RegisterShiftService::class);

    $this->future = Event::factory()->create([
        'name' => 'Spring Gala',
        'event_date' => today()->addDays(14),
        'starts_at' => today()->addDays(14)->setTime(20, 0),
        'ends_at' => today()->addDays(14)->setTime(23, 0),
        'entry_fee' => 30,
        'pool_fee' => 0,
        'door_prepay_enabled' => true,
    ]);
});

function prepayMember(Category $category): Member
{
    return Member::factory()->create([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => fake()->unique()->safeEmail(),
    ]);
}

function heldVisit(Event $event, array $overrides = []): Attendance
{
    return Attendance::factory()->create([
        'event_id' => $event->id,
        'checked_in_at' => null,
        'payment_method' => 'cash',
        'amount_paid' => 30,
        'prepaid_ahead' => true,
        ...$overrides,
    ]);
}

function lastPrepayNotificationBody(): ?string
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications->last()?->getBody();
}

test('prepaid cash counts as received on the shift but is held out of the expected close', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);
    // Paid by card: there's no cash to hold.
    heldVisit($this->future, ['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 999]);

    expect($this->service->cashReceivedCents($shift))->toBe(5000)
        ->and($this->service->heldPrepayCashCents($shift))->toBe(3000)
        ->and($this->service->expectedClosingCountCents($shift))->toBe(12000);
});

test('a box with only prepaid cash balances at its opening count', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);

    $closed = $this->service->closeShift($shift, $this->manager, 100);

    expect($this->service->varianceCents($closed))->toBe(0);
});

test('the shift breakdowns put prepays in their own bucket and still sum to cash received', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 0);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);
    heldVisit($this->future, ['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 15]);

    $cash = $this->service->cashRevenueBreakdownCents($shift);

    expect($this->service->revenueBreakdown($shift))->toEqual([
        'event' => 20.0,
        'prepay' => 45.0,
        'subscription' => 0.0,
        'other' => 0.0,
    ])
        ->and($cash['event'])->toBe(2000)
        ->and($cash['prepay'])->toBe(3000)
        ->and(array_sum($cash))->toBe($this->service->cashReceivedCents($shift));
});

test('held prepay cash is split per event, earliest first', function () {
    $later = Event::factory()->create(['event_date' => today()->addDays(30), 'door_prepay_enabled' => true]);
    $shift = $this->service->openShift($this->register, $this->manager, 0);
    heldVisit($later, ['register_shift_id' => $shift->id, 'amount_paid' => 10]);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);
    heldVisit($this->future, ['register_shift_id' => $shift->id, 'amount_paid' => 5]);

    expect($this->service->heldPrepayCashByEventCents($shift))->toBe([
        $this->future->id => 3500,
        $later->id => 1000,
    ]);
});

test('a prepay an Owner removes after its shift closed leaves that shift\'s numbers alone and leaves the event\'s envelope', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    $prepay = heldVisit($this->future, ['register_shift_id' => $shift->id]);
    $closed = $this->service->closeShift($shift, $this->manager, 120);
    $before = [
        $this->service->expectedClosingCountCents($closed),
        $this->service->heldPrepayCashCents($closed),
        $this->service->cashRevenueBreakdownCents($closed),
        $this->service->revenueBreakdown($closed),
    ];

    $owner = User::factory()->create(['role' => Role::Owner]);
    $removal = app(VisitRemovalService::class)->remove($prepay, $owner, 'Event cancelled for them');
    $closed = $closed->fresh();

    expect($removal->prepaid_ahead)->toBeTrue()
        ->and([
            $this->service->expectedClosingCountCents($closed),
            $this->service->heldPrepayCashCents($closed),
            $this->service->cashRevenueBreakdownCents($closed),
            $this->service->revenueBreakdown($closed),
        ])->toEqual($before)
        ->and($this->service->varianceCents($closed))->toBe(0)
        ->and(app(PrepayCashService::class)->heldCashForEventCents($this->future))->toBe(0);
});

test('a prepay removed while its shift is open comes out of the drawer and the held figure together', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    $prepay = heldVisit($this->future, ['register_shift_id' => $shift->id]);
    $expectedBefore = $this->service->expectedClosingCountCents($shift);

    app(VisitRemovalService::class)->remove($prepay, $this->manager, 'Changed their mind');

    expect($this->service->expectedClosingCountCents($shift))->toBe($expectedBefore)
        ->and($this->service->heldPrepayCashCents($shift))->toBe(0)
        ->and(VisitRemoval::sole()->after_shift_closed)->toBeFalse();
});

test('the event\'s held prepay cash covers desk and Prepay List cash, arrived or not, and nothing else', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 0);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);
    heldVisit($this->future, ['register_shift_id' => null, 'amount_paid' => 25, 'checked_in_at' => now()]);
    heldVisit($this->future, ['payment_method' => 'venmo', 'amount_paid' => 999]);
    // Paid on the night: in that night's box, not the envelope.
    Attendance::factory()->create(['event_id' => $this->future->id, 'payment_method' => 'cash', 'amount_paid' => 999]);
    heldVisit(Event::factory()->create(), ['amount_paid' => 999]);

    expect(app(PrepayCashService::class)->heldCashForEventCents($this->future))->toBe(5500);
});

test('a desk check-in for a future door-prepay event is recorded as prepaid ahead and tells the desk to set the cash aside', function () {
    $this->service->openShift($this->register, $this->manager, 100);
    $member = prepayMember($this->irregular);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->fillForm(['event_id' => $this->future->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['payment_method' => 'cash'])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->sole();
    $body = lastPrepayNotificationBody();

    expect($attendance->prepaid_ahead)->toBeTrue()
        ->and($attendance->checked_in_at)->toBeNull()
        ->and($body)->toContain('prepay envelope')
        ->and($body)->toContain('$30.00');
});

test('tonight\'s check-in and a past door-prepay event\'s are not prepaid ahead, so their cash stays in the box', function () {
    $this->service->openShift($this->register, $this->manager, 100);
    $tonight = Event::factory()->create(['event_date' => today(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $past = Event::factory()->create(['event_date' => today()->subDays(3), 'entry_fee' => 20, 'pool_fee' => 0, 'door_prepay_enabled' => true]);
    $first = prepayMember($this->irregular);
    $second = prepayMember($this->irregular);

    foreach ([[$tonight, $first], [$past, $second]] as [$event, $member]) {
        Livewire::test(CheckIn::class)
            ->set('registerId', $this->register->id)
            ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
            ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash'])
            ->assertHasNoActionErrors();
    }

    expect(Attendance::where('prepaid_ahead', true)->exists())->toBeFalse();
});

test('closing a box with prepaid cash names a separate envelope per event and leaves it out of tonight\'s total', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    $tonight = Event::factory()->create(['name' => 'Friday Social', 'event_date' => today(), 'entry_fee' => 20, 'pool_fee' => 0]);
    Attendance::factory()->create(['event_id' => $tonight->id, 'register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 120])
        ->assertHasNoActionErrors();

    $body = lastPrepayNotificationBody();

    expect($body)->toContain('total $20.00')
        ->and($body)->toContain('Friday Social')
        ->and($body)->toContain('separate envelope for each event')
        ->and($body)->toContain($this->future->label().': $30.00')
        ->and($this->service->varianceCents($shift->fresh()))->toBe(0);
});

test('closing a box holding only prepaid cash names just the prepay envelope', function () {
    MembershipSetting::current()->update(['cash_envelope_reminder_enabled' => true]);
    $shift = $this->service->openShift($this->register, $this->manager, 100);
    heldVisit($this->future, ['register_shift_id' => $shift->id]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 100])
        ->assertHasNoActionErrors();

    $body = lastPrepayNotificationBody();

    expect($body)->not->toContain('Cash collected')
        ->and($body)->toContain($this->future->label().': $30.00');
});

test('a Prepay List entry is prepaid ahead, takes a real payment method, and its cash is held for the event', function () {
    $member = Member::factory()->create();

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $this->future,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: ['member_id' => $member->id, 'payment_method' => 'cash'])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->sole();

    expect($attendance->prepaid_ahead)->toBeTrue()
        ->and($attendance->register_shift_id)->toBeNull()
        ->and(app(PrepayCashService::class)->heldCashForEventCents($this->future))->toBe(3000);
});

test('the Prepay List rejects a payment method that isn\'t one of the club\'s', function () {
    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $this->future,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: ['member_id' => Member::factory()->create()->id, 'payment_method' => 'cash-ish'])
        ->assertHasTableActionErrors(['payment_method']);

    expect(Attendance::exists())->toBeFalse();
});

test('refunding held prepay cash points at the event\'s prepay cash, not a drawer', function () {
    $shift = $this->service->openShift($this->register, $this->manager, 0);
    $atDesk = heldVisit($this->future, ['register_shift_id' => $shift->id]);
    $fromList = heldVisit($this->future, ['register_shift_id' => null]);
    $removals = app(VisitRemovalService::class);

    $sameNight = Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);

    expect($removals->refundLabel($atDesk))->toContain('prepay cash held for this event')
        ->and($removals->refundLabel($fromList))->toContain('prepay cash held for this event')
        ->and($removals->refundLabel($sameNight))->toContain('from register');

    $this->service->closeShift($shift, $this->manager, 20);

    expect($removals->refundLabel($atDesk->fresh()))->toContain('prepay cash held for this event');
});
