<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the migration gives the shipped methods a starting icon', function () {
    expect(PaymentMethod::pluck('icon', 'code')->only(['cash', 'venmo', 'comp', 'voucher', 'kiosk'])->all())->toBe([
        'cash' => 'banknotes',
        'venmo' => 'device-phone-mobile',
        'comp' => 'gift',
        'kiosk' => 'qr-code',
        'voucher' => 'wallet',
    ]);
});

test('the Checked in tonight list shows the payment method\'s icon, named for screen readers, beside what was paid', function () {
    $event = Event::factory()->create();
    Attendance::factory()->for(Member::factory())->for($event)->create(['checked_in_at' => now(), 'payment_method' => 'venmo', 'amount_paid' => 20]);

    Livewire::test('checked-in-roster', ['eventId' => $event->id])
        ->assertSeeHtml('aria-label="Venmo"');
});

test('no icon for a $0 visit with no method, or a method with no icon picked', function () {
    PaymentMethod::where('code', 'venmo')->update(['icon' => null]);
    $event = Event::factory()->create();
    Attendance::factory()->for(Member::factory())->for($event)->create(['checked_in_at' => now(), 'payment_method' => null, 'amount_paid' => 0]);
    Attendance::factory()->for(Member::factory())->for($event)->create(['checked_in_at' => now(), 'payment_method' => 'venmo', 'amount_paid' => 20]);

    Livewire::test('checked-in-roster', ['eventId' => $event->id])
        ->assertDontSeeHtml('aria-label="Venmo"');
});

test('a manager picks a method\'s icon from the list, and nothing outside it is accepted', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $method = PaymentMethod::where('code', 'venmo')->firstOrFail();

    Livewire::test(EditPaymentMethod::class, ['record' => $method->getKey()])
        ->fillForm(['icon' => 'credit-card'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($method->refresh()->icon)->toBe('credit-card');

    Livewire::test(EditPaymentMethod::class, ['record' => $method->getKey()])
        ->fillForm(['icon' => 'x-mark'])
        ->call('save')
        ->assertHasFormErrors(['icon']);

    expect($method->refresh()->icon)->toBe('credit-card');
});
