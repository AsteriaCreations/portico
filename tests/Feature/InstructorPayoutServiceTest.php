<?php

use App\Enums\EntryCoverageSource;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\InstructorPayRate;
use App\Services\InstructorPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new InstructorPayoutService;
});

test('pays per attendee, broken out by how the entry was covered', function () {
    $yoga = EventType::factory()->create(['name' => 'Yoga']);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::None, 'rate' => 10.00]);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::RegularSubscription, 'rate' => 5.00]);

    $event = Event::factory()->create(['event_type_id' => $yoga->id]);
    Attendance::factory()->for($event)->count(6)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);
    Attendance::factory()->for($event)->count(4)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::RegularSubscription]);

    $result = $this->service->calculate($event);

    expect($result->total)->toBe(80.0) // 6*10 + 4*5
        ->and($result->lineItems)->toHaveCount(2);

    $cashLine = collect($result->lineItems)->first(fn ($item) => $item['source'] === EntryCoverageSource::None);
    expect($cashLine['count'])->toBe(6)
        ->and($cashLine['subtotal'])->toBe(60.0);
});

test('a coverage bucket with no configured rate contributes nothing and is omitted', function () {
    $yoga = EventType::factory()->create(['name' => 'Yoga']);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::None, 'rate' => 10.00]);

    $event = Event::factory()->create(['event_type_id' => $yoga->id]);
    Attendance::factory()->for($event)->count(3)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);
    // Comped -- no InstructorPayRate row for this bucket, contributes $0.
    Attendance::factory()->for($event)->count(2)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::Comp]);

    $result = $this->service->calculate($event);

    expect($result->total)->toBe(30.0)
        ->and($result->lineItems)->toHaveCount(1);
});

test('only counts arrived attendees, not a prepaid-but-not-yet-arrived row', function () {
    $yoga = EventType::factory()->create(['name' => 'Yoga']);
    InstructorPayRate::create(['event_type_id' => $yoga->id, 'entry_covered_by' => EntryCoverageSource::None, 'rate' => 10.00]);

    $event = Event::factory()->create(['event_type_id' => $yoga->id]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);
    Attendance::factory()->for($event)->create(['checked_in_at' => null, 'entry_covered_by' => EntryCoverageSource::None]);

    $result = $this->service->calculate($event);

    expect($result->total)->toBe(10.0);
});

test('an event whose type has no configured rates returns an empty result', function () {
    $social = EventType::factory()->create(['name' => 'Social']);
    $event = Event::factory()->create(['event_type_id' => $social->id]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now(), 'entry_covered_by' => EntryCoverageSource::None]);

    $result = $this->service->calculate($event);

    expect($result->lineItems)->toBe([])
        ->and($result->total)->toBe(0.0);
});
