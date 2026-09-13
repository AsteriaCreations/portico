<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Enums\Role;
use App\Filament\Admin\Widgets\EventCompCostWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\CompReason;
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

test('the event comp cost widget is absent when nothing on the comp list has arrived', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $presenter = CompReason::factory()->create(['name' => 'Presenter']);
    // Still on the comp list, not yet arrived — hasn't cost anything yet.
    Attendance::factory()->for($event)->recycle($this->category)->create([
        'checked_in_at' => null,
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 20,
    ]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertDontSee('Comp list cost');
});

test('the event comp cost widget shows the foregone-revenue breakdown once a comp has arrived, scoped to this event', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $otherEvent = Event::factory()->create(['event_date' => '2026-07-20']);
    $presenter = CompReason::factory()->create(['name' => 'Presenter']);
    $houseSub = CompReason::factory()->create(['name' => 'House Sub']);

    Attendance::factory()->for($event)->recycle($this->category)->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 20,
    ]);
    Attendance::factory()->for($event)->recycle($this->category)->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $houseSub->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 15,
    ]);
    // Designated host, not a reason-tagged comp — excluded, same reasoning as CompCostWidget.
    Attendance::factory()->for($event)->recycle($this->category)->create([
        'checked_in_at' => now(),
        'comp_reason_id' => null,
        'entry_covered_by' => EntryCoverageSource::Host,
        'entry_coverage' => 50,
    ]);
    // An arrived comp at a different event — excluded.
    Attendance::factory()->for($otherEvent)->recycle($this->category)->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 999,
    ]);

    $this->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertSee('Comp list cost')
        ->assertSee('Presenter')
        ->assertSee('House Sub')
        ->assertSee('$35.00'); // total: $20 + $15, excluding the host and the other event
});

test('event comp cost widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(EventCompCostWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(EventCompCostWidget::canView())->toBeTrue();
});
