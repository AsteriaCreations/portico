<?php

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

uses(RefreshDatabase::class);

// End to end through the real JSON loader: a lang/es.json on disk, the
// installation's locale setting, SetLocale middleware, and the rendered panel.
beforeEach(function () {
    $this->originalLangPath = lang_path();
    $this->scratchLangPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-lang-'.uniqid();
    File::makeDirectory($this->scratchLangPath);
    File::put($this->scratchLangPath.DIRECTORY_SEPARATOR.'es.json', json_encode([
        'Records' => 'Registros',
        'Members & Events' => 'Miembros y eventos',
        'members' => 'miembros',
        'Categories' => 'Categorías',
    ], JSON_UNESCAPED_UNICODE));

    app()->useLangPath($this->scratchLangPath);
    app('translator')->getLoader()->addJsonPath($this->scratchLangPath);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

afterEach(function () {
    app()->useLangPath($this->originalLangPath);
    File::deleteDirectory($this->scratchLangPath);
    app()->setLocale('en');
    Number::useLocale('en');
});

test('the sidebar renders translated groups and item labels for the installation locale', function () {
    MembershipSetting::current()->update(['locale' => 'es']);

    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('Registros')
        ->assertSee('Miembros y eventos')
        ->assertSee('Miembros')
        ->assertDontSee('Members &amp; Events');
});

test('the sidebar stays English when no locale is set', function () {
    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('Records')
        ->assertSee('Members &amp; Events', false)
        ->assertDontSee('Registros');
});
