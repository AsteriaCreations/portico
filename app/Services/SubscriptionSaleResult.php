<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Amounts are integer cents -- see App\Support\Cents.
 */
final readonly class SubscriptionSaleResult
{
    /**
     * @param  Collection<int, Subscription>  $rows  one per covered month, in order
     */
    public function __construct(
        public Collection $rows,
        public int $voucherAppliedCents,
    ) {}

    /**
     * "July 2026", or "July 2026 – September 2026" for a multi-month bundle.
     */
    public function rangeLabel(): string
    {
        $first = $this->rows->first()->covered_month;
        $last = $this->rows->last()->covered_month;

        return $first->isSameMonth($last)
            ? $first->translatedFormat('F Y')
            : $first->translatedFormat('F Y').' – '.$last->translatedFormat('F Y');
    }
}
