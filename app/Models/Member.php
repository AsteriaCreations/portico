<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'member_number', 'username', 'preferred_name', 'first_name', 'last_name', 'email', 'email_opt_in',
    'category_id', 'sponsor_id', 'date_vetted', 'dob', 'is_active', 'subscription_eligible',
    'on_watchlist', 'watchlist_reason', 'is_banned', 'ban_reason', 'banned_until', 'probation_override_start',
    'missing_paperwork', 'is_deceased', 'hospitality_note', 'notes',
])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_vetted' => 'date',
            'dob' => 'date',
            'is_active' => 'boolean',
            'email_opt_in' => 'boolean',
            'subscription_eligible' => 'boolean',
            'on_watchlist' => 'boolean',
            'is_banned' => 'boolean',
            'banned_until' => 'date',
            'probation_override_start' => 'date',
            'missing_paperwork' => 'boolean',
            'is_deceased' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sponsor_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function sponsoredGuests(): HasMany
    {
        return $this->hasMany(Member::class, 'sponsor_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function addOnDayPasses(): HasMany
    {
        return $this->hasMany(AddOnDayPass::class);
    }

    public function banExceptions(): HasMany
    {
        return $this->hasMany(BanException::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(MemberStatusChange::class);
    }

    public function usernameChanges(): HasMany
    {
        return $this->hasMany(MemberUsernameChange::class);
    }

    /**
     * Append-only log of every paperwork/waiver signing -- see
     * hasValidPaperwork() for the read side and PaperworkType.
     */
    public function paperwork(): HasMany
    {
        return $this->hasMany(MemberPaperwork::class);
    }

    /**
     * Assigned via MemberForm's skills field, gated Admin+ by the
     * assign-member-skills gate (stronger than MemberResource's own
     * Manager+ floor) -- see App\Models\Skill.
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    /**
     * Reachable indefinitely, across every visit -- unlike Attendance's own
     * visit_note, which nothing ever surfaces once the visit is over. See
     * ActivePatrons::addBehaviorNoteAction() (the only write path) and
     * AttendanceBehaviorNotePolicy (Manager+ read, no write path at all).
     */
    public function behaviorNotes(): HasManyThrough
    {
        return $this->hasManyThrough(AttendanceBehaviorNote::class, Attendance::class);
    }

    /**
     * Available account credit, always summed live from the ledger — never
     * stored, so it can't drift the way the old spreadsheet balance did.
     */
    public function voucherBalance(): float
    {
        return (float) $this->vouchers()->sum('amount');
    }

    public function isProspective(): bool
    {
        return $this->category->name === 'Prospective';
    }

    public function hasActiveSubscriptionFor(AddOn $addOn, CarbonInterface $coveredMonth): bool
    {
        return $this->subscriptions()
            ->where('add_on_id', $addOn->id)
            ->whereDate('covered_month', $coveredMonth->toDateString())
            ->exists();
    }

    /**
     * A one-time purchase covering a subscribable add-on for exactly this
     * event -- distinct from a subscription, which covers a whole calendar
     * month. See docs/BLUEPRINT.md "Fee pipeline".
     */
    public function hasDayPassFor(AddOn $addOn, Event $event): bool
    {
        return $this->addOnDayPasses()->where('event_id', $event->id)->where('add_on_id', $addOn->id)->exists();
    }

    /**
     * Eligible once attended events (all-time) reach the configured threshold,
     * or a manager has manually flagged the member — the latter exists to
     * grandfather in members whose pre-system attendance history isn't in the
     * attendance table.
     */
    public function isSubscriptionEligible(): bool
    {
        return $this->subscription_eligible
            || $this->attendance()->whereNotNull('checked_in_at')->count() >= MembershipSetting::current()->subscription_eligibility_threshold;
    }

    public function hasBanExceptionFor(Event $event): bool
    {
        return $this->banExceptions()->where('event_id', $event->id)->exists();
    }

    /**
     * True when the member has signed this paperwork type and, if the type
     * renews (renewal_months set), the latest signing is still within that
     * window. Same "derived, never stored" shape as isSubscriptionEligible().
     */
    public function hasValidPaperwork(PaperworkType $type): bool
    {
        $latest = $this->paperwork()
            ->where('paperwork_type_id', $type->id)
            ->max('signed_on');

        if (! $latest) {
            return false;
        }

        if ($type->renewal_months === null) {
            return true;
        }

        return Carbon::parse($latest)
            ->addMonths($type->renewal_months)
            ->isFuture();
    }

    /**
     * Whether the member may use a given add-on -- false only when an active
     * PaperworkType gates that add-on (gates_add_on_id) and the member lacks
     * valid paperwork for it (e.g. an expired Pool Waiver blocks Pool).
     * PricingService drops a gated add-on's line for a member this returns
     * false for; CheckIn blocks a day-pass purchase.
     */
    public function canUseAddOn(AddOn $addOn): bool
    {
        return PaperworkType::query()
            ->where('active', true)
            ->where('gates_add_on_id', $addOn->id)
            ->get()
            ->every(fn (PaperworkType $type) => $this->hasValidPaperwork($type));
    }

    /**
     * is_banned alone means "was placed in a banned state" -- the historical
     * record, never auto-flipped back (no cron: an automated change would
     * need a changed_by on the audit row with no authenticated user to
     * attribute it to). This is what every actual admission/eligibility
     * decision should read instead: banned_until null means a permanent
     * ban (unchanged from before this existed); a past date means the
     * suspension has run its course. Same "derived, never stored" shape as
     * isOnProbation().
     */
    public function isCurrentlyBanned(): bool
    {
        return $this->is_banned && (! $this->banned_until || ! $this->banned_until->isPast());
    }

    /**
     * A one-time-only payment method (Venmo, PayPal — any row flagged
     * payment_methods.one_time_only) may be used once per member, ever,
     * across both entry and subscription payments. Computed from actual
     * payment history rather than a stored flag, the same "derived, never
     * stored" shape as subscription eligibility. Using any one of them
     * locks out all of them (house rule: one electronic payment per
     * member, not one per brand).
     */
    public function hasUsedOneTimeMethod(): bool
    {
        $codes = PaymentMethod::oneTimeCodes();

        return $this->attendance()->whereIn('payment_method', $codes)->exists()
            || $this->subscriptions()->whereIn('payment_method', $codes)->exists();
    }

    /**
     * Appends a dated marker to hospitality_note when a member's one-time
     * payment method is spent, so staff glancing at the member record see
     * it without digging through payment history. Prior content is kept,
     * not overwritten — the column is a short VARCHAR, so it's trimmed from
     * the front (oldest first) to fit rather than dropping the new note.
     */
    public function recordOneTimeMethodUsage(string $label): void
    {
        $note = "{$label} used ".now()->toDateString();
        $combined = $this->hospitality_note ? "{$this->hospitality_note}; {$note}" : $note;

        $this->update(['hospitality_note' => mb_substr($combined, -120)]);
    }

    /**
     * Reporting-only — never affects admission or pricing (see
     * AdmissionPolicy). Computed from date_vetted, same "derived, never
     * stored" pattern as subscription eligibility and the under-21 flag,
     * rather than a manually-toggled boolean. probation_override_start lets
     * a manager set when the clock actually started for an edge case
     * date_vetted doesn't cover — it replaces date_vetted as the basis, it
     * isn't an on/off flag like subscription_eligible's manual override.
     */
    public function isOnProbation(): bool
    {
        $start = $this->probation_override_start ?? $this->date_vetted;

        if ($start === null) {
            return false;
        }

        return now()->lt($start->clone()->addDays(MembershipSetting::current()->probation_period_days));
    }

    /**
     * Next available member_number, for MemberObserver to assign on create.
     * A plain MAX+1 rather than a counter table — a collision under
     * concurrent creates surfaces as a unique-constraint violation on
     * insert, which callers that need to be race-safe (e.g.
     * CheckIn::createGuest()) already retry the same way they retry a
     * username collision.
     */
    public static function nextMemberNumber(): int
    {
        return (int) (static::max('member_number') ?? 0) + 1;
    }
}
