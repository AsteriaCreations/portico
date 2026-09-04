<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mirrors EntryCoverageSource's shape, generalized to any subscribable
 * add-on (only Pool, at launch) — recorded on
 * attendance_add_ons.covered_by. Always set (never left null) for a
 * subscribable add-on's line, the same convention entry_covered_by already
 * uses; left genuinely null for an ordinary flat add-on, where the whole
 * coverage concept doesn't apply.
 */
enum AddOnCoverageSource: string implements HasLabel
{
    case None = 'none';
    case Comp = 'comp';
    case Subscription = 'subscription';
    case DayPass = 'day_pass';
    case LegacyImport = 'legacy_import';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Comp => 'Comp',
            self::Subscription => 'Subscription',
            self::DayPass => 'Day Pass',
            self::LegacyImport => 'Legacy Import',
        };
    }
}
