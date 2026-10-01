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
 * A username rename by someone below Manager (Door at the Check-In Desk,
 * once door_username_rename_enabled is on) is a change Managers normally
 * make themselves, so every active Manager+ hears about it. A Manager+
 * rename notifies nobody -- they already own that call. The change itself
 * is logged by MemberObserver either way.
 */
class UsernameRenameNotifier
{
    public function notifyIfNeeded(Member $member, string $oldUsername, User $renamedBy): void
    {
        if ($renamedBy->role->atLeast(Role::Manager)) {
            return;
        }

        $managers = User::query()
            ->where('active', true)
            ->whereIn('role', [Role::Manager, Role::Admin, Role::Owner])
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title(__('Username renamed at the desk: :old → :new', ['old' => $oldUsername, 'new' => $member->username]))
            ->body(__('By :name.', ['name' => $renamedBy->name]))
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
