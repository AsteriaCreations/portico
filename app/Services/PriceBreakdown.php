<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;

final readonly class PriceBreakdown
{
    /**
     * @param  AddOnPriceLine[]  $addOnLines  one per subscribable add-on priced for this event (Pool, at launch) — empty for a comped-off or otherwise inapplicable add-on, never for a non-subscribable one.
     */
    public function __construct(
        public float $entryFee,
        public float $entryCoverage,
        public EntryCoverageSource $entryCoveredBy,
        public array $addOnLines,
        public float $voucherCoverage,
        public float $amountPaid,
    ) {}

    /**
     * Map to the attendance table's entry snapshot columns, ready for
     * Attendance::create(). Add-on lines are a separate concern — see
     * addOnAttendanceRows() — since each is its own attendance_add_ons row,
     * not a fixed set of columns on attendance itself.
     */
    public function toAttendanceAttributes(): array
    {
        return [
            'entry_fee' => $this->entryFee,
            'entry_coverage' => $this->entryCoverage,
            'entry_covered_by' => $this->entryCoveredBy,
            'voucher_coverage' => $this->voucherCoverage,
            'amount_paid' => $this->amountPaid,
        ];
    }

    /**
     * @return array<int, array<string, mixed>> one AttendanceAddOn::create() payload per subscribable line, still missing attendance_id
     */
    public function addOnAttendanceRows(): array
    {
        return array_map(fn (AddOnPriceLine $line) => $line->toAttendanceAddOnAttributes(), $this->addOnLines);
    }
}
