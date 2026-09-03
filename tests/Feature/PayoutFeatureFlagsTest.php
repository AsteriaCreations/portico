<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\EventTypes\Pages\EditEventType;
use App\Filament\Admin\Resources\EventTypes\RelationManagers\InstructorPayRatesRelationManager;
use App\Filament\Admin\Widgets\InstructorPayoutWidget;
use App\Filament\Admin\Widgets\ShowrunnerPayoutWidget;
use App\Models\EventType;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the showrunner payout tiers resource is forbidden when showrunner_payouts_enabled is off, even for an admin', function () {
    MembershipSetting::current()->update(['showrunner_payouts_enabled' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $this->get('/admin/showrunner-payout-tiers')->assertForbidden();
});

test('the showrunner payout tiers resource is reachable again once showrunner_payouts_enabled is restored', function () {
    MembershipSetting::current()->update(['showrunner_payouts_enabled' => false]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    $this->get('/admin/showrunner-payout-tiers')->assertForbidden();

    MembershipSetting::current()->update(['showrunner_payouts_enabled' => true]);

    $this->get('/admin/showrunner-payout-tiers')->assertSuccessful();
});

test('the instructor pay rates tab is hidden once instructor_payouts_enabled is off, restored once re-enabled', function () {
    MembershipSetting::current()->update(['instructor_payouts_enabled' => false]);
    $eventType = EventType::factory()->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    expect(InstructorPayRatesRelationManager::canViewForRecord($eventType, EditEventType::class))->toBeFalse();

    MembershipSetting::current()->update(['instructor_payouts_enabled' => true]);

    expect(InstructorPayRatesRelationManager::canViewForRecord($eventType, EditEventType::class))->toBeTrue();
});

test('showrunner payout widget is restricted to manager and up, and hidden once showrunner_payouts_enabled is off', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(ShowrunnerPayoutWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(ShowrunnerPayoutWidget::canView())->toBeTrue();

    MembershipSetting::current()->update(['showrunner_payouts_enabled' => false]);
    expect(ShowrunnerPayoutWidget::canView())->toBeFalse();

    MembershipSetting::current()->update(['showrunner_payouts_enabled' => true]);
    expect(ShowrunnerPayoutWidget::canView())->toBeTrue();
});

test('instructor payout widget is restricted to manager and up, and hidden once instructor_payouts_enabled is off', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(InstructorPayoutWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(InstructorPayoutWidget::canView())->toBeTrue();

    MembershipSetting::current()->update(['instructor_payouts_enabled' => false]);
    expect(InstructorPayoutWidget::canView())->toBeFalse();

    MembershipSetting::current()->update(['instructor_payouts_enabled' => true]);
    expect(InstructorPayoutWidget::canView())->toBeTrue();
});
