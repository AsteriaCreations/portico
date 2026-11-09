<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ShiftsWorked;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\ShiftAssignment;
use App\Models\ShiftReward;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Models\Voucher;
use App\Services\ShiftRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // December, looking back at November.
    $this->travelTo(Carbon::parse('2026-12-02 12:00'));
    MembershipSetting::current()->update([
        'staff_scheduling_enabled' => true,
        'vouchers_enabled' => true,
        'staff_monthly_shift_requirement' => 3,
        'staff_extra_shift_voucher_amount' => 10,
    ]);

    $this->manager = User::factory()->create(['name' => 'Morgan', 'role' => Role::Manager, 'active' => true]);
    $this->sam = User::factory()->create(['name' => 'Sam', 'role' => Role::Door, 'active' => true, 'member_id' => Member::factory()->create()->id]);
    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->desk->users()->attach($this->sam);
    $this->event = Event::factory()->create(['event_date' => '2026-11-06']);
    $this->november = Carbon::parse('2026-11-01');

    // Five worked Desk shifts in November: two beyond the requirement of 3.
    foreach (range(1, 5) as $day) {
        $slot = ShiftSlot::factory()->create(['event_id' => $this->event->id, 'shift_role_id' => $this->desk->id, 'starts_at' => "2026-11-0{$day} 20:00", 'ends_at' => "2026-11-0{$day} 23:00"]);
        ShiftAssignment::create(['shift_slot_id' => $slot->id, 'user_id' => $this->sam->id, 'assigned_by' => $this->manager->id, 'worked_at' => "2026-11-0{$day} 20:00"]);
    }
});

test('the amount starts at the shifts beyond the requirement times the per-shift amount', function () {
    $service = app(ShiftRewardService::class);

    expect($service->shiftsBeyond($this->sam, $this->november))->toBe(2)
        ->and($service->suggestedAmount($this->sam, $this->november))->toBe('20.00');

    MembershipSetting::current()->update(['staff_extra_shift_voucher_amount' => null]);
    expect($service->suggestedAmount($this->sam, $this->november))->toBeNull();
});

test('granting puts a voucher on the linked member and records it once for the month', function () {
    $reward = app(ShiftRewardService::class)->grant($this->manager, $this->sam, $this->november, '25.00', 'Covered two closes');

    $voucher = Voucher::sole();
    expect($voucher->member_id)->toBe($this->sam->member_id)
        ->and((float) $voucher->amount)->toBe(25.0)
        ->and($voucher->reason)->toBe('Shift reward — November 2026 (2 extra shifts): Covered two closes')
        ->and($voucher->recorded_by)->toBe($this->manager->id)
        ->and($reward->only(['shifts_worked', 'shifts_beyond', 'granted_by']))->toBe(['shifts_worked' => 5, 'shifts_beyond' => 2, 'granted_by' => $this->manager->id]);

    expect(fn () => app(ShiftRewardService::class)->grant($this->manager, $this->sam, $this->november, 25, null))
        ->toThrow(ValidationException::class, 'already got a voucher');
    expect(Voucher::count())->toBe(1);
});

test('a voucher is refused when :dataset', function (Closure $setUp, string $message) {
    $setUp($this);

    expect(fn () => app(ShiftRewardService::class)->grant($this->manager, $this->sam->fresh(), $this->november, 20, null))
        ->toThrow(ValidationException::class, $message);
    expect(ShiftReward::count())->toBe(0);
})->with([
    'they did not go beyond' => [fn ($test) => MembershipSetting::current()->update(['staff_monthly_shift_requirement' => 5]), 'haven\'t worked beyond'],
    'there is no requirement' => [fn ($test) => MembershipSetting::current()->update(['staff_monthly_shift_requirement' => 0]), 'no monthly shift requirement'],
    'vouchers are off' => [fn ($test) => MembershipSetting::current()->update(['vouchers_enabled' => false]), 'Vouchers are turned off'],
    'no member is linked' => [fn ($test) => $test->sam->update(['member_id' => null]), 'isn\'t linked to a member record'],
]);

test('a voucher has to be worth something, and only Manager and up can grant one', function () {
    expect(fn () => app(ShiftRewardService::class)->grant($this->manager, $this->sam, $this->november, 0, null))
        ->toThrow(ValidationException::class, 'worth something')
        ->and(fn () => app(ShiftRewardService::class)->grant($this->sam, $this->sam, $this->november, 20, null))
        ->toThrow(HttpException::class);
});

test('a Manager grants it from Shifts Worked, changing the amount, and the page shows it', function () {
    $this->actingAs($this->manager);

    Livewire::test(ShiftsWorked::class)
        ->callAction('previousMonth')
        ->assertSet('month', '2026-11')
        ->assertTableActionVisible('grantVoucher', $this->sam)
        ->mountTableAction('grantVoucher', $this->sam)
        ->assertTableActionDataSet(['amount' => '20.00'])
        ->setTableActionData(['amount' => 30, 'note' => 'Thank you'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertTableColumnStateSet('reward', 'Granted $30.00', $this->sam)
        ->assertTableActionHidden('grantVoucher', $this->sam);

    expect((float) Voucher::sole()->amount)->toBe(30.0);
});

test('someone beyond the requirement with no member record shows why there is no button', function () {
    $this->sam->update(['member_id' => null]);
    $this->actingAs($this->manager);

    Livewire::test(ShiftsWorked::class)
        ->callAction('previousMonth')
        ->assertTableActionHidden('grantVoucher', $this->sam)
        ->assertTableColumnStateSet('reward', 'No member record linked', $this->sam);
});
