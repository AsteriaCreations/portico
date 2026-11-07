<?php

namespace App\Models;

use Database\Factories\BehaviorNoteRetractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who took back a behavior note, and why. Written only by
 * App\Services\BehaviorNoteRetractionService; see BehaviorNoteRetractionPolicy.
 */
#[Fillable(['attendance_behavior_note_id', 'reason', 'retracted_by'])]
class BehaviorNoteRetraction extends Model
{
    /** @use HasFactory<BehaviorNoteRetractionFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    public function behaviorNote(): BelongsTo
    {
        return $this->belongsTo(AttendanceBehaviorNote::class, 'attendance_behavior_note_id');
    }

    public function retractedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retracted_by');
    }
}
