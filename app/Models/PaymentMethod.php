<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['label', 'code', 'requires_register_shift', 'one_time_only', 'sort_order', 'active'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requires_register_shift' => 'boolean',
            'one_time_only' => 'boolean',
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

    /**
     * The codes for methods a member may select only once, ever — using one
     * appends a dated note to their hospitality_note and disables all such
     * methods for them thereafter (Member::hasUsedOneTimeMethod()). E.g.
     * Venmo, PayPal.
     *
     * @return Collection<int, string>
     */
    public static function oneTimeCodes(): Collection
    {
        return static::query()
            ->where('one_time_only', true)
            ->pluck('code');
    }
}
