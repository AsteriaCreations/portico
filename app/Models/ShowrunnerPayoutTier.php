<?php

namespace App\Models;

use App\Enums\PayoutType;
use Database\Factories\ShowrunnerPayoutTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['min_headcount', 'max_headcount', 'payout_type', 'payout_value'])]
class ShowrunnerPayoutTier extends Model
{
    /** @use HasFactory<ShowrunnerPayoutTierFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'min_headcount' => 'integer',
            'max_headcount' => 'integer',
            'payout_type' => PayoutType::class,
            'payout_value' => 'decimal:2',
        ];
    }
}
