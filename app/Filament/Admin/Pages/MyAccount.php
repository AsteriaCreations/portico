<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * The signed-in user's own settings: where club email reaches them, plus a
 * way to the Change password page. Reached from the user menu (see
 * AdminPanelProvider::userMenuItems()), not the nav rail. Like
 * ChangePassword, canAccess() stays at its default so every role can reach
 * it -- it only ever edits auth()->user().
 */
class MyAccount extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.my-account';

    protected static ?string $slug = 'my-account';

    protected static ?string $title = 'My account';

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
        $this->form->fill([
            'sign_in_email' => auth()->user()->email,
            'contact_email' => auth()->user()->contact_email,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('sign_in_email')
                    ->label('Sign-in email')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('What you type to sign in. Only an Admin can change it.')),
                TextInput::make('contact_email')
                    ->label('Preferred email for communication')
                    ->email()
                    ->maxLength(255)
                    ->helperText(__('Where the club sends you email, such as event summaries. Leave blank to use your sign-in email.')),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save')
            ->action(function (): void {
                $data = $this->form->getState();

                auth()->user()->update([
                    'contact_email' => filled($data['contact_email'] ?? null) ? $data['contact_email'] : null,
                ]);

                Notification::make()->title(__('Account updated'))->success()->send();
            });
    }

    public function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->color('gray')
            ->url(ChangePassword::getUrl());
    }
}
