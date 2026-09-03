<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Admin\Resources\Vouchers\Pages\ListVouchers;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a manager can browse the voucher ledger', function () {
    Voucher::factory()->count(3)->create();
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    Livewire::test(ListVouchers::class)->assertSuccessful();
});

test('a door volunteer has no access to the vouchers resource at all', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Door]));

    $this->get('/admin/vouchers')->assertForbidden();
});

test('a manager cannot issue a voucher, only admin and owner can', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    $this->get('/admin/vouchers/create')->assertForbidden();
});

test('an admin can issue voucher credit, recorded_by defaults to them', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);
    $member = Member::factory()->create();

    Livewire::test(CreateVoucher::class)
        ->fillForm([
            'member_id' => $member->id,
            'amount' => 25,
            'reason' => 'referral bonus',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $voucher = Voucher::where('member_id', $member->id)->firstOrFail();
    expect($voucher->amount)->toEqual(25)
        ->and($voucher->reason)->toBe('referral bonus')
        ->and($voucher->recorded_by)->toBe($admin->id);
});

test('an owner can also issue voucher credit', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Owner]));
    $member = Member::factory()->create();

    Livewire::test(CreateVoucher::class)
        ->fillForm(['member_id' => $member->id, 'amount' => 25, 'reason' => 'goodwill'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Voucher::where('member_id', $member->id)->exists())->toBeTrue();
});

test('an admin can correct a wrongly-issued voucher with an offsetting negative row', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $member = Member::factory()->create();
    $issued = Voucher::factory()->for($member)->create(['amount' => 25]);

    Livewire::test(CreateVoucher::class)
        ->fillForm([
            'member_id' => $member->id,
            'amount' => -25,
            'reason' => "correction: voided #{$issued->id}, issued in error",
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($member->voucherBalance())->toEqual(0.0);
});

test('a voucher amount of zero is rejected', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $member = Member::factory()->create();

    Livewire::test(CreateVoucher::class)
        ->fillForm(['member_id' => $member->id, 'amount' => 0, 'reason' => 'oops'])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(Voucher::where('member_id', $member->id)->exists())->toBeFalse();
});

test('a voucher can never be updated or deleted, even by an admin', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $voucher = Voucher::factory()->create();

    expect($admin->can('update', $voucher))->toBeFalse()
        ->and($admin->can('delete', $voucher))->toBeFalse();
});
