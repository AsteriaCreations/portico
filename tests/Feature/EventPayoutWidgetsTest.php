<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventType;
use App\Models\InstructorPayRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    Plan::create([
        'add_on_id' => $entry->id,
        'price' => 60.00,
        'credit' => 25.00,
        'effective_from' => '2026-01-01',
    ]);

    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 10.00]);

    $this->category = Category::factory()->create();

    // EventResource::update is Admin+ (Manager can view but not edit an
    // event), so the edit page these tests load needs an Admin actor.
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
});

test('the showrunner payout widget is absent when the event has no showrunner assigned', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'showrunner_id' => null]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertDontSee('Showrunner payout');
});

test('the showrunner payout widget shows the commission breakdown once a showrunner is assigned', function () {
    $showrunner = Member::factory()->create(['category_id' => $this->category->id]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 10, 'showrunner_id' => $showrunner->id]);
    Attendance::factory()->for($event)->recycle($this->category)->count(5)->create([
        'checked_in_at' => now(),
        'entry_covered_by' => EntryCoverageSource::None,
        'entry_fee' => 10,
        'entry_coverage' => 0,
    ]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertSee('Showrunner payout')
        ->assertSee('$5.00'); // 10% of $50 door
});

test('the instructor payout widget is absent when the event type has no configured rates', function () {
    $social = EventType::factory()->create(['name' => 'Social']);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'event_type_id' => $social->id]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertDontSee('Instructor payout');
});

test('the instructor payout widget shows the per-head breakdown once rates are configured for the event type', function () {
    $yoga = EventType::factory()->create(['name' => 'Yoga']);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::None, 'rate' => 10.00]);
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'event_type_id' => $yoga->id]);
    Attendance::factory()->for($event)->recycle($this->category)->count(3)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertSee('Instructor payout')
        ->assertSee('$30.00'); // 3 * $10
});
