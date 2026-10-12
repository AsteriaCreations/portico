<?php

namespace App\Services;

use App\Models\ShowrunnerPayoutTier;

/**
 * Amounts are integer cents -- see App\Support\Cents. Convert with
 * Cents::toFloat() only to display them.
 */
final readonly class ShowrunnerPayoutResult
{
    /**
     * @param  list<ShowrunnerPayoutBand>  $bands  the percentage tiers that paid something, lowest first
     * @param  ?ShowrunnerPayoutTier  $voucherTier  the flat voucher tier the headcount falls in, if any
     * @param  ?int  $payoutAmountCents  null only when no tiers are configured at all
     */
    public function __construct(
        public int $headcount,
        public bool $includeSh,
        public int $cashCount,
        public int $shCount,
        public int $entryFeeCents,
        public array $bands,
        public ?ShowrunnerPayoutTier $voucherTier,
        public ?int $payoutAmountCents,
    ) {}
}
