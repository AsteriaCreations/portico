<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

use function Filament\Support\get_model_label;

/**
 * Makes a Filament Resource's model/navigation labels translatable, with the
 * English text as the translation key (see CONTRIBUTING.md).
 *
 * Filament derives these labels from the model class name ("member",
 * "members") or takes them from static properties, neither of which goes
 * through the translator. The plural is built from the *English* singular
 * and then translated on its own: pluralizing an already-translated word
 * would apply English rules to another language.
 */
trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __(static::englishModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel ?? Str::plural(static::englishModelLabel()));
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel !== null
            ? __(static::$navigationLabel)
            : static::getTitleCasePluralModelLabel();
    }

    protected static function englishModelLabel(): string
    {
        return static::$modelLabel ?? get_model_label(static::getModel());
    }
}
