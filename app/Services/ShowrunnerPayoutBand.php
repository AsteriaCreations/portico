<?php

namespace App\Services;

use App\Models\ShowrunnerPayoutTier;

/**
 * One percentage tier's share of a showrunner payout: how many attendees
 * fell inside it and what they earned, in integer cents.
 */
final readonly class ShowrunnerPayoutBand
{
    public function __construct(
        public ShowrunnerPayoutTier $tier,
        public int $attendees,
        public int $amountCents,
    ) {}
}
