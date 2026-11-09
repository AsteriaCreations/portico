<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's vote on a proposed role task: 1 (include it) or -1 (don't).
 * See RoleTaskProposalService.
 */
#[Fillable(['role_task_id', 'user_id', 'vote'])]
class RoleTaskVote extends Model
{
    protected function casts(): array
    {
        return [
            'vote' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RoleTask::class, 'role_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
