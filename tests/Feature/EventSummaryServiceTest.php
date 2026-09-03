<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Services\EventSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EventSummaryService;
});

test('forEvent counts arrived attendees, prepaid no-shows, and total revenue', function () {
    $event = Event::factory()->create();
    Attendance::factory()->for($event)->count(2)->create(['checked_in_at' => now(), 'amount_paid' => 20]);
    Attendance::factory()->for($event)->create(['checked_in_at' => null, 'amount_paid' => 15]);

    $summary = $this->service->forEvent($event);

    expect($summary['checked_in'])->toBe(2)
        ->and($summary['prepaid_no_show'])->toBe(1)
        ->and($summary['revenue'])->toEqual(55.0);
});

test('forEvent only counts attendance for the given event, not others', function () {
    $event = Event::factory()->create();
    $otherEvent = Event::factory()->create();
    Attendance::factory()->for($event)->create(['checked_in_at' => now(), 'amount_paid' => 20]);
    Attendance::factory()->for($otherEvent)->create(['checked_in_at' => now(), 'amount_paid' => 999]);

    $summary = $this->service->forEvent($event);

    expect($summary['checked_in'])->toBe(1)
        ->and($summary['revenue'])->toEqual(20.0);
});

test('forEvent returns zeroes for an event with no attendance at all', function () {
    $event = Event::factory()->create();

    $summary = $this->service->forEvent($event);

    expect($summary)->toBe(['checked_in' => 0, 'prepaid_no_show' => 0, 'revenue' => 0.0]);
});
