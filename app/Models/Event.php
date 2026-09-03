<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_date', 'starts_at', 'ends_at', 'ended_notification_sent_at', 'comp_list_due_at', 'name', 'event_type_id', 'entry_fee', 'pool_fee', 'door_prepay_enabled', 'showrunner_id', 'host_id', 'notes', 'created_by'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_notification_sent_at' => 'datetime',
            'comp_list_due_at' => 'date',
            'entry_fee' => 'decimal:2',
            'pool_fee' => 'decimal:2',
            'door_prepay_enabled' => 'boolean',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function showrunner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'showrunner_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'host_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function compRequests(): HasMany
    {
        return $this->hasMany(CompRequest::class);
    }

    public function poolDayPasses(): HasMany
    {
        return $this->hasMany(PoolDayPass::class);
    }

    /**
     * "Today" per event_date alone breaks for an event that spans midnight —
     * once past 12am its event_date no longer matches "today" even though
     * it's still running. Widened here with the actual starts_at/ends_at
     * window (plus a configurable buffer on each side,
     * MembershipSetting::current()->event_window_buffer_minutes, admin-
     * editable via the Membership Settings page) as an alternative match,
     * not a replacement — event_date keeps its existing "whole calendar day"
     * reach for events that don't cross midnight, this only adds coverage
     * for ones that do. Events with no starts_at/ends_at (nullable for
     * pre-existing events) fall back to the event_date check alone.
     */
    public static function currentQuery(): Builder
    {
        $buffer = MembershipSetting::current()->event_window_buffer_minutes;
        $now = now();

        return static::query()->where(fn ($query) => $query
            ->whereDate('event_date', today())
            ->orWhere(fn ($withinWindow) => $withinWindow
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->where('starts_at', '<=', $now->copy()->addMinutes($buffer))
                ->where('ends_at', '>=', $now->copy()->subMinutes($buffer))));
    }

    /**
     * "Not yet past" — today or later by event_date, OR (for an event that
     * spans midnight, whose event_date has already ticked into yesterday)
     * still running per ends_at. Deliberately no buffer window here, unlike
     * currentQuery() — that method answers "is this happening right now,
     * within a few minutes' grace" for the check-in desk; this one answers
     * the coarser "hasn't ended" for scoping what a Showrunner can still
     * reach (see ShowrunnerCompRequests::eventOptionsQuery()).
     */
    public static function currentOrFutureQuery(): Builder
    {
        return static::query()->where(fn ($query) => $query
            ->whereDate('event_date', '>=', today())
            ->orWhere(fn ($stillRunning) => $stillRunning
                ->whereNotNull('ends_at')
                ->where('ends_at', '>=', now())));
    }

    /**
     * Per-instance mirror of currentQuery()'s own today-or-window logic, for
     * deciding whether a single already-loaded Event is happening right now
     * (a live arrival) vs. only selectable because of door_prepay_enabled
     * (a prepayment for later) — see CheckIn::checkInAction().
     */
    public function isCurrentlyActive(): bool
    {
        if ($this->event_date->isToday()) {
            return true;
        }

        if (! $this->starts_at || ! $this->ends_at) {
            return false;
        }

        $buffer = MembershipSetting::current()->event_window_buffer_minutes;
        $now = now();

        return $this->starts_at->lte($now->copy()->addMinutes($buffer))
            && $this->ends_at->gte($now->copy()->subMinutes($buffer));
    }

    /**
     * Reporting-only, same as Member::isOnProbation() — nothing reads this to
     * block or gate the Comp List itself, it's purely a staff reminder.
     */
    public function isCompListOverdue(): bool
    {
        return $this->comp_list_due_at !== null && $this->comp_list_due_at->lt(today());
    }

    /**
     * An event with no ends_at set (nullable, for pre-existing events) is
     * never considered ended by this — matches NotifyEventEnded's own
     * whereNotNull('ends_at') eligibility filter, and gates whether
     * EventForm's read-only summary section has anything to show.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }
}
