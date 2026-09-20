<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Makes a relation manager's tab title translatable, with the English text as
 * the translation key -- both an explicit `protected static $title` (which
 * can't call __()) and the title Filament derives from the relationship name.
 */
trait TranslatesRelationManagerTitle
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord, $pageClass));
    }
}
