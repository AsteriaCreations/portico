<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Categories\Pages\CreateCategory;
use App\Filament\Admin\Resources\Categories\Pages\EditCategory;
use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('categories list page renders for a manager', function () {
    Category::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListCategories::class)->assertSuccessful();
});

test('a manager can create a category', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'House Sub',
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::where('name', 'House Sub')->exists())->toBeTrue();
});

test('a door volunteer has no access to the categories resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/categories')->assertForbidden();
});

test('a manager can freely rename and delete a custom category', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $category = Category::factory()->create(['name' => 'House Sub']);
    $this->actingAs($manager);

    Livewire::test(EditCategory::class, ['record' => $category->getKey()])
        ->fillForm(['name' => 'Presenter'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->refresh()->name)->toBe('Presenter')
        ->and($manager->can('delete', $category))->toBeTrue();
});

test('renaming a protected category name is silently ignored', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $category = Category::factory()->create(['name' => 'Prospective']);
    $this->actingAs($manager);

    Livewire::test(EditCategory::class, ['record' => $category->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->refresh()->name)->toBe('Prospective');
});

test('a protected category cannot be deleted, even by an admin', function () {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $guest = Category::factory()->create(['name' => 'Guest']);
    $irregular = Category::factory()->create(['name' => 'Irregular']);

    expect($admin->can('delete', $guest))->toBeFalse()
        ->and($admin->can('delete', $irregular))->toBeFalse();
});
