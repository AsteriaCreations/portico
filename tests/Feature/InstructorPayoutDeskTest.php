<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\InstructorPayout;
use App\Models\InstructorPayRate;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\InstructorPayoutService;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door, 'name' => "Stephen O'Connell"]);
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->register = Register::factory()->create();

    $yoga = EventType::factory()->create(['name' => 'Yoga']);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::None, 'rate' => 10.00]);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::RegularSubscription, 'rate' => 5.00]);

    // 3 x $10 + 2 x $5 = $40 owed.
    $this->yoga = Event::factory()->create(['name' => 'Sunrise Yoga', 'event_type_id' => $yoga->id, 'event_date' => today()->toDateString()]);
    Attendance::factory()->for($this->yoga)->count(3)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);
    Attendance::factory()->for($this->yoga)->count(2)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::RegularSubscription]);
});

function openDeskBox(User $user): RegisterShift
{
    return app(RegisterShiftService::class)->openShift(test()->register, $user, 100);
}

test('Door sees tonight\'s instructor payout on the desk', function () {
    $this->actingAs($this->door);

    $this->get('/admin/check-in')
        ->assertSuccessful()
        ->assertSee('Sunrise Yoga instructor payout so far: $40.00')
        ->assertSee('(5 people)');
});

test('the desk line is absent with the flag off, for an event not on tonight, or for a type with no rates', function () {
    $this->actingAs($this->door);

    MembershipSetting::current()->update(['instructor_payouts_enabled' => false]);
    $this->get('/admin/check-in')->assertDontSee('instructor payout so far');

    MembershipSetting::current()->update(['instructor_payouts_enabled' => true]);
    $this->yoga->update(['event_date' => today()->subWeek()->toDateString(), 'starts_at' => null, 'ends_at' => null]);
    $this->get('/admin/check-in')->assertDontSee('instructor payout so far');

    Event::factory()->create(['name' => 'Social', 'event_type_id' => EventType::factory()->create()->id, 'event_date' => today()->toDateString()]);
    $this->get('/admin/check-in')->assertDontSee('instructor payout so far');
});

test('paying the instructor records the payout and takes it off the box\'s expected cash', function () {
    $this->actingAs($this->door);
    $shift = openDeskBox($this->door);
    $service = app(RegisterShiftService::class);
    $before = $service->expectedClosingCountCents($shift);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionVisible('payInstructor')
        ->mountAction('payInstructor')
        ->assertSchemaStateSet(['event_id' => $this->yoga->id, 'amount' => 40.0])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $payout = InstructorPayout::sole();

    expect($payout->event_id)->toBe($this->yoga->id)
        ->and($payout->register_shift_id)->toBe($shift->id)
        ->and($payout->recorded_by)->toBe($this->door->id)
        ->and((float) $payout->amount)->toEqual(40.0)
        ->and((float) $payout->calculated_amount)->toEqual(40.0)
        ->and($service->expectedClosingCountCents($shift))->toBe($before - 4000);

    // Counting the drawer after handing the instructor $40 balances.
    $closed = $service->closeShift($shift, $this->door, ($before - 4000) / 100);
    expect($service->varianceCents($closed))->toBe(0);
});

test('the amount is editable, and the calculated figure is kept alongside it', function () {
    $this->actingAs($this->door);
    openDeskBox($this->door);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('payInstructor', data: ['event_id' => $this->yoga->id, 'amount' => 45, 'notes' => 'Rounded up'])
        ->assertHasNoActionErrors();

    $payout = InstructorPayout::sole();

    expect((float) $payout->amount)->toEqual(45.0)
        ->and((float) $payout->calculated_amount)->toEqual(40.0)
        ->and($payout->notes)->toBe('Rounded up');

    $this->get('/admin/check-in')
        ->assertSee('Sunrise Yoga instructor paid $45.00')
        ->assertSee('(calculated then: $40.00)');
});

test('an instructor is paid once per event: the button goes away and a second payout is refused', function () {
    $this->actingAs($this->door);
    $shift = openDeskBox($this->door);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('payInstructor', data: ['event_id' => $this->yoga->id, 'amount' => 40]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('payInstructor');

    expect(fn () => app(RegisterShiftService::class)->recordInstructorPayout($shift, $this->yoga, $this->door, 40))
        ->toThrow(HttpException::class);

    expect(InstructorPayout::count())->toBe(1);
});

test('paying needs an open box, and training mode or the flag being off writes nothing', function () {
    $this->actingAs($this->door);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('payInstructor');

    openDeskBox($this->door);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('toggleTrainingMode')
        ->callAction('payInstructor', data: ['event_id' => $this->yoga->id, 'amount' => 40]);

    expect(InstructorPayout::count())->toBe(0);

    session()->forget('checkin.training_mode');
    MembershipSetting::current()->update(['instructor_payouts_enabled' => false]);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('payInstructor');

    expect(InstructorPayout::count())->toBe(0);
});

test('a Manager can correct a payout with a reason; Door cannot', function () {
    $shift = openDeskBox($this->door);
    app(RegisterShiftService::class)->recordInstructorPayout($shift, $this->yoga, $this->door, 40);

    $this->actingAs($this->door);
    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('correctInstructorPayout');

    $this->actingAs($this->manager);
    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('correctInstructorPayout', data: ['event_id' => $this->yoga->id, 'amount' => -5, 'notes' => ''])
        ->assertHasActionErrors(['notes' => 'required']);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('correctInstructorPayout', data: ['event_id' => $this->yoga->id, 'amount' => 0, 'notes' => 'Oops'])
        ->assertHasActionErrors(['amount']);

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('correctInstructorPayout', data: ['event_id' => $this->yoga->id, 'amount' => -5, 'notes' => 'Instructor handed $5 back'])
        ->assertHasNoActionErrors();

    expect(InstructorPayout::count())->toBe(2)
        ->and(app(RegisterShiftService::class)->totalInstructorPayoutsCents($shift))->toBe(3500);
});

test('a payout corrected back to $0 shows as reversed and can be paid again', function () {
    $shift = openDeskBox($this->door);
    $service = app(RegisterShiftService::class);
    $service->recordInstructorPayout($shift, $this->yoga, $this->door, 40);
    $service->recordInstructorPayout($shift, $this->yoga, $this->manager, -40, 'Goofy', correction: true);

    $this->actingAs($this->door);

    $this->get('/admin/check-in')
        ->assertSee('Net paid: $0.00 (reversed — not paid)')
        ->assertSee('Sunrise Yoga instructor payout so far: $40.00');

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionVisible('payInstructor')
        ->callAction('payInstructor', data: ['event_id' => $this->yoga->id, 'amount' => 40])
        ->assertHasNoActionErrors();

    expect(InstructorPayout::count())->toBe(3)
        ->and($service->totalInstructorPayoutsCents($shift))->toBe(4000);

    $this->get('/admin/check-in')
        ->assertSee('Net paid: $40.00')
        ->assertDontSee('instructor payout so far');

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->assertActionHidden('payInstructor');

    // The fresh payout after the reversal is labelled a payment, not a correction.
    expect(collect(app(InstructorPayoutService::class)->history($this->yoga))->pluck('kind')->all())
        ->toBe(['payment', 'correction', 'payment']);
});

test('instructor payouts are append-only for everyone', function () {
    $payout = InstructorPayout::factory()->create();
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);

    expect(Gate::forUser($owner)->allows('update', $payout))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $payout))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('deleteAny', InstructorPayout::class))->toBeFalse();
});

test('the event page shows what was paid at the desk', function () {
    $shift = openDeskBox($this->door);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $this->get("/admin/events/{$this->yoga->id}/edit")->assertSee('Not paid at the desk yet.');

    app(RegisterShiftService::class)->recordInstructorPayout($shift, $this->yoga, $this->door, 40);

    $this->get("/admin/events/{$this->yoga->id}/edit")
        ->assertSee('Paid at the desk: $40.00 by '.$this->door->name);
});

test('closing the box after paying the instructor balances when the drawer is counted', function () {
    $this->actingAs($this->door);
    $shift = openDeskBox($this->door);
    app(RegisterShiftService::class)->recordInstructorPayout($shift, $this->yoga, $this->door, 40);

    // Opened with $100, no cash taken, $40 handed to the instructor.
    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->callAction('closeShift', data: ['closing_count' => 60])
        ->assertHasNoActionErrors();

    expect(app(RegisterShiftService::class)->varianceCents($shift->fresh()))->toBe(0);
});
