<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['label', 'code', 'requires_register_shift', 'sort_order', 'active'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requires_register_shift' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return array<string, string> code => label, for Select options.
     */
    public static function options(): array
    {
        return static::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->pluck('label', 'code')
            ->all();
    }

    /**
     * The codes that require an open register shift to select, and that
     * count toward RegisterShiftService::cashReceived()'s box reconciliation.
     *
     * @return Collection<int, string>
     */
    public static function cashCodes(): Collection
    {
        return static::query()
            ->where('requires_register_shift', true)
            ->pluck('code');
    }
}
