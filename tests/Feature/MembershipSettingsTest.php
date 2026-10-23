<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\Category;
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

test('the settings are grouped into sections in setup order, with the set-once ones collapsed', function () {
    $this->actingAs(userWithRoleForSettings(Role::Owner));

    $sections = collect(Livewire::test(MembershipSettings::class)->instance()->form->getComponents())
        ->mapWithKeys(fn ($section): array => [$section->getHeading() => $section->isCollapsed()]);

    expect($sections->all())->toBe([
        'Organization' => false,
        'Admission & membership' => false,
        'Guests' => false,
        'Watchlist' => false,
        'House Policy' => false,
        'Events & check-in' => false,
        'Check-In Desk' => false,
        'Members list & Active Patrons' => false,
        'Kiosk' => true,
        'Updates & deploy' => true,
    ]);

    $this->get('/admin/membership-settings')
        ->assertSeeInOrder(['Displayed organization name', 'Age of majority', 'Allow guests during probation', 'Watchlist: who staff notify', 'Venue capacity', 'Searchable member fields', 'Show staff roles on Active Patrons', 'Kiosk scans allowed per minute', 'Deploy Scheduled Task name']);
});

test('mount fills the form from the current singleton row', function () {
    MembershipSetting::current()->update([
        'subscription_eligibility_threshold' => 7,
        'probation_period_days' => 60,
        'guests_allowed_during_probation' => true,
        'venue_capacity' => 150,
        'default_opening_float' => 200.50,
        'event_window_buffer_minutes' => 20,
        'age_of_majority' => 19,
        'alcohol_flag_age' => 20,
        'currency' => 'EUR',
        'hide_member_pii_by_default' => false,
        'checkin_display_name_field' => 'username',
    ]);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaStateSet([
            'subscription_eligibility_threshold' => 7,
            'probation_period_days' => 60,
            'guests_allowed_during_probation' => true,
            'venue_capacity' => 150,
            'default_opening_float' => 200.50,
            'event_window_buffer_minutes' => 20,
            'age_of_majority' => 19,
            'alcohol_flag_age' => 20,
            'currency' => 'EUR',
            'hide_member_pii_by_default' => false,
            'checkin_display_name_field' => 'username',
        ]);
});

test('saving updates the singleton row', function () {
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm([
            'subscription_eligibility_threshold' => 10,
            'subscription_eligibility_window_months' => 24,
            'probation_period_days' => 45,
            'guests_allowed_during_probation' => true,
            'max_guests_per_night' => 2,
            'venue_capacity' => 200,
            'default_opening_float' => 75.25,
            'event_window_buffer_minutes' => 10,
            'age_of_majority' => 19,
            'alcohol_flag_age' => 20,
            'watchlist_notify_label' => 'the Signal group',
            'currency' => 'EUR',
            'hide_member_pii_by_default' => false,
            'active_patrons_show_staff_roles' => false,
            'checkin_display_name_field' => 'full_name',
            'member_email_required' => false,
            'week_starts_on' => 0,
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    $setting = MembershipSetting::current();

    expect($setting->subscription_eligibility_threshold)->toBe(10)
        ->and($setting->subscription_eligibility_window_months)->toBe(24)
        ->and($setting->probation_period_days)->toBe(45)
        ->and($setting->guests_allowed_during_probation)->toBeTrue()
        ->and($setting->max_guests_per_night)->toBe(2)
        ->and($setting->venue_capacity)->toBe(200)
        ->and($setting->default_opening_float)->toBe('75.25')
        ->and($setting->event_window_buffer_minutes)->toBe(10)
        ->and($setting->age_of_majority)->toBe(19)
        ->and($setting->alcohol_flag_age)->toBe(20)
        ->and($setting->watchlist_notify_label)->toBe('the Signal group')
        ->and($setting->currency)->toBe('EUR')
        ->and($setting->hide_member_pii_by_default)->toBeFalse()
        ->and($setting->active_patrons_show_staff_roles)->toBeFalse()
        ->and($setting->checkin_display_name_field)->toBe('full_name')
        ->and($setting->member_email_required)->toBeFalse()
        ->and($setting->week_starts_on)->toBe(0);
});

test('currency is uppercased on save and rejected if not a recognized code', function () {
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm([
            'subscription_eligibility_threshold' => 5,
            'probation_period_days' => 90,
            'event_window_buffer_minutes' => 15,
            'age_of_majority' => 18,
            'alcohol_flag_age' => 21,
            'currency' => 'eur',
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->currency)->toBe('EUR');

    Livewire::test(MembershipSettings::class)
        ->fillForm(['currency' => 'ZZZ'])
        ->callAction('save')
        ->assertHasActionErrors(['currency']);
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

test('the club week follows the language by default, and the chosen start day once set', function () {
    $this->travelTo('2026-09-23 12:00:00'); // a Wednesday; English weeks start on Monday

    expect(MembershipSetting::startOfWeek()->toDateTimeString())->toBe('2026-09-21 00:00:00')
        ->and(MembershipSetting::endOfWeek()->toDateTimeString())->toBe('2026-09-27 23:59:59');

    MembershipSetting::current()->update(['week_starts_on' => 0]); // Sunday

    expect(MembershipSetting::startOfWeek()->toDateTimeString())->toBe('2026-09-20 00:00:00')
        ->and(MembershipSetting::endOfWeek()->toDateTimeString())->toBe('2026-09-26 23:59:59');

    MembershipSetting::current()->update(['week_starts_on' => 5]); // Friday, so Wednesday is late in the week

    expect(MembershipSetting::startOfWeek()->toDateTimeString())->toBe('2026-09-18 00:00:00')
        ->and(MembershipSetting::endOfWeek()->toDateTimeString())->toBe('2026-09-24 23:59:59')
        ->and(MembershipSetting::startOfWeek(now()->subWeek())->toDateString())->toBe('2026-09-11');
});

test('letting a first visit check in after close defaults on and can be turned off', function () {
    expect(MembershipSetting::current()->first_visit_after_close_enabled)->toBeTrue();

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaStateSet(['first_visit_after_close_enabled' => true])
        ->fillForm(['first_visit_after_close_enabled' => false])
        ->callAction('save')
        ->assertHasNoErrors();

    expect(MembershipSetting::current()->fresh()->first_visit_after_close_enabled)->toBeFalse();
});

test('the guest follow-up category and kiosk show-code time default to what the app always did, and save', function () {
    $staff = Category::factory()->create(['name' => 'Staff']);
    Category::factory()->create(['name' => 'Guest']);
    $setting = MembershipSetting::current();
    expect($setting->guest_followup_moves_category)->toBeTrue()
        ->and($setting->guest_followup_category_id)->toBeNull()
        ->and($setting->kiosk_show_code_seconds)->toBe(60);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaStateSet(['guest_followup_moves_category' => true, 'kiosk_show_code_seconds' => 60])
        ->fillForm(['guest_followup_category_id' => $staff->id, 'kiosk_show_code_seconds' => 5])
        ->callAction('save')
        ->assertHasFormErrors(['kiosk_show_code_seconds']);

    Livewire::test(MembershipSettings::class)
        ->fillForm(['guest_followup_category_id' => $staff->id, 'kiosk_show_code_seconds' => 30])
        ->callAction('save')
        ->assertHasNoErrors();

    $setting = MembershipSetting::current()->fresh();
    expect($setting->guest_followup_category_id)->toBe($staff->id)
        ->and($setting->kiosk_show_code_seconds)->toBe(30);
});

test('the follow-up category picker never offers Guest or Prospective', function () {
    $guest = Category::factory()->create(['name' => 'Guest']);
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['guest_followup_category_id' => $guest->id])
        ->callAction('save')
        ->assertHasFormErrors(['guest_followup_category_id']);
});

test('the separate subscription envelope setting defaults on and saves on Feature Flags', function () {
    expect(MembershipSetting::current()->separate_subscription_envelope)->toBeTrue();
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(FeatureFlags::class)
        ->fillForm(['separate_subscription_envelope' => false])
        ->callAction('save')
        ->assertHasNoErrors();

    expect(MembershipSetting::current()->fresh()->separate_subscription_envelope)->toBeFalse();
});

test('the kiosk screen times default to what the kiosk always used, and save', function () {
    $setting = MembershipSetting::current();
    expect($setting->kiosk_result_seconds)->toBe(4)
        ->and($setting->kiosk_problem_result_seconds)->toBe(7)
        ->and($setting->kiosk_same_code_cooldown_seconds)->toBe(8);

    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaStateSet(['kiosk_result_seconds' => 4, 'kiosk_problem_result_seconds' => 7, 'kiosk_same_code_cooldown_seconds' => 8])
        ->fillForm(['kiosk_result_seconds' => 0])
        ->callAction('save')
        ->assertHasFormErrors(['kiosk_result_seconds']);

    Livewire::test(MembershipSettings::class)
        ->fillForm(['kiosk_result_seconds' => 3, 'kiosk_problem_result_seconds' => 10, 'kiosk_same_code_cooldown_seconds' => 12])
        ->callAction('save')
        ->assertHasNoErrors();

    $setting = MembershipSetting::current()->fresh();
    expect($setting->kiosk_result_seconds)->toBe(3)
        ->and($setting->kiosk_problem_result_seconds)->toBe(10)
        ->and($setting->kiosk_same_code_cooldown_seconds)->toBe(12);
});

test('the kiosk rate limit defaults to 30 a minute and can only be raised', function () {
    expect(MembershipSetting::current()->kiosk_requests_per_minute)->toBe(30);
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['kiosk_requests_per_minute' => 20])
        ->callAction('save')
        ->assertHasFormErrors(['kiosk_requests_per_minute']);

    Livewire::test(MembershipSettings::class)
        ->fillForm(['kiosk_requests_per_minute' => 60])
        ->callAction('save')
        ->assertHasNoErrors();

    expect(MembershipSetting::current()->fresh()->kiosk_requests_per_minute)->toBe(60);
});

test('the kiosk code email batch defaults to 50 and saves', function () {
    expect(MembershipSetting::current()->kiosk_code_email_batch_size)->toBe(50);
    $this->actingAs(userWithRoleForSettings(Role::Manager));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['kiosk_code_email_batch_size' => 0])
        ->callAction('save')
        ->assertHasFormErrors(['kiosk_code_email_batch_size']);

    Livewire::test(MembershipSettings::class)
        ->fillForm(['kiosk_code_email_batch_size' => 20])
        ->callAction('save')
        ->assertHasNoErrors();

    expect(MembershipSetting::current()->fresh()->kiosk_code_email_batch_size)->toBe(20);
});
