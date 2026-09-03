<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('door cannot access the feature flags page', function () {
    $this->actingAs(userWithRoleForSettings(Role::Door));

    expect(FeatureFlags::canAccess())->toBeFalse();

    $this->get('/admin/feature-flags')->assertForbidden();
});

test('manager and above can access the feature flags page', function () {
    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $this->actingAs(userWithRoleForSettings($role));

        expect(FeatureFlags::canAccess())->toBeTrue();

        $this->get('/admin/feature-flags')->assertSuccessful();
    }
});

test('mount fills the form from the current singleton row', function () {
    MembershipSetting::current()->update([
        'vouchers_enabled' => false,
        'add_ons_enabled' => false,
        'showrunner_comp_requests_enabled' => false,
        'manager_perk_enabled' => false,
        'suspensions_enabled' => false,
        'pool_enabled' => false,
        'prepay_enabled' => false,
        'register_shifts_enabled' => false,
        'visit_notes_enabled' => false,
        'behavior_notes_enabled' => false,
    ]);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(FeatureFlags::class)
        ->assertSchemaStateSet([
            'vouchers_enabled' => false,
            'add_ons_enabled' => false,
            'showrunner_comp_requests_enabled' => false,
            'manager_perk_enabled' => false,
            'suspensions_enabled' => false,
            'pool_enabled' => false,
            'prepay_enabled' => false,
            'register_shifts_enabled' => false,
            'visit_notes_enabled' => false,
            'behavior_notes_enabled' => false,
        ]);
});

test('saving updates the singleton row', function () {
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(FeatureFlags::class)
        ->fillForm([
            'vouchers_enabled' => false,
            'add_ons_enabled' => false,
            'showrunner_comp_requests_enabled' => false,
            'manager_perk_enabled' => false,
            'suspensions_enabled' => false,
            'pool_enabled' => false,
            'prepay_enabled' => false,
            'register_shifts_enabled' => false,
            'visit_notes_enabled' => false,
            'behavior_notes_enabled' => false,
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    $setting = MembershipSetting::current();

    expect($setting->vouchers_enabled)->toBeFalse()
        ->and($setting->add_ons_enabled)->toBeFalse()
        ->and($setting->showrunner_comp_requests_enabled)->toBeFalse()
        ->and($setting->manager_perk_enabled)->toBeFalse()
        ->and($setting->suspensions_enabled)->toBeFalse()
        ->and($setting->pool_enabled)->toBeFalse()
        ->and($setting->prepay_enabled)->toBeFalse()
        ->and($setting->register_shifts_enabled)->toBeFalse()
        ->and($setting->visit_notes_enabled)->toBeFalse()
        ->and($setting->behavior_notes_enabled)->toBeFalse();
});
