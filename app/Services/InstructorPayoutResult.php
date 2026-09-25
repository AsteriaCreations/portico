<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;

/**
 * Amounts are integer cents -- see App\Support\Cents. Convert with
 * Cents::toFloat() only to display them.
 */
final readonly class InstructorPayoutResult
{
    /**
     * @param  array<int, array{source: EntryCoverageSource, count: int, rateCents: int, subtotalCents: int}>  $lineItems
     */
    public function __construct(
        public array $lineItems,
        public int $totalCents,
    ) {}
}
