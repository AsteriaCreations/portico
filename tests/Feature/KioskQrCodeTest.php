<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function kioskQrUser(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

function enableKioskCheckin(): void
{
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true]);
}

test('kiosk_checkin_enabled is off by default, and the gates follow it', function () {
    $this->actingAs(kioskQrUser(Role::Owner));

    expect(MembershipSetting::current()->kiosk_checkin_enabled)->toBeFalse()
        ->and(Gate::allows('manage-kiosk-token'))->toBeFalse()
        ->and(Gate::allows('replace-kiosk-token'))->toBeFalse();

    enableKioskCheckin();

    expect(Gate::allows('manage-kiosk-token'))->toBeTrue()
        ->and(Gate::allows('replace-kiosk-token'))->toBeTrue();
});

test('the kiosk gates are door+ to show a code and manager+ to replace one', function () {
    enableKioskCheckin();

    $this->actingAs(kioskQrUser(Role::DM));
    expect(Gate::allows('manage-kiosk-token'))->toBeFalse();

    $this->actingAs(kioskQrUser(Role::Door));
    expect(Gate::allows('manage-kiosk-token'))->toBeTrue()
        ->and(Gate::allows('replace-kiosk-token'))->toBeFalse();

    $this->actingAs(kioskQrUser(Role::Manager));
    expect(Gate::allows('replace-kiosk-token'))->toBeTrue();
});

test('an admin can turn kiosk check-in on at feature flags', function () {
    $this->actingAs(kioskQrUser(Role::Admin));

    Livewire::test(FeatureFlags::class)
        ->fillForm(['kiosk_checkin_enabled' => true])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect(MembershipSetting::current()->kiosk_checkin_enabled)->toBeTrue();
});

test('ensureKioskToken creates a code once and then reuses it', function () {
    $member = Member::factory()->create();

    $token = $member->ensureKioskToken();

    expect($token)->toHaveLength(48)
        ->and($member->refresh()->kiosk_token)->toBe($token)
        ->and($member->kiosk_token_generated_at)->not->toBeNull()
        ->and($member->ensureKioskToken())->toBe($token);
});

test('regenerateKioskToken replaces the code', function () {
    $member = Member::factory()->create();
    $old = $member->ensureKioskToken();

    $new = $member->regenerateKioskToken();

    expect($new)->not->toBe($old)
        ->and($member->refresh()->kiosk_token)->toBe($new)
        ->and(Member::where('kiosk_token', $old)->exists())->toBeFalse();
});

test('the kiosk code is never mass-assignable or serialized', function () {
    $member = Member::factory()->create();
    $member->ensureKioskToken();

    $member->fill(['kiosk_token' => 'forged'])->save();

    expect($member->refresh()->kiosk_token)->not->toBe('forged')
        ->and($member->toArray())->not->toHaveKey('kiosk_token');
});

test('the desk hides the kiosk qr action while the flag is off', function () {
    $this->actingAs(kioskQrUser(Role::Door));
    $member = Member::factory()->create();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionHidden('kioskQrCode');
});

test('door can open a member\'s kiosk qr at the desk, which creates the code once', function () {
    enableKioskCheckin();
    $this->actingAs(kioskQrUser(Role::Door));
    $member = Member::factory()->create();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionVisible('kioskQrCode')
        ->mountAction('kioskQrCode')
        ->assertMountedActionModalSee('Open a printable card');

    $token = $member->refresh()->kiosk_token;
    expect($token)->not->toBeNull();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->mountAction('kioskQrCode');

    expect($member->refresh()->kiosk_token)->toBe($token);
});

test('the desk creates no kiosk code in training mode', function () {
    enableKioskCheckin();
    $this->actingAs(kioskQrUser(Role::Door));
    $member = Member::factory()->create();

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('data.member_id', $member->id)
        ->mountAction('kioskQrCode')
        ->assertMountedActionModalSee('No kiosk code yet.');

    expect($member->refresh()->kiosk_token)->toBeNull();
});

test('a manager can replace a kiosk code from the member page', function () {
    enableKioskCheckin();
    $this->actingAs(kioskQrUser(Role::Manager));
    $member = Member::factory()->create();
    $old = $member->ensureKioskToken();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertActionVisible('kioskQrCode')
        ->callAction('replaceKioskCode');

    expect($member->refresh()->kiosk_token)->not->toBe($old);
});

test('the printable card shows the qr for door, and is forbidden with the flag off', function () {
    $this->actingAs(kioskQrUser(Role::Door));
    $member = Member::factory()->create();
    $member->ensureKioskToken();

    $this->get(route('kiosk-qr-card', $member))->assertForbidden();

    enableKioskCheckin();

    $this->get(route('kiosk-qr-card', $member))
        ->assertOk()
        ->assertSee('data:image/png;base64,', false)
        ->assertSee($member->displayName());
});

test('the printable card never creates a code', function () {
    enableKioskCheckin();
    $this->actingAs(kioskQrUser(Role::Door));
    $member = Member::factory()->create();

    $this->get(route('kiosk-qr-card', $member))->assertNotFound();

    expect($member->refresh()->kiosk_token)->toBeNull();
});

test('the printable card is forbidden below door', function () {
    enableKioskCheckin();
    $this->actingAs(kioskQrUser(Role::Volunteer));
    $member = Member::factory()->create();
    $member->ensureKioskToken();

    $this->get(route('kiosk-qr-card', $member))->assertForbidden();
});
