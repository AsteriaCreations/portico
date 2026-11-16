<?php

namespace App\Support;

use App\Models\MembershipSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * The date range the Analytics page is showing, chosen once at the top of the
 * page and read by every widget on it. Always whole days and always
 * half-open (from inclusive, until exclusive): an inclusive upper bound has
 * already counted a boundary row twice on this page once.
 */
final class AnalyticsPeriod
{
    public const DEFAULT_PRESET = 'this_week';

    public const CUSTOM = 'custom';

    public const GRAIN_WEEK = 'week';

    public const GRAIN_MONTH = 'month';

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $untilExclusive,
        private readonly ?string $label = null,
    ) {}

    /**
     * @return array<string, string> preset key => translated label
     */
    public static function presets(): array
    {
        return [
            'this_week' => __('This week'),
            'last_week' => __('Last week'),
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'this_quarter' => __('This quarter'),
            'this_year' => __('This year'),
            'last_12_months' => __('Last 12 months'),
            self::CUSTOM => __('Custom dates'),
        ];
    }

    /**
     * From the Analytics page's filter state, which also arrives from the
     * URL: anything unrecognised, or a custom range that isn't complete yet,
     * falls back to the default preset rather than failing.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $preset = $filters['preset'] ?? null;

        if ($preset === self::CUSTOM) {
            $from = self::parseDay($filters['from'] ?? null);
            $until = self::parseDay($filters['until'] ?? null);

            return $from && $until && $from->lessThanOrEqualTo($until)
                ? self::between($from, $until)
                : self::forPreset(self::DEFAULT_PRESET);
        }

        return self::forPreset(is_string($preset) ? $preset : self::DEFAULT_PRESET);
    }

    public static function forPreset(string $preset): self
    {
        $today = CarbonImmutable::now()->startOfDay();
        $month = $today->startOfMonth();
        $week = CarbonImmutable::instance(MembershipSetting::startOfWeek($today))->startOfDay();

        [$from, $until] = match ($preset) {
            'last_week' => [$week->subWeek(), $week],
            'this_month' => [$month, $month->addMonth()],
            'last_month' => [$month->subMonth(), $month],
            'this_quarter' => [$today->startOfQuarter(), $today->startOfQuarter()->addMonths(3)],
            'this_year' => [$today->startOfYear(), $today->startOfYear()->addYear()],
            'last_12_months' => [$month->subMonths(11), $month->addMonth()],
            default => [$week, $week->addWeek()],
        };

        return new self(array_key_exists($preset, self::presets()) && $preset !== self::CUSTOM ? $preset : self::DEFAULT_PRESET, $from, $until);
    }

    /**
     * A custom range; both days are included.
     */
    public static function between(CarbonInterface $from, CarbonInterface $until): self
    {
        return new self(
            self::CUSTOM,
            CarbonImmutable::instance($from)->startOfDay(),
            CarbonImmutable::instance($until)->startOfDay()->addDay(),
        );
    }

    public function label(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        if ($this->preset !== self::CUSTOM) {
            return self::presets()[$this->preset];
        }

        return $this->from->isoFormat('ll').' – '.$this->untilExclusive->subDay()->isoFormat('ll');
    }

    /**
     * The step a trend chart uses for this period: weeks for a period of a
     * week or less, months for anything longer.
     */
    public function grain(): string
    {
        return $this->from->diffInDays($this->untilExclusive) <= 7 ? self::GRAIN_WEEK : self::GRAIN_MONTH;
    }

    /**
     * The trailing run of weeks or months a trend chart draws, oldest first,
     * ending with the one this period's last day falls in. A chart of only
     * the period itself would often be a single bar.
     *
     * @return list<self>
     */
    public function buckets(int $count = 12): array
    {
        $lastDay = $this->untilExclusive->subDay();
        $buckets = [];

        if ($this->grain() === self::GRAIN_WEEK) {
            $end = CarbonImmutable::instance(MembershipSetting::startOfWeek($lastDay))->startOfDay();

            for ($back = $count - 1; $back >= 0; $back--) {
                $from = $end->subWeeks($back);
                $buckets[] = new self(self::CUSTOM, $from, $from->addWeek(), $from->translatedFormat('M j'));
            }

            return $buckets;
        }

        $end = $lastDay->startOfMonth();

        for ($back = $count - 1; $back >= 0; $back--) {
            $from = $end->subMonths($back);
            $buckets[] = new self(self::CUSTOM, $from, $from->addMonth(), $from->translatedFormat('M Y'));
        }

        return $buckets;
    }

    /**
     * Limits $query to rows whose timestamp $column falls in this period.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(Builder $query, string $column): Builder
    {
        return $query->where($column, '>=', $this->from)->where($column, '<', $this->untilExclusive);
    }

    /**
     * The same for a date-only column (events.event_date, subscriptions.paid_on),
     * compared by day so it doesn't matter whether the driver stores a time part.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrainDate(Builder $query, string $column): Builder
    {
        return $query
            ->whereDate($column, '>=', $this->from->toDateString())
            ->whereDate($column, '<', $this->untilExclusive->toDateString());
    }

    private static function parseDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
