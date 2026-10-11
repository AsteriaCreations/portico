<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventRepeat: string implements HasLabel
{
    case Once = 'once';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case MonthlySameWeekday = 'monthly_same_weekday';

    public function getLabel(): string
    {
        return match ($this) {
            self::Once => __('Just this date'),
            self::Weekly => __('Weekly'),
            self::Biweekly => __('Every 2 weeks'),
            self::MonthlySameWeekday => __('Monthly, same weekday (e.g. 2nd Friday)'),
        };
    }
}
