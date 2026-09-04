<?php

namespace App\Enums;

use App\Models\MembershipSetting;
use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Showrunner = 'showrunner';
    case Volunteer = 'volunteer';
    case DM = 'dm';
    case Door = 'door';
    case Manager = 'manager';
    case Admin = 'admin';
    case Owner = 'owner';

    public function atLeast(self $minimum): bool
    {
        $order = [self::Showrunner, self::Volunteer, self::DM, self::Door, self::Manager, self::Admin, self::Owner];

        return array_search($this, $order, true) >= array_search($minimum, $order, true);
    }

    /**
     * Generic default label — deliberately no DB access, so this stays safe
     * to call anywhere (tests, emails, contexts with no request/DB), and is
     * what Filament calls automatically wherever a Role-cast column or
     * Role::class is used without custom options(). "Showrunner" and "DM"
     * are the two that read as this club's own jargon rather than a
     * universal term; the rest already do. See displayLabel() for the
     * per-installation alias override.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Showrunner => 'Event Lead',
            self::Volunteer => 'Volunteer',
            self::DM => 'Monitor',
            self::Door => 'Door',
            self::Manager => 'Manager',
            self::Admin => 'Admin',
            self::Owner => 'Owner',
        };
    }

    /**
     * The label to actually show in the UI — a club's own alias for this
     * role (App\Filament\Admin\Pages\RoleLabels, Manager+) if one is set,
     * else the generic default above. Every explicit UI display site (the
     * Users form/table; Active Patrons' signed-in-staff line) calls this
     * instead of relying on automatic HasLabel resolution, since that
     * always calls getLabel() and has no way to consult settings.
     */
    public function displayLabel(): string
    {
        return MembershipSetting::current()->role_labels[$this->value] ?? $this->getLabel();
    }
}
