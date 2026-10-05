<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Someone the kiosk turned away for a reason staff must handle in person
 * (watchlist, ban, missing sign-up or paperwork, under the alcohol-flag age,
 * building full): every active Door+ hears about it, since whoever is on the
 * desk right now needs to act. The body is the admission decision's own
 * message -- what the desk would show Door anyway -- never a reason only
 * Manager+ may read.
 */
class KioskStaffAlert
{
    public function send(Member $member, string $why): void
    {
        $staff = User::query()
            ->where('active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->role->atLeast(Role::Door));

        if ($staff->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title(__('Kiosk: :name needs the desk', ['name' => $member->displayName()]))
            ->body($why)
            ->warning()
            ->actions([
                Action::make('openDesk')
                    ->label('Open the Check-In Desk')
                    ->url(CheckIn::getUrl())
                    ->markAsRead(),
            ]);

        // sendNow(), not ->sendToDatabase(): this app runs no queue worker
        // (see ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
        LaravelNotification::sendNow($staff->values(), $notification->toDatabase());
    }
}
