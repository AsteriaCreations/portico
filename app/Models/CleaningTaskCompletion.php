<?php

namespace App\Models;

use Database\Factories\CleaningTaskCompletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cleaning_task_id', 'completed_by', 'for_week_start', 'notes'])]
class CleaningTaskCompletion extends Model
{
    /** @use HasFactory<CleaningTaskCompletionFactory> */
    use HasFactory;

    // Append-only: a completion record is never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'for_week_start' => 'date',
        ];
    }

    public function cleaningTask(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
