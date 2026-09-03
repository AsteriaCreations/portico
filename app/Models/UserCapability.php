<?php

namespace App\Models;

use App\Enums\Capability;
use Database\Factories\UserCapabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'capability', 'granted_by'])]
class UserCapability extends Model
{
    /** @use HasFactory<UserCapabilityFactory> */
    use HasFactory;

    // No updated_at column: changing who granted it, or which capability,
    // means revoke (delete) + re-grant, not an in-place edit.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
