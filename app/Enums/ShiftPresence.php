<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * For a night someone isn't on a shift: here anyway, or not here.
 */
enum ShiftPresence: string implements HasIcon, HasLabel
{
    case Off = 'off';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => __('Off: I\'ll be there'),
            self::Out => __('Out: I won\'t be there'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Out => __('Out'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Off => Heroicon::OutlinedUser,
            self::Out => Heroicon::OutlinedArrowRightStartOnRectangle,
        };
    }
}
