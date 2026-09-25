<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Support\Cents;

/**
 * Amounts are integer cents -- see App\Support\Cents. As floats, a visit
 * covered exactly by credit plus a voucher could leave 7.2e-16 "due", which
 * counted as money changing hands and drew a transaction fee.
 */
final readonly class PriceBreakdown
{
    /**
     * @param  AddOnPriceLine[]  $addOnLines  one per subscribable add-on priced for this event (Pool, at launch) — empty for a comped-off or otherwise inapplicable add-on, never for a non-subscribable one.
     */
    public function __construct(
        public int $entryFeeCents,
        public int $entryCoverageCents,
        public EntryCoverageSource $entryCoveredBy,
        public array $addOnLines,
        public int $voucherCoverageCents,
        public int $amountPaidCents,
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
            'entry_fee' => Cents::toDecimal($this->entryFeeCents),
            'entry_coverage' => Cents::toDecimal($this->entryCoverageCents),
            'entry_covered_by' => $this->entryCoveredBy,
            'voucher_coverage' => Cents::toDecimal($this->voucherCoverageCents),
            'amount_paid' => Cents::toDecimal($this->amountPaidCents),
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
