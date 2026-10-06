<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Http\Middleware\VerifyKioskDevice;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Serves the kiosk page from a scratch public folder whose Vite manifest
 * does (or doesn't) list the kiosk script, so the test never depends on
 * whether this checkout has run `npm run build`.
 */
function withKioskBuild(bool $built, Closure $test): void
{
    $publicPath = public_path();
    $scratch = storage_path('framework/testing/kiosk-'.uniqid());
    File::ensureDirectoryExists("{$scratch}/build");
    File::put("{$scratch}/build/manifest.json", json_encode($built
        ? ['resources/js/kiosk.js' => ['file' => 'assets/kiosk.js', 'src' => 'resources/js/kiosk.js', 'isEntry' => true]]
        : []));

    try {
        app()->usePublicPath($scratch);
        $test();
    } finally {
        app()->usePublicPath($publicPath);
        File::deleteDirectory($scratch);
    }
}

/** The kiosk page as a linked tablet loads it. */
function openKiosk(object $test, ?string $secret = null)
{
    return $test->withUnencryptedCookie(VerifyKioskDevice::COOKIE, $secret ?? $test->secret)->get('/kiosk');
}

beforeEach(function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true]);
    $this->secret = MembershipSetting::current()->regenerateKioskDeviceSecret();
});

test('the kiosk page does not exist while kiosk check-in is off', function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => false]);

    openKiosk($this)->assertNotFound();
    $this->get('/kiosk?key='.$this->secret)->assertNotFound();
});

test('the kiosk page needs no sign-in and starts no session', function () {
    withKioskBuild(true, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        $response = openKiosk($this)->assertOk();

        expect(collect($response->headers->getCookies())->map->getName()->all())
            ->not->toContain(config('session.cookie'), 'XSRF-TOKEN');
    });
});

test('opening the setup link links the tablet with an http-only same-site cookie and drops the key from the address', function () {
    $response = $this->get('/kiosk?key='.$this->secret)->assertRedirect(route('kiosk'));

    $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === VerifyKioskDevice::COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe($this->secret)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/kiosk')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addYears(4)->timestamp);
});

test('a setup link with a wrong or old key links nothing', function () {
    withKioskBuild(true, function () {
        $response = $this->get('/kiosk?key=not-the-secret')
            ->assertOk()
            ->assertSee('This setup link is no longer valid.', false)
            ->assertSee('data-open="0"', false);

        expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(VerifyKioskDevice::COOKIE);
    });
});

test('a tablet never linked, or linked with a replaced secret, is asked to set up and shows no scanner', function () {
    withKioskBuild(true, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        $this->get('/kiosk')
            ->assertOk()
            ->assertSee('set up yet', false)
            ->assertSee('data-open="0"', false)
            ->assertDontSee('data-video', false);

        $old = $this->secret;
        MembershipSetting::current()->regenerateKioskDeviceSecret();

        openKiosk($this, $old)
            ->assertSee('set up yet', false)
            ->assertDontSee('data-video', false);
    });
});

test('with an event running a linked tablet shows the scanner', function () {
    withKioskBuild(true, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        openKiosk($this)
            ->assertOk()
            ->assertSee('data-open="1"', false)
            ->assertSee('data-video', false)
            ->assertSee(route('kiosk.scan'), false);
    });
});

test('with no event running the kiosk page is closed and asks for no camera', function () {
    withKioskBuild(true, function () {
        openKiosk($this)
            ->assertOk()
            ->assertSee('data-open="0"', false)
            ->assertSee('no event running right now', false)
            ->assertDontSee('data-video', false);
    });
});

test('an unbuilt kiosk script shows how to build it instead of failing', function () {
    withKioskBuild(false, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        openKiosk($this)
            ->assertOk()
            ->assertSee('built on this server yet', false)
            ->assertDontSee('data-video', false);
    });
});

test('an admin can set up a kiosk tablet, and the link it shows carries the new secret', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $page = Livewire::test(FeatureFlags::class)
        ->assertActionVisible('setUpKioskDevice')
        ->callAction('setUpKioskDevice');

    $url = $page->get('kioskSetupUrl');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(route('kiosk'))
        ->and(MembershipSetting::current()->fresh()->kioskDeviceSecretMatches($query['key'] ?? null))->toBeTrue();
});

test('setting up a kiosk tablet again locks out the old link', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    $old = $this->secret;

    Livewire::test(FeatureFlags::class)->callAction('setUpKioskDevice');

    expect(MembershipSetting::current()->fresh()->kioskDeviceSecretMatches($old))->toBeFalse();
});

test('a manager cannot set up a kiosk tablet, and nobody can with the flag off', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(FeatureFlags::class)->assertActionHidden('setUpKioskDevice');

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => false]);

    Livewire::test(FeatureFlags::class)->assertActionHidden('setUpKioskDevice');
});

test('turning kiosk check-in on reminds an admin to set up the tablet', function () {
    MembershipSetting::current()->forceFill(['kiosk_checkin_enabled' => false, 'kiosk_device_secret_hash' => null])->save();
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(FeatureFlags::class)
        ->fillForm(['kiosk_checkin_enabled' => true])
        ->callAction('save');

    expect(DB::table('notifications')->where('notifiable_id', $admin->id)->where('data', 'like', '%set up the kiosk tablet%')->exists())->toBeTrue();
});
