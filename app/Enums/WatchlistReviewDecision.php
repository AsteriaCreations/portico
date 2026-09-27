<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WatchlistReviewDecision: string implements HasLabel
{
    case Removed = 'removed';
    case Extended = 'extended';
    case KeptIndefinitely = 'kept_indefinitely';

    public function getLabel(): string
    {
        return match ($this) {
            self::Removed => __('Removed from watchlist'),
            self::Extended => __('Review date extended'),
            self::KeptIndefinitely => __('Kept on indefinitely'),
        };
    }
}
