<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['label', 'code', 'requires_register_shift', 'one_time_only', 'transaction_fee', 'sort_order', 'active'])]
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
            'transaction_fee' => 'decimal:2',
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

    /**
     * The flat per-transaction surcharge configured for a method (0 for no
     * code, an unknown code, or a method with none set) — e.g. $2 for
     * Venmo/PayPal/electronic, modelling the real processor cost of
     * accepting that method. Folded straight into amount_paid by the
     * caller; this method never touches the database beyond the lookup.
     */
    public static function feeFor(?string $code): float
    {
        if (! $code) {
            return 0.0;
        }

        return (float) (static::where('code', $code)->value('transaction_fee') ?? 0);
    }

    /**
     * A short note for staff (e.g. "A $2.00 transaction fee will be added
     * to the total.") shown live next to a payment_method Select so the fee
     * is visible before the total is charged, not just baked silently into
     * the recorded amount. Null when the method carries no fee.
     */
    public static function feeHelperText(?string $code): ?string
    {
        $fee = static::feeFor($code);

        return $fee > 0 ? 'A '.MembershipSetting::formatMoney($fee).' transaction fee will be added to the total.' : null;
    }
}
