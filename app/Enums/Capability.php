<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Independent, additive facts about a user, orthogonal to the nested
 * users.role hierarchy (Role::atLeast()). A capability grants nothing
 * role-related on its own -- e.g. Cleaning Crew and Door are two separate
 * facts a person can hold at once. Deliberately not mirrored as a MySQL
 * enum() column (unlike Role/EventType/PlanType/*CoverageSource): the
 * user_capabilities.capability column is a plain string, so adding a case
 * here is a one-line change, not a schema migration.
 */
enum Capability: string implements HasLabel
{
    case CleaningCrew = 'cleaning_crew';

    public function getLabel(): string
    {
        return match ($this) {
            self::CleaningCrew => 'Cleaning Crew',
        };
    }
}
