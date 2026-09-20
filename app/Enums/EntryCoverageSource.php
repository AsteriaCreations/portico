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
            self::None => __('None'),
            self::Comp => __('Comp'),
            self::RegularSubscription => __('Regular Subscription'),
            self::LegacyImport => __('Legacy Import'),
            self::EventComp => __('Event Comp'),
            self::Host => __('Host'),
        };
    }
}
