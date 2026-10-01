<?php

namespace App\Filament\Concerns;

use App\Models\Member;
use App\Services\UsernameRenameNotifier;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

/**
 * The "Rename username" action, shared by the Member edit page (Manager+)
 * and the Check-In Desk (Door, when the club turns that on at Feature
 * Flags). The plain Member form locks username (see MemberForm), so this is
 * the only rename path: the one place a duplicate gets checked, and the
 * change gets logged via MemberObserver -> member_username_changes. Who may
 * use it is the rename-member-username gate, re-checked server-side here
 * (never trust ->visible() alone). A rename by someone below Manager
 * notifies every active Manager+ (UsernameRenameNotifier).
 */
trait RenamesMemberUsername
{
    /**
     * @param  Closure(): ?Member  $resolveMember  the member being renamed
     * @param  Closure(): bool|null  $haltBeforeSave  return true to stop before writing (e.g. the desk's training mode)
     * @param  Closure(): void|null  $afterSave  runs after a successful rename
     */
    protected function makeRenameUsernameAction(Closure $resolveMember, ?Closure $haltBeforeSave = null, ?Closure $afterSave = null): Action
    {
        return Action::make('renameUsername')
            ->label('Rename username')
            ->visible(fn (): bool => $resolveMember() !== null && Gate::allows('rename-member-username'))
            ->schema([
                TextInput::make('username')
                    ->label('New username')
                    ->required()
                    ->maxLength(60)
                    ->default(fn (): ?string => $resolveMember()?->username)
                    ->unique(table: 'members', column: 'username', ignorable: fn (): ?Member => $resolveMember()),
            ])
            ->action(function (array $data) use ($resolveMember, $haltBeforeSave, $afterSave): void {
                abort_unless(Gate::allows('rename-member-username'), 403);

                $member = $resolveMember();
                abort_unless($member, 404);

                if ($haltBeforeSave && $haltBeforeSave()) {
                    return;
                }

                $oldUsername = $member->username;

                // The unique() rule above already checked at validation
                // time -- this only catches the narrow race between that
                // check and this write (same pattern as
                // CheckIn::registerGuestAction()).
                try {
                    $member->update(['username' => $data['username']]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title(__('That username was just taken — please choose another.'))->danger()->send();

                    return;
                }

                if ($member->username !== $oldUsername) {
                    app(UsernameRenameNotifier::class)->notifyIfNeeded($member, $oldUsername, auth()->user());
                }

                if ($afterSave) {
                    $afterSave();
                }

                Notification::make()->title(__('Username updated'))->success()->send();
            });
    }
}
