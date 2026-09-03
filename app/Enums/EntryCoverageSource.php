<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EntryCoverageSource: string implements HasLabel
{
    case None = 'none';
    case Comp = 'comp';
    case RegularSubscription = 'regular_subscription';
    case LegacyImport = 'legacy_import';
    case EventComp = 'event_comp';
    case Host = 'host';

    // Without this, Filament's TextColumn::badge() displays the raw backing
    // value (e.g. "regular_subscription") rather than a humanized name.
    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Comp => 'Comp',
            self::RegularSubscription => 'Regular Subscription',
            self::LegacyImport => 'Legacy Import',
            self::EventComp => 'Event Comp',
            self::Host => 'Host',
        };
    }
}
