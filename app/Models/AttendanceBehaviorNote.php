<?php

namespace App\Models;

use Database\Factories\AttendanceBehaviorNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
