<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
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

beforeEach(function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true]);
});

test('the kiosk page does not exist while kiosk check-in is off', function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => false]);

    $this->get('/kiosk')->assertNotFound();
});

test('the kiosk page needs no sign-in and starts no session', function () {
    withKioskBuild(true, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        $response = $this->get('/kiosk')->assertOk();

        expect(collect($response->headers->getCookies())->map->getName()->all())
            ->not->toContain(config('session.cookie'), 'XSRF-TOKEN');
    });
});

test('with an event running the kiosk page shows the scanner', function () {
    withKioskBuild(true, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        $this->get('/kiosk')
            ->assertOk()
            ->assertSee('data-open="1"', false)
            ->assertSee('data-video', false)
            ->assertSee(route('kiosk.scan'), false);
    });
});

test('with no event running the kiosk page is closed and asks for no camera', function () {
    withKioskBuild(true, function () {
        $this->get('/kiosk')
            ->assertOk()
            ->assertSee('data-open="0"', false)
            ->assertSee('There\'s no event running right now.')
            ->assertDontSee('data-video', false);
    });
});

test('an unbuilt kiosk script shows how to build it instead of failing', function () {
    withKioskBuild(false, function () {
        Event::factory()->create(['event_date' => today()->toDateString()]);

        $this->get('/kiosk')
            ->assertOk()
            ->assertSee('The kiosk screen isn\'t built on this server yet.')
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
    $old = MembershipSetting::current()->regenerateKioskDeviceSecret();

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
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => false]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(FeatureFlags::class)
        ->fillForm(['kiosk_checkin_enabled' => true])
        ->callAction('save');

    expect(DB::table('notifications')->where('notifiable_id', $admin->id)->where('data', 'like', '%set up the kiosk tablet%')->exists())->toBeTrue();
});
