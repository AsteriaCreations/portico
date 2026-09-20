<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Points the app at a scratch lang folder so a test can "install" a language
// without writing into the real lang/ directory.
beforeEach(function () {
    $this->originalLangPath = lang_path();
    $this->scratchLangPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-lang-'.uniqid();
    File::makeDirectory($this->scratchLangPath);
    app()->useLangPath($this->scratchLangPath);
});

afterEach(function () {
    app()->useLangPath($this->originalLangPath);
    File::deleteDirectory($this->scratchLangPath);
    app()->setLocale('en');
    Number::useLocale('en');
});

function installLanguage(string $code): void
{
    File::put(lang_path("{$code}.json"), '{}');
}

function managerForLocale(): User
{
    return User::factory()->create(['active' => true, 'role' => Role::Manager]);
}

test('available locales are English plus every installed lang json, labelled in their own language', function () {
    expect(MembershipSetting::availableLocales())->toBe(['en' => 'English']);

    installLanguage('es');
    installLanguage('fr');

    expect(MembershipSetting::availableLocales())->toBe([
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
    ]);
});

test('the locale column is null by default so an upgrade changes nothing', function () {
    expect(MembershipSetting::current()->locale)->toBeNull();
});

test('an admin request applies the installed locale from the setting', function () {
    installLanguage('es');
    MembershipSetting::current()->update(['locale' => 'es']);

    $this->actingAs(managerForLocale())->get('/admin/membership-settings')->assertSuccessful();

    expect(app()->getLocale())->toBe('es')
        ->and(Number::defaultLocale())->toBe('es');
});

test('a null locale leaves the configured app locale in force', function () {
    installLanguage('es');

    $this->actingAs(managerForLocale())->get('/admin/membership-settings')->assertSuccessful();

    expect(app()->getLocale())->toBe(config('app.locale'))
        ->and(Number::defaultLocale())->toBe(config('app.locale'));
});

test('a locale with no installed lang file is ignored', function () {
    MembershipSetting::current()->update(['locale' => 'de']);

    $this->actingAs(managerForLocale())->get('/admin/membership-settings')->assertSuccessful();

    expect(app()->getLocale())->toBe(config('app.locale'));
});

test('a manager can save an installed locale from the settings page', function () {
    installLanguage('es');

    $this->actingAs(managerForLocale());

    Livewire::test(MembershipSettings::class)
        ->fillForm(['locale' => 'es'])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->locale)->toBe('es');
});

test('the settings page rejects a locale that is not installed', function () {
    $this->actingAs(managerForLocale());

    Livewire::test(MembershipSettings::class)
        ->fillForm(['locale' => 'de'])
        ->callAction('save')
        ->assertHasActionErrors(['locale']);

    expect(MembershipSetting::current()->locale)->toBeNull();
});

test('the locale can be cleared back to the server default', function () {
    installLanguage('es');
    MembershipSetting::current()->update(['locale' => 'es']);

    $this->actingAs(managerForLocale());

    Livewire::test(MembershipSettings::class)
        ->fillForm(['locale' => null])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->locale)->toBeNull();
});
