<?php

namespace App\Services;

use App\Models\ShowrunnerPayoutTier;

final readonly class ShowrunnerPayoutResult
{
    public function __construct(
        public int $headcount,
        public bool $includeSh,
        public int $cashCount,
        public int $shCount,
        public float $cashRevenue,
        public float $shRevenue,
        public float $poolRevenue,
        public float $addonRevenue,
        public float $doorTotal,
        public ?ShowrunnerPayoutTier $tier,
        public ?float $payoutAmount,
    ) {}
}
