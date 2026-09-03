<?php

namespace App\Models;

use App\Enums\EntryCoverageSource;
use App\Enums\PoolCoverageSource;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'member_id', 'event_id', 'checked_in_by', 'checked_in_at', 'departed_at',
    'entry_fee', 'entry_coverage', 'entry_covered_by', 'comp_reason_id',
    'pool_fee', 'pool_coverage', 'pool_covered_by',
    'voucher_coverage',
    'amount_paid', 'payment_method', 'register_shift_id', 'on_behalf_note', 'notes', 'visit_note',
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
            'pool_fee' => 'decimal:2',
            'pool_coverage' => 'decimal:2',
            'pool_covered_by' => PoolCoverageSource::class,
            'voucher_coverage' => 'decimal:2',
            'amount_paid' => 'decimal:2',
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

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }
}
