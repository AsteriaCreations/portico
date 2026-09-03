<?php

namespace App\Models;

use App\Enums\MemberStatusField;
use Database\Factories\MemberStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'status', 'value', 'reason', 'changed_by'])]
class MemberStatusChange extends Model
{
    /** @use HasFactory<MemberStatusChangeFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => MemberStatusField::class,
            'value' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
