<?php

namespace App\Models;

use Database\Factories\CompReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'grants_voucher_amount', 'sort_order', 'active'])]
class CompReason extends Model
{
    /** @use HasFactory<CompReasonFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'grants_voucher_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
