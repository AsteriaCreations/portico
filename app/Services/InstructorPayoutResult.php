<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;

final readonly class InstructorPayoutResult
{
    /**
     * @param  array<int, array{source: EntryCoverageSource, count: int, rate: float, subtotal: float}>  $lineItems
     */
    public function __construct(
        public array $lineItems,
        public float $total,
    ) {}
}
