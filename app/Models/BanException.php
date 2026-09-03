<?php

namespace App\Models;

use Database\Factories\BanExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'event_id', 'granted_by', 'reason'])]
class BanException extends Model
{
    /** @use HasFactory<BanExceptionFactory> */
    use HasFactory;

    // No updated_at column: a granted exception is never edited.
    public const UPDATED_AT = null;

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
