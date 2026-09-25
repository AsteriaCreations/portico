<?php

namespace App\Services;

use App\Models\Attendance;

/**
 * Amounts are integer cents -- see App\Support\Cents.
 */
final readonly class CheckInResult
{
    public function __construct(
        public Attendance $attendance,
        public PriceBreakdown $breakdown,
        public int $subscriptionTotalCents,
        public int $voucherAppliedCents,
        public int $addOnTotalCents,
    ) {}
}
