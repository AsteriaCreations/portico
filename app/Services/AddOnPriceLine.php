<?php

namespace App\Services;

use App\Enums\AddOnCoverageSource;
use App\Models\AddOn;
use App\Support\Cents;

/**
 * One subscribable add-on's priced line within a PriceBreakdown — Pool, at
 * launch, but generic to any add-on flagged `subscribable`. Never built for
 * a non-subscribable (flat) add-on; those stay entirely outside
 * PricingService, exactly as before this existed. Amounts are integer cents
 * -- see App\Support\Cents.
 */
final readonly class AddOnPriceLine
{
    public function __construct(
        public AddOn $addOn,
        public int $feeCents,
        public int $coverageCents,
        public AddOnCoverageSource $coveredBy,
    ) {}

    public function amountDueCents(): int
    {
        return $this->feeCents - $this->coverageCents;
    }

    /**
     * Ready for AttendanceAddOn::create() — 'price' is the same "amount
     * actually paid" meaning a flat add-on's row already uses, so it still
     * folds straight into attendance.amount_paid with no changes needed
     * anywhere that sums it. name/is_overnight are snapshotted from the
     * catalog row at creation time, same as a flat add-on's row.
     */
    public function toAttendanceAddOnAttributes(): array
    {
        return [
            'add_on_id' => $this->addOn->id,
            'name' => $this->addOn->name,
            'price' => Cents::toDecimal($this->amountDueCents()),
            'fee' => Cents::toDecimal($this->feeCents),
            'coverage' => Cents::toDecimal($this->coverageCents),
            'covered_by' => $this->coveredBy,
            'is_overnight' => $this->addOn->is_overnight,
        ];
    }
}
