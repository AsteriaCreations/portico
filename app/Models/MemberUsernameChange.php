<?php

namespace App\Models;

use Database\Factories\MemberUsernameChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'old_username', 'new_username', 'changed_by'])]
class MemberUsernameChange extends Model
{
    /** @use HasFactory<MemberUsernameChangeFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
