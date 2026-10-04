<?php

use App\Enums\Capability;
use App\Enums\Role;
use App\Filament\Admin\Pages\CleaningChecklist;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// A fixture language registered in memory ("es" is a valid ICU locale, which
// Filament's money formatting needs). See LocalizationResourcesTest.
beforeEach(function () {
    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        'System' => 'xx-System',
        'How to use Feature Flags' => 'xx-How to use Feature Flags',
        "Each row is one recurring task. Click <strong>Mark done</strong> once you've completed it." => 'xx-Click <strong>Mark done</strong> row',
        'Name (first & last)' => 'xx-Name (first & last)',
        ':count behavior note|:count behavior notes' => 'xx-:count note|xx-:count notes',
    ]]]]);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));
});

afterEach(function () {
    app()->setLocale('en');
});

test('a page section heading and its instruction panel title are translated', function () {
    app()->setLocale('es');

    Livewire::test(FeatureFlags::class)
        ->assertSee('xx-System')
        ->assertSee('xx-How to use Feature Flags');
});

test('an instruction paragraph containing markup is translated and rendered as HTML', function () {
    UserCapability::factory()->create(['user_id' => auth()->id(), 'capability' => Capability::CleaningCrew]);
    app()->setLocale('es');

    Livewire::test(CleaningChecklist::class)->assertSeeHtml('xx-Click <strong>Mark done</strong> row');
});

test('select option labels on the settings page are translated', function () {
    app()->setLocale('es');

    Livewire::test(MembershipSettings::class)->assertSee('xx-Name (first &amp; last)', false);
});

test('every instruction panel and page view renders in English', function () {
    $views = collect(glob(resource_path('views/filament/admin/instructions/*.blade.php')))
        ->map(fn (string $path): string => 'filament.admin.instructions.'.basename($path, '.blade.php'));

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        $html = view($view)->render();

        expect($html)->toContain('<details')
            ->and($html)->not->toContain('__(')
            ->and($html)->not->toContain('{{');
    }
});

test('the panel footer is translated, keeping the app name and both links', function () {
    $english = view('filament.admin.footer')->render();
    expect($english)->toContain(e(config('app.name')).' is free software, licensed under the <a href="https://www.gnu.org/licenses/agpl-3.0.html"')
        ->toContain('>GNU Affero General Public License v3.0 or later</a>. <a href="https://github.com/AsteriaCreations/portico"')
        ->toContain('>Source code</a>.');

    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        ':app is free software, licensed under the :license. :source.' => 'xx-:app libre, :license. :source.',
        'GNU Affero General Public License v3.0 or later' => 'xx-AGPL',
        'Source code' => 'xx-Source',
    ]]]]);
    app()->setLocale('es');

    expect(view('filament.admin.footer')->render())
        ->toContain('xx-'.e(config('app.name')).' libre, <a href="https://www.gnu.org/licenses/agpl-3.0.html"')
        ->toContain('>xx-AGPL</a>. <a href="https://github.com/AsteriaCreations/portico"')
        ->toContain('>xx-Source</a>.');
});
