<?php

namespace App\Models;

use Database\Factories\DoorDiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed amount off entry the desk can apply to one visit, e.g. "Food
 * donation — $5". Taken off the entry still owed after any subscription
 * credit or comp, never below zero -- see PricingService::entryDiscountCents().
 */
#[Fillable(['name', 'description', 'amount', 'sort_order', 'active'])]
class DoorDiscount extends Model
{
    /** @use HasFactory<DoorDiscountFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    public function attendanceDiscounts(): HasMany
    {
        return $this->hasMany(AttendanceDiscount::class);
    }
}
