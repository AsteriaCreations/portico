<?php

namespace App\Filament\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * Reached only via RequirePasswordChange's forced redirect (or its own URL) --
 * never in the nav rail, see shouldRegisterNavigation() below. canAccess() is
 * deliberately left at CanAuthorizeAccess's default (true for any authenticated
 * panel user), since this must be reachable by every role, not just the roles
 * that could otherwise reach a Users-adjacent screen.
 */
class ChangePassword extends Page
{
    protected string $view = 'filament.admin.pages.change-password';

    protected static ?string $slug = 'change-password';

    protected static ?string $title = 'Change password';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('current_password')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->currentPassword(),
                TextInput::make('new_password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->helperText('At least 12 characters with upper and lower case, a number, and a symbol.'),
                TextInput::make('new_password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('new_password'),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Set new password')
            ->action(function (): void {
                $data = $this->form->getState();

                $user = auth()->user();
                $user->password = $data['new_password'];
                $user->must_change_password = false;
                $user->save();

                Notification::make()->title('Password updated')->success()->send();

                $this->redirect(Dashboard::getUrl());
            });
    }
}
