<?php

namespace App\Models;

use App\Enums\AddOnKind;
use Carbon\CarbonInterface;
use Database\Factories\AddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'kind', 'priced_per_event', 'price', 'subscribable', 'max_per_night', 'is_overnight', 'description', 'sort_order', 'active'])]
class AddOn extends Model
{
    /** @use HasFactory<AddOnFactory> */
    use HasFactory;

    // The one seeded 'entry' row's name — protected from rename/delete the
    // same way Category::PROTECTED_NAMES protects Prospective/Guest/
    // Irregular, since every Regular subscription is keyed off it.
    public const string ENTRY_NAME = 'Entry';

    // The one seeded per-event-priced, subscribable row. Named directly
    // (same technique as ENTRY_NAME) only where code needs to know "is this
    // specifically Pool" — the pool_enabled feature flag, which pre-dates
    // and is orthogonal to the generic subscribable/priced_per_event
    // mechanism every other add-on now shares.
    public const string POOL_NAME = 'Pool';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => AddOnKind::class,
            'priced_per_event' => 'boolean',
            'price' => 'decimal:2',
            'subscribable' => 'boolean',
            'max_per_night' => 'integer',
            'is_overnight' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function attendanceAddOns(): HasMany
    {
        return $this->hasMany(AttendanceAddOn::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function dayPasses(): HasMany
    {
        return $this->hasMany(AddOnDayPass::class);
    }

    /**
     * The one protected row every Regular subscription targets.
     * Deliberately no static caching -- see MembershipSetting::current()'s
     * own comment on why: a static would leak a stale instance across Pest
     * tests within the same process (RefreshDatabase resets the database,
     * not PHP statics) and, in a long-running PHP-FPM worker, across
     * requests after a reseed.
     */
    public static function entry(): self
    {
        return static::where('kind', AddOnKind::Entry)->firstOrFail();
    }

    public static function pool(): ?self
    {
        return static::where('name', self::POOL_NAME)->first();
    }

    /**
     * Every add-on currently participating in PricingService's coverage
     * engine -- subscribable and active. Deliberately NOT filtered by
     * pool_enabled: that flag only stops *new* Pool commitments (see
     * isCurrentlyPurchasable()) -- an event that already has a nonzero
     * pool_fee still prices and charges for it, and an existing Pool
     * subscription/day-pass still covers it, exactly as before Pool was a
     * real add_ons row. Callers never need an extra
     * ->where('active', true) of their own.
     *
     * @return Builder<self>
     */
    public function scopeSubscribable(Builder $query): Builder
    {
        return $query->where('subscribable', true)->where('active', true);
    }

    /**
     * Whether a NEW subscription or day pass can be bought for this add-on
     * right now -- distinct from scopeSubscribable() above, which governs
     * pricing/coverage of what's already committed. Only Pool has a flag
     * gating this today (pool_enabled); every other subscribable add-on is
     * always purchasable once it exists.
     */
    public function isCurrentlyPurchasable(): bool
    {
        return $this->name !== self::POOL_NAME || MembershipSetting::current()->pool_enabled;
    }

    /**
     * What this add-on costs for a given event, or null if it doesn't apply
     * (e.g. Pool at an event with no pool). A priced_per_event add-on reads
     * event.pool_fee directly rather than a generic per-event pivot — Pool
     * is the only one that exists, and building a whole second table for a
     * hypothetical second per-event-priced add-on isn't warranted yet; a
     * club that wants one would need that mechanism built then. Never
     * called for the 'entry' row — its fee always comes from
     * event.entry_fee via PricingService's own entry branch, not here.
     */
    public function priceFor(Event $event): ?float
    {
        if ($this->priced_per_event) {
            $fee = (float) $event->pool_fee;

            return $fee > 0 ? $fee : null;
        }

        return $this->price !== null ? (float) $this->price : null;
    }

    /**
     * Building-wide across every concurrent event that night, not per event
     * -- a physical resource like the one rentable room is shared venue-wide,
     * the same scope CapacityService::occupancy() uses for the building
     * itself. A prepay counts immediately, same as occupancy, since the sale
     * is already committed regardless of whether the attendee has arrived.
     */
    public function unitsSoldOn(CarbonInterface $date): int
    {
        return $this->attendanceAddOns()
            ->whereHas('attendance.event', fn ($query) => $query->whereDate('event_date', $date))
            ->count();
    }

    public function hasRoomOn(CarbonInterface $date): bool
    {
        return $this->max_per_night === null || $this->unitsSoldOn($date) < $this->max_per_night;
    }
}
