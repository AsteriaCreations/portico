<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Admin\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Admin\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('payment methods list page renders for a manager', function () {
    PaymentMethod::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListPaymentMethods::class)->assertSuccessful();
});

test('a manager can create a payment method', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreatePaymentMethod::class)
        ->fillForm([
            'label' => 'Check',
            'code' => 'check',
            'requires_register_shift' => true,
            'sort_order' => 5,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PaymentMethod::where('code', 'check')->exists())->toBeTrue();
});

test('a door volunteer has no access to the payment methods resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/payment-methods')->assertForbidden();
});

test('a payment method label is editable but code is immutable after creation', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $method = PaymentMethod::where('code', 'cash')->firstOrFail();
    $this->actingAs($manager);

    Livewire::test(EditPaymentMethod::class, ['record' => $method->getKey()])
        ->fillForm(['label' => 'Cash (drawer)', 'code' => 'renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $method->refresh();
    expect($method->label)->toBe('Cash (drawer)')
        ->and($method->code)->toBe('cash');
});
