<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * A staff login linking its own member record from My account is a call
 * an Admin otherwise makes in Users, so every other active Manager+ hears
 * about it and can check the right member was picked. The linker
 * themselves is left out: they just did it.
 */
class MemberSelfLinkNotifier
{
    public function notify(User $linkedBy, Member $member): void
    {
        $managers = User::query()
            ->where('active', true)
            ->whereIn('role', [Role::Manager, Role::Admin, Role::Owner])
            ->whereKeyNot($linkedBy->id)
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title(__(':name linked their login to member :username', ['name' => $linkedBy->name, 'username' => $member->username]))
            ->body(__('Linked from My account. If it is the wrong member, an Admin can change it in Users.'))
            ->actions([
                Action::make('view')
                    ->label('View member')
                    ->url(MemberResource::getUrl('edit', ['record' => $member]))
                    ->markAsRead(),
            ]);

        // sendNow(), not ->sendToDatabase(): this app runs no queue worker
        // (see ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
        LaravelNotification::sendNow($managers, $notification->toDatabase());
    }
}
