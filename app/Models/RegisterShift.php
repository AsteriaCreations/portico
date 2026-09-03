<?php

namespace App\Models;

use Database\Factories\RegisterShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['register_id', 'opened_by', 'opening_count', 'closed_by', 'closed_at', 'closing_count', 'notes'])]
class RegisterShift extends Model
{
    /** @use HasFactory<RegisterShiftFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'opening_count' => 'decimal:2',
            'closed_at' => 'datetime',
            'closing_count' => 'decimal:2',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function drops(): HasMany
    {
        return $this->hasMany(RegisterDrop::class);
    }

    public function miscellaneousPayments(): HasMany
    {
        return $this->hasMany(MiscellaneousPayment::class);
    }
}
