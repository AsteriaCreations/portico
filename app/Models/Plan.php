<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['add_on_id', 'duration_months', 'price', 'credit', 'effective_from', 'effective_to'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'price' => 'decimal:2',
            'credit' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    /**
     * The plan row effective on the given date for a given duration
     * (default 1 month) — historically accurate even if `plans` has since
     * changed, so a re-priced past event still matches what was actually
     * charged at the time. The per-visit entry credit always comes from the
     * duration-1 row, regardless of what duration a member actually
     * purchased under — coverage is checked per calendar month
     * (Member::hasActiveSubscriptionFor()), not per purchase.
     */
    public static function currentFor(AddOn $addOn, CarbonInterface $asOf, int $durationMonths = 1): ?self
    {
        return static::query()
            ->where('add_on_id', $addOn->id)
            ->where('duration_months', $durationMonths)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $asOf->toDateString()))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Every currently-effective plan row for an add-on, one per distinct
     * duration — the monthly rate plus any bulk-discount durations (e.g. a
     * 3-month bundle) — ordered shortest-first. Powers the check-in and
     * admin bulk-purchase duration pickers; adding a new bundle duration
     * later needs no code change, just a new `plans` row.
     *
     * @return Collection<int, self>
     */
    public static function currentOptionsFor(AddOn $addOn, CarbonInterface $asOf): Collection
    {
        return static::query()
            ->where('add_on_id', $addOn->id)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $asOf->toDateString()))
            ->orderByDesc('effective_from')
            ->get()
            ->unique('duration_months')
            ->sortBy('duration_months')
            ->values();
    }
}
