<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Skills\Pages\CreateSkill;
use App\Filament\Admin\Resources\Skills\Pages\EditSkill;
use App\Filament\Admin\Resources\Skills\Pages\ListSkills;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('skills list page renders for a manager', function () {
    Skill::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListSkills::class)->assertSuccessful();
});

test('a manager can create a skill', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateSkill::class)
        ->fillForm([
            'name' => 'First Aid',
            'description' => 'Certified in basic first aid / CPR',
            'sort_order' => 5,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Skill::where('name', 'First Aid')->exists())->toBeTrue();
});

test('a door volunteer has no access to the skills resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/skills')->assertForbidden();
});

test('a manager can edit a skill', function () {
    $skill = Skill::factory()->create(['name' => 'DM Experience']);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(EditSkill::class, ['record' => $skill->getKey()])
        ->fillForm(['name' => 'Dungeon Master Experience'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($skill->refresh()->name)->toBe('Dungeon Master Experience');
});
