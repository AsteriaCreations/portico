<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Attendance;
use App\Models\CashExchange;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\User;
use App\Services\RegisterShiftService;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->door);

    $this->register = Register::factory()->create();
    $this->service = app(RegisterShiftService::class);
    $this->shift = $this->service->openShift($this->register, $this->door, 100);

    MembershipSetting::current()->update(['cash_exchanges_enabled' => true]);
    // Venmo is seeded with a fee; tests that want one set their own.
    PaymentMethod::where('code', 'venmo')->update(['transaction_fee' => 0]);
});

function deskWithBox(Register $register)
{
    return Livewire::test(CheckIn::class)->set('registerId', $register->id);
}

test('cash_exchanges_enabled is off by default', function () {
    MembershipSetting::query()->delete();

    expect(MembershipSetting::current()->cash_exchanges_enabled)->toBeFalse();
});

test('the action shows only with the flag on and a box open', function () {
    deskWithBox($this->register)->assertActionVisible('recordCashExchange');

    MembershipSetting::current()->update(['cash_exchanges_enabled' => false]);
    deskWithBox($this->register)->assertActionHidden('recordCashExchange');

    MembershipSetting::current()->update(['cash_exchanges_enabled' => true]);
    $this->service->closeShift($this->shift, $this->door, 100);
    deskWithBox($this->register)->assertActionHidden('recordCashExchange');
});

test('Door records a swap: the box expects less cash and takings do not change', function () {
    $before = $this->service->revenueBreakdown($this->shift);

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'venmo', 'amount' => 40, 'notes' => '@pat'])
        ->assertHasNoActionErrors();

    $exchange = CashExchange::where('register_shift_id', $this->shift->id)->sole();

    expect($exchange->payment_method)->toBe('venmo')
        ->and((float) $exchange->amount)->toEqual(40.0)
        ->and((float) $exchange->fee)->toEqual(0.0)
        ->and($exchange->recorded_by)->toBe($this->door->id)
        ->and($this->service->expectedClosingCountCents($this->shift))->toBe(6000)
        ->and($this->service->revenueBreakdown($this->shift))->toBe($before)
        ->and($this->service->electronicByMethodCents($this->shift))->toBe([])
        ->and($this->service->cashExchangesByMethodCents($this->shift))->toBe(['Venmo' => 4000]);
});

test('the method\'s fee is snapshotted and paid on top, without taking more cash out of the box', function () {
    PaymentMethod::where('code', 'venmo')->update(['transaction_fee' => 0.50]);

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'venmo', 'amount' => 40])
        ->assertHasNoActionErrors();

    PaymentMethod::where('code', 'venmo')->update(['transaction_fee' => 2]);

    expect((float) CashExchange::sole()->fee)->toEqual(0.5)
        ->and($this->service->expectedClosingCountCents($this->shift))->toBe(6000)
        ->and($this->service->cashExchangesByMethodCents($this->shift))->toBe(['Venmo' => 4050]);
});

test('cash and Comp are not offered, and a forged cash method records nothing', function () {
    expect(PaymentMethod::cashExchangeOptions())->not->toHaveKeys(['cash', 'comp'])
        ->and(PaymentMethod::cashExchangeOptions())->toHaveKey('venmo');

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'cash', 'amount' => 40]);

    expect(CashExchange::count())->toBe(0);
});

test('a forged swap is rejected once the flag is off', function () {
    $desk = deskWithBox($this->register);
    MembershipSetting::current()->update(['cash_exchanges_enabled' => false]);

    $desk->call('mountAction', 'recordCashExchange')
        ->set('mountedActions.0.data', ['payment_method' => 'venmo', 'amount' => 40])
        ->call('callMountedAction');

    expect(CashExchange::count())->toBe(0);
});

test('Door cannot enter a negative amount; a Manager can, with a reason, and it carries no fee', function () {
    PaymentMethod::where('code', 'venmo')->update(['transaction_fee' => 0.50]);
    $this->service->recordCashExchange($this->shift, $this->door, 40, 'venmo');

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'venmo', 'amount' => -40, 'notes' => 'mistake'])
        ->assertHasActionErrors(['amount']);

    expect(CashExchange::count())->toBe(1);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'venmo', 'amount' => -40])
        ->assertHasActionErrors(['notes']);

    deskWithBox($this->register)
        ->callAction('recordCashExchange', data: ['payment_method' => 'venmo', 'amount' => -40, 'notes' => 'Recorded twice'])
        ->assertHasNoActionErrors();

    $correction = CashExchange::latest('id')->first();

    expect((float) $correction->amount)->toEqual(-40.0)
        ->and((float) $correction->fee)->toEqual(0.0)
        ->and($this->service->totalCashExchangedCents($this->shift))->toBe(0)
        ->and($this->service->expectedClosingCountCents($this->shift))->toBe(10000);
});

test('a swap cannot be recorded on a closed shift', function () {
    $this->service->closeShift($this->shift, $this->door, 100);

    expect(fn () => $this->service->recordCashExchange($this->shift, $this->door, 40, 'venmo'))
        ->toThrow(HttpException::class);
});

test('the box summary, variance math and close message list the swap apart from takings', function () {
    $member = Member::factory()->create(['category_id' => Category::factory()->create(['name' => 'Irregular'])->id]);
    Attendance::factory()->for($member)->for(Event::factory()->create(['event_date' => today()->toDateString()]))
        ->create(['register_shift_id' => $this->shift->id, 'payment_method' => 'cash', 'amount_paid' => 20, 'checked_in_at' => now()]);
    $this->service->recordCashExchange($this->shift, $this->door, 15, 'venmo');

    Livewire::test('register-box-summary', ['registerId' => $this->register->id])
        ->assertSee('expected now $105.00')
        ->assertSee('Cash given for electronic payments $15.00 (received Venmo $15.00, not included above)');

    $math = $this->service->varianceMathCents($this->shift);
    expect($math['exchanged'])->toBe(1500)
        ->and($math['expected'])->toBe(10500)
        ->and($math['exchangeReceived'])->toBe(['Venmo' => 1500]);

    deskWithBox($this->register)->callAction('closeShift', data: ['closing_count' => 105])->assertHasNoActionErrors();

    $sent = new Notifications;
    $sent->mount();

    expect($this->service->varianceCents($this->shift->fresh()))->toBe(0)
        ->and($sent->notifications->map(fn ($notification) => $notification->getBody())->implode(' '))
        ->toContain('Also received electronically in exchange for cash from the box (not takings): Venmo $15.00.');
});

test('the ledger can never be edited or deleted', function () {
    $exchange = CashExchange::factory()->create(['register_shift_id' => $this->shift->id]);
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);

    expect($owner->can('update', $exchange))->toBeFalse()
        ->and($owner->can('delete', $exchange))->toBeFalse()
        ->and($this->door->can('viewAny', CashExchange::class))->toBeTrue();
});
