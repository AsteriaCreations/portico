<?php

use App\Enums\EventRepeat;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Services\EventDuplicator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function repeatDates(string $first, EventRepeat $repeat, int $count): array
{
    return array_map(
        fn (CarbonImmutable $date): string => $date->toDateString(),
        app(EventDuplicator::class)->datesFor(CarbonImmutable::parse($first), $repeat, $count),
    );
}

/**
 * @return array<string, mixed>
 */
function duplicateFormData(EventType $type, ?string $name, User $user): array
{
    return [
        'event_date' => '2026-10-02',
        'starts_at' => '2026-10-02 20:00:00',
        'ends_at' => '2026-10-03 02:00:00',
        'comp_list_due_at' => '2026-09-30',
        'name' => $name,
        'event_type_id' => $type->id,
        'entry_fee' => 20,
        'pool_fee' => 0,
        'created_by' => $user->id,
    ];
}

test('just this date gives only the first date, whatever the count', function () {
    expect(repeatDates('2026-10-09', EventRepeat::Once, 5))->toBe(['2026-10-09']);
});

test('every 2 weeks steps 14 days', function () {
    expect(repeatDates('2026-10-09', EventRepeat::Biweekly, 3))->toBe(['2026-10-09', '2026-10-23', '2026-11-06']);
});

test('monthly keeps the same weekday position: the 2nd Friday stays the 2nd Friday', function () {
    expect(repeatDates('2026-10-09', EventRepeat::MonthlySameWeekday, 3))->toBe(['2026-10-09', '2026-11-13', '2026-12-11']);
});

test('monthly falls back to the last weekday when a month has no 5th one', function () {
    // Oct 30 2026 is the 5th Friday; November has only four (the last is Nov 27).
    expect(repeatDates('2026-10-30', EventRepeat::MonthlySameWeekday, 2))->toBe(['2026-10-30', '2026-11-27']);
});

test('the count is capped', function () {
    expect(repeatDates('2026-10-09', EventRepeat::Weekly, 500))->toHaveCount(EventDuplicator::MAX_OCCURRENCES);
});

test('a date that already has the same event is skipped, and re-running creates nothing new', function () {
    $user = User::factory()->create();
    $type = EventType::factory()->create();
    $source = Event::factory()->create(['name' => 'Friday Social', 'event_type_id' => $type->id, 'event_date' => '2026-09-25']);
    Event::factory()->create(['name' => 'Friday Social', 'event_type_id' => $type->id, 'event_date' => '2026-10-09']);
    // Same date, different event: doesn't block the copy.
    Event::factory()->create(['name' => 'Pool Party', 'event_type_id' => $type->id, 'event_date' => '2026-10-02']);
    $data = duplicateFormData($type, 'Friday Social', $user);

    $result = app(EventDuplicator::class)->duplicate($source, $data, EventRepeat::Weekly, 3);

    expect($result)->toBe(['created' => 2, 'skipped' => ['2026-10-09']])
        ->and(Event::whereDate('event_date', '2026-10-16')->first()->comp_list_due_at->toDateString())->toBe('2026-10-14');

    expect(app(EventDuplicator::class)->duplicate($source, $data, EventRepeat::Weekly, 3))
        ->toBe(['created' => 0, 'skipped' => ['2026-10-02', '2026-10-09', '2026-10-16']]);
});

test('unnamed events of the same type count as the same event', function () {
    $user = User::factory()->create();
    $type = EventType::factory()->create();
    $source = Event::factory()->create(['name' => null, 'event_type_id' => $type->id, 'event_date' => '2026-10-02']);

    $result = app(EventDuplicator::class)->duplicate($source, duplicateFormData($type, null, $user), EventRepeat::Weekly, 2);

    expect($result)->toBe(['created' => 1, 'skipped' => ['2026-10-02']]);
});
