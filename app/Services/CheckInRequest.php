<?php

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * What the desk submitted for one check-in, already pulled out of the
 * Check-In page's form state. Everything here is client-supplied: the
 * service re-derives prices, coverage and eligibility from the database and
 * treats these only as the staff member's choices.
 */
final readonly class CheckInRequest
{
    /**
     * @param  array<int, int>  $subscriptionMonths  add-on id (Entry included) => months to buy; an add-on absent here buys nothing
     * @param  array<int, int|string>  $addOnIds  flat add-ons selected
     */
    public function __construct(
        public CarbonInterface|string|null $checkedInAt = null,
        public ?string $paymentMethod = null,
        public ?string $onBehalfNote = null,
        public ?string $notes = null,
        public array $subscriptionMonths = [],
        public array $addOnIds = [],
        public bool $compEntry = false,
        public int|string|null $compReasonId = null,
        public bool $applyVoucher = false,
        public int|string|null $voucherPayerId = null,
        public float $voucherAmount = 0.0,
        public string $voucherReason = '',
    ) {}
}
