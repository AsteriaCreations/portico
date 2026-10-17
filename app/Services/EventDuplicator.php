<?php

namespace App\Services;

use App\Enums\EventRepeat;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Backs EventsTable's Duplicate action: copies an event onto one date or a
 * repeating run of dates in one go. Every copy keeps the first copy's times,
 * so an event that runs past midnight still ends the next day. A date that
 * already has the same event (same name and type) is skipped rather than
 * doubled, so re-running the same repeat is harmless.
 */
class EventDuplicator
{
    public const MAX_OCCURRENCES = 26;

    /**
     * The dates a repeat produces, starting with $first itself.
     *
     * Monthly keeps the first date's place in its month (2nd Friday stays
     * 2nd Friday); a 5th weekday the month doesn't have falls back to that
     * month's last one.
     *
     * @return list<CarbonImmutable>
     */
    public function datesFor(CarbonImmutable $first, EventRepeat $repeat, int $count): array
    {
        $first = $first->startOfDay();

        if ($repeat === EventRepeat::Once) {
            return [$first];
        }

        $nthWeekday = intdiv($first->day - 1, 7) + 1;
        $dates = [];

        for ($i = 0; $i < min(max(1, $count), self::MAX_OCCURRENCES); $i++) {
            $dates[] = match ($repeat) {
                EventRepeat::Weekly => $first->addWeeks($i),
                EventRepeat::Biweekly => $first->addWeeks($i * 2),
                EventRepeat::MonthlySameWeekday => $this->nthWeekdayOf(
                    $first->startOfMonth()->addMonthsNoOverflow($i),
                    $nthWeekday,
                    $first->dayOfWeek,
                ),
            };
        }

        return $dates;
    }

    /**
     * Creates the copies. $data is the Duplicate form's state: its
     * event_date/starts_at/ends_at/comp_list_due_at describe the first copy,
     * and each later copy is moved by whole days from it.
     *
     * @param  array<string, mixed>  $data
     * @return array{created: int, skipped: list<string>} skipped = the dates (Y-m-d) that already had this event
     */
    public function duplicate(Event $source, array $data, EventRepeat $repeat, int $count): array
    {
        $first = CarbonImmutable::parse($data['event_date'])->startOfDay();
        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($source, $data, $repeat, $count, $first, &$created, &$skipped): void {
            $addOnIds = $source->addOns()->pluck('add_ons.id');

            foreach ($this->datesFor($first, $repeat, $count) as $date) {
                $exists = Event::whereDate('event_date', $date)
                    ->where('name', $data['name'] ?? null)
                    ->where('event_type_id', $data['event_type_id'] ?? null)
                    ->exists();

                if ($exists) {
                    $skipped[] = $date->toDateString();

                    continue;
                }

                $days = (int) $first->diffInDays($date);

                $event = Event::create([
                    ...$data,
                    'event_date' => $date->toDateString(),
                    'starts_at' => static::shift($data['starts_at'] ?? null, $days),
                    'ends_at' => static::shift($data['ends_at'] ?? null, $days),
                    'checkin_closes_at' => static::shift($data['checkin_closes_at'] ?? null, $days),
                    'comp_list_due_at' => static::shift($data['comp_list_due_at'] ?? null, $days),
                ]);
                $event->addOns()->sync($addOnIds);

                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function nthWeekdayOf(CarbonImmutable $month, int $nth, int $dayOfWeek): CarbonImmutable
    {
        return $month->nthOfMonth($nth, $dayOfWeek) ?: $month->lastOfMonth($dayOfWeek);
    }

    private static function shift(mixed $value, int $days): ?string
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->addDays($days)->toDateTimeString();
    }
}
