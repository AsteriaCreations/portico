<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->displayLabel()),
                TextColumn::make('member.username')
                    ->label('Member')
                    ->toggleable(),
                IconColumn::make('active')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                IconColumn::make('must_change_password')
                    ->label('Password reset pending')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Must change password at next login') : __('No password change required')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(fn () => collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $role->displayLabel()])->all()),
                TernaryFilter::make('active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                static::resetPasswordAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Per-row check so bulk delete honours UserPolicy::delete()'s
                    // self-account, rank and last-active-Owner guards, not just
                    // the resource-level Admin+ gate.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    /**
     * For someone locked out: sets a random temporary password, shows it
     * once to read out, signs the account out everywhere, and makes it choose
     * its own at next sign-in. Shared with EditUser's header. The password
     * is only ever in this one flashed notification -- never stored or
     * logged in plain text.
     */
    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Reset the password for :name?', ['name' => $record->name]))
            ->modalDescription(__("They'll be signed out everywhere. You'll see a temporary password once to pass on, and they'll choose their own when they next sign in."))
            ->modalSubmitActionLabel(__('Reset password'))
            ->visible(fn (User $record): bool => Gate::allows('resetPassword', $record))
            ->action(function (User $record): void {
                abort_unless(Gate::allows('resetPassword', $record), 403);

                $password = $record->resetToTemporaryPassword();

                Notification::make()
                    ->title(__('Temporary password for :name', ['name' => $record->name]))
                    ->body(__(":password — pass this on now; it won't be shown again. They'll be asked to choose their own password when they sign in.", ['password' => $password]))
                    ->persistent()
                    ->success()
                    ->send();
            });
    }
}
