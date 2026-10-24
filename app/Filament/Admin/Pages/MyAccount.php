<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Member;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own settings: where club email reaches them, their
 * own member record if they aren't linked yet, plus a way to the Change
 * password page. Reached from the user menu (see
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
            'email_opt_in' => auth()->user()->email_opt_in,
            'colorblind_palette' => auth()->user()->colorblind_palette,
            'member_id' => auth()->user()->member_id,
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
                    ->live(onBlur: true)
                    ->helperText(__('Where the club sends you email, such as event summaries. Leave blank to use your sign-in email.')),
                // Asked only when the address actually changes, so a hijacked
                // session can't quietly redirect this account's mail. The
                // required() closure runs again on the server at save time,
                // so hiding the field in the browser doesn't skip it.
                TextInput::make('current_password')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->currentPassword()
                    ->required(fn (Get $get): bool => self::contactEmailChanged($get('contact_email')))
                    ->visible(fn (Get $get): bool => self::contactEmailChanged($get('contact_email')))
                    ->dehydrated(false)
                    ->helperText(__('Needed to change your preferred email.')),
                // Self-service only while unlinked: once set, only an Admin
                // (Users resource) can change it, so an account can't hop
                // between member records. Options come from
                // Member::scopeSelfLinkable() and save() re-checks it.
                Select::make('member_id')
                    ->label('Your member record')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Member::query()
                        ->selfLinkable()
                        ->where('username', 'like', $search.'%')
                        ->orderBy('username')
                        ->limit(10)
                        ->pluck('username', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Member::find($value)?->username)
                    ->disabled(fn (): bool => auth()->user()->member_id !== null)
                    ->helperText(fn (): string => auth()->user()->member_id !== null
                        ? __('Linked to your account. Ask an Admin if this is wrong.')
                        : __('Optional. Type your member username to link it to this login. A member already linked to another login is not listed.')),
                Toggle::make('email_opt_in')
                    ->label('Email me club communications')
                    ->helperText(__('Turn off to stop club email, such as event summaries. You still get notifications in the app (the bell).')),
                Toggle::make('colorblind_palette')
                    ->label('Colorblind-friendly colors')
                    ->helperText(__('Shows good and bad as blue and orange instead of green and red, everywhere in the app, for you only.')),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save')
            ->action(function (): void {
                $data = $this->form->getState();
                $user = auth()->user();

                if ($user->member_id === null && filled($data['member_id'] ?? null)) {
                    if (! Member::query()->selfLinkable()->whereKey($data['member_id'])->exists()) {
                        throw ValidationException::withMessages([
                            'data.member_id' => __('That member can no longer be linked. Pick another or ask an Admin.'),
                        ]);
                    }

                    $user->member_id = (int) $data['member_id'];
                }

                $user->update([
                    'contact_email' => self::normalize($data['contact_email'] ?? null),
                    'email_opt_in' => (bool) ($data['email_opt_in'] ?? true),
                    'colorblind_palette' => (bool) ($data['colorblind_palette'] ?? false),
                ]);

                $this->data['current_password'] = null;

                Notification::make()->title(__('Account updated'))->success()->send();

                // The palette is applied as a page loads, so reload to show a change.
                if (auth()->user()->wasChanged('colorblind_palette')) {
                    $this->redirect(static::getUrl());
                }
            });
    }

    private static function contactEmailChanged(?string $contactEmail): bool
    {
        return self::normalize($contactEmail) !== auth()->user()->contact_email;
    }

    private static function normalize(?string $contactEmail): ?string
    {
        return filled($contactEmail) ? trim($contactEmail) : null;
    }

    public function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->color('gray')
            ->url(ChangePassword::getUrl());
    }
}
