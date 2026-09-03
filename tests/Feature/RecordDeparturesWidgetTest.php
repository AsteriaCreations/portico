<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\RecordDeparturesWidget;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
});

function clearMemberForDepartures(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
    ], $overrides));
}

test('the widget is visible to Volunteer and above, but not Showrunner', function () {
    $showrunner = User::factory()->create(['active' => true, 'role' => Role::Showrunner]);
    $this->actingAs($showrunner);
    expect(RecordDeparturesWidget::canView())->toBeFalse();

    foreach ([Role::Volunteer, Role::DM, Role::Door, Role::Manager, Role::Admin, Role::Owner] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);
        $this->actingAs($user);
        expect(RecordDeparturesWidget::canView())->toBeTrue();
    }
});

test('the widget does not render on a Showrunner\'s dashboard, but does for a Volunteer', function () {
    $showrunner = User::factory()->create(['active' => true, 'role' => Role::Showrunner]);
    $this->actingAs($showrunner)
        ->get('/admin')
        ->assertSuccessful()
        ->assertDontSee('Record departures');

    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Record departures');
});

test('a Volunteer can record a departure, restoring a capacity-blocked check-in', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $existingMember = clearMemberForDepartures($this->irregular, ['username' => 'already-here']);
    $event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20]);
    Attendance::factory()->for($existingMember)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(RecordDeparturesWidget::class)
        ->callAction('recordDepartures', data: ['count' => 1])
        ->assertHasNoActionErrors();

    $adjustment = OccupancyAdjustment::firstOrFail();
    expect($adjustment->delta)->toEqual(-1)
        ->and($adjustment->for_date->toDateString())->toBe(today()->toDateString())
        ->and($adjustment->recorded_by)->toBe($volunteer->id);
});
