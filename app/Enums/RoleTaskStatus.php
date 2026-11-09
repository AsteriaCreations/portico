<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A role task is on the role's standard list, or proposed by staff and
 * waiting for a Manager+ to approve (standard) or decline it.
 */
enum RoleTaskStatus: string implements HasLabel
{
    case Standard = 'standard';
    case Proposed = 'proposed';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => __('Standard'),
            self::Proposed => __('Proposed'),
            self::Declined => __('Declined'),
        };
    }
}
