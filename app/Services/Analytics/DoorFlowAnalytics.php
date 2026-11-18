<?php

namespace App\Services\Analytics;

use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How the door ran, for the Analytics page: how full the building got, when
 * people arrived, how long they stayed, and how they were checked in.
 *
 * A night is an event date, every event on it together (the building is
 * shared, as in CapacityService), private rentals included. Times of day
 * only mean something for visits checked in through the app: an imported
 * visit (no checked_in_by) carries its event's date at midnight, so those
 * are left out of everything here but the channel mix, where they are
 * their own line.
 */
class DoorFlowAnalytics
{
    public const CHANNEL_DESK = 'desk';

    public const CHANNEL_KIOSK = 'kiosk';

    public const CHANNEL_STAFF = 'staff';

    public const CHANNEL_PREPAID = 'prepaid';

    public const CHANNEL_IMPORTED = 'imported';

    /** @var array<string, Collection<int, object>> */
    private array $visits = [];

    /**
     * Each night in the period with visits checked in through the app, oldest
     * first: arrivals, the most people inside at once and when.
     *
     * The peak replays the night: +1 at each arrival, −1 at each recorded
     * departure, and each anonymous "N people left" adjustment at the moment
     * it was recorded. Someone never marked as leaving counts as staying, so
     * on a night with few departures recorded the peak is the end-of-night
     * figure.
     *
     * @return list<array{date: string, arrivals: int, peak: int, peak_at: ?CarbonImmutable}>
     */
    public function nights(AnalyticsPeriod $period): array
    {
        $live = $this->visits($period)->filter(fn (object $visit): bool => $visit->checked_in_by !== null);

        if ($live->isEmpty()) {
            return [];
        }

        // A date range, not whereIn: a date column can be stored with a time part.
        $adjustments = DB::table('occupancy_adjustments')
            ->whereDate('for_date', '>=', $live->min('event_date'))
            ->whereDate('for_date', '<=', $live->max('event_date'))
            ->whereNotNull('created_at')
            ->get(['for_date', 'delta', 'created_at'])
            ->groupBy(fn (object $adjustment): string => substr((string) $adjustment->for_date, 0, 10));

        return $live
            ->groupBy('event_date')
            ->sortKeys()
            ->map(function (Collection $visits, string $date) use ($adjustments): array {
                $changes = [];

                foreach ($visits as $visit) {
                    $changes[] = [$visit->checked_in_at, 1];

                    if ($visit->departed_at !== null && $visit->departed_at >= $visit->checked_in_at) {
                        $changes[] = [$visit->departed_at, -1];
                    }
                }

                foreach ($adjustments[$date] ?? [] as $adjustment) {
                    $changes[] = [CarbonImmutable::parse($adjustment->created_at)->toDateTimeString(), (int) $adjustment->delta];
                }

                // By time; at the same moment, people leaving before people arriving.
                usort($changes, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

                $inside = 0;
                $peak = 0;
                $peakAt = null;

                foreach ($changes as [$at, $delta]) {
                    $inside = max(0, $inside + $delta);

                    if ($inside > $peak) {
                        $peak = $inside;
                        $peakAt = $at;
                    }
                }

                return [
                    'date' => $date,
                    'arrivals' => $visits->count(),
                    'peak' => $peak,
                    'peak_at' => $peakAt === null ? null : CarbonImmutable::parse($peakAt),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * nights() as night => peak, for the event comparison.
     *
     * @return array<string, int>
     */
    public function peaks(AnalyticsPeriod $period): array
    {
        return array_column($this->nights($period), 'peak', 'date');
    }

    /**
     * The nights' peaks against the venue capacity set today (null when no
     * capacity is set; capacity history isn't kept).
     *
     * @return array{nights: int, busiest: ?array{date: string, arrivals: int, peak: int, peak_at: ?CarbonImmutable}, average_peak: ?int, capacity: ?int, at_capacity: int, near_capacity: int}
     */
    public function occupancySummary(AnalyticsPeriod $period): array
    {
        $nights = collect($this->nights($period));
        $capacity = MembershipSetting::current()->venue_capacity;

        return [
            'nights' => $nights->count(),
            'busiest' => $nights->sortByDesc('peak')->first(),
            'average_peak' => $nights->isEmpty() ? null : (int) round($nights->avg('peak')),
            'capacity' => $capacity,
            'at_capacity' => $capacity === null ? 0 : $nights->filter(fn (array $night): bool => $night['peak'] >= $capacity)->count(),
            'near_capacity' => $capacity === null ? 0 : $nights->filter(fn (array $night): bool => $night['peak'] >= $capacity * 0.9)->count(),
        ];
    }

    /**
     * Arrivals by hour of the night, averaged over the period's nights, from
     * the first hour anyone arrived to the last; the night runs from noon
     * to noon, so an after-midnight hour follows the evening.
     *
     * @return list<array{hour: int, label: string, average: float}>
     */
    public function arrivalsByHour(AnalyticsPeriod $period): array
    {
        $live = $this->visits($period)->filter(fn (object $visit): bool => $visit->checked_in_by !== null);
        $nights = $live->pluck('event_date')->unique()->count();

        if ($nights === 0) {
            return [];
        }

        // Noon is step 0, so 11pm is 11 and 1am is 13.
        $byStep = $live->countBy(fn (object $visit): int => ((int) substr($visit->checked_in_at, 11, 2) + 12) % 24);
        $hours = [];

        foreach (range($byStep->keys()->min(), $byStep->keys()->max()) as $step) {
            $hour = ($step + 12) % 24;
            $hours[] = [
                'hour' => $hour,
                'label' => CarbonImmutable::today()->setTime($hour, 0)->isoFormat('LT'),
                'average' => round(($byStep[$step] ?? 0) / $nights, 1),
            ];
        }

        return $hours;
    }

    /**
     * How long people stayed, from the visits with a recorded departure.
     * sample is how many of the period's arrivals that is, so a figure from
     * a handful of departures reads for what it is.
     *
     * @return array{arrivals: int, sample: int, median_minutes: ?int, average_minutes: ?int, under_hour: int, one_to_three: int, over_three: int}
     */
    public function lengthOfStay(AnalyticsPeriod $period): array
    {
        $live = $this->visits($period)->filter(fn (object $visit): bool => $visit->checked_in_by !== null);

        $minutes = $live
            ->filter(fn (object $visit): bool => $visit->departed_at !== null && $visit->departed_at >= $visit->checked_in_at)
            ->map(fn (object $visit): int => intdiv(strtotime($visit->departed_at) - strtotime($visit->checked_in_at), 60))
            ->sort()
            ->values();

        $count = $minutes->count();

        return [
            'arrivals' => $live->count(),
            'sample' => $count,
            'median_minutes' => $count === 0 ? null : (int) round($count % 2
                ? $minutes[intdiv($count, 2)]
                : ($minutes[$count / 2 - 1] + $minutes[$count / 2]) / 2),
            'average_minutes' => $count === 0 ? null : (int) round($minutes->avg()),
            'under_hour' => $minutes->filter(fn (int $stay): bool => $stay < 60)->count(),
            'one_to_three' => $minutes->filter(fn (int $stay): bool => $stay >= 60 && $stay < 180)->count(),
            'over_three' => $minutes->filter(fn (int $stay): bool => $stay >= 180)->count(),
        ];
    }

    /**
     * How the period's arrivals were checked in, largest first. Every visit
     * falls in one channel, tried in this order:
     *  - imported: brought in from earlier records (no checked_in_by)
     *  - kiosk: scanned in at the self check-in tablet
     *  - prepaid: the visit was booked on an earlier day and marked arrived
     *  - staff: a staff member checked in as themselves (signing in, or at the desk)
     *  - desk: everything else, checked in by staff at the desk
     *
     * @return array<string, int> channel => arrivals, only channels with any
     */
    public function channels(AnalyticsPeriod $period): array
    {
        return $this->visits($period)
            ->countBy(fn (object $visit): string => match (true) {
                $visit->checked_in_by === null => self::CHANNEL_IMPORTED,
                $visit->payment_method === PaymentMethod::KIOSK => self::CHANNEL_KIOSK,
                $visit->created_at !== null && substr($visit->created_at, 0, 10) < substr($visit->checked_in_at, 0, 10) => self::CHANNEL_PREPAID,
                $visit->staff_member_id !== null && (int) $visit->staff_member_id === (int) $visit->member_id => self::CHANNEL_STAFF,
                default => self::CHANNEL_DESK,
            })
            ->sortDesc()
            ->all();
    }

    /**
     * The period's arrived visits on events held so far, each with its
     * event's date and, where staff checked it in, that staff login's own
     * member record. Loaded once per period.
     *
     * @return Collection<int, object{member_id: int, checked_in_by: ?int, checked_in_at: string, departed_at: ?string, created_at: ?string, payment_method: ?string, event_date: string, staff_member_id: ?int}>
     */
    private function visits(AnalyticsPeriod $period): Collection
    {
        $key = $period->from->toDateString().'|'.$period->untilExclusive->toDateString();

        return $this->visits[$key] ??= $period->constrainDate(DB::table('attendance'), 'events.event_date')
            ->join('events', 'events.id', '=', 'attendance.event_id')
            ->leftJoin('users', 'users.id', '=', 'attendance.checked_in_by')
            ->whereNotNull('attendance.checked_in_at')
            ->whereDate('events.event_date', '<=', today())
            ->get([
                'attendance.member_id',
                'attendance.checked_in_by',
                'attendance.checked_in_at',
                'attendance.departed_at',
                'attendance.created_at',
                'attendance.payment_method',
                'events.event_date',
                'users.member_id as staff_member_id',
            ])
            ->map(function (object $visit): object {
                // Same shape on SQLite and MariaDB: plain "Y-m-d H:i:s" strings.
                // Cut, not parsed: a year of visits is thousands of rows, and
                // a Carbon parse of each timestamp took seconds.
                $visit->event_date = substr((string) $visit->event_date, 0, 10);
                $visit->checked_in_at = self::dateTime($visit->checked_in_at);
                $visit->departed_at = self::dateTime($visit->departed_at);
                $visit->created_at = self::dateTime($visit->created_at);

                return $visit;
            });
    }

    /**
     * A stored timestamp as "Y-m-d H:i:s", whichever database wrote it.
     *
     * @return ($value is null ? null : string)
     */
    private static function dateTime(?string $value): ?string
    {
        return $value === null ? null : substr(str_replace('T', ' ', $value), 0, 19);
    }
}
