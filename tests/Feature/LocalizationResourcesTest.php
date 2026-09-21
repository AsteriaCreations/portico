<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Technical;
use App\Filament\Admin\Resources\Categories\Pages\CreateCategory;
use App\Filament\Admin\Resources\Categories\Pages\EditCategory;
use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Resources\Members\Pages\CreateMember;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Filament\Admin\Resources\Members\RelationManagers\BehaviorNotesRelationManager;
use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Models\Category;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// A fixture language registered in memory -- "es" has no lang file in this test, so this
// exercises the translation call sites themselves rather than any shipped
// translation. setLoaded(), not addLines(), because addLines() nests any key
// containing a ".", unlike the flat JSON loader.
beforeEach(function () {
    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        // Derived from the attribute name ('sort_order') -- never set with ->label().
        'Sort order' => 'xx-Sort order',
        // Set explicitly with ->label('Comped').
        'Comped' => 'xx-Comped',
        'Bulk upload categories' => 'xx-Bulk upload categories',
        'Category' => 'xx-Category',
        'Watchlist' => 'xx-Watchlist',
        'Show personal info' => 'xx-Show personal info',
        'Hide personal info' => 'xx-Hide personal info',
        // Prose, not labels: section headings, tooltips, helper text, plurals.
        'Identity' => 'xx-Identity',
        'Not comped' => 'xx-Not comped',
        'This category name is relied on by check-in logic and cannot be renamed.' => 'xx-Protected name',
        'Created :count member|Created :count members' => 'xx-Made :count member|xx-Made :count members',
    ]]]]);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

afterEach(function () {
    app()->setLocale('en');
});

test('a form field label derived from its attribute name is translated', function () {
    app()->setLocale('es');

    Livewire::test(CreateCategory::class)->assertSee('xx-Sort order');
});

test('an explicit form field label is translated', function () {
    app()->setLocale('es');

    Livewire::test(CreateCategory::class)->assertSee('xx-Comped');
});

test('table column headers and header actions are translated', function () {
    Category::factory()->create();
    app()->setLocale('es');

    Livewire::test(ListCategories::class)
        ->assertSee('xx-Comped')
        ->assertSee('xx-Bulk upload categories');
});

test('table filters and a closure-driven action label are translated', function () {
    Member::factory()->create();
    app()->setLocale('es');

    // piiHidden defaults on, so the closure label resolves to "Show personal info".
    Livewire::test(ListMembers::class)
        ->assertSee('xx-Category')
        ->assertSee('xx-Watchlist')
        ->assertSee('xx-Show personal info');
});

test('labels stay English under the default locale', function () {
    Livewire::test(CreateCategory::class)
        ->assertSee('Sort order')
        ->assertDontSee('xx-');
});

test('resource model, plural and navigation labels are translated from their English derivation', function () {
    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        'member' => 'miembro',
        'members' => 'miembros',
        'Subscription Plan' => 'Plan de suscripción',
        'Subscription Plans' => 'Planes de suscripción',
    ]]]]);
    app()->setLocale('es');

    // Derived from the model class name; the plural is built from the English
    // singular, not from the translated one.
    expect(MemberResource::getModelLabel())->toBe('miembro')
        ->and(MemberResource::getPluralModelLabel())->toBe('miembros')
        ->and(MemberResource::getNavigationLabel())->toBe('Miembros')
        // Set through static properties on the resource.
        ->and(PlanResource::getModelLabel())->toBe('Plan de suscripción')
        ->and(PlanResource::getNavigationLabel())->toBe('Planes de suscripción');

    app()->setLocale('en');

    expect(MemberResource::getPluralModelLabel())->toBe('members')
        ->and(MemberResource::getNavigationLabel())->toBe('Members');
});

test('a resource list page title, a page navigation label and a relation manager title are translated', function () {
    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        'members' => 'miembros',
        'Technical' => 'Técnico',
        'Behavior notes' => 'Notas de conducta',
    ]]]]);
    app()->setLocale('es');

    expect(Technical::getNavigationLabel())->toBe('Técnico')
        ->and(BehaviorNotesRelationManager::getTitle(Member::factory()->create(), EditMember::class))->toBe('Notas de conducta');

    Livewire::test(ListMembers::class)->assertSee('Miembros');
});

test('section headings on the member form are translated', function () {
    app()->setLocale('es');

    Livewire::test(CreateMember::class)->assertSee('xx-Identity');
});

test('a tooltip driven by a closure on a table column is translated', function () {
    Category::factory()->create(['is_comped' => false]);
    app()->setLocale('es');

    Livewire::test(ListCategories::class)->assertSee('xx-Not comped');
});

test('a helper text closure on the category form is translated for a protected category', function () {
    $guest = Category::factory()->create(['name' => 'Guest']);
    app()->setLocale('es');

    Livewire::test(EditCategory::class, ['record' => $guest->getKey()])
        ->assertSee('xx-Protected name');
});

test('plural notification titles pick the singular or plural translation', function () {
    app()->setLocale('es');

    expect(trans_choice('Created :count member|Created :count members', 1))->toBe('xx-Made 1 member')
        ->and(trans_choice('Created :count member|Created :count members', 3))->toBe('xx-Made 3 members');

    app()->setLocale('en');

    expect(trans_choice('Created :count member|Created :count members', 1))->toBe('Created 1 member')
        ->and(trans_choice('Created :count member|Created :count members', 3))->toBe('Created 3 members');
});
