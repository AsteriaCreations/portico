<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Makes a Filament Page's navigation label and title translatable, with the
 * English text as the translation key. A `protected static $navigationLabel`
 * or `$title` can't call __() itself, and Filament's derived defaults (from
 * the class name) never touch the translator, so this wraps whichever one the
 * page ends up with.
 */
trait TranslatesPageLabels
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? __($title) : $title;
    }
}
