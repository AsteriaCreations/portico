<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PoolCoverageSource: string implements HasLabel
{
    case None = 'none';
    case Comp = 'comp';
    case PoolSubscription = 'pool_subscription';
    case DayPass = 'day_pass';
    case LegacyImport = 'legacy_import';

    // See EntryCoverageSource::getLabel() for why this exists.
    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Comp => 'Comp',
            self::PoolSubscription => 'Pool Subscription',
            self::DayPass => 'Day Pass',
            self::LegacyImport => 'Legacy Import',
        };
    }
}
