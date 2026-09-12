<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\MembershipSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithRoleForSettings(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('door cannot access the membership settings page', function () {
    $this->actingAs(userWithRoleForSettings(Role::Door));

    expect(MembershipSettings::canAccess())->toBeFalse();

    $this->get('/admin/membership-settings')->assertForbidden();
});

test('manager and above can access the membership settings page', function () {
    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $this->actingAs(userWithRoleForSettings($role));

        expect(MembershipSettings::canAccess())->toBeTrue();

        $this->get('/admin/membership-settings')->assertSuccessful();
    }
});

test('mount fills the form from the current singleton row', function () {
    MembershipSetting::current()->update([
        'subscription_eligibility_threshold' => 7,
        'probation_period_days' => 60,
        'venue_capacity' => 150,
        'default_opening_float' => 200.50,
        'event_window_buffer_minutes' => 20,
        'hide_member_pii_by_default' => false,
        'checkin_display_name_field' => 'username',
    ]);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaStateSet([
            'subscription_eligibility_threshold' => 7,
            'probation_period_days' => 60,
            'venue_capacity' => 150,
            'default_opening_float' => 200.50,
            'event_window_buffer_minutes' => 20,
            'hide_member_pii_by_default' => false,
            'checkin_display_name_field' => 'username',
        ]);
});

test('saving updates the singleton row', function () {
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm([
            'subscription_eligibility_threshold' => 10,
            'probation_period_days' => 45,
            'venue_capacity' => 200,
            'default_opening_float' => 75.25,
            'event_window_buffer_minutes' => 10,
            'hide_member_pii_by_default' => false,
            'checkin_display_name_field' => 'full_name',
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    $setting = MembershipSetting::current();

    expect($setting->subscription_eligibility_threshold)->toBe(10)
        ->and($setting->probation_period_days)->toBe(45)
        ->and($setting->venue_capacity)->toBe(200)
        ->and($setting->default_opening_float)->toBe(75.25)
        ->and($setting->event_window_buffer_minutes)->toBe(10)
        ->and($setting->hide_member_pii_by_default)->toBeFalse()
        ->and($setting->checkin_display_name_field)->toBe('full_name');
});

test('venue_capacity and default_opening_float can be cleared to null', function () {
    MembershipSetting::current()->update(['venue_capacity' => 100, 'default_opening_float' => 50]);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm([
            'subscription_eligibility_threshold' => 5,
            'probation_period_days' => 90,
            'venue_capacity' => null,
            'default_opening_float' => null,
            'event_window_buffer_minutes' => 15,
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    $setting = MembershipSetting::current();

    expect($setting->venue_capacity)->toBeNull()
        ->and($setting->default_opening_float)->toBeNull();
});

test('only owner can grant the manage-org-name gate', function () {
    foreach ([Role::Manager, Role::Admin] as $role) {
        $this->actingAs(userWithRoleForSettings($role));

        expect(Gate::allows('manage-org-name'))->toBeFalse();
    }

    $this->actingAs(userWithRoleForSettings(Role::Owner));

    expect(Gate::allows('manage-org-name'))->toBeTrue();
});

test('the org_name field is hidden from manager and admin, visible to owner', function () {
    foreach ([Role::Manager, Role::Admin] as $role) {
        $this->actingAs(userWithRoleForSettings($role));

        Livewire::test(MembershipSettings::class)
            ->assertSchemaComponentExists('org_name', checkComponentUsing: fn ($component) => ! $component->isVisible());
    }

    $this->actingAs(userWithRoleForSettings(Role::Owner));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaComponentExists('org_name', checkComponentUsing: fn ($component) => $component->isVisible());
});

test('owner can update org_name', function () {
    $this->actingAs(userWithRoleForSettings(Role::Owner));

    Livewire::test(MembershipSettings::class)
        ->fillForm([
            'subscription_eligibility_threshold' => 5,
            'probation_period_days' => 90,
            'event_window_buffer_minutes' => 15,
            'org_name' => 'Riverside Social Club',
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->org_name)->toBe('Riverside Social Club');
});

test('a forged org_name payload from a manager or admin is silently ignored on save', function () {
    foreach ([Role::Manager, Role::Admin] as $role) {
        MembershipSetting::current()->update(['org_name' => null]);
        $this->actingAs(userWithRoleForSettings($role));

        Livewire::test(MembershipSettings::class)
            ->set('data.org_name', 'Forged Name')
            ->fillForm([
                'subscription_eligibility_threshold' => 5,
                'probation_period_days' => 90,
                'event_window_buffer_minutes' => 15,
            ])
            ->callAction('save')
            ->assertHasNoActionErrors();

        expect(MembershipSetting::current()->org_name)->toBeNull();
    }
});

test('the admin panel brand name falls back to config app.name until org_name is set', function () {
    expect(Filament::getPanel('admin')->getBrandName())->toBe(config('app.name'));

    MembershipSetting::current()->update(['org_name' => 'Riverside Social Club']);

    expect(Filament::getPanel('admin')->getBrandName())->toBe('Riverside Social Club');
});
