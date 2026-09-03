<?php

namespace App\Models;

use App\Enums\CompRequestStatus;
use Database\Factories\CompRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_id', 'member_id', 'comp_reason_id', 'requested_reason_text', 'requested_by', 'notes', 'status', 'reviewed_by', 'reviewed_at', 'review_notes', 'attendance_id'])]
class CompRequest extends Model
{
    /** @use HasFactory<CompRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CompRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function compReason(): BelongsTo
    {
        return $this->belongsTo(CompReason::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
