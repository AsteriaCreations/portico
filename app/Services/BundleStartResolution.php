<?php

namespace App\Services;

use Carbon\CarbonInterface;

final readonly class BundleStartResolution
{
    /**
     * @param  array<int, CarbonInterface>  $skippedMonths  months the sliding window had to pass over because they were already covered by an existing subscription
     */
    public function __construct(
        public CarbonInterface $start,
        public array $skippedMonths = [],
    ) {}
}
