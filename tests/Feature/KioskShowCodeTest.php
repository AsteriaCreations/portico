<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Http\Middleware\VerifyKioskDevice;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\KioskCodeDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * "Show on kiosk": staff send a member's code from the desk, and the kiosk
 * tablet collects it (as the tablet does: a plain POST with the device
 * cookie, nobody signed in) to show for the member to photograph.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = MembershipSetting::current();
    $this->settings->update(['kiosk_checkin_enabled' => true, 'kiosk_show_code_enabled' => true]);
    $this->secret = $this->settings->regenerateKioskDeviceSecret();

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $this->member = Member::factory()->create();
});

function pollKiosk(object $test, ?string $secret = null)
{
    return $test->withCredentials()->withUnencryptedCookie(VerifyKioskDevice::COOKIE, $secret ?? $test->secret)
        ->postJson('/kiosk/show-code');
}

function showCodeUser(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('the poll does not exist with kiosk check-in off', function () {
    $this->settings->update(['kiosk_checkin_enabled' => false]);

    pollKiosk($this)->assertNotFound();
});

test('the poll refuses a missing or wrong device cookie', function () {
    app(KioskCodeDisplay::class)->queue($this->member);

    $this->postJson('/kiosk/show-code')->assertForbidden();
    pollKiosk($this, 'not-the-secret')->assertForbidden();
});

test('the poll gets nothing while nothing is waiting', function () {
    pollKiosk($this)->assertNoContent();
});

test('a sent code is handed to the tablet once, with the member\'s name', function () {
    app(KioskCodeDisplay::class)->queue($this->member);

    pollKiosk($this)->assertOk()
        ->assertJson(['name' => $this->member->displayName()])
        ->assertJsonPath('qr', fn (string $qr): bool => str_starts_with($qr, 'data:image/png;base64,'));

    pollKiosk($this)->assertNoContent();
});

test('a sent code the tablet never collects expires', function () {
    app(KioskCodeDisplay::class)->queue($this->member);

    $this->travel(KioskCodeDisplay::seconds() + 1)->seconds();

    pollKiosk($this)->assertNoContent();
});

test('with the feature off the tablet gets nothing even if a code is waiting', function () {
    app(KioskCodeDisplay::class)->queue($this->member);
    $this->settings->update(['kiosk_show_code_enabled' => false]);

    pollKiosk($this)->assertNoContent();
});

test('a code replaced after it was sent never reaches the tablet', function () {
    app(KioskCodeDisplay::class)->queue($this->member);
    $this->member->regenerateKioskToken();

    pollKiosk($this)->assertNoContent();
});

test('polling has its own rate limit budget, so it never blocks scans', function () {
    foreach (range(1, 30) as $ignored) {
        pollKiosk($this)->assertNoContent();
    }

    pollKiosk($this)->assertTooManyRequests();
    $this->withCredentials()->withUnencryptedCookie(VerifyKioskDevice::COOKIE, $this->secret)
        ->postJson('/kiosk/scan', ['token' => 'no-such-code'])
        ->assertOk();
});

test('the show-on-kiosk gate is door+ and needs both flags', function () {
    $this->actingAs(showCodeUser(Role::Door));
    expect(Gate::allows('show-kiosk-code-on-kiosk'))->toBeTrue();

    $this->actingAs(showCodeUser(Role::DM));
    expect(Gate::allows('show-kiosk-code-on-kiosk'))->toBeFalse();

    $this->actingAs(showCodeUser(Role::Door));
    $this->settings->update(['kiosk_show_code_enabled' => false]);
    expect(Gate::allows('show-kiosk-code-on-kiosk'))->toBeFalse();

    $this->settings->update(['kiosk_show_code_enabled' => true, 'kiosk_checkin_enabled' => false]);
    expect(Gate::allows('show-kiosk-code-on-kiosk'))->toBeFalse();
});

test('kiosk_show_code_enabled is off by default', function () {
    MembershipSetting::query()->delete();
    MembershipSetting::forgetCurrent();

    expect(MembershipSetting::current()->refresh()->kiosk_show_code_enabled)->toBeFalse();
});

test('door sends a member\'s code to the kiosk from the desk, creating it if needed', function () {
    $this->actingAs(showCodeUser(Role::Door));

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionVisible('showKioskQrCode')
        ->callAction('showKioskQrCode')
        ->assertNotified('Showing on the kiosk');

    expect($this->member->refresh()->kiosk_token)->not->toBeNull();
    pollKiosk($this)->assertOk()->assertJson(['name' => $this->member->displayName()]);
});

test('the desk hides show-on-kiosk while the flag is off', function () {
    $this->settings->update(['kiosk_show_code_enabled' => false]);
    $this->actingAs(showCodeUser(Role::Door));

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->assertActionHidden('showKioskQrCode');
});

test('training mode sends nothing to the kiosk', function () {
    $this->actingAs(showCodeUser(Role::Door));

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('data.member_id', $this->member->id)
        ->callAction('showKioskQrCode')
        ->assertNotified('Practice only — nothing saved');

    expect($this->member->refresh()->kiosk_token)->toBeNull();
    pollKiosk($this)->assertNoContent();
});

test('with no event running nothing is sent and staff are told the kiosk is closed', function () {
    $this->event->update(['event_date' => today()->subDays(3)->toDateString(), 'starts_at' => null, 'ends_at' => null]);
    $this->actingAs(showCodeUser(Role::Door));

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->member->id)
        ->callAction('showKioskQrCode')
        ->assertNotified('The kiosk is closed');

    pollKiosk($this)->assertNoContent();
});

test('a manager can send the code from the member page', function () {
    $this->actingAs(showCodeUser(Role::Manager));

    Livewire::test(EditMember::class, ['record' => $this->member->getRouteKey()])
        ->callAction('showKioskQrCode');

    pollKiosk($this)->assertOk();
});

test('an admin can turn show-on-kiosk on at feature flags', function () {
    $this->settings->update(['kiosk_show_code_enabled' => false]);
    $this->actingAs(showCodeUser(Role::Admin));

    Livewire::test(FeatureFlags::class)
        ->fillForm(['kiosk_show_code_enabled' => true])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect(MembershipSetting::current()->kiosk_show_code_enabled)->toBeTrue();
});

test('a sent code waits as long as Membership Settings says', function () {
    $this->settings->update(['kiosk_show_code_seconds' => 20]);
    app(KioskCodeDisplay::class)->queue($this->member);

    $this->travel(21)->seconds();

    pollKiosk($this)->assertNoContent();
    expect(KioskCodeDisplay::seconds())->toBe(20);
});
