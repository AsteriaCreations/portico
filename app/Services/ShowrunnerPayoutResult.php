<?php

namespace App\Services;

use App\Models\ShowrunnerPayoutTier;

/**
 * Amounts are integer cents -- see App\Support\Cents. Convert with
 * Cents::toFloat() only to display them.
 */
final readonly class ShowrunnerPayoutResult
{
    public function __construct(
        public int $headcount,
        public bool $includeSh,
        public int $cashCount,
        public int $shCount,
        public int $cashRevenueCents,
        public int $shRevenueCents,
        public int $poolRevenueCents,
        public int $addonRevenueCents,
        public int $doorTotalCents,
        public ?ShowrunnerPayoutTier $tier,
        public ?int $payoutAmountCents,
    ) {}
}
