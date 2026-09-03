<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\PoolCoverageSource;

final readonly class PriceBreakdown
{
    public function __construct(
        public float $entryFee,
        public float $entryCoverage,
        public EntryCoverageSource $entryCoveredBy,
        public float $poolFee,
        public float $poolCoverage,
        public PoolCoverageSource $poolCoveredBy,
        public float $voucherCoverage,
        public float $amountPaid,
    ) {}

    /**
     * Map to the attendance table's snapshot column names, ready for Attendance::create().
     */
    public function toAttendanceAttributes(): array
    {
        return [
            'entry_fee' => $this->entryFee,
            'entry_coverage' => $this->entryCoverage,
            'entry_covered_by' => $this->entryCoveredBy,
            'pool_fee' => $this->poolFee,
            'pool_coverage' => $this->poolCoverage,
            'pool_covered_by' => $this->poolCoveredBy,
            'voucher_coverage' => $this->voucherCoverage,
            'amount_paid' => $this->amountPaid,
        ];
    }
}
