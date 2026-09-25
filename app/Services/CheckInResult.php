<?php

namespace App\Services;

use App\Models\Attendance;

final readonly class CheckInResult
{
    public function __construct(
        public Attendance $attendance,
        public PriceBreakdown $breakdown,
        public float $subscriptionTotal,
        public float $voucherApplied,
        public float $addOnTotal,
    ) {}
}
