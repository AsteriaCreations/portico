<?php

namespace App\Models;

use Database\Factories\AttendanceBehaviorNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['attendance_id', 'note', 'created_by'])]
class AttendanceBehaviorNote extends Model
{
    /** @use HasFactory<AttendanceBehaviorNoteFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Set once the note has been taken back (BehaviorNoteRetractionService).
     * The note itself is kept: it drops off Active Patrons but stays on the
     * Manager+ audit trails, marked retracted.
     */
    public function retraction(): HasOne
    {
        return $this->hasOne(BehaviorNoteRetraction::class);
    }

    public function isRetracted(): bool
    {
        return $this->retraction()->exists();
    }

    /**
     * @param  Builder<AttendanceBehaviorNote>  $query
     */
    public function scopeNotRetracted(Builder $query): void
    {
        $query->whereDoesntHave('retraction');
    }
}
