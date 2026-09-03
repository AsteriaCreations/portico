<?php

namespace App\Enums;

enum Role: string
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
}
