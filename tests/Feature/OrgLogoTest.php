<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\MembershipSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/** Like the UI: clear any current logo (its ✕), then drop the new file in. */
function uploadOrgLogo(?UploadedFile $file)
{
    $page = Livewire::test(MembershipSettings::class)->set('data.org_logo_path', []);

    return ($file ? $page->set('data.org_logo_path', $file) : $page)
        ->callAction('save');
}

test('the logo field is hidden from manager and admin, visible to owner', function () {
    foreach ([Role::Manager, Role::Admin] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(MembershipSettings::class)
            ->assertSchemaComponentExists('org_logo_path', checkComponentUsing: fn ($component) => ! $component->isVisible());
    }

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));

    Livewire::test(MembershipSettings::class)
        ->assertSchemaComponentExists('org_logo_path', checkComponentUsing: fn ($component) => $component->isVisible());
});

test('an owner uploads a logo and it is stored on the public disk', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));

    uploadOrgLogo(UploadedFile::fake()->image('club.png', 400, 100))->assertHasNoActionErrors();

    $path = MembershipSetting::current()->org_logo_path;
    expect($path)->toStartWith('branding/');
    Storage::disk('public')->assertExists($path);
    expect(MembershipSetting::current()->orgLogoUrl())->toBe(Storage::disk('public')->url($path));
});

test('replacing the logo deletes the old file', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));
    uploadOrgLogo(UploadedFile::fake()->image('first.png'));
    $first = MembershipSetting::current()->org_logo_path;

    uploadOrgLogo(UploadedFile::fake()->image('second.png'))->assertHasNoActionErrors();

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists(MembershipSetting::current()->org_logo_path);
});

test('removing the logo deletes its file and goes back to the name', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));
    uploadOrgLogo(UploadedFile::fake()->image('club.png'));
    $path = MembershipSetting::current()->org_logo_path;

    uploadOrgLogo(null)->assertHasNoActionErrors();

    Storage::disk('public')->assertMissing($path);
    expect(MembershipSetting::current()->org_logo_path)->toBeNull()
        ->and(MembershipSetting::current()->orgLogoUrl())->toBeNull();
});

test('an SVG or oversized logo is rejected', function (UploadedFile $file) {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));

    uploadOrgLogo($file)->assertHasFormErrors(['org_logo_path']);

    expect(MembershipSetting::current()->org_logo_path)->toBeNull();
})->with([
    'svg' => fn () => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
    'over 1 MB' => fn () => UploadedFile::fake()->image('big.png')->size(1500),
]);

test('a forged logo path from a manager or admin is ignored on save', function () {
    foreach ([Role::Manager, Role::Admin] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(MembershipSettings::class)
            ->set('data.org_logo_path', 'branding/forged.png')
            ->callAction('save');

        expect(MembershipSetting::current()->org_logo_path)->toBeNull();
    }
});

test('the panel shows the club name as text until a logo is set, then the logo', function () {
    $panel = Filament::getPanel('admin');
    expect($panel->getBrandLogo())->toBeNull();

    MembershipSetting::current()->update(['org_logo_path' => 'branding/club.png']);

    expect($panel->getBrandLogo())->toBe(Storage::disk('public')->url('branding/club.png'));
    $this->get('/admin/login')->assertSee(Storage::disk('public')->url('branding/club.png'));
});

test('the kiosk screen shows the club logo instead of the Portico wheel once set', function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true, 'org_logo_path' => 'branding/club.png']);

    $this->get('/kiosk')
        ->assertOk()
        ->assertSee('<img class="mark" src="'.Storage::disk('public')->url('branding/club.png').'" alt="">', false)
        ->assertDontSee('portico-wheel-white.png');
});
