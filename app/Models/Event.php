<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            'archived_at' => 'datetime',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * How an event is named everywhere a person has to pick or recognise it
     * -- the check-in desk's picker, page titles and breadcrumbs. The name is
     * optional, so an unnamed event falls back to its type, and the date is
     * always there to tell repeat events apart.
     */
    public function label(): string
    {
        return $this->event_date->translatedFormat('M j, Y').' — '.($this->name ?? $this->eventType?->name ?? __('Untitled event'));
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

    public function addOnDayPasses(): HasMany
    {
        return $this->hasMany(AddOnDayPass::class);
    }

    public function banExceptions(): HasMany
    {
        return $this->hasMany(BanException::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Anything that points at this event and would block (or be lost by) a
     * hard delete: attendance and prepays, comp requests, day passes, ban
     * exceptions. An event with none of these was created by mistake or never
     * used, and can simply be deleted; otherwise it can only be archived.
     */
    public function hasRecordedActivity(): bool
    {
        return $this->hasAnyAttendance()
            || ($this->comp_requests_exists ?? $this->compRequests()->exists())
            || ($this->add_on_day_passes_exists ?? $this->addOnDayPasses()->exists())
            || ($this->ban_exceptions_exists ?? $this->banExceptions()->exists());
    }

    /**
     * Uses a withExists('attendance') flag when the query loaded one (the
     * events table does, so its per-row action checks don't each query).
     */
    private function hasAnyAttendance(): bool
    {
        return (bool) ($this->attendance_exists ?? $this->attendance()->exists());
    }

    /**
     * A past event can always be archived. An upcoming one only while nobody
     * is on it, so no one's prepayment ends up on an event the desk can no
     * longer see.
     */
    public function canBeArchived(): bool
    {
        return ! $this->isArchived()
            && ($this->hasEnded() || ! $this->hasAnyAttendance());
    }

    /**
     * Columns set here rather than through #[Fillable], so a form can never
     * archive an event by posting archived_at.
     */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_at' => now(), 'archived_by' => $by->id])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null, 'archived_by' => null])->save();
    }

    /**
     * Flat, non-subscribable add-ons (Sleepover, Private room rental, …)
     * this event actually offers at check-in -- see AddOn::events().
     *
     * @return BelongsToMany<AddOn, $this>
     */
    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class);
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
     * pre-existing events) fall back to the event_date check alone. An
     * archived event is never current, whatever its dates.
     */
    public static function currentQuery(): Builder
    {
        $buffer = MembershipSetting::current()->event_window_buffer_minutes;
        $now = now();

        return static::query()->whereNull('archived_at')->where(fn ($query) => $query
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
     * reach (see ShowrunnerCompRequests::eventOptionsQuery()). Archived
     * events are excluded, as in currentQuery().
     */
    public static function currentOrFutureQuery(): Builder
    {
        return static::query()->whereNull('archived_at')->where(fn ($query) => $query
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
        if ($this->isArchived()) {
            return false;
        }

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
