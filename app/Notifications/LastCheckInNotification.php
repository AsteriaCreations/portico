<?php

namespace App\Notifications;

use Filament\Notifications\DatabaseNotification;

/**
 * The bell's copy of the Check-In Desk's "Checked in — $X due" toast, so the
 * desk can re-check what the last person owed after the toast has faded.
 * Its own class only so its `notifications.type` tells it apart from every
 * other bell entry: each new check-in deletes the previous one, keeping just
 * the latest per staff user.
 */
class LastCheckInNotification extends DatabaseNotification {}
