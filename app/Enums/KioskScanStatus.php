<?php

namespace App\Enums;

/**
 * What the kiosk screen shows after a scan. Only Admitted writes anything.
 */
enum KioskScanStatus: string
{
    case Admitted = 'admitted';
    case AlreadyCheckedIn = 'already_checked_in';
    case SeeStaff = 'see_staff';
    case NotRecognized = 'not_recognized';
    case Closed = 'closed';
}
