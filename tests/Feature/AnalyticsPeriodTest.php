<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Support\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A Wednesday.
    $this->travelTo('2026-11-18 15:30:00');
});

/**
 * @return array{0: string, 1: string} from, and the first day after the period
 */
function periodBounds(AnalyticsPeriod $period): array
{
    return [$period->from->toDateTimeString(), $period->untilExclusive->toDateTimeString()];
}

test('each preset covers whole days of its calendar unit', function (string $preset, string $from, string $until) {
    expect(periodBounds(AnalyticsPeriod::forPreset($preset)))->toBe([$from.' 00:00:00', $until.' 00:00:00']);
})->with([
    ['this_week', '2026-11-16', '2026-11-23'],
    ['last_week', '2026-11-09', '2026-11-16'],
    ['this_month', '2026-11-01', '2026-12-01'],
    ['last_month', '2026-10-01', '2026-11-01'],
    ['this_quarter', '2026-10-01', '2027-01-01'],
    ['this_year', '2026-01-01', '2027-01-01'],
    ['last_12_months', '2025-12-01', '2026-12-01'],
]);

test('the week presets follow the club\'s own first day of the week', function () {
    MembershipSetting::current()->update(['week_starts_on' => 0]);

    expect(periodBounds(AnalyticsPeriod::forPreset('this_week')))->toBe(['2026-11-15 00:00:00', '2026-11-22 00:00:00'])
        ->and(periodBounds(AnalyticsPeriod::forPreset('last_week')))->toBe(['2026-11-08 00:00:00', '2026-11-15 00:00:00']);
});

test('filter state it cannot use falls back to this week', function (?array $filters) {
    $period = AnalyticsPeriod::fromFilters($filters);

    expect($period->preset)->toBe('this_week')
        ->and(periodBounds($period))->toBe(['2026-11-16 00:00:00', '2026-11-23 00:00:00'])
        ->and($period->label())->toBe('This week');
})->with([
    'nothing' => [null],
    'an unknown preset' => [['preset' => 'next_decade']],
    'a preset that is not text' => [['preset' => ['this_month']]],
    'custom with no dates yet' => [['preset' => 'custom', 'from' => null, 'until' => null]],
    'custom with one date' => [['preset' => 'custom', 'from' => '2026-09-01', 'until' => null]],
    'custom with dates reversed' => [['preset' => 'custom', 'from' => '2026-09-05', 'until' => '2026-09-01']],
    'custom with a date that is not one' => [['preset' => 'custom', 'from' => 'not a date', 'until' => '2026-09-01']],
]);

test('a custom range includes both of its days', function () {
    $period = AnalyticsPeriod::fromFilters(['preset' => 'custom', 'from' => '2026-09-01', 'until' => '2026-09-05']);

    expect($period->preset)->toBe('custom')
        ->and(periodBounds($period))->toBe(['2026-09-01 00:00:00', '2026-09-06 00:00:00'])
        ->and($period->label())->toBe('Sep 1, 2026 – Sep 5, 2026');
});

test('a period of a week or less charts by week, a longer one by month', function () {
    expect(AnalyticsPeriod::forPreset('this_week')->grain())->toBe('week')
        ->and(AnalyticsPeriod::fromFilters(['preset' => 'custom', 'from' => '2026-09-01', 'until' => '2026-09-03'])->grain())->toBe('week')
        ->and(AnalyticsPeriod::forPreset('this_month')->grain())->toBe('month')
        ->and(AnalyticsPeriod::forPreset('this_year')->grain())->toBe('month');
});

test('a week period charts the twelve weeks ending with it, back to back', function () {
    $buckets = AnalyticsPeriod::forPreset('this_week')->buckets();

    expect($buckets)->toHaveCount(12)
        ->and(periodBounds($buckets[11]))->toBe(['2026-11-16 00:00:00', '2026-11-23 00:00:00'])
        ->and(periodBounds($buckets[0]))->toBe(['2026-08-31 00:00:00', '2026-09-07 00:00:00'])
        ->and($buckets[11]->label())->toBe('Nov 16');

    foreach (range(1, 11) as $index) {
        expect($buckets[$index]->from->equalTo($buckets[$index - 1]->untilExclusive))->toBeTrue();
    }
});

test('a longer period charts the twelve months ending with its last month', function () {
    $buckets = AnalyticsPeriod::forPreset('this_quarter')->buckets();

    expect($buckets)->toHaveCount(12)
        ->and(periodBounds($buckets[11]))->toBe(['2026-12-01 00:00:00', '2027-01-01 00:00:00'])
        ->and(periodBounds($buckets[0]))->toBe(['2026-01-01 00:00:00', '2026-02-01 00:00:00'])
        ->and($buckets[11]->label())->toBe('Dec 2026');
});

test('a row on the boundary between two periods is counted in exactly one of them', function () {
    $event = Event::factory()->create(['event_date' => '2026-11-16']);
    // The first instant of this week, and the last second of last week.
    Attendance::factory()->for($event)->create(['checked_in_at' => '2026-11-16 00:00:00']);
    Attendance::factory()->for($event)->create(['checked_in_at' => '2026-11-15 23:59:59']);

    $count = fn (string $preset): int => AnalyticsPeriod::forPreset($preset)->constrain(Attendance::query(), 'checked_in_at')->count();

    expect($count('this_week'))->toBe(1)
        ->and($count('last_week'))->toBe(1);
});

test('a date-only column is matched by day at both ends of the period', function () {
    Event::factory()->create(['event_date' => '2026-10-31']);
    Event::factory()->create(['event_date' => '2026-11-01']);
    Event::factory()->create(['event_date' => '2026-11-30']);
    Event::factory()->create(['event_date' => '2026-12-01']);

    $dates = AnalyticsPeriod::forPreset('this_month')->constrainDate(Event::query(), 'event_date')
        ->orderBy('event_date')
        ->get()
        ->map(fn (Event $event): string => $event->event_date->toDateString())
        ->all();

    expect($dates)->toBe(['2026-11-01', '2026-11-30']);
});
