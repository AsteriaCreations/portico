<?php

namespace App\Models;

use App\Enums\EntryCoverageSource;
use Carbon\Carbon;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'member_id', 'event_id', 'checked_in_by', 'checked_in_at', 'departed_at',
    'entry_fee', 'entry_coverage', 'entry_covered_by', 'comp_reason_id',
    'voucher_coverage',
    'amount_paid', 'payment_method', 'register_shift_id', 'prepaid_ahead', 'on_behalf_note', 'notes', 'visit_note',
])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $table = 'attendance';

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'departed_at' => 'datetime',
            'entry_fee' => 'decimal:2',
            'entry_coverage' => 'decimal:2',
            'entry_covered_by' => EntryCoverageSource::class,
            'voucher_coverage' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'prepaid_ahead' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function compReason(): BelongsTo
    {
        return $this->belongsTo(CompReason::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function addOns(): HasMany
    {
        return $this->hasMany(AttendanceAddOn::class);
    }

    public function behaviorNotes(): HasMany
    {
        return $this->hasMany(AttendanceBehaviorNote::class);
    }

    /**
     * Append-only -- see App\Services\EntryCorrectionService.
     */
    public function paymentCorrections(): HasMany
    {
        return $this->hasMany(PaymentCorrection::class);
    }

    /**
     * Per-visit, not per-member -- a guest can be overnight on one attendance
     * row and not another. Driven by the club's existing Private room
     * rental/Sleepover add-ons rather than a separate flag; see
     * docs/BLUEPRINT.md "Still open" (overnight guest handling).
     */
    public function isOvernightStay(): bool
    {
        if ($this->relationLoaded('addOns')) {
            return $this->addOns->contains('is_overnight', true);
        }

        return $this->addOns()->where('is_overnight', true)->exists();
    }

    /**
     * Adds first_arrived_at: the member's earliest arrival on any visit, so
     * isNewMemberVisit() can be read for a whole list without a query per
     * row.
     *
     * @param  Builder<Attendance>  $query
     */
    #[Scope]
    protected function withFirstArrival(Builder $query): void
    {
        $query->addSelect([
            'first_arrived_at' => static::query()
                ->from('attendance', 'earliest')
                ->selectRaw('min(earliest.checked_in_at)')
                ->whereColumn('earliest.member_id', 'attendance.member_id')
                ->whereNotNull('earliest.checked_in_at'),
        ]);
    }

    /**
     * A Prospective promoted to Irregular at the desk on this visit: an
     * Irregular member arriving for the first time. Derived, since the
     * promotion itself isn't logged -- a Prospective can't check in until
     * the desk records their details, so their first arrival is the night
     * they were promoted.
     */
    public function isNewMemberVisit(): bool
    {
        if ($this->checked_in_at === null || $this->member?->category?->name !== 'Irregular') {
            return false;
        }

        $firstArrival = array_key_exists('first_arrived_at', $this->attributes)
            ? $this->attributes['first_arrived_at']
            : static::query()->where('member_id', $this->member_id)->whereNotNull('checked_in_at')->min('checked_in_at');

        return $firstArrival !== null && $this->checked_in_at->equalTo(Carbon::parse($firstArrival));
    }

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }
}
