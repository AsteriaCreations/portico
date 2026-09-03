<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PayoutType: string implements HasLabel
{
    case Voucher = 'voucher';
    case Percentage = 'percentage';

    public function getLabel(): string
    {
        return match ($this) {
            self::Voucher => 'Voucher',
            self::Percentage => 'Percentage',
        };
    }
}
