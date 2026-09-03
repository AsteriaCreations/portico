<?php

namespace App\Filament\Admin\Resources\Members\Pages;

use App\Filament\Admin\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\QueryException;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->renameUsernameAction(),
            DeleteAction::make(),
        ];
    }

    // username is locked on the form itself (see MemberForm) -- this is the
    // only rename path, so it's the one place a duplicate gets checked and
    // the change gets logged (via MemberObserver -> member_username_changes).
    // No separate gate: this is a header action on the Member edit page,
    // already Manager+ only via MemberPolicy.
    protected function renameUsernameAction(): Action
    {
        return Action::make('renameUsername')
            ->label('Rename username')
            ->schema([
                TextInput::make('username')
                    ->label('New username')
                    ->required()
                    ->maxLength(60)
                    ->default(fn (): string => $this->getRecord()->username)
                    ->unique(table: 'members', column: 'username', ignoreRecord: true),
            ])
            ->action(function (array $data): void {
                // The unique() rule above already checked at validation
                // time -- this only catches the narrow race between that
                // check and this write (same pattern as
                // CheckIn::registerGuestAction()).
                try {
                    $this->getRecord()->update(['username' => $data['username']]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    Notification::make()->title('That username was just taken — please choose another.')->danger()->send();

                    return;
                }

                // The main form's own username field is disabled/dehydrated
                // and only ever filled at mount -- without this, the edit
                // page would keep showing the old value until a full reload.
                $this->refreshFormData(['username']);

                Notification::make()->title('Username updated')->success()->send();
            });
    }
}
