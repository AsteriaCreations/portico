<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a shift is for. Open, Close and Single are an event's standard
 * shifts, set together from its Staffing tab (ShiftSlotService); Setup and
 * Extra are added one at a time, with their own times.
 */
enum ShiftKind: string implements HasLabel
{
    case Open = 'open';
    case Close = 'close';
    case Single = 'single';
    case Setup = 'setup';
    case Extra = 'extra';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Close => __('Close'),
            self::Single => __('Whole night'),
            self::Setup => __('Setup'),
            self::Extra => __('Extra'),
        };
    }

    public function isStandard(): bool
    {
        return in_array($this, [self::Open, self::Close, self::Single], true);
    }
}
