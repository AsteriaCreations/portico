<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What a staff member says about one shift on My availability. Every case
 * has an icon as well as a color, so it never rests on color alone.
 */
enum ShiftPreference: string implements HasColor, HasIcon, HasLabel
{
    case Preferred = 'preferred';
    case Available = 'available';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Preferred => __('Preferred'),
            self::Available => __('Available'),
            self::Unavailable => __('No'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Preferred => 'success',
            self::Available => 'info',
            self::Unavailable => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Preferred => Heroicon::OutlinedStar,
            self::Available => Heroicon::OutlinedCheck,
            self::Unavailable => Heroicon::OutlinedXMark,
        };
    }
}
